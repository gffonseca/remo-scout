<?php

namespace App\Policies;

use App\Models\NeedRoleRequirement;
use App\Models\TacticalRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Catálogo de papéis/especialidades: só coordenador+ cria, edita, arquiva. Nunca exclui fisicamente com avaliações. */
class CatalogPolicy extends BasePolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->isCoordinator();
    }

    public function update(User $user, Model $model): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->is_active && $user->isAdmin() && ! $model->assessments()->exists()
            && ! ($model instanceof TacticalRole && NeedRoleRequirement::where('tactical_role_id', $model->id)->exists());
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
