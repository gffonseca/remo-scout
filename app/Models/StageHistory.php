<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'stage_history';

    protected $guarded = ['id'];

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(ProspectStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(ProspectStage::class, 'to_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
