<?php

namespace App\Models;

use App\Services\PhotoBackfill;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerExternalId extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified' => 'boolean'];
    }

    protected static function booted(): void
    {
        // vínculo confiável novo ou confirmado: a foto do provedor passa ao cadastro (se ele não tiver foto)
        static::saved(function (self $e) {
            if ($e->provider === 'api_football' && ($e->verified || $e->link_method === 'cadastro_provisorio')) {
                PhotoBackfill::forPlayer($e->player_id);
            }
        });
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
