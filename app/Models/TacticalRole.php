<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Versioned;
use App\Services\CatalogApproval;
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
            // nova fase/comportamento num perfil ativo sem critério A–D correspondente: volta para proposta (vale para qualquer perfil de acesso)
            if ($m->exists && $m->isDirty(['phases', 'behaviors']) && $m->status === CatalogStatus::Ativo && CatalogApproval::gaps($m) !== []) {
                $m->status = CatalogStatus::Proposta;
                $m->reviewed_at = null;
            }
        });
    }

    protected function versionedFields(): array
    {
        return ['level_guide', 'position_group', 'description', 'compatible_positions', 'phases', 'behaviors', 'mandatory_requirements', 'desirable_requirements', 'criteria', 'evaluation_suggestions'];
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
