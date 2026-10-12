<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SquadPlan extends Model
{
    use Auditable, HasUuids;

    public const PHASES = ['com_posse' => 'Com posse', 'sem_posse' => 'Sem posse'];

    protected $guarded = ['id'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(SquadPlanSlot::class)->orderBy('sort');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shapeFor(string $phase): string
    {
        return $phase === 'com_posse' ? $this->shape_with_ball : $this->shape_without_ball;
    }
}
