<?php

namespace Tests\Feature;

use App\Models\Appearance;
use App\Models\Club;
use App\Models\DataSource;
use App\Models\Fixture;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerSeasonStat;
use App\Models\ProviderAthlete;
use App\Models\SyncRun;
use App\Services\SeasonStatsAggregator;
use App\Sources\ApiFootball;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use App\Support\ClubResolver;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\Http;

/** API-Football: partidas reais salvas (Série A com estatística individual; Série C só escalação). */
class ApiFootballTest extends ScoutTestCase
{
    private ApiFootball $api;

    private SyncRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        DataSource::byKey('api_football')->update(['enabled' => true, 'min_interval_ms' => 0, 'daily_quota' => 7000]);
        config(['scout.credentials.API_FOOTBALL_KEY' => 'k-test']);
        ClubResolver::flush();
        SourceClient::$sleeper = fn () => null;
        $this->api = new ApiFootball;
        $this->run = SyncRun::create(['data_source_id' => DataSource::byKey('api_football')->id, 'job' => 't']);
    }

    protected function tearDown(): void
    {
        SourceClient::$sleeper = null;
        parent::tearDown();
    }

    private function sample(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/api_football/{$name}.json")), true)['response'][0];
    }

    private function appearanceOf(string $extId): ?Appearance
    {
        $pid = PlayerExternalId::where('provider', 'api_football')->where('external_id', $extId)->value('player_id');

        return Appearance::where('player_id', $pid)->first();
    }

    public function test_serie_a_uses_provider_minutes_and_keeps_only_present_stats(): void
    {
        $d = $this->sample('fixture_serie_a');
        $f = $this->api->ingestFixture($d, 'Série A', '2026', $this->run);

        $this->assertSame('completo', $f->data_quality);
        $this->assertSame('provedor', $f->field_quality['mandante']['minutos']);
        $this->assertSame('conferem', $f->field_quality['gols']); // pênalti perdido não conta como gol
        $this->assertSame(3, $f->away_score);

        $keeper = $this->appearanceOf('41169'); // Léo Jardim: 90 min, sem finalizações medidas
        $this->assertSame(90, $keeper->minutes);
        $this->assertSame(6.3, $keeper->stats['nota']);
        $this->assertSame(7, $keeper->stats['defesas']);
        $this->assertArrayNotHasKey('finalizacoes', $keeper->stats); // null do provedor = não medido, não zero

        $sub = collect($d['events'])->firstWhere('type', 'subst');
        $this->assertSame(52, $this->appearanceOf((string) $sub['player']['id'])->minutes);
        $this->assertSame(38, $this->appearanceOf((string) $sub['assist']['id'])->minutes);
        $this->assertSame(46, Appearance::where('fixture_id', $f->id)->count());
        $this->assertSame(0, Appearance::where('fixture_id', $f->id)->where('used', false)->where('minutes', '!=', 0)->count());
        $goals = collect($d['events'])->where('type', 'Goal')->where('detail', '!=', 'Missed Penalty')->count();
        $this->assertSame($goals, (int) Appearance::where('fixture_id', $f->id)->sum('goals'));
    }

    public function test_serie_c_without_player_stats_computes_minutes_from_lineup_and_substitutions(): void
    {
        $d = $this->sample('fixture_serie_c');
        $f = $this->api->ingestFixture($d, 'Série C', '2026', $this->run);
        $this->assertSame('determinados', $f->field_quality['mandante']['minutos']);
        $this->assertFalse($f->field_quality['mandante']['estatistica_individual']);
        $sub = collect($d['events'])->firstWhere('type', 'subst');
        $min = $sub['time']['elapsed'];
        $this->assertSame($min, $this->appearanceOf((string) $sub['player']['id'])->minutes);
        $this->assertSame(90 - $min, $this->appearanceOf((string) $sub['assist']['id'])->minutes);
        $this->assertNull($this->appearanceOf((string) $sub['assist']['id'])->stats);

        // escalação de um lado some: aquele lado fica indeterminado, o outro se mantém
        $broken = $d;
        $broken['lineups'][0]['startXI'] = array_slice($broken['lineups'][0]['startXI'], 0, 10);
        $broken['fixture']['id'] = 999;
        $f2 = $this->api->ingestFixture($broken, 'Série C', '2026', $this->run);
        $this->assertSame('parcial', $f2->data_quality);
        $this->assertSame('incompleta', $f2->field_quality['mandante']['escalacao']);
    }

    public function test_extra_time_fixture_has_120_minutes(): void
    {
        $d = $this->sample('fixture_serie_c');
        $d['fixture']['status']['short'] = 'AET';
        $d['score']['extratime'] = ['home' => 0, 'away' => 0];
        $f = $this->api->ingestFixture($d, 'Copa', '2026', $this->run);
        $this->assertSame(120, $f->field_quality['duracao']);
        $starterNeverSubbed = collect($d['lineups'][0]['startXI'])->pluck('player.id')
            ->diff(collect($d['events'])->where('type', 'subst')->pluck('player.id'))->first();
        $this->assertSame(120, $this->appearanceOf((string) $starterNeverSubbed)->minutes);
    }

    public function test_identity_links_only_with_same_birth_date_and_creates_review_otherwise(): void
    {
        $d = $this->sample('fixture_serie_a');
        $keeperName = 'Léo Jardim';
        $vasco = Club::create(['name' => 'Vasco da Gama']);
        $scout = Player::create(['name' => $keeperName, 'birth_date' => '1995-03-20', 'current_club_id' => $vasco->id]);
        ProviderAthlete::create(['provider' => 'api_football', 'external_id' => '41169', 'name' => $keeperName, 'birth_date' => '1995-03-20', 'fetched_at' => now()]);
        // outro atleta do Vasco no scout sem nascimento: vira revisão, não vínculo
        $sub = collect($d['events'])->firstWhere('type', 'subst');
        $other = Player::create(['name' => $sub['player']['name'], 'current_club_id' => $vasco->id]);
        ProviderAthlete::create(['provider' => 'api_football', 'external_id' => (string) $sub['player']['id'], 'name' => $sub['player']['name'], 'birth_date' => '2000-01-01', 'fetched_at' => now()]);

        $this->api->ingestFixture($d, 'Série A', '2026', $this->run);

        $x = PlayerExternalId::where('provider', 'api_football')->where('external_id', '41169')->sole();
        $this->assertSame($scout->id, $x->player_id);
        $this->assertTrue($x->verified);
        $this->assertSame('nome_nascimento', $x->link_method);
        $this->assertSame(1, $scout->appearances()->count());

        $this->assertSame(0, $other->appearances()->count());
        $c = IdentityCandidate::where('player_id', $other->id)->sole();
        $this->assertSame('mesclar', $c->kind);
        $this->assertStringContainsString('01/01/2000', $c->evidence);
        $shadow = Player::find($c->shadow_player_id);
        $this->assertSame('2000-01-01', $shadow->birth_date->toDateString()); // provisório já nasce com nascimento do provedor
        $this->assertSame('api_football', $shadow->field_sources['birth_date']['source']);
    }

    public function test_squad_page_stores_birth_and_never_reads_injury(): void
    {
        $page = json_decode(file_get_contents(base_path('tests/fixtures/api_football/players_page.json')), true);
        $row = $page['response'][0]['player'];
        $row['injured'] = true;
        $a = $this->api->storeAthlete($row, '133', '2026');
        $this->assertSame('1992-06-12', $a->birth_date->toDateString());
        $this->assertSame(172, $a->height_cm);
        $this->assertArrayNotHasKey('injured', $a->getAttributes());
        $this->assertStringNotContainsString('injur', json_encode(ProviderAthlete::first()->toArray()));
    }

    public function test_errors_inside_http_200_are_failures_not_success(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['requests' => 'You have reached the request limit for the day'], 'response' => []])]);
        try {
            $this->api->sync($this->run, [71], 10, 2026);
        } catch (SourceException $e) {
        }
        $s = DataSource::byKey('api_football');
        $this->assertSame('degradado', $s->health);
        $this->assertSame(1, $s->consecutive_failures);
        $this->assertStringContainsString('request limit', $s->last_error);
        Http::assertSent(fn ($r) => $r->hasHeader('x-apisports-key', 'k-test'));
    }

    public function test_season_aggregate_weights_rating_by_minutes_and_sums_present_stats(): void
    {
        $this->api->ingestFixture($this->sample('fixture_serie_a'), 'Série A', '2026', $this->run);
        $second = $this->sample('fixture_serie_a');
        $second['fixture']['id'] = 2;
        $this->api->ingestFixture($second, 'Série A', '2026', $this->run);
        SeasonStatsAggregator::rebuildProvider('api_football');
        $pid = PlayerExternalId::where('provider', 'api_football')->where('external_id', '41169')->value('player_id');
        $s = PlayerSeasonStat::where('player_id', $pid)->sole();
        $this->assertSame(180, $s->minutes);
        $this->assertSame('6.30', (string) $s->rating);
        $this->assertSame(14, $s->extra['estatistica']['defesas']);
        $this->assertSame(2, $s->extra['jogos_com_estatistica']);
        $this->assertArrayNotHasKey('finalizacoes', $s->extra['estatistica']);
    }

    public function test_sync_fetches_new_fixtures_once_and_skips_known_complete_ones(): void
    {
        $fx = $this->sample('fixture_serie_a');
        $list = ['errors' => [], 'response' => [['fixture' => ['id' => $fx['fixture']['id'], 'timestamp' => 1, 'status' => ['short' => 'FT']]],
            ['fixture' => ['id' => 5, 'timestamp' => 2, 'status' => ['short' => 'NS']]]]];
        Http::fake([
            '*/fixtures?league=*' => Http::response($list),
            '*/fixtures?id=*' => Http::response(['errors' => [], 'response' => [$fx]]),
            '*/players?*' => Http::response(['errors' => [], 'response' => [], 'paging' => ['current' => 1, 'total' => 1]]),
        ]);
        $this->api->sync($this->run, [71], 10, 2026);
        $this->assertSame(1, $this->run->received);
        $this->assertSame(1, Fixture::where('provider', 'api_football')->count());
        Http::assertSentCount(4); // lista + 2 elencos + 1 partida (não finalizada é ignorada)

        $run2 = SyncRun::create(['data_source_id' => DataSource::byKey('api_football')->id, 'job' => 't']);
        \Illuminate\Support\Facades\Cache::flush();
        Fixture::query()->update(['played_on' => now()->subDays(30)->toDateString()]); // antiga e completa: não reconsulta
        (new ApiFootball)->sync($run2, [71], 10, 2026);
        $this->assertSame(0, $run2->received);
    }

    public function test_provisional_players_are_hidden_from_player_list_by_default(): void
    {
        $this->api->ingestFixture($this->sample('fixture_serie_c'), 'Série C', '2026', $this->run);
        $real = Player::create(['name' => 'Atleta do Scout Visível']);
        $cbf = Player::create(['name' => 'Cadastrado da CBF', 'data_origin' => 'provedor']); // cadastro deliberado continua visível
        $this->assertGreaterThan(30, Player::provisional()->count());
        $this->assertFalse(Player::provisional()->whereKey([$real->id, $cbf->id])->exists());
        $this->actingAs($this->user('analista'));
        $html = $this->get(\App\Filament\Resources\Players\PlayerResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringContainsString('Atleta do Scout Visível', $html);
        $this->assertStringContainsString('Cadastrado da CBF', $html);
        $shadow = Player::provisional()->first();
        $this->assertStringNotContainsString(e($shadow->name), $html);
    }
}
