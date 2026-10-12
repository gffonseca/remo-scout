<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'errors' => 'array', 'completed_at' => 'datetime', 'reverted_at' => 'datetime', 'revert_report' => 'array', 'has_change_log' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ImportChange::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }
}
