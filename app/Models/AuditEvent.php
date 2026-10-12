<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?Model $model = null, ?array $changes = null): void
    {
        static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model ? class_basename($model) : null,
            'auditable_id' => $model?->getKey() !== null ? (string) $model->getKey() : null,
            'changes' => $changes,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
