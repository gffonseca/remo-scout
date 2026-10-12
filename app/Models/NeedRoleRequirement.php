<?php

namespace App\Models;

use App\Enums\GamePhase;
use App\Enums\Level;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nível mínimo observado exigido num papel, numa fase (null = geral). */
class NeedRoleRequirement extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['phase' => GamePhase::class, 'min_level' => Level::class, 'is_mandatory' => 'boolean'];
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(RecruitmentNeed::class, 'recruitment_need_id');
    }

    public function tacticalRole(): BelongsTo
    {
        return $this->belongsTo(TacticalRole::class);
    }

    public function label(): string
    {
        return ($this->tacticalRole?->name ?? 'Papel').' · '.GamePhase::label($this->phase).' ≥ '.$this->min_level->value;
    }
}
