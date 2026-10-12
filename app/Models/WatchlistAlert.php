<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistAlert extends Model
{
    protected $guarded = ['id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WatchlistItem::class, 'watchlist_item_id');
    }
}
