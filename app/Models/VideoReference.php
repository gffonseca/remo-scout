<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link para vídeo em plataforma licenciada. O sistema não baixa nem republica vídeo. */
class VideoReference extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ScoutingReport::class, 'scouting_report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
