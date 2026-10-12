<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'competitions' => 'array'];
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'current_club_id');
    }

    public function crestUrl(): ?string
    {
        return \App\Support\Media::url($this->crest_path) ?? $this->crest_url;
    }

    public function externalIds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClubExternalId::class);
    }

    public static function findOrCreateByName(?string $name, string $country = 'Brasil'): ?self
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $existing = static::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? static::create(['name' => $name, 'country' => $country]);
    }
}
