<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\SyncRun;
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
        SyncRun::closeStale($ds->id, 240); // coleta local leva ~90 min; 4 h sem fim = coletor caiu
        $run = SyncRun::create(['data_source_id' => $ds->id, 'job' => 'coletor_local', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['host' => mb_substr((string) $request->input('host'), 0, 60), 'versao' => mb_substr((string) $request->input('version'), 0, 20)]]);

        return response()->json(['run_id' => $run->id]);
    }

    public function batch(Request $request, string $source): JsonResponse
    {
        $run = $this->run($request, $source);
        $data = $request->validate(['kind' => 'required|in:lista,detalhe,intermediarios', 'items' => 'required|array|max:200', 'competition' => 'nullable|array']);
        $result = match ($data['kind']) {
            'lista' => CbfIngest::athleteList($data['items'], $data['competition'] ?? [], $run),
            'detalhe' => collect($data['items'])->map(fn ($d) => is_array($d) ? CbfIngest::athleteDetail($d, $run) : ['status' => 'invalido'])->countBy('status')->all(),
            'intermediarios' => CbfIngest::intermediaries($data['items'], $run),
        };

        return response()->json($result);
    }

    public function pending(Request $request): JsonResponse
    {
        return response()->json(['ids' => CbfIngest::pendingDetails((int) $request->integer('limit', 150))]);
    }

    public function finish(Request $request, string $source): JsonResponse
    {
        $run = $this->run($request, $source);
        $in = $request->validate(['requests_made' => 'nullable|integer|min:0', 'errors' => 'nullable|integer|min:0', 'messages' => 'nullable|array|max:50', 'messages.*' => 'string|max:300']);
        $run->requests_made = (int) ($in['requests_made'] ?? 0);
        $run->errors = (int) ($in['errors'] ?? 0);
        foreach ($in['messages'] ?? [] as $m) {
            $run->note($m);
        }
        if ($source === 'cbf_intermediarios') {
            $run->note('Empresários vinculados ao registro CBF: '.CbfIngest::linkAgents());
        }
        $run->finish();
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
