<?php

namespace Tests\Feature;

use App\Models\CbfAthlete;
use App\Models\DataSource;
use App\Models\SyncRun;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\Storage;

/** F17 — idempotência por lote, manifesto de páginas, retomada, conclusão parcial explícita e "atrasada". */
class CollectorManifestTest extends ScoutTestCase
{
    private const TOKEN = 'teste-token-coletor-0123456789abcdef0123456789';

    private const SLUG = 'campeonato-brasileiro/serie-d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        config(['scout.collector_token' => self::TOKEN, 'scout.collector_competitions' => [self::SLUG => 'Série D']]);
        Storage::fake('media');
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->withToken(self::TOKEN)->json($method, '/api/coletor/'.$path, $data);
    }

    private function page(string $run, int $page, int $total = 3, array $ids = [])
    {
        $ids = $ids ?: [(string) (1000 + $page * 10), (string) (1001 + $page * 10)];

        return $this->api('POST', 'cbf_atletas/lote', ['run_id' => $run, 'kind' => 'lista', 'batch_key' => 'lista:'.self::SLUG.':'.$page,
            'page' => $page, 'total_pages' => $total,
            'competition' => ['slug' => self::SLUG, 'label' => 'Série D', 'season' => '2026'],
            'items' => array_map(fn ($id) => ['atleta_id' => $id, 'atleta_nome' => "Atleta {$id}", 'atleta_apelido' => "A{$id}", 'clube_nome_popular' => 'Clube X'], $ids)]);
    }

    private function manifest(int $total = 3): array
    {
        return [self::SLUG => ['label' => 'Série D', 'found' => true, 'total_pages' => $total, 'limit' => 1000]];
    }

    public function test_resent_batch_is_not_reprocessed_and_counters_do_not_double(): void
    {
        $run = $this->api('POST', 'cbf_atletas/inicio', ['host' => 'mini', 'resume' => true])->json('run_id');
        $this->page($run, 1)->assertOk()->assertJsonMissingPath('duplicado');
        $received = SyncRun::find($run)->received;
        $this->page($run, 1)->assertOk()->assertJsonPath('duplicado', true); // resposta perdida → reenvio
        $this->assertSame($received, SyncRun::find($run)->received);
        $this->assertSame(2, CbfAthlete::count());
    }

    public function test_missing_pages_make_partial_explicit_with_list(): void
    {
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        $this->page($run, 1);
        $this->page($run, 3);
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'requests_made' => 5, 'manifest' => $this->manifest()])
            ->assertOk()->assertJsonPath('status', 'parcial');
        $r = SyncRun::find($run);
        $this->assertSame([2], $r->scope['manifesto'][self::SLUG]['faltando']);
        $this->assertSame(2, $r->scope['manifesto'][self::SLUG]['recebidas']);
        $this->assertEquals(['lotes' => 2, 'itens' => 4], $r->scope['ingestao']);
        $this->assertStringContainsString('1 de 3 páginas faltando (2)', implode(' ', $r->messages));
        $this->assertSame('degradado', DataSource::byKey('cbf_atletas')->health);
    }

    public function test_interrupted_run_is_resumed_without_losing_or_duplicating(): void
    {
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        $this->page($run, 1);
        // outro coletor logo em seguida não entra numa execução que ainda está rodando
        $this->assertNotSame($run, $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id'));
        SyncRun::where('id', '!=', $run)->delete();
        // coletor cai aqui (sem /fim). Parada há mais de 10 min, no mesmo dia: retoma a mesma execução
        $this->travel(11)->minutes();
        $again = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true, 'version' => '1.1.0'])->assertJsonPath('resumed', true);
        $this->assertSame($run, $again->json('run_id'));
        $this->assertSame([1], $again->json()['received'][self::SLUG]);
        $this->page($run, 1)->assertJsonPath('duplicado', true); // se reenviar mesmo assim, não duplica
        $this->page($run, 2);
        $this->page($run, 3);
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'requests_made' => 3, 'manifest' => $this->manifest()])->assertJsonPath('status', 'concluido');
        $this->assertSame(6, CbfAthlete::count());
        $this->assertSame(1, SyncRun::where('job', 'coletor_local')->count());
    }

    public function test_run_finished_as_partial_is_never_resumed(): void
    {
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        $this->page($run, 1);
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'manifest' => $this->manifest()])->assertJsonPath('status', 'parcial');
        $this->travel(2)->hours();
        $this->assertNotSame($run, $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id')); // relê a CBF inteira
    }

    public function test_finished_run_is_not_resumed_and_old_run_is_not_resumed(): void
    {
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        foreach ([1, 2, 3] as $p) {
            $this->page($run, $p);
        }
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'manifest' => $this->manifest()])->assertJsonPath('status', 'concluido');
        $this->assertNotSame($run, $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id'));

        $old = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        $this->page($old, 1);
        SyncRun::whereKey($old)->update(['started_at' => now()->subDay()]);
        $this->assertNotSame($old, $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id'));
    }

    public function test_competition_not_found_or_not_collected_is_reported(): void
    {
        config(['scout.collector_competitions' => [self::SLUG => 'Série D', 'copa-verde/masculino' => 'Copa Verde', 'copa-do-brasil/masculino' => 'Copa do Brasil']]);
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        foreach ([1, 2, 3] as $p) {
            $this->page($run, $p);
        }
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'manifest' => $this->manifest() + ['copa-verde/masculino' => ['label' => 'Copa Verde', 'found' => false]]])
            ->assertJsonPath('status', 'parcial'); // parcial pela Copa do Brasil não coletada
        $m = SyncRun::find($run)->scope['manifesto'];
        $this->assertSame('nao_encontrada', $m['copa-verde/masculino']['situacao']);
        $this->assertSame('nao_coletada', $m['copa-do-brasil/masculino']['situacao']);
    }

    public function test_competition_without_edition_is_a_warning_not_a_partial_run(): void
    {
        config(['scout.collector_competitions' => [self::SLUG => 'Série D', 'copa-verde/masculino' => 'Copa Verde']]);
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        foreach ([1, 2, 3] as $p) {
            $this->page($run, $p);
        }
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'manifest' => $this->manifest() + ['copa-verde/masculino' => ['label' => 'Copa Verde', 'found' => false]]])
            ->assertJsonPath('status', 'concluido');
        $this->assertStringContainsString('não encontrada na CBF (aviso)', implode(' ', SyncRun::find($run)->messages));
    }

    public function test_empty_page_counts_as_received(): void
    {
        $run = $this->api('POST', 'cbf_atletas/inicio', ['resume' => true])->json('run_id');
        $this->page($run, 1, 1, ['5']);
        $this->api('POST', 'cbf_atletas/lote', ['run_id' => $run, 'kind' => 'lista', 'batch_key' => 'x', 'page' => 2, 'total_pages' => 2,
            'competition' => ['slug' => self::SLUG], 'items' => []])->assertOk();
        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'manifest' => $this->manifest(2)])->assertJsonPath('status', 'concluido');
    }

    public function test_mac_offline_turns_collector_sources_late_and_unhealthy(): void
    {
        $s = DataSource::byKey('cbf_atletas');
        $s->update(['health' => 'ok']);
        SyncRun::create(['data_source_id' => $s->id, 'job' => 'coletor_local', 'status' => 'concluido', 'finished_at' => now()->subHours(50)]);
        $this->artisan('scout:probe-sources', ['--only' => 'cbf_atletas'])->expectsOutputToContain('ATRASADA')->assertSuccessful();
        $s->refresh();
        $this->assertSame('atrasado', $s->health);
        $this->assertStringContainsString('Mac Mini', $s->last_error);

        SyncRun::create(['data_source_id' => $s->id, 'job' => 'coletor_local', 'status' => 'parcial', 'finished_at' => now()->subHours(2)]);
        $this->assertSame('em_dia', $s->fresh()->freshness()); // parcial recente também é dado atualizado
    }
}
