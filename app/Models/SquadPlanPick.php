<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SquadPlanPick extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(SquadPlanSlot::class, 'squad_plan_slot_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
