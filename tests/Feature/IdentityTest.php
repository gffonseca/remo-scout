<?php

namespace Tests\Feature;

use App\Models\Appearance;
use App\Models\CbfAthlete;
use App\Models\Club;
use App\Models\DataSource;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerSeasonStat;
use App\Models\SyncRun;
use App\Services\Collector\CbfIngest;
use App\Services\IdentityReview;
use App\Services\PlayerEnrichment;
use App\Sources\ApiFutebol;
use App\Sources\SourceClient;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** F14 — vínculo automático só com ID oficial ou nome + nascimento; o resto vai à revisão humana. */
class IdentityTest extends ScoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
    }

    private function fakeTm(string $dob, string $id = '1'): void
    {
        Http::fake([
            "*/players/search/*" => Http::sequence()->push(['results' => [['id' => $id, 'name' => 'João Silva', 'club' => ['name' => 'Remo'], 'age' => 25]]]),
            "*/players/{$id}/profile" => Http::response(['id' => $id, 'dateOfBirth' => $dob, 'citizenship' => ['Brazil']]),
        ]);
    }

    public function test_tm_without_birth_date_never_auto_links_even_with_single_exact_result(): void
    {
        $remo = Club::create(['name' => 'Remo']);
        $p = Player::create(['name' => 'João Silva', 'current_club_id' => $remo->id]);
        $this->fakeTm('2000-01-01');
        $this->assertSame('revisar', PlayerEnrichment::linkTransfermarkt($p->load('currentClub'))['status']);
        $this->assertNull($p->externalId('transfermarkt'));
        $this->assertStringContainsString('sem data de nascimento', IdentityCandidate::first()->evidence);
    }

    public function test_tm_birth_mismatch_goes_to_review_with_evidence_and_match_links(): void
    {
        $remo = Club::create(['name' => 'Remo']);
        $p = Player::create(['name' => 'João Silva', 'current_club_id' => $remo->id, 'birth_date' => '2000-01-01']);
        $this->fakeTm('1999-05-05'); // homônimo de outra idade
        $this->assertSame('revisar', PlayerEnrichment::linkTransfermarkt($p->load('currentClub'))['status']);
        $this->assertStringContainsString('≠ cadastro', IdentityCandidate::first()->evidence);

        IdentityCandidate::query()->delete();
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        \Illuminate\Support\Facades\Http::clearResolvedInstances();
        $this->fakeTm('2000-01-01', '2');
        $this->assertSame('vinculado', PlayerEnrichment::linkTransfermarkt($p->fresh()->load('currentClub'))['status']);
        $this->assertTrue(PlayerExternalId::first()->verified);
    }

    public function test_cbf_homonyms_in_same_club_create_no_link(): void
    {
        $club = Club::create(['name' => 'Paysandu', 'state' => 'PA']);
        Player::create(['name' => 'Gabriel', 'current_club_id' => $club->id]);
        Player::create(['name' => 'Gabriel', 'current_club_id' => $club->id]);
        $a = CbfAthlete::create(['cbf_id' => '5', 'name' => 'Gabriel Souza', 'nickname' => 'GABRIEL', 'club_id' => $club->id]);
        $this->assertFalse(CbfIngest::link($a));
        $this->assertNull($a->fresh()->player_id);
    }

    public function test_cbf_probable_without_birth_waits_for_human_and_confirmation_queues_detail(): void
    {
        $club = Club::create(['name' => 'Paysandu', 'state' => 'PA']);
        $p = Player::create(['name' => 'Nicolas', 'current_club_id' => $club->id]);
        $a = CbfAthlete::create(['cbf_id' => '77', 'name' => 'Nicolas Careca', 'nickname' => 'NICOLAS', 'club_id' => $club->id, 'club_name' => 'Paysandu']);
        CbfIngest::link($a);
        $run = SyncRun::create(['data_source_id' => DataSource::byKey('cbf_atletas')->id, 'job' => 't']);
        $res = CbfIngest::athleteDetail(['atleta_id' => '77', 'atleta_data_nascimento' => '10/01/1990', 'atleta_estatisticas' => ['qtd_partidas' => '9']], $run);
        $this->assertSame('provavel', $res['status']);
        $this->assertNull($p->fresh()->birth_date); // nada aplicado
        $this->assertFalse(PlayerSeasonStat::where('player_id', $p->id)->exists());
        $c = IdentityCandidate::where('player_id', $p->id)->firstOrFail();
        $this->assertStringContainsString('10/01/1990', $c->evidence);

        IdentityReview::confirm($c, $this->user('analista'));
        $a->refresh();
        $this->assertSame('confirmado', $a->link_status);
        $this->assertNull($a->detail_fetched_at); // volta para a fila de detalhe e será aplicado
        $this->assertSame('77', $p->fresh()->cbf_id);
        CbfIngest::athleteDetail(['atleta_id' => '77', 'atleta_data_nascimento' => '10/01/1990', 'atleta_estatisticas' => ['qtd_partidas' => '9']], $run);
        $this->assertSame('1990-01-10', $p->fresh()->birth_date->toDateString());
    }

    public function test_discarding_legacy_link_undoes_what_came_from_that_source(): void
    {
        $p = Player::create(['name' => 'X', 'height_cm' => 180]);
        $p->forceFill(['birth_date' => '1995-02-02', 'cbf_id' => '88', 'photo_path' => 'players/cbf/88-abc.jpg',
            'field_sources' => ['birth_date' => ['source' => 'cbf', 'at' => '2026-10-12'], 'cbf_id' => ['source' => 'cbf', 'at' => '2026-10-12']]])->save();
        CbfAthlete::create(['cbf_id' => '88', 'name' => 'X', 'player_id' => $p->id, 'link_status' => 'provavel']);
        PlayerSeasonStat::create(['player_id' => $p->id, 'provider' => 'cbf', 'competition_key' => 'cbf-total', 'competition_name' => 'CBF', 'season' => '2026', 'appearances' => 3]);
        $c = IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'cbf', 'external_id' => '88', 'kind' => 'confirmar', 'label' => 'X', 'status' => 'pendente']);

        IdentityReview::discard($c, $this->user('analista'));
        $p->refresh();
        $this->assertNull($p->birth_date);
        $this->assertNull($p->cbf_id);
        $this->assertNull($p->photo_path);
        $this->assertSame(180, $p->height_cm); // dado de outra origem intacto
        $this->assertFalse(PlayerSeasonStat::where('player_id', $p->id)->exists());
        $this->assertNull(CbfAthlete::where('cbf_id', '88')->value('player_id'));
    }

    public function test_lineup_player_becomes_provisional_and_merge_moves_appearances(): void
    {
        $club = Club::create(['name' => 'Time A']);
        $scout = Player::create(['name' => 'Fulano de Tal', 'current_club_id' => $club->id]);
        ClubFixtureHelper::bind($club, '1');
        $run = SyncRun::create(['data_source_id' => DataSource::byKey('api_futebol')->id, 'job' => 't']);
        (new ApiFutebol(SourceClient::for('api_futebol')))->ingestMatch([
            'partida_id' => 5, 'status' => 'finalizado', 'data_realizacao_iso' => '2026-05-01T16:00:00-0300',
            'time_mandante' => ['time_id' => 1, 'nome_popular' => 'Time A'], 'time_visitante' => ['time_id' => 2, 'nome_popular' => 'Time B'],
            'placar_mandante' => 0, 'placar_visitante' => 0,
            'escalacoes' => ['mandante' => ['titulares' => [['atleta' => ['atleta_id' => 10, 'nome_popular' => 'Fulano']]], 'reservas' => []], 'visitante' => null],
            'gols' => ['mandante' => [], 'visitante' => []], 'cartoes' => [], 'substituicoes' => ['mandante' => [], 'visitante' => []],
        ], null, 'Teste', '2026', $run);

        $this->assertSame(0, $scout->appearances()->count()); // não liga só por nome + clube
        $c = IdentityCandidate::where('player_id', $scout->id)->where('kind', 'mesclar')->firstOrFail();
        $shadowId = $c->shadow_player_id;

        $analista = $this->user('analista');
        IdentityReview::confirm($c, $analista);
        $this->assertSame(1, $scout->appearances()->count());
        $this->assertNull(Player::withTrashed()->find($shadowId));
        $c = IdentityCandidate::find($c->id); // a trilha da decisão sobrevive à exclusão do provisório
        $this->assertNotNull($c);
        $this->assertSame('vinculado', $c->status);
        $this->assertSame($analista->id, $c->decided_by);
        $this->assertNull($c->shadow_player_id);
        $x = PlayerExternalId::where('provider', 'api_futebol')->where('external_id', '10')->first();
        $this->assertSame($scout->id, $x->player_id);
        $this->assertTrue($x->verified);
    }

    public function test_reader_cannot_decide_identity(): void
    {
        $p = Player::create(['name' => 'X']);
        $c = IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'transfermarkt', 'external_id' => '1', 'label' => 'X', 'status' => 'pendente']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        IdentityReview::confirm($c, $this->user('leitor'));
    }

    public function test_migration_turns_legacy_name_club_links_into_review_items(): void
    {
        $m = require database_path('migrations/2026_10_14_000300_identity_confidence.php');
        $m->down();
        $p = Player::create(['name' => 'Legado']);
        DB::table('player_external_ids')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'player_id' => $p->id, 'provider' => 'transfermarkt',
            'external_id' => '42', 'verified' => false, 'created_at' => now(), 'updated_at' => now()]);
        $m->up();
        $this->assertSame('nome_clube_legado', PlayerExternalId::first()->link_method);
        $this->assertSame('confirmar', IdentityCandidate::where('player_id', $p->id)->value('kind'));
    }

    public function test_discarding_lineup_link_moves_its_appearances_away_and_rebuilds_totals(): void
    {
        $club = Club::create(['name' => 'Time A']);
        $p = Player::create(['name' => 'Fulano', 'current_club_id' => $club->id]);
        ClubFixtureHelper::bind($club, '1');
        PlayerExternalId::create(['player_id' => $p->id, 'provider' => 'api_futebol', 'external_id' => '10', 'verified' => false, 'link_method' => 'nome_clube_legado']);
        $run = SyncRun::create(['data_source_id' => DataSource::byKey('api_futebol')->id, 'job' => 't']);
        (new ApiFutebol(SourceClient::for('api_futebol')))->ingestMatch([
            'partida_id' => 8, 'status' => 'finalizado', 'data_realizacao_iso' => '2026-05-01T16:00:00-0300',
            'time_mandante' => ['time_id' => 1, 'nome_popular' => 'Time A'], 'time_visitante' => ['time_id' => 2, 'nome_popular' => 'Time B'],
            'placar_mandante' => 0, 'placar_visitante' => 0,
            'escalacoes' => ['mandante' => ['titulares' => [['atleta' => ['atleta_id' => 10, 'nome_popular' => 'Fulano']]], 'reservas' => []], 'visitante' => null],
            'gols' => ['mandante' => [], 'visitante' => []], 'cartoes' => [], 'substituicoes' => ['mandante' => [], 'visitante' => []],
        ], null, 'Teste', '2026', $run);
        \App\Services\SeasonStatsAggregator::rebuildProvider('api_futebol');
        $this->assertSame(1, $p->appearances()->count());
        $c = IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'api_futebol', 'external_id' => '10', 'kind' => 'confirmar', 'label' => 'x', 'status' => 'pendente']);

        $msg = IdentityReview::discard($c, $this->user('analista'));
        $this->assertStringContainsString('1 atuação(ões) movida(s)', $msg);
        $this->assertSame(0, $p->appearances()->count());
        $this->assertFalse(PlayerSeasonStat::where('player_id', $p->id)->exists());
        $x = PlayerExternalId::where('provider', 'api_futebol')->where('external_id', '10')->sole();
        $this->assertNotSame($p->id, $x->player_id);
        $this->assertSame(1, Player::find($x->player_id)->appearances()->count());
        $this->assertTrue(PlayerSeasonStat::where('player_id', $x->player_id)->exists());
    }

    public function test_merge_rebuilds_totals_for_every_provider_including_api_football(): void
    {
        $into = Player::create(['name' => 'Destino']);
        $from = Player::create(['name' => 'Provisório', 'data_origin' => 'provedor']);
        foreach ([[$into, 1], [$from, 2]] as [$pl, $n]) {
            $f = \App\Models\Fixture::create(['provider' => 'api_football', 'external_id' => (string) $n, 'competition_name' => 'Série A', 'season' => '2026', 'data_quality' => 'completo',
                'field_quality' => ['gols' => 'conferem']]);
            \App\Models\Appearance::create(['fixture_id' => $f->id, 'player_id' => $pl->id, 'provider' => 'api_football', 'started' => true, 'used' => true, 'minutes' => 90]);
        }
        \App\Services\SeasonStatsAggregator::rebuildProvider('api_football');
        \App\Services\PlayerMerge::merge($from, $into, null);
        $this->assertSame(180, PlayerSeasonStat::where('player_id', $into->id)->where('provider', 'api_football')->sole()->minutes);
    }
}

/** Liga um clube ao ID do provedor para o teste de escalação. */
class ClubFixtureHelper
{
    public static function bind(Club $club, string $ext): void
    {
        \App\Models\ClubExternalId::create(['club_id' => $club->id, 'provider' => 'api_futebol', 'external_id' => $ext, 'external_name' => $club->name]);
    }
}
