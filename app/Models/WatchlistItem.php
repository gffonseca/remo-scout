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
        return ['next_review_on' => 'date', 'last_reviewed_at' => 'datetime', 'club_baseline_at' => 'datetime'];
    }

    /** Linha de base do clube no momento em que o atleta entra na watchlist (sem clube também é base). */
    protected static function booted(): void
    {
        static::creating(function (self $i) {
            if ($i->club_baseline_at === null && $i->player_id) {
                $i->last_seen_club_id ??= Player::whereKey($i->player_id)->value('current_club_id');
                $i->club_baseline_at = now();
            }
        });
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
