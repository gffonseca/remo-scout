<?php

namespace App\Models;

use App\Enums\DataOrigin;
use App\Enums\Confirmation;
use App\Enums\Foot;
use App\Enums\MarketStatus;
use App\Enums\Position;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Player extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    /** Defaults espelhando o banco (necessários antes do primeiro refresh). */
    protected $attributes = [
        'preferred_foot' => 'nao_informado',
        'data_origin' => 'manual',
        'market_status' => 'desconhecido',
        'salary_currency' => 'BRL',
        'salary_periodicity' => 'mensal',
        'salary_confirmation' => 'nao_verificado',
        'asking_price_currency' => 'BRL',
        'is_priority' => false,
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'positions' => 'array',
            'birth_date' => 'date',
            'preferred_foot' => Foot::class,
            'primary_position' => Position::class,
            'data_origin' => DataOrigin::class,
            'languages' => 'array',
            'eu_passport' => 'boolean',
            'is_priority' => 'boolean',
            'market_status' => MarketStatus::class,
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'salary_confirmation' => Confirmation::class,
            'salary_observed_on' => 'date',
            'asking_price' => 'decimal:2',
        ];
    }

    /** Idade calculada na data de hoje; null quando nascimento não informado (≠ zero). */
    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birth_date?->age);
    }

    public function currentClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'current_club_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function rightsHolderClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'rights_holder_club_id');
    }

    /** "não informada" quando sem valores — nunca zero. */
    public function salaryRangeLabel(): string
    {
        if ($this->salary_min === null && $this->salary_max === null) {
            return 'não informada';
        }
        $f = fn ($v) => number_format((float) $v, 0, ',', '.');
        $range = $this->salary_min !== null && $this->salary_max !== null && (float) $this->salary_min !== (float) $this->salary_max
            ? $f($this->salary_min).' – '.$f($this->salary_max)
            : $f($this->salary_min ?? $this->salary_max);

        return $this->salary_currency.' '.$range.' ('.$this->salary_periodicity.', '.mb_strtolower($this->salary_confirmation->getLabel()).')';
    }

    protected static function booted(): void
    {
        // Mantém histórico: cada mudança na faixa salarial ou na pedida gera observação de mercado datada.
        static::saved(function (self $p) {
            if ($p->wasChanged(['salary_min', 'salary_max']) || ($p->wasRecentlyCreated && ($p->salary_min !== null || $p->salary_max !== null))) {
                MarketObservation::create([
                    'player_id' => $p->id, 'kind' => 'salario', 'amount' => $p->salary_min ?? $p->salary_max, 'amount_max' => $p->salary_max,
                    'currency' => $p->salary_currency, 'periodicity' => $p->salary_periodicity,
                    'confirmation' => $p->salary_confirmation?->value ?? 'nao_verificado', 'source_label' => 'Faixa salarial no cadastro do atleta',
                    'observed_on' => $p->salary_observed_on ?? now()->toDateString(), 'author_id' => auth()->id(),
                ]);
            }
            if ($p->asking_price !== null && ($p->wasChanged('asking_price') || $p->wasRecentlyCreated)) {
                MarketObservation::create([
                    'player_id' => $p->id, 'kind' => 'pedida_transferencia', 'amount' => $p->asking_price, 'currency' => $p->asking_price_currency,
                    'periodicity' => 'unico', 'confirmation' => 'nao_verificado', 'source_label' => 'Pedida no cadastro do atleta',
                    'observed_on' => now()->toDateString(), 'author_id' => auth()->id(),
                ]);
            }
        });
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(PlayerExternalId::class);
    }

    public function rosterSpells(): HasMany
    {
        return $this->hasMany(RosterSpell::class)->orderByDesc('started_on');
    }

    public function contractObservations(): HasMany
    {
        return $this->hasMany(ContractObservation::class)->orderByDesc('observed_on');
    }

    public function latestContract(): HasOne
    {
        return $this->hasOne(ContractObservation::class)->latestOfMany('observed_on');
    }

    public function marketObservations(): HasMany
    {
        return $this->hasMany(MarketObservation::class)->orderByDesc('observed_on');
    }

    public function scoutingReports(): HasMany
    {
        return $this->hasMany(ScoutingReport::class)->latest();
    }

    /** Relatórios submetidos (aplique ->visibleTo() no eager load para respeitar a avaliação independente). */
    public function submittedReports(): HasMany
    {
        return $this->hasMany(ScoutingReport::class)->where('status', 'submetido')->latest('submitted_at');
    }

    public function videoReferences(): HasMany
    {
        return $this->hasMany(VideoReference::class)->latest();
    }

    public function roleAssessments(): HasMany
    {
        return $this->hasMany(PlayerRoleAssessment::class);
    }

    public function specialtyAssessments(): HasMany
    {
        return $this->hasMany(PlayerSpecialtyAssessment::class);
    }

    public function prospectCases(): HasMany
    {
        return $this->hasMany(ProspectCase::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function scopeWithPosition(Builder $q, string $code): Builder
    {
        return $q->where(fn ($w) => $w->where('primary_position', $code)->orWhereJsonContains('positions', $code));
    }

    /**
     * Possíveis duplicatas: mesmo ID CBF, ou mesmo nome normalizado + mesma data de nascimento,
     * ou mesmo nome sem nascimento informado (sinaliza para revisão; nunca une automaticamente).
     */
    public static function possibleDuplicates(string $name, ?string $birthDate = null, ?string $cbfId = null, ?string $ignoreId = null)
    {
        return static::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($name, $birthDate, $cbfId) {
                if ($cbfId) {
                    $q->orWhere('cbf_id', $cbfId);
                }
                $q->orWhere(function ($w) use ($name, $birthDate) {
                    $w->whereRaw('lower(unaccent_name(name)) = lower(unaccent_name(?))', [$name]);
                    if ($birthDate) {
                        $w->where(fn ($b) => $b->whereDate('birth_date', $birthDate)->orWhereNull('birth_date'));
                    }
                });
            })
            ->limit(10)
            ->get();
    }

    public function displayLabel(): string
    {
        $bits = array_filter([
            $this->primary_position?->value,
            $this->age !== null ? $this->age.' anos' : null,
            $this->currentClub?->name,
        ]);

        return $this->name.($bits ? ' ('.implode(' · ', $bits).')' : '');
    }
}
