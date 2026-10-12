<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectorBatch;
use App\Models\CollectorTask;
use App\Models\DataSource;
use App\Models\SyncRun;
use App\Services\Collector\CbfIngest;
use App\Services\Collector\SofascoreTasks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        if ($request->boolean('resume') && ($prev = $this->interrupted($ds))) {
            $prev->forceFill(['status' => 'em_execucao', 'finished_at' => null]);
            $prev->note('Retomada pelo coletor '.mb_substr((string) $request->input('version'), 0, 20).'.');
            $prev->save();

            return response()->json(['run_id' => $prev->id, 'resumed' => true, 'received' => $this->receivedPages($prev)]);
        }
        SyncRun::closeStale($ds->id, 240); // coleta local leva ~90 min; 4 h sem fim = coletor caiu
        $run = SyncRun::create(['data_source_id' => $ds->id, 'job' => 'coletor_local', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['host' => mb_substr((string) $request->input('host'), 0, 60), 'versao' => mb_substr((string) $request->input('version'), 0, 20)]]);

        return response()->json(['run_id' => $run->id, 'resumed' => false, 'received' => (object) []]);
    }

    /**
     * Execução interrompida que pode ser retomada (F17): do mesmo dia (fuso de Brasília), nunca encerrada pelo /fim do coletor,
     * com lotes recebidos e parada há mais de 10 min (não entra numa execução que ainda está rodando).
     */
    private function interrupted(DataSource $ds): ?SyncRun
    {
        $tz = config('scout.display_timezone');
        $run = SyncRun::where('data_source_id', $ds->id)->where('job', 'coletor_local')->whereIn('status', ['em_execucao', 'falhou'])
            ->where('started_at', '>=', now($tz)->startOfDay()->utc())->latest('started_at')->first();
        if (! $run || ($run->scope['encerrada_pelo_coletor'] ?? false)) {
            return null;
        }
        $last = CollectorBatch::where('sync_run_id', $run->id)->max('created_at');

        return $last && Carbon::parse($last)->lt(now()->subMinutes(10)) ? $run : null;
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
        } catch (UniqueConstraintViolationException $e) {
            if ($b = $seen()) { // dois envios simultâneos do mesmo lote
                return response()->json(($b->result ?? []) + ['duplicado' => true]);
            }
            throw $e; // violação vinda do processamento: erro real, não duplicidade
        }

        return response()->json($result);
    }

    /** Tarefas sob demanda (Sofascore) com os endereços que o servidor precisa que o Mac busque. */
    public function tasks(): JsonResponse
    {
        if (! DataSource::where('key', SofascoreTasks::SOURCE)->value('enabled')) {
            return response()->json(['tarefas' => [], 'interval_ms' => 2500]);
        }
        $tasks = CollectorTask::where('source', SofascoreTasks::SOURCE)->where('status', 'aguardando_coletor')->orderBy('updated_at')->limit(20)->get()
            ->map(fn ($t) => ['id' => $t->id, 'source' => $t->source, 'requests' => static::requestsOf($t)]);

        return response()->json(['tarefas' => $tasks, 'interval_ms' => max(2500, (int) DataSource::where('key', SofascoreTasks::SOURCE)->value('min_interval_ms'))]);
    }

    public function taskResult(Request $request, string $task): JsonResponse
    {
        abort_unless(DataSource::where('key', SofascoreTasks::SOURCE)->value('enabled'), 423, 'Fonte desligada no CIFUT Remo (kill switch).');
        $t = CollectorTask::findOrFail($task);
        $in = $request->validate(['responses' => 'required|array|max:10', 'responses.*.url' => 'required|string|max:500', 'responses.*.query' => 'nullable|array',
            'responses.*.status' => 'required|integer|min:0|max:599', 'responses.*.body' => 'nullable|string|max:3000000']);
        $responses = array_values(array_filter($in['responses'], fn ($r) => SofascoreTasks::allowed($r['url'])));
        $t = SofascoreTasks::receive($t, $responses);

        return response()->json(['status' => $t->status, 'requests' => static::requestsOf($t), 'result' => $t->result]);
    }

    private static function requestsOf(CollectorTask $t): array
    {
        return $t->status === 'aguardando_coletor'
            ? collect($t->pending_paths ?? [])->filter(fn ($r) => SofascoreTasks::allowed($r['url']))->values()->all()
            : [];
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
        $scope['encerrada_pelo_coletor'] = true;
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
                    // não encontrada na CBF (ex.: sem edição nesta temporada) é aviso; não coletada é falta
                    $manifest[$slug] = ['competicao' => $label, 'situacao' => $m ? 'nao_encontrada' : 'nao_coletada'];
                    $missing += $m ? 0 : 1;
                    $run->note("{$label}: ".($m ? 'competição não encontrada na CBF (aviso)' : 'não coletada nesta execução').'.');

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
            if ($manifest && collect($manifest)->every(fn ($m) => ($m['situacao'] ?? null) === 'nao_encontrada')) {
                $missing++; // nenhuma competição encontrada: a página da CBF provavelmente mudou
                $run->note('Nenhuma competição encontrada na CBF: verificar se a página mudou.');
            }
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
