<?php

namespace App\Models;

use App\Enums\AssessmentKind;
use App\Enums\Confidence;
use App\Enums\Level;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\Verdict;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoutingReport extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'assessment_type' => AssessmentKind::class,
            'criteria_levels' => 'array',
            'source_type' => ReportSource::class,
            'match_date' => 'date',
            'confidence' => Confidence::class,
            'verdict' => Verdict::class,
            'status' => ReportStatus::class,
            'submitted_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(RecruitmentNeed::class, 'recruitment_need_id');
    }

    public function tacticalRole(): BelongsTo
    {
        return $this->belongsTo(TacticalRole::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(VideoReference::class);
    }

    /**
     * Avaliação independente: analista vê os próprios relatórios e, de outros autores, apenas
     * os submetidos de atletas para os quais ele já submeteu relatório. Coordenador/admin/leitor
     * veem todos os submetidos (leitor nunca vê rascunhos de terceiros).
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->role->isCoordinatorOrAbove()) {
            return $q->where(fn ($w) => $w->where('status', ReportStatus::Submetido)->orWhere('author_id', $user->id));
        }
        if ($user->role === UserRole::Leitor) {
            return $q->where('status', ReportStatus::Submetido);
        }

        return $q->where(function ($w) use ($user) {
            $w->where('author_id', $user->id)
                ->orWhere(function ($o) use ($user) {
                    $o->where('status', ReportStatus::Submetido)
                        ->whereIn('player_id', static::query()->select('player_id')
                            ->where('author_id', $user->id)->where('status', ReportStatus::Submetido));
                });
        });
    }

    public function submit(): void
    {
        $this->status = ReportStatus::Submetido;
        $this->submitted_at = now();
        $this->save();
    }
}
