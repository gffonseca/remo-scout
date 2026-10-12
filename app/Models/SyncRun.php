<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Execução de sincronização. HTTP 200 ou job despachado não significa "concluído". */
class SyncRun extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['requests_made' => 0, 'received' => 0, 'created' => 0, 'updated' => 0, 'discarded' => 0, 'errors' => 0, 'status' => 'solicitado'];

    protected function casts(): array
    {
        return ['scope' => 'array', 'messages' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function note(string $msg): void
    {
        $m = $this->messages ?? [];
        if (count($m) < 200) {
            $m[] = now()->format('H:i:s').' '.$msg;
        }
        $this->messages = $m;
    }

    /**
     * Fecha execuções da fonte que ficaram "em execução" além do razoável (worker morto, timeout, deploy no meio).
     * Chamado no início de cada nova execução da mesma fonte. Nunca apaga: marca "falhou" com a explicação.
     */
    public static function closeStale(string $dataSourceId, int $olderThanMinutes): int
    {
        $n = 0;
        foreach (static::where('data_source_id', $dataSourceId)->where('status', 'em_execucao')
            ->where('started_at', '<', now()->subMinutes($olderThanMinutes))->get() as $run) {
            $run->note("Interrompida: sem conclusão após {$olderThanMinutes} min (worker reiniciado, timeout ou deploy).");
            $run->finish('falhou');
            $n++;
        }

        return $n;
    }

    public function finish(?string $status = null): void
    {
        $this->status = $status ?? ($this->errors > 0 ? ($this->created + $this->updated > 0 ? 'parcial' : 'falhou') : 'concluido');
        $this->finished_at = now();
        $this->save();
    }
}
