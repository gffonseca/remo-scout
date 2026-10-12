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

    public function finish(?string $status = null): void
    {
        $this->status = $status ?? ($this->errors > 0 ? ($this->created + $this->updated > 0 ? 'parcial' : 'falhou') : 'concluido');
        $this->finished_at = now();
        $this->save();
    }
}
