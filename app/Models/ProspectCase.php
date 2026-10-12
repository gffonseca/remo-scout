<?php

namespace App\Models;

use App\Enums\Priority;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ProspectCase extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['priority' => Priority::class, 'due_on' => 'date'];
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(RecruitmentNeed::class, 'recruitment_need_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProspectStage::class, 'prospect_stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(StageHistory::class)->orderByDesc('created_at');
    }

    /** Move de etapa registrando responsável e motivo. Motivo é obrigatório ao arquivar. */
    public function moveTo(ProspectStage $stage, ?string $reason, ?int $userId, ?string $nextAction = null, $dueOn = null): void
    {
        if ($stage->is_terminal && blank($reason)) {
            throw new \InvalidArgumentException('Informe o motivo para arquivar.');
        }
        DB::transaction(function () use ($stage, $reason, $userId, $nextAction, $dueOn) {
            $from = $this->prospect_stage_id;
            $this->prospect_stage_id = $stage->id;
            if ($stage->is_terminal) {
                $this->archived_reason = $reason;
            }
            if ($nextAction !== null) {
                $this->next_action = $nextAction;
            }
            if ($dueOn !== null) {
                $this->due_on = $dueOn;
            }
            if ($stage->is_shortlist && $this->shortlist_rank === null) {
                $this->shortlist_rank = (int) static::query()->where('recruitment_need_id', $this->recruitment_need_id)->max('shortlist_rank') + 1;
            }
            $this->save();
            $this->history()->create([
                'from_stage_id' => $from,
                'to_stage_id' => $stage->id,
                'user_id' => $userId,
                'reason' => $reason,
            ]);
        });
    }

    public static function open(RecruitmentNeed $need, Player $player, ?int $userId, ?string $reason = null): self
    {
        $stage = ProspectStage::first();
        $case = static::firstOrCreate(
            ['recruitment_need_id' => $need->id, 'player_id' => $player->id],
            ['prospect_stage_id' => $stage->id, 'owner_id' => $userId, 'priority' => $need->priority?->value ?? 'media'],
        );
        if ($case->wasRecentlyCreated) {
            $case->history()->create(['from_stage_id' => null, 'to_stage_id' => $stage->id, 'user_id' => $userId, 'reason' => $reason ?? 'Candidato identificado']);
        }

        return $case;
    }
}
