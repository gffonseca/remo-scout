<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectorBatch;
use App\Models\DataSource;
use App\Models\SyncRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use App\Services\Collector\CbfIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API do coletor local. O coletor só busca páginas públicas e repassa; toda regra (lista branca,
 * vínculo, revisão) fica aqui, versionada e testada.
 */
class CollectorController extends Controller
{
    private const SOURCES = ['cbf_atletas', 'cbf_intermediarios'];

    /** Plano da execução: o que coletar e com que ritmo. */
    public function plan(): JsonResponse
    {
        $season = (string) config('scout.collector_season', now()->year);
        $sources = DataSource::whereIn('key', self::SOURCES)->get()->keyBy('key');
        $on = fn (string $k) => (bool) ($sources[$k]->enabled ?? false);

        return response()->json([
            'season' => $season,
            'interval_ms' => max(2500, (int) ($sources['cbf_atletas']->min_interval_ms ?? 2500)), // CBF responde 429 abaixo disso
            'cbf_atletas' => $on('cbf_atletas') ? [
                'competitions' => collect(config('scout.collector_competitions'))->map(fn ($label, $slug) => ['slug' => $slug, 'label' => $label, 'season' => $season])->values(),
                'max_details' => 150,
            ] : null,
            'cbf_intermediarios' => $on('cbf_intermediarios') ? ['max_pages' => 120] : null,
        ]);
    }

    public function start(Request $request, string $source): JsonResponse
    {
        $ds = $this->source($source);
        // F17 — retomada: execução do mesmo dia que não concluiu e já recebeu lotes continua de onde parou.
        if ($request->boolean('resume')) {
            $prev = SyncRun::where('data_source_id', $ds->id)->where('job', 'coletor_local')
                ->whereIn('status', ['em_execucao', 'parcial', 'falhou'])->where('started_at', '>=', now()->subHours(20))
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('collector_batches')->whereColumn('collector_batches.sync_run_id', 'sync_runs.id'))
                ->latest('started_at')->first();
            if ($prev) {
                $prev->forceFill(['status' => 'em_execucao', 'finished_at' => null]);
                $prev->note('Retomada pelo coletor '.mb_substr((string) $request->input('version'), 0, 20).'.');
                $prev->save();

                return response()->json(['run_id' => $prev->id, 'resumed' => true, 'received' => $this->receivedPages($prev)]);
            }
        }
        SyncRun::closeStale($ds->id, 240); // coleta local leva ~90 min; 4 h sem fim = coletor caiu
        $run = SyncRun::create(['data_source_id' => $ds->id, 'job' => 'coletor_local', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['host' => mb_substr((string) $request->input('host'), 0, 60), 'versao' => mb_substr((string) $request->input('version'), 0, 20)]]);

