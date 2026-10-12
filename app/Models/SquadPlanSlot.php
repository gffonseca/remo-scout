<?php

namespace App\Models;

use App\Enums\Position;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SquadPlanSlot extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => Position::class];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SquadPlan::class, 'squad_plan_id');
    }

    public function tacticalRole(): BelongsTo
    {
        return $this->belongsTo(TacticalRole::class);
    }

    public function picks(): HasMany
    {
        return $this->hasMany(SquadPlanPick::class)->orderBy('depth');
    }
}
