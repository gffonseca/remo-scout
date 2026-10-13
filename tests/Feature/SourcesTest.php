<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\DataSource;
use App\Models\EnrichmentReview;
use App\Models\Fixture;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\SyncRun;
use App\Services\PlayerEnrichment;
use App\Sources\ApiFutebol;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use App\Sources\SumulaImporter;
use App\Sources\SumulaParser;
use App\Support\ClubResolver;
use App\Support\Media;
use App\Support\NameMatcher;
use Database\Seeders\ClubsSeeder;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SourcesTest extends ScoutTestCase
{
    use CompleteLineups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        DataSource::query()->update(['min_interval_ms' => 200]);
        ClubResolver::flush();
    }

    public function test_minute_rules(): void
    {
        $this->assertSame(71, ApiFutebol::absMinute('26:44', 'segundo-tempo'));
        $this->assertSame(45, ApiFutebol::absMinute('47:10', 'primeiro-tempo')); // acréscimo limitado ao fim regulamentar
        $this->assertNull(ApiFutebol::absMinute(null, 'segundo-tempo'));
        $this->assertSame(74, SumulaParser::absMinute('29:00', '2T'));
        $this->assertSame(90, SumulaParser::absMinute('+05:00', '2T'));
        $this->assertNull(PlayerSeasonStat::per90(3, 0));
        $this->assertSame(0.4, PlayerSeasonStat::per90(4, 900));
    }

    public function test_name_and_club_matching(): void
    {
        $this->assertSame(100, NameMatcher::score('Germán Cano', 'German Cano'));
        $this->assertGreaterThanOrEqual(88, NameMatcher::score('Ziyech', 'Hakim Ziyech'));
        Club::create(['name' => 'Botafogo', 'state' => 'RJ']);
        Club::create(['name' => 'Botafogo-SP', 'state' => 'SP']);
        $this->assertSame('Botafogo', ClubResolver::byName('Botafogo de Futebol e Regatas')?->name);
        $this->assertSame('Botafogo-SP', ClubResolver::byName('Botafogo-SP')?->name);
    }

    public function test_sumula_fpf_import_minutes_ids_and_idempotency(): void
    {
        Club::create(['name' => 'Remo', 'state' => 'PA']);
        Club::create(['name' => 'Cametá', 'state' => 'PA']);
        $d = SumulaParser::parse(SumulaParser::textFromPdf(base_path('tests/fixtures/sumula-fpf-cameta-remo-2026.pdf')));
        $this->assertSame('Cametá', $d['home']);
        $this->assertSame(23, count($d['players']['away']));
        $this->assertSame([2, 3], [$d['score_home'], $d['score_away']]);
        $r = SumulaImporter::import($d, 'sumula_fpf');
        $this->assertSame(46, $r['appearances']);
        $rangel = Player::where('cbf_id', '186722')->first();
        $this->assertNotNull($rangel);
        $sub = Player::where('cbf_id', '785447')->first(); // entrou aos 29' do 2T
        $app = $sub->appearances()->first();
        $this->assertSame(74, $app->minute_in);
        $this->assertSame(16, $app->minutes);
        $kayky = Player::where('cbf_id', '653267')->first();
        $this->assertSame(1, $kayky->appearances()->first()->own_goals); // gol contra (CT)
        SumulaImporter::import($d, 'sumula_fpf');
        $this->assertSame(1, Fixture::count());
        $this->assertSame(46, Player::count());
        $this->assertTrue(PlayerSeasonStat::where('provider', 'sumula_fpf')->where('player_id', $sub->id)->exists());
    }

    public function test_api_futebol_partial_match_never_assumes_90_minutes(): void
    {
        $run = SyncRun::create(['data_source_id' => DataSource::byKey('api_futebol')->id, 'job' => 't']);
        $base = ['partida_id' => 1, 'time_mandante' => ['time_id' => 1, 'nome_popular' => 'Time A'], 'time_visitante' => ['time_id' => 2, 'nome_popular' => 'Time B'],
            'placar_mandante' => 1, 'placar_visitante' => 0, 'status' => 'finalizado', 'data_realizacao_iso' => '2026-05-01T16:00:00-0300',
            'escalacoes' => [
                'mandante' => ['titulares' => [['atleta' => ['atleta_id' => 10, 'nome_popular' => 'Fulano'], 'camisa' => '9']], 'reservas' => [['atleta' => ['atleta_id' => 11, 'nome_popular' => 'Ciclano'], 'camisa' => '19']]],
                'visitante' => ['titulares' => [['atleta' => ['atleta_id' => 20, 'nome_popular' => 'Beltrano'], 'camisa' => '1']], 'reservas' => []],
            ],
            'gols' => ['mandante' => [['atleta' => ['atleta_id' => 10], 'minuto' => '10:00', 'periodo_slug' => 'primeiro-tempo', 'penalti' => false, 'gol_contra' => false]], 'visitante' => []],
            'cartoes' => ['amarelo' => ['mandante' => [], 'visitante' => []], 'vermelho' => ['mandante' => [], 'visitante' => []]],
            'substituicoes' => ['mandante' => [['saiu' => ['atleta_id' => 10], 'entrou' => ['atleta_id' => 11], 'minuto' => '20:00', 'periodo_slug' => 'segundo-tempo']], 'visitante' => []],
        ];
        $base['substituicoes']['_fill'] = true;
        $base = $this->completeLineups($base);
        $api = new ApiFutebol(SourceClient::for('api_futebol'));
        $f = $api->ingestMatch($base, null, 'Teste', '2026', $run);
        $this->assertSame('completo', $f->data_quality);
        $fulano = Player::where('name', 'Fulano')->first()->appearances()->first();
        $this->assertSame(65, $fulano->minutes);
        $this->assertSame(1, $fulano->goals);
        $this->assertSame(25, Player::where('name', 'Ciclano')->first()->appearances()->first()->minutes);

        $partial = $base;
        $partial['partida_id'] = 2;
        $partial['substituicoes'] = ['mandante' => [], 'visitante' => []];
        $f2 = $api->ingestMatch($partial, null, 'Teste', '2026', $run);
        $this->assertSame('parcial', $f2->data_quality);
        $this->assertNull($f2->appearances()->where('started', true)->first()->minutes); // indeterminado, não 90
        $this->assertSame(24, Player::count()); // reaproveita ids do provedor (22 titulares + 2 reservas usados)
    }

    public function test_source_client_kill_switch_quota_and_block(): void
    {
        $tm = DataSource::byKey('transfermarkt');
        $tm->update(['enabled' => false]);
        try {
            SourceClient::for('transfermarkt')->getJson('/players/search/x');
            $this->fail('deveria bloquear fonte desativada');
        } catch (SourceException $e) {
            $this->assertSame('desativada', $e->kind);
        }
        $tm->update(['enabled' => true, 'daily_quota' => 1, 'used_today' => 1, 'quota_date' => DataSource::quotaDay()]);
        try {
            SourceClient::for('transfermarkt')->getJson('/players/search/x');
            $this->fail('deveria respeitar cota');
        } catch (SourceException $e) {
            $this->assertSame('cota', $e->kind);
        }
        $tm->update(['daily_quota' => 100, 'used_today' => 0]);
        Http::fake(['*' => Http::response('Forbidden', 403)]);
        try {
            SourceClient::for('transfermarkt')->getJson('/players/search/x');
        } catch (SourceException $e) {
            $this->assertSame('bloqueado', $e->kind);
        }
        $this->assertSame('bloqueado', $tm->fresh()->health);
        $this->assertSame(1, $tm->fresh()->used_today);
    }

    public function test_transfermarkt_enrichment_fills_empty_and_reviews_conflicts(): void
    {
        $club = Club::create(['name' => 'Botafogo', 'state' => 'RJ']);
        $p = Player::create(['name' => 'Ziyech', 'current_club_id' => $club->id, 'height_cm' => 175, 'birth_date' => '1993-03-19']);
        Http::fake([
            '*/players/search/*' => Http::response(['results' => [['id' => '217111', 'name' => 'Hakim Ziyech', 'position' => 'RW', 'club' => ['id' => '537', 'name' => 'Botafogo de Futebol e Regatas'], 'age' => 33]]]),
            '*/players/217111/profile' => Http::response(['id' => '217111', 'url' => 'https://www.transfermarkt.com/x', 'dateOfBirth' => '1993-03-19', 'height' => 180,
                'citizenship' => ['Morocco', 'Netherlands'], 'position' => ['main' => 'Right Winger', 'other' => ['Attacking Midfield']], 'foot' => 'left',
                'club' => ['id' => '537', 'name' => 'Botafogo de Futebol e Regatas', 'contractExpires' => '2028-12-31'], 'marketValue' => 2200000, 'agent' => null]),
            '*/players/217111/stats' => Http::response(['stats' => [['competitionId' => 'BRA1', 'competitionName' => 'Série A', 'seasonId' => '2026', 'clubId' => '537', 'appearances' => 2, 'goals' => 0, 'minutesPlayed' => 125],
                ['competitionId' => 'NL1', 'competitionName' => 'Eredivisie', 'seasonId' => '2019', 'clubId' => '610', 'appearances' => 30, 'goals' => 16]]]),
            '*/clubs/610/profile' => Http::response(['id' => '610', 'name' => 'Ajax Amsterdam']),
        ]);
        $link = PlayerEnrichment::linkTransfermarkt($p->load('currentClub'));
        $this->assertSame('vinculado', $link['status']);
        $res = PlayerEnrichment::enrichTransfermarkt($p->fresh());
        $this->assertSame('ok', $res['status']);
        $p->refresh();
        $this->assertSame('PD', $p->primary_position->value);
        $this->assertSame('nome_nascimento', $p->externalIds()->where('provider', 'transfermarkt')->value('link_method'));
        $this->assertSame('esquerdo', $p->preferred_foot->value);
        $this->assertNull($p->eu_passport); // F15: cidadania holandesa declarada não confirma passaporte
        $this->assertSame(['Marrocos', 'Holanda'], collect($p->citizenships)->pluck('country')->all());
        $this->assertSame('transfermarkt', $p->citizenships[1]['source']);
        $this->assertSame(175, $p->height_cm); // não sobrescreve
        $this->assertTrue(EnrichmentReview::where('player_id', $p->id)->where('field', 'height_cm')->where('proposed_value', '180')->exists());
        $this->assertSame('transfermarkt', $p->field_sources['preferred_foot']['source']);
        // a fonte manda só o ID do clube: ID fica em club_external_id, rótulo da fonte à parte, nunca o código como nome.
        // "Botafogo de Futebol e Regatas" casou com "Botafogo" só pelo núcleo do nome → não vira clube canônico sem confirmação.
        $rows = $p->seasonStats()->where('provider', 'transfermarkt')->get()->keyBy('club_external_id');
        $this->assertSame(['537', '610'], $rows->keys()->map(fn ($k) => (string) $k)->sort()->values()->all());
        $this->assertSame('Ajax Amsterdam', $rows['610']->club_source_name);
        $this->assertSame('Botafogo de Futebol e Regatas', $rows['537']->club_source_name);
        $this->assertNull($rows['610']->club_id);
        $this->assertNull($rows['537']->club_id);
        $this->assertSame('nucleo', ClubExternalId::where('provider', 'transfermarkt')->where('external_id', '537')->value('method'));
        $this->assertTrue($rows->every(fn ($r) => $r->club_name === null || ! ctype_digit($r->club_name)));
        $this->assertTrue($p->marketObservations()->where('currency', 'EUR')->exists());
    }

    public function test_ambiguous_search_creates_candidates_not_link(): void
    {
        $p = Player::create(['name' => 'Wallace']);
        Http::fake(['*/players/search/*' => Http::response(['results' => [
            ['id' => '1', 'name' => 'Wallace', 'club' => ['name' => 'Clube X'], 'age' => 30],
            ['id' => '2', 'name' => 'Wallace', 'club' => ['name' => 'Clube Y'], 'age' => 25],
        ]])]);
        $r = PlayerEnrichment::linkTransfermarkt($p);
        $this->assertSame('revisar', $r['status']);
        $this->assertSame(0, $p->externalIds()->count());
        $this->assertSame(2, IdentityCandidate::where('player_id', $p->id)->count());
    }

    public function test_sources_page_renders_for_coordinator_and_is_hidden_for_reader(): void
    {
        $this->actingAs($this->user('coordenador'));
        $this->get('/fontes')->assertOk()->assertSee('API Futebol');
        $this->get('/partidas')->assertOk();
        $this->get('/revisoes-de-dados')->assertOk();
    }

    public function test_reader_cannot_access_sources(): void
    {
        $this->actingAs($this->user('leitor'));
        $this->get('/fontes')->assertForbidden();
    }

    public function test_uploaded_photo_and_crest_take_priority_over_external(): void
    {
        Storage::fake('media');
        $club = Club::create(['name' => 'Clube Foto', 'country' => 'Brasil', 'crest_url' => 'https://example.test/c.png']);
        $p = Player::create(['name' => 'Atleta Foto', 'photo_url' => 'https://example.test/p.jpg']);
        $external = $p->photoUrl(); // foto sempre servida pelo CIFUT; a versão muda quando a origem muda
        $this->assertStringContainsString('/foto/atleta/'.$p->id, $external);
        $this->assertSame('https://example.test/c.png', $club->crestUrl());
        $p->update(['photo_path' => 'players/x.jpg']);
        $club->update(['crest_path' => 'crests/x.png']);
        $this->assertNotSame($external, $p->fresh()->photoUrl());
        $this->assertStringContainsString('crests/x.png', $club->fresh()->crestUrl());
        $this->assertSame('', Media::crestTag(null));
    }

    public function test_provider_club_aliases_resolve_without_duplicates(): void
    {
        $this->seed(ClubsSeeder::class);
        ClubResolver::flush();
        $before = Club::count();
        $this->assertSame('Red Bull Bragantino', ClubResolver::resolve('api_futebol', '999001', 'Bragantino', true)?->name);
        $this->assertSame('ABECAT', ClubResolver::resolve('api_futebol', '999002', 'Abecat Ouvidorense', true)?->name);
        $this->assertSame($before, Club::count());
    }
}
