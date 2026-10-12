<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Especialidade combinável (ex.: pegador de pênaltis). Pode valer para qualquer posição. */
class Specialty extends Model
{
    use Auditable, HasUuids, Versioned;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'compatible_positions' => 'array',
            'status' => CatalogStatus::class,
        ];
    }

    protected function versionedFields(): array
    {
        return ['description', 'compatible_positions', 'evaluation_suggestions'];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(PlayerSpecialtyAssessment::class);
    }
}
