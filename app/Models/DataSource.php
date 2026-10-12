<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'requires_credential' => 'boolean', 'quota_date' => 'date',
            'last_success_at' => 'datetime', 'last_error_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class)->latest();
    }

    public static function byKey(string $key): self
    {
        return static::where('key', $key)->firstOrFail();
    }

    public function credential(): ?string
    {
        return $this->credential_env ? (config('scout.credentials.'.$this->credential_env) ?: null) : null;
    }

    public function isUnofficial(): bool
    {
        return $this->kind === 'nao_oficial';
    }

    public function kindLabel(): string
    {
        return [
            'api_comercial' => 'API comercial', 'nao_oficial' => 'Fonte não oficial', 'documento_publico' => 'Documento público',
            'oficial_credenciado' => 'Oficial (credencial)',
        ][$this->kind] ?? $this->kind;
    }

    public function remainingQuota(): ?int
    {
        if ($this->daily_quota === null) {
            return null;
        }
        $used = $this->quota_date?->isToday() ? $this->used_today : 0;

        return max(0, $this->daily_quota - $used);
    }
}
