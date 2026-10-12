<?php

namespace Tests\Feature;

use App\Models\Appearance;
use App\Models\DataSource;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\SyncRun;
use App\Services\SeasonStatsAggregator;
use App\Sources\ApiFutebol;
use App\Sources\SourceClient;
use Database\Seeders\DataSourcesSeeder;

/** F13 — qualidade por campo: escalação, evento sem jogador, minuto ilegível, prorrogação (120) e agregados coerentes. */
class FixtureQualityTest extends ScoutTestCase
{
    use CompleteLineups;

    private ApiFutebol $api;

    private SyncRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        $this->api = new ApiFutebol(SourceClient::for('api_futebol'));
        $this->run = SyncRun::create(['data_source_id' => DataSource::byKey('api_futebol')->id, 'job' => 't']);
    }

    private function match(int $id, array $over = []): array
    {
        $d = array_replace_recursive([
            'partida_id' => $id, 'status' => 'finalizado', 'data_realizacao_iso' => '2026-05-01T16:00:00-0300',
            'time_mandante' => ['time_id' => 1, 'nome_popular' => 'Time A'], 'time_visitante' => ['time_id' => 2, 'nome_popular' => 'Time B'],
            'placar_mandante' => 1, 'placar_visitante' => 0,
            'escalacoes' => [
                'mandante' => ['titulares' => [['atleta' => ['atleta_id' => 10, 'nome_popular' => 'Fulano']]], 'reservas' => [['atleta' => ['atleta_id' => 11, 'nome_popular' => 'Ciclano']]]],
                'visitante' => ['titulares' => [], 'reservas' => []],
            ],
            'gols' => ['mandante' => [['atleta' => ['atleta_id' => 10], 'minuto' => '10:00', 'periodo_slug' => 'primeiro-tempo']], 'visitante' => []],
            'cartoes' => ['amarelo' => ['mandante' => [], 'visitante' => []], 'vermelho' => ['mandante' => [], 'visitante' => []]],
            'substituicoes' => ['_fill' => true, 'mandante' => [['saiu' => ['atleta_id' => 10], 'entrou' => ['atleta_id' => 11], 'minuto' => '20:00', 'periodo_slug' => 'segundo-tempo']], 'visitante' => []],
        ], $over);

        return $this->completeLineups($d);
    }

    private function app(string $name, int $fixtureExt): ?Appearance
    {
        return Appearance::whereHas('fixture', fn ($q) => $q->where('external_id', (string) $fixtureExt))
            ->where('player_id', Player::where('name', $name)->value('id'))->first();
    }

    public function test_missing_lineup_on_one_side_keeps_other_side_minutes(): void
    {
        $d = $this->match(1);
        $d['escalacoes']['visitante'] = null;
        $f = $this->api->ingestMatch($d, null, 'Teste', '2026', $this->run);
        $this->assertSame('parcial', $f->data_quality);
        $this->assertSame('ausente', $f->field_quality['visitante']['escalacao']);
        $this->assertSame('determinados', $f->field_quality['mandante']['minutos']);
        $this->assertSame(65, $this->app('Fulano', 1)->minutes);
        $this->assertStringContainsString('visitante: escalação ausente', $f->quality_note);
    }

    public function test_ten_identified_starters_make_side_minutes_unknown_but_bench_is_confirmed_zero(): void
    {
        $d = $this->match(2);
        $d['escalacoes']['mandante']['titulares'][5] = ['atleta' => null];
        $d['escalacoes']['mandante']['reservas'][] = ['atleta' => ['atleta_id' => 12, 'nome_popular' => 'Banco']];
        $f = $this->api->ingestMatch($d, null, 'Teste', '2026', $this->run);
        $this->assertSame('incompleta', $f->field_quality['mandante']['escalacao']);
        $this->assertSame(10, $f->field_quality['mandante']['titulares_identificados']);
        $this->assertNull($this->app('Fulano', 2)->minutes);
        $this->assertSame(0, $this->app('Banco', 2)->minutes); // não entrou: zero confirmado
        $this->assertFalse($this->app('Banco', 2)->used);
    }

    public function test_goal_without_player_marks_goals_unverified_and_aggregate_as_lower_bound(): void
    {
        $d = $this->match(3, ['placar_mandante' => 2]);
        $d['gols']['mandante'][] = ['atleta' => null, 'minuto' => '30:00', 'periodo_slug' => 'segundo-tempo'];
        $f = $this->api->ingestMatch($d, null, 'Teste', '2026', $this->run);
        $this->assertSame('divergem', $f->field_quality['gols']);
        $this->assertNull($this->app('Fulano', 3)->minutes); // feed incompleto: não confia nos minutos
        SeasonStatsAggregator::rebuildProvider(ApiFutebol::PROVIDER);
        $s = PlayerSeasonStat::where('player_id', Player::where('name', 'Fulano')->value('id'))->sole();
        $this->assertSame(1, $s->goals);
        $this->assertSame(1, $s->extra['jogos_gols_nao_conferidos']);
        $this->assertNull($s->minutes);
    }

    public function test_unreadable_substitution_minute_keeps_player_as_used_with_unknown_minutes(): void
    {
        $d = $this->match(4);
        $d['substituicoes']['mandante'][0]['minuto'] = null;
        $f = $this->api->ingestMatch($d, null, 'Teste', '2026', $this->run);
        $this->assertSame('minuto_ilegivel', $f->field_quality['mandante']['substituicoes']);
        $ciclano = $this->app('Ciclano', 4);
        $this->assertTrue($ciclano->used);
        $this->assertNull($ciclano->minutes);
        $this->assertSame('determinados', $f->field_quality['visitante']['minutos']);
    }

    public function test_extra_time_uses_120_minutes(): void
    {
        $d = $this->match(5);
        $d['substituicoes']['mandante'] = [['saiu' => ['atleta_id' => 10], 'entrou' => ['atleta_id' => 11], 'minuto' => '05:00', 'periodo_slug' => 'prorrogacao-primeiro-tempo']];
        $f = $this->api->ingestMatch($d, null, 'Teste', '2026', $this->run);
        $this->assertSame(120, $f->field_quality['duracao']);
        $this->assertTrue($f->field_quality['prorrogacao']);
        $this->assertSame(95, $this->app('Fulano', 5)->minutes);
        $this->assertSame(25, $this->app('Ciclano', 5)->minutes);
        $this->assertSame(120, $this->app('Extra mandante 3', 5)->minutes);
        $this->assertSame(105, ApiFutebol::absMinute('15:00', 'prorrogacao-primeiro-tempo'));
        $this->assertSame(118, ApiFutebol::absMinute('13:10', 'prorrogacao-segundo-tempo'));
        $this->assertNull(ApiFutebol::absMinute('02:00', 'disputa-penaltis'));
    }

    public function test_season_aggregate_keeps_known_minutes_without_turning_unknown_into_zero(): void
    {
        $this->api->ingestMatch($this->match(6), null, 'Teste', '2026', $this->run);
        $partial = $this->match(7);
        $partial['substituicoes']['mandante'] = [];
        $this->api->ingestMatch($partial, null, 'Teste', '2026', $this->run);
        SeasonStatsAggregator::rebuildProvider(ApiFutebol::PROVIDER);
        $s = PlayerSeasonStat::where('player_id', Player::where('name', 'Fulano')->value('id'))->sole();
        $this->assertSame(2, $s->appearances);
        $this->assertNull($s->minutes);
        $this->assertSame(65, $s->extra['minutos_conhecidos']);
        $this->assertSame(1, $s->extra['jogos_minutos_indeterminados']);
        $this->assertNull(PlayerSeasonStat::per90($s->goals, $s->minutes));
    }
}
