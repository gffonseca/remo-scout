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
            'level_guide' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** F22: critério A–D editado por quem não aprova → perfil volta a "proposta" (precisa nova aprovação). */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if ($m->exists && $m->isDirty('level_guide') && $m->getOriginal('status') === CatalogStatus::Ativo
                && ! (auth()->user()?->isCoordinator() ?? false)) {
                $m->status = CatalogStatus::Proposta;
                $m->reviewed_at = null;
            }
        });
    }

    protected function versionedFields(): array
    {
        return ['level_guide', 'description', 'compatible_positions', 'evaluation_suggestions'];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(PlayerSpecialtyAssessment::class);
    }
}
