<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityCandidate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'decided_at' => 'datetime'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Cadastro provisório (criado por escalação) que pode ser mesclado em $player. */
    public function shadow(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'shadow_player_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function kindLabel(): string
    {
        return ['escolher' => 'Escolher correspondência', 'confirmar' => 'Confirmar vínculo provável', 'mesclar' => 'Mesclar cadastro provisório'][$this->kind] ?? $this->kind;
    }
}
