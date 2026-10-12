<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Papel/perfil tático de JOGADOR. Não confundir com perfil de acesso de usuário. */
class TacticalRole extends Model
{
    use Auditable, HasUuids, Versioned;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'compatible_positions' => 'array',
            'phases' => 'array',
            'criteria' => 'array',
            'status' => CatalogStatus::class,
        ];
    }

    protected function versionedFields(): array
    {
        return ['position_group', 'description', 'compatible_positions', 'phases', 'behaviors', 'mandatory_requirements', 'desirable_requirements', 'criteria', 'evaluation_suggestions'];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(PlayerRoleAssessment::class);
    }

    public function label(): string
    {
        return $this->position_group.' · '.$this->name;
    }

    /** Critérios do formulário de scout; se não definidos, usa comportamentos/sugestões como guia. */
    public function criteriaList(): array
    {
        $list = array_values(array_filter($this->criteria ?? []));

        return $list ?: ['Execução do papel (geral)'];
    }
}
