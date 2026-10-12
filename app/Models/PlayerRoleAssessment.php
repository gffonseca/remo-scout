<?php

namespace App\Models;

use App\Enums\AssessmentKind;
use App\Enums\Confidence;
use App\Enums\GamePhase;
use App\Enums\Level;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Classificação consolidada do atleta num papel (várias por atleta; guarda a versão do papel). */
class PlayerRoleAssessment extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => AssessmentKind::class, 'level' => Level::class, 'confidence' => Confidence::class, 'phase' => GamePhase::class, 'observed_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->role_version = $a->role_version ?: (TacticalRole::find($a->tactical_role_id)?->version ?? 1);
            $a->observed_on ??= now()->toDateString();
        });
    }

    /** Data de referência da evidência (observação), para validade. */
    public function referenceDate(): \Illuminate\Support\Carbon
    {
        return $this->observed_on ?? $this->updated_at ?? now();
    }

    public function isExpired(): bool
    {
        return $this->referenceDate()->lt(now()->subMonths((int) config('scout.assessment_valid_months', 12)));
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function tacticalRole(): BelongsTo
    {
        return $this->belongsTo(TacticalRole::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
