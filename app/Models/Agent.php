<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Empresário/agência (contato profissional). */
class Agent extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function label(): string
    {
        return $this->name.($this->agency ? ' ('.$this->agency.')' : '');
    }

    public function whatsappUrl(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($this->whatsapp ?: $this->phone));

        return $digits ? 'https://wa.me/'.(strlen($digits) <= 11 ? '55'.$digits : $digits) : null;
    }
}
