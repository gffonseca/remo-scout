<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchlistItem extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_review_on' => 'date', 'last_reviewed_at' => 'datetime'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(WatchlistAlert::class)->latest();
    }

    public function isOverdue(): bool
    {
        return $this->status === 'ativo' && $this->next_review_on !== null && $this->next_review_on->lt(now(config('scout.display_timezone'))->startOfDay());
    }
}
