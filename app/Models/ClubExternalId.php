<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubExternalId extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
