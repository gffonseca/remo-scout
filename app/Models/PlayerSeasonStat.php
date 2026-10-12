<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estatística por temporada e competição, sempre atrelada ao provedor (não somar provedores). */
class PlayerSeasonStat extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['extra' => 'array', 'fetched_at' => 'datetime', 'rating' => 'decimal:2'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** por90 = total × 90 / minutos; null quando minutos ausentes ou zero. */
    public static function per90(?int $total, ?int $minutes): ?float
    {
        if ($total === null || ! $minutes) {
            return null;
        }

        return round($total * 90 / $minutes, 2);
    }
}