        return response()->json(['run_id' => $run->id, 'resumed' => false, 'received' => (object) []]);
    }

    /** Páginas de lista já recebidas nesta execução, por competição. @return array<string, list<int>> */
    private function receivedPages(SyncRun $run): array
    {
        return CollectorBatch::where('sync_run_id', $run->id)->where('kind', 'lista')->whereNotNull('page')
            ->orderBy('page')->get(['competition', 'page'])->groupBy('competition')
            ->map(fn ($g) => $g->pluck('page')->unique()->values()->all())->all();
    }

    /**
     * Lote com chave de idempotência (F17): reenvio após resposta perdida devolve o resultado gravado,
     * sem reprocessar nem somar contadores. Sem chave (coletor antigo), processa como antes.
     */
    public function batch(Request $request, string $source): JsonResponse
    {
        $run = $this->run($request, $source);
        $data = $request->validate(['kind' => 'required|in:lista,detalhe,intermediarios', 'items' => 'present|array|max:200', 'competition' => 'nullable|array',
            'batch_key' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'total_pages' => 'nullable|integer|min:1']);
        $process = fn () => match ($data['kind']) {
            'lista' => CbfIngest::athleteList($data['items'], $data['competition'] ?? [], $run),
            'detalhe' => collect($data['items'])->map(fn ($d) => is_array($d) ? CbfIngest::athleteDetail($d, $run) : ['status' => 'invalido'])->countBy('status')->all(),
            'intermediarios' => CbfIngest::intermediaries($data['items'], $run),
        };
        if (empty($data['batch_key'])) {
            return response()->json($process());
        }
        $seen = fn () => CollectorBatch::where('sync_run_id', $run->id)->where('batch_key', $data['batch_key'])->first();
        if ($b = $seen()) {
            return response()->json(($b->result ?? []) + ['duplicado' => true]);
        }
        try {
            $result = DB::transaction(function () use ($run, $data, $process) {
                $b = CollectorBatch::create(['sync_run_id' => $run->id, 'batch_key' => $data['batch_key'], 'kind' => $data['kind'],
                    'competition' => $data['competition']['slug'] ?? null, 'page' => $data['page'] ?? null, 'total_pages' => $data['total_pages'] ?? null,
                    'items' => count($data['items'])]);
                $result = $process();
                $b->update(['result' => $result]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(($seen()?->result ?? []) + ['duplicado' => true]); // dois envios simultâneos do mesmo lote
        }

        return response()->json($result);
    }

    public function pending(Request $request): JsonResponse
    {
        return response()->json(['ids' => CbfIngest::pendingDetails((int) $request->integer('limit', 150))]);
    }

    public function finish(Request $request, string $source): JsonResponse
    {
        $run = $this->run($request, $source);
        $in = $request->validate(['requests_made' => 'nullable|integer|min:0', 'errors' => 'nullable|integer|min:0', 'messages' => 'nullable|array|max:50', 'messages.*' => 'string|max:300',
            'manifest' => 'nullable|array|max:40', 'manifest.*.total_pages' => 'nullable|integer|min:0', 'manifest.*.limit' => 'nullable|integer|min:1',
            'manifest.*.found' => 'nullable|boolean', 'manifest.*.label' => 'nullable|string|max:120']);
        // contadores separados: coleta (chamadas à CBF, somadas entre retomadas) × ingestão (lotes únicos recebidos)
        $run->requests_made += (int) ($in['requests_made'] ?? 0);
        $run->errors = (int) ($in['errors'] ?? 0);
        foreach ($in['messages'] ?? [] as $m) {
            $run->note($m);
        }
        if ($source === 'cbf_intermediarios') {
            $run->note('Empresários vinculados ao registro CBF: '.CbfIngest::linkAgents());
        }
        $batches = CollectorBatch::where('sync_run_id', $run->id);
        $scope = $run->scope ?? [];
        $scope['ingestao'] = ['lotes' => (clone $batches)->count(), 'itens' => (int) (clone $batches)->sum('items')];
        $missing = 0;
        if (isset($in['manifest'])) {
            $received = $this->receivedPages($run);
            $planned = $source === 'cbf_atletas' ? array_keys(config('scout.collector_competitions')) : [];
            $manifest = [];
            foreach (array_unique(array_merge($planned, array_keys($in['manifest']))) as $slug) {
                $m = $in['manifest'][$slug] ?? null;
                $label = $m['label'] ?? (config('scout.collector_competitions')[$slug] ?? $slug);
                if (! $m || ! ($m['found'] ?? true)) {
                    $manifest[$slug] = ['competicao' => $label, 'situacao' => $m ? 'nao_encontrada' : 'nao_coletada'];
                    $missing++;
                    $run->note("{$label}: ".($m ? 'competição não encontrada na CBF' : 'não coletada nesta execução').'.');

                    continue;
                }
                $expected = min((int) ($m['total_pages'] ?? 0), (int) ($m['limit'] ?? PHP_INT_MAX));
                $got = $received[$slug] ?? [];
                $lack = array_values(array_diff(range(1, max(1, $expected)), $got));
                $lack = $expected === 0 ? [] : $lack;
                $manifest[$slug] = ['competicao' => $label, 'esperadas' => $expected, 'recebidas' => count(array_intersect($got, range(1, max(1, $expected)))), 'faltando' => $lack];
                if ($lack) {
                    $missing++;
                    $run->note("{$label}: ".count($lack)." de {$expected} páginas faltando (".implode(', ', array_slice($lack, 0, 15)).(count($lack) > 15 ? '…' : '').').');
                }
            }
            $scope['manifesto'] = $manifest;
        }
        $run->scope = $scope;
        $run->note("Coleta: {$run->requests_made} chamadas à CBF · ingestão: {$scope['ingestao']['lotes']} lotes únicos, {$scope['ingestao']['itens']} itens.");
        if ($missing > 0 && $run->received > 0) {
            $run->finish('parcial'); // conclusão parcial explícita: há páginas/competições faltando
        } else {
            $run->finish($missing > 0 ? 'falhou' : null);
        }
        $ok = in_array($run->status, ['concluido', 'parcial'], true);
        $run->source->update($ok
            ? ['health' => $run->status === 'concluido' ? 'ok' : 'degradado', 'last_success_at' => now()]
            : ['health' => 'degradado', 'last_error_at' => now(), 'last_error' => 'Coletor local: execução sem dados recebidos']);

        return response()->json(['status' => $run->status, 'received' => $run->received, 'created' => $run->created, 'updated' => $run->updated]);
    }

    private function source(string $key): DataSource
    {
        abort_unless(in_array($key, self::SOURCES, true), 404);
        $ds = DataSource::byKey($key);
        abort_unless($ds->enabled, 423, 'Fonte desligada no CIFUT Remo (kill switch).');

        return $ds;
    }

    private function run(Request $request, string $source): SyncRun
    {
        $ds = $this->source($source);
        $run = SyncRun::where('id', (string) $request->input('run_id'))->where('data_source_id', $ds->id)->where('status', 'em_execucao')->first();
        abort_unless($run, 409, 'Execução inexistente ou encerrada.');

        return $run;
    }
}
