<?php

namespace Tests\Feature;

use App\Models\Appearance;
use App\Models\DataSource;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\SyncRun;
use App\Services\SeasonStatsAggregator;
use App\Sources\ApiFutebol;
use App\Sources\SourceClient;
use Database\Seeders\DataSourcesSeeder;

/** F08 — partida parcial/recente é reconsultada e eventos corrigidos são reconciliados sem duplicar. */
class FixtureRefreshTest extends ScoutTestCase
{
    use CompleteLineups;

    private ApiFutebol $api;

    private SyncRun $run;

    private string $playedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        $this->api = new ApiFutebol(SourceClient::for('api_futebol'));
        $this->run = SyncRun::create(['data_source_id' => DataSource::byKey('api_futebol')->id, 'job' => 't']);
        $this->playedAt = now()->subDay()->format('Y-m-d\TH:i:sO');
    }

    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'partida_id' => 77, 'status' => 'finalizado', 'data_realizacao_iso' => $this->playedAt,
            'time_mandante' => ['time_id' => 1, 'nome_popular' => 'Time A'], 'time_visitante' => ['time_id' => 2, 'nome_popular' => 'Time B'],
            'placar_mandante' => 1, 'placar_visitante' => 0,
            'escalacoes' => [
                'mandante' => ['titulares' => [['atleta' => ['atleta_id' => 10, 'nome_popular' => 'Fulano']], ['atleta' => ['atleta_id' => 12, 'nome_popular' => 'Errado']]],
                    'reservas' => [['atleta' => ['atleta_id' => 11, 'nome_popular' => 'Ciclano']]]],
                'visitante' => ['titulares' => [['atleta' => ['atleta_id' => 20, 'nome_popular' => 'Beltrano']]], 'reservas' => []],
            ],
            'gols' => ['mandante' => [['atleta' => ['atleta_id' => 10], 'minuto' => '10:00', 'periodo_slug' => 'primeiro-tempo']], 'visitante' => []],
            'cartoes' => ['amarelo' => ['mandante' => [], 'visitante' => []], 'vermelho' => ['mandante' => [], 'visitante' => []]],
            'substituicoes' => ['mandante' => [], 'visitante' => []],
        ], $over);
    }

    private function app(string $name): ?Appearance
    {
        return Player::where('name', $name)->first()?->appearances()->first();
    }

    public function test_partial_then_complete_then_corrected_updates_without_duplicates(): void
    {
        // 1) primeira resposta parcial: sem substituições → minutos indeterminados
        $f = $this->api->ingestMatch($this->payload(), null, 'Teste', '2026', $this->run);
        $this->assertSame('parcial', $f->data_quality);
        $this->assertNull($this->app('Fulano')->minutes);

        // 2) resposta completa: substituição chega → minutos calculados
        $complete = $this->completeLineups($this->payload(['substituicoes' => ['_fill' => true, 'mandante' => [['saiu' => ['atleta_id' => 10], 'entrou' => ['atleta_id' => 11], 'minuto' => '20:00', 'periodo_slug' => 'segundo-tempo']]]]));
        $f = $this->api->ingestMatch($complete, null, 'Teste', '2026', $this->run);
        $this->assertSame('completo', $f->data_quality);
        $this->assertSame(65, $this->app('Fulano')->minutes);
        $this->assertSame(25, $this->app('Ciclano')->minutes);

        // 3) correção da fonte: gol era de outro atleta e "Errado" não jogou
        $corrected = $complete;
        $corrected['escalacoes']['mandante']['titulares'] = array_values(array_filter($complete['escalacoes']['mandante']['titulares'], fn ($t) => $t['atleta']['atleta_id'] !== 12));
        $corrected['escalacoes']['mandante']['titulares'][] = ['atleta' => ['atleta_id' => 9099, 'nome_popular' => 'Substituto do Errado']];
        $corrected['gols']['mandante'] = [['atleta' => ['atleta_id' => 11], 'minuto' => '30:00', 'periodo_slug' => 'segundo-tempo']];
        $this->api->ingestMatch($corrected, null, 'Teste', '2026', $this->run);
        SeasonStatsAggregator::rebuildProvider(ApiFutebol::PROVIDER);

        $this->assertSame(1, Fixture::count());
        $this->assertSame(0, $this->app('Fulano')->goals); // evento antigo removido
        $this->assertSame(1, $this->app('Ciclano')->goals);
        $this->assertNull($this->app('Errado')); // saiu da escalação corrigida
        $this->assertSame(1, Appearance::where('player_id', Player::where('name', 'Fulano')->value('id'))->count());
        $this->assertFalse(PlayerSeasonStat::where('player_id', Player::where('name', 'Errado')->value('id'))->exists()); // agregado obsoleto removido
        $this->assertSame(1, Player::where('name', 'Errado')->count()); // ninguém duplicado (Errado continua cadastrado, sem a partida)
        $this->assertSame(1, Player::where('name', 'Fulano')->count());
    }

    public function test_missing_lineup_in_a_later_response_does_not_erase_that_side(): void
    {
        $this->api->ingestMatch($this->payload(), null, 'Teste', '2026', $this->run);
        $glitch = $this->payload();
        $glitch['escalacoes']['visitante'] = null;
        $this->api->ingestMatch($glitch, null, 'Teste', '2026', $this->run);
        $this->assertNotNull($this->app('Beltrano'));

        $empty = $this->payload(['placar_visitante' => 0]);
        $empty['escalacoes']['visitante'] = ['titulares' => [], 'reservas' => []]; // vazia
        $empty['partida_id'] = 77;
        $empty['status'] = 'finalizado ';  // payload diferente para forçar reprocessamento
        $this->api->ingestMatch($empty, null, 'Teste', '2026', $this->run);
        $this->assertNotNull($this->app('Beltrano'));
        $noIds = $this->payload();
        $noIds['escalacoes']['visitante']['titulares'] = [['atleta' => null]]; // atletas sem identificação
        $this->api->ingestMatch($noIds, null, 'Teste', '2026', $this->run);
        $this->assertNotNull($this->app('Beltrano'));
    }

    public function test_identical_payload_only_touches_fetched_at(): void
    {
        $f = $this->api->ingestMatch($this->payload(), null, 'Teste', '2026', $this->run);
        $this->travel(7)->hours();
        $updatedBefore = $f->fresh()->updated_at;
        $this->app('Fulano')->update(['minutes' => 999]); // se reprocessasse, sobrescreveria
        $f2 = $this->api->ingestMatch($this->payload(), null, 'Teste', '2026', $this->run);
        $this->assertSame(999, $this->app('Fulano')->fresh()->minutes);
        $this->assertTrue($f2->fresh()->fetched_at->gt($updatedBefore));
    }

    public function test_selection_refetches_new_partial_and_recent_but_not_old_complete(): void
    {
        $mk = fn ($id, $quality, $playedDaysAgo, $fetchedHoursAgo) => Fixture::create(['provider' => ApiFutebol::PROVIDER, 'external_id' => (string) $id,
            'data_quality' => $quality, 'played_on' => now()->subDays($playedDaysAgo)->toDateString(), 'fetched_at' => now()->subHours($fetchedHoursAgo)]);
        $mk(1, 'completo', 40, 100);  // completa e antiga → não
        $mk(2, 'parcial', 10, 12);    // parcial na janela e vencida → sim
        $mk(3, 'parcial', 10, 1);     // parcial, mas consultada há 1 h → não (TTL)
        $mk(4, 'completo', 1, 8);     // recente → sim
        $mk(5, 'parcial', 45, 100);   // parcial fora da janela de 30 dias → não
        $list = array_map(fn ($i) => ['partida_id' => $i], [1, 2, 3, 4, 5, 6]);
        [$new, $refresh] = ApiFutebol::selectForFetch($list);
        $this->assertSame([6], array_column($new, 'partida_id'));
        $this->assertSame([2, 4], array_column($refresh, 'partida_id'));
    }
}
