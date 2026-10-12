<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Atleta inscrito em competição CBF (registro público, coletado localmente). Não cria atleta no scout automaticamente. */
class CbfAthlete extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['competitions' => 'array', 'stats' => 'array', 'birth_date' => 'date', 'last_seen_at' => 'datetime', 'detail_fetched_at' => 'datetime'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function displayName(): string
    {
        return $this->nickname ? mb_convert_case($this->nickname, MB_CASE_TITLE).' ('.$this->name.')' : $this->name;
    }
}
