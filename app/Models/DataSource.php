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
            'last_success_at' => 'datetime', 'last_error_at' => 'datetime', 'circuit_open_until' => 'datetime',
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

    /** Cabeçalho de autenticação conforme o provedor (valor só em memória; nunca em log). */
    public function authHeaders(): array
    {
        $c = $this->credential();
        if (! $c) {
            return [];
        }

        return match ($this->key) {
            'api_futebol', 'dados_futebol' => ['Authorization' => 'Bearer '.$c],
            'sportmonks' => ['Authorization' => $c],
            default => [],
        };
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

    /** Dia/mês de cota no fuso de exibição (as APIs brasileiras zeram à meia-noite de Brasília). */
    public static function quotaDay(): string
    {
        return now(config('scout.display_timezone'))->toDateString();
    }

    public static function quotaMonth(): string
    {
        return now(config('scout.display_timezone'))->format('Y-m');
    }

    public function usedToday(): int
    {
        return $this->quota_date?->toDateString() === static::quotaDay() ? (int) $this->used_today : 0;
    }

    public function usedThisMonth(): int
    {
        return $this->quota_month === static::quotaMonth() ? (int) $this->used_month : 0;
    }

    /** Menor saldo entre a cota diária e a mensal; null = sem limite conhecido. */
    public function remainingQuota(): ?int
    {
        $left = [];
        if ($this->daily_quota !== null) {
            $left[] = max(0, $this->daily_quota - $this->usedToday());
        }
        if ($this->monthly_quota !== null) {
            $left[] = max(0, $this->monthly_quota - $this->usedThisMonth());
        }

        return $left ? min($left) : null;
    }

    public function circuitOpen(): bool
    {
        return $this->circuit_open_until !== null && $this->circuit_open_until->isFuture();
    }

    /** Última ingestão concluída (o teste diário de saúde não conta como dado atualizado). */
    public function lastIngestedAt(): ?\Carbon\CarbonInterface
    {
        $at = $this->runs()->reorder()->where('status', 'concluido')->max('finished_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /**
     * Diagnóstico de atualização: em_dia | atrasado | nunca | sem_expectativa.
     * Mede a última ingestão concluída contra a expectativa da fonte (ex.: coletor do Mac desligado → atrasado).
     */
    public function freshness(): string
    {
        if (! $this->expected_refresh_hours) {
            return 'sem_expectativa';
        }
        $at = $this->lastIngestedAt();
        if (! $at) {
            return 'nunca';
        }

        return $at->lt(now()->subHours($this->expected_refresh_hours)) ? 'atrasado' : 'em_dia';
    }

    public function freshnessLabel(): string
    {
        return ['em_dia' => 'Em dia', 'atrasado' => 'Atrasada', 'nunca' => 'Nunca atualizada', 'sem_expectativa' => 'Sob demanda'][$this->freshness()];
    }
}
