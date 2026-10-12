<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\ClubSquadEntry;
use App\Models\DataSource;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\SyncRun;
use App\Services\IdentityReview;
use App\Sources\DadosFutebol;
use App\Sources\SourceClient;
use App\Support\ClubResolver;
use App\Support\PlayerInsight;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Dados Futebol: elenco como retrato com origem/data; nunca altera cadastro nem liga por nome. */
class DadosFutebolTest extends ScoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        DataSource::query()->update(['min_interval_ms' => 0]);
        config(['scout.credentials.DADOS_FUTEBOL_KEY' => 'k-test']);
        ClubResolver::flush();
        SourceClient::$sleeper = fn () => null;
    }

    protected function tearDown(): void
    {
        SourceClient::$sleeper = null;
        parent::tearDown();
    }

    private function newRun(): SyncRun
    {
        return SyncRun::create(['data_source_id' => DataSource::byKey('dados_futebol')->id, 'job' => 'elencos']);
    }

    private function squad(array $rows): array
    {
        return ['data' => $rows, 'meta' => ['total' => count($rows)]];
    }

    public function test_club_is_mapped_only_when_search_resolves_to_exactly_that_club(): void
    {
        $remo = Club::create(['name' => 'Remo', 'state' => 'PA']);
        Http::fake([
            '*/v1/times?*' => Http::response(['data' => [['id' => 22, 'nome' => 'Remo'], ['id' => 900, 'nome' => 'Remo Sub-20 de Outro Lugar']]]),
            '*/v1/times/22/jogadores' => Http::response($this->squad([['id' => 1, 'nome' => 'Fulano', 'nome_popular' => 'Fulano']])),
        ]);
        $this->assertSame('22', (new DadosFutebol)->teamIdFor($remo, $this->newRun()));
        $this->assertSame('22', ClubExternalId::where('provider', 'dados_futebol')->value('external_id'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer k-test'));

        $amb = Club::create(['name' => 'Atlético', 'state' => null]);
        Http::fake(['*' => Http::response(['data' => [['id' => 5, 'nome' => 'Atlético-MG'], ['id' => 6, 'nome' => 'Atlético-GO']]])]);
        $run = $this->newRun();
        $this->assertNull((new DadosFutebol)->teamIdFor($amb, $run));
        $this->assertStringContainsString('mapear manualmente', implode(' ', $run->messages));
    }

    public function test_squad_snapshot_keeps_origin_marks_departures_and_never_writes_zero_shirt(): void
    {
        $club = Club::create(['name' => 'Paysandu']);
        ClubExternalId::create(['club_id' => $club->id, 'provider' => 'dados_futebol', 'external_id' => '292', 'external_name' => 'Paysandu']);
        Http::fake(['*/v1/times/292/jogadores' => Http::sequence()
            ->push($this->squad([
                ['id' => 1, 'nome' => 'Atleta Um', 'nome_popular' => 'Um', 'numero_camisa' => 9, 'posicao' => 'atacante'],
                ['id' => 2, 'nome' => 'Atleta Dois', 'nome_popular' => 'Dois', 'numero_camisa' => null],
            ]))
            ->push($this->squad([['id' => 1, 'nome' => 'Atleta Um', 'nome_popular' => 'Um', 'numero_camisa' => 9]]))
            ->push($this->squad([]))]);

        $api = new DadosFutebol;
        $api->client->source->update(['enabled' => true]);
        $s1 = $api->syncSquad($club, $this->newRun());
        $this->assertSame(2, $s1['new']);
        $this->assertNull(ClubSquadEntry::where('external_player_id', '2')->value('shirt_number')); // ausente ≠ 0
        $this->assertSame('atacante', ClubSquadEntry::where('external_player_id', '1')->value('position_raw'));
        $keys = array_keys(json_decode(ClubSquadEntry::where('external_player_id', '1')->toBase()->value('raw'), true));
        sort($keys);
        $this->assertSame(['id', 'nome', 'nome_popular', 'numero_camisa', 'posicao'], $keys);
        $this->assertSame([], array_diff_key(DadosFutebol::whitelist(['id' => 1, 'lesionado' => true, 'cpf' => 'x']), ['id' => 1]));

        Cache::flush(); // ignora o cache de 6 h para simular a semana seguinte
        $this->travel(7)->days();
        $s2 = $api->syncSquad($club, $this->newRun());
        $this->assertSame(1, $s2['left']);
        $this->assertNotNull(ClubSquadEntry::where('external_player_id', '2')->value('left_at'));

        Cache::flush();
        $this->travel(7)->days();
        $run = $this->newRun();
        $api->syncSquad($club, $run); // resposta vazia não "esvazia" o elenco
        $this->assertNull(ClubSquadEntry::where('external_player_id', '1')->value('left_at'));
        $this->assertStringContainsString('retrato anterior mantido', implode(' ', $run->messages));
    }

    public function test_name_and_club_only_create_review_and_confirmation_links_snapshot(): void
    {
        $club = Club::create(['name' => 'Paysandu']);
        ClubExternalId::create(['club_id' => $club->id, 'provider' => 'dados_futebol', 'external_id' => '292', 'external_name' => 'Paysandu']);
        $p = Player::create(['name' => 'Nicolas Careca', 'current_club_id' => $club->id]);
        Player::create(['name' => 'Gabriel', 'current_club_id' => $club->id]);
        Player::create(['name' => 'Gabriel', 'current_club_id' => $club->id]); // homônimos: nem revisão
        Http::fake(['*/v1/times/292/jogadores' => Http::response($this->squad([
            ['id' => 77, 'nome' => 'Nicolas Careca', 'nome_popular' => 'Nicolas', 'numero_camisa' => 10],
            ['id' => 78, 'nome' => 'Gabriel', 'nome_popular' => 'Gabriel'],
        ]))]);

        $stats = (new DadosFutebol)->syncSquad($club, $this->newRun());
        $this->assertSame(1, $stats['review']);
        $this->assertSame(0, $stats['linked']);
        $this->assertNull(ClubSquadEntry::where('external_player_id', '77')->value('player_id'));
        $this->assertSame(0, PlayerExternalId::count()); // nada ligado sozinho
        $c = IdentityCandidate::where('provider', 'dados_futebol')->sole();
        $this->assertSame($p->id, $c->player_id);
        $this->assertStringContainsString('não informa nascimento', $c->evidence);

        IdentityReview::confirm($c, $this->user('analista'));
        Cache::flush();
        $stats = (new DadosFutebol)->syncSquad($club, $this->newRun());
        $this->assertSame(1, $stats['linked']);
        $this->assertSame($p->id, ClubSquadEntry::where('external_player_id', '77')->value('player_id'));
        $this->assertStringContainsString('confirma no elenco, camisa 10', PlayerInsight::squadNote($p));
    }

    public function test_discarded_review_is_not_reopened_and_other_club_is_flagged_without_changing_player(): void
    {
        [$a, $b] = [Club::create(['name' => 'Paysandu']), Club::create(['name' => 'Tuna Luso'])];
        ClubExternalId::create(['club_id' => $b->id, 'provider' => 'dados_futebol', 'external_id' => '5', 'external_name' => 'Tuna']);
        $p = Player::create(['name' => 'Atleta X', 'current_club_id' => $a->id]);
        PlayerExternalId::create(['player_id' => $p->id, 'provider' => 'dados_futebol', 'external_id' => '50', 'verified' => true, 'link_method' => 'manual']);
        $q = Player::create(['name' => 'Atleta Y', 'current_club_id' => $b->id]);
        IdentityCandidate::create(['player_id' => $q->id, 'provider' => 'dados_futebol', 'external_id' => '51', 'label' => 'Y', 'status' => 'descartado', 'kind' => 'escolher']);
        Http::fake(['*' => Http::response($this->squad([['id' => 50, 'nome' => 'Atleta X'], ['id' => 51, 'nome' => 'Atleta Y']]))]);

        (new DadosFutebol)->syncSquad($b, $this->newRun());
        $this->assertSame('descartado', IdentityCandidate::where('external_id', '51')->value('status'));
        $this->assertSame($a->id, $p->fresh()->current_club_id);
        $this->assertStringContainsString('Tuna Luso', PlayerInsight::squadNote($p));
    }

    public function test_command_stops_on_source_problem_and_records_partial_run(): void
    {
        $remo = Club::create(['name' => 'Remo']);
        ClubExternalId::create(['club_id' => $remo->id, 'provider' => 'dados_futebol', 'external_id' => '22', 'external_name' => 'Remo']);
        DataSource::byKey('dados_futebol')->update(['daily_quota' => 0]);
        Http::fake();
        $this->artisan('scout:sync-dados-futebol')->assertSuccessful();
        $run = SyncRun::where('job', 'elencos')->sole();
        $this->assertSame('falhou', $run->status);
        $this->assertStringContainsString('Cota', implode(' ', $run->messages));
        Http::assertNothingSent();
    }
}
