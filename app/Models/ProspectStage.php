<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectStage extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_shortlist' => 'boolean', 'is_terminal' => 'boolean'];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ProspectCase::class);
    }

    public static function ordered()
    {
        return static::query()->orderBy('sort')->get();
    }

    public static function first(): self
    {
        return static::query()->orderBy('sort')->firstOrFail();
    }
}
