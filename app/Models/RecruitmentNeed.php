<?php

namespace App\Models;

use App\Enums\Foot;
use App\Enums\Horizon;
use App\Enums\Level;
use App\Enums\NeedStatus;
use App\Enums\Position;
use App\Enums\Priority;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentNeed extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => Position::class,
            'preferred_foot' => Foot::class,
            'target_level' => Level::class,
            'mandatory_requirements' => 'array',
            'desirable_requirements' => 'array',
            'horizon' => Horizon::class,
            'priority' => Priority::class,
            'status' => NeedStatus::class,
            'budget_transfer_max' => 'decimal:2',
            'budget_salary_max' => 'decimal:2',
        ];
    }

    public function tacticalRole(): BelongsTo
    {
        return $this->belongsTo(TacticalRole::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class)->withPivot('is_mandatory');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ProspectCase::class);
    }

    public function shortlist(): HasMany
    {
        return $this->cases()
            ->whereHas('stage', fn ($q) => $q->where('is_shortlist', true))
            ->orderByRaw('shortlist_rank is null, shortlist_rank');
    }

    /** "não informado" quando nenhum teto foi definido — nunca "viável". */
    public function budgetLabel(): string
    {
        if ($this->budget_transfer_max === null && $this->budget_salary_max === null) {
            return 'Orçamento não informado';
        }
        $fmt = fn ($v) => $v === null ? 'não informado' : $this->budget_currency.' '.number_format((float) $v, 2, ',', '.');

        return 'Transferência até '.$fmt($this->budget_transfer_max).' · Salário até '.$fmt($this->budget_salary_max);
    }
}
