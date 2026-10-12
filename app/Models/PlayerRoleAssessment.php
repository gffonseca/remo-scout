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
use Illuminate\Support\Carbon;

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
    public function referenceDate(): Carbon
    {
        return $this->observed_on ?? $this->updated_at ?? now();
    }

    public function isExpired(): bool
    {
        return $this->referenceDate()->lt(now()->subMonths((int) config('scout.assessment_valid_months', 12)));
    }

    /**
     * Avaliações OBSERVADAS vigentes: dentro da validade e a mais recente de cada atleta+papel+fase
     * (uma reavaliação substitui a anterior, mesmo que a anterior fosse mais alta).
     */
    public function scopeCurrentObserved($query)
    {
        $cutoff = now()->subMonths((int) config('scout.assessment_valid_months', 12))->toDateString();

        return $query->where('player_role_assessments.kind', 'observado')
            ->whereRaw('coalesce(player_role_assessments.observed_on, player_role_assessments.created_at::date) >= ?', [$cutoff])
            ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('player_role_assessments as newer')
                ->whereColumn('newer.player_id', 'player_role_assessments.player_id')
                ->whereColumn('newer.tactical_role_id', 'player_role_assessments.tactical_role_id')
                ->whereRaw('newer.phase is not distinct from player_role_assessments.phase')
                ->where('newer.kind', 'observado')
                ->whereRaw('(coalesce(newer.observed_on, newer.created_at::date), newer.created_at, newer.id) > (coalesce(player_role_assessments.observed_on, player_role_assessments.created_at::date), player_role_assessments.created_at, player_role_assessments.id)'));
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
