<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Matriz padrão: todos os usuários ativos leem; analista+ cria/edita; coordenador+ exclui.
 * Leitor nunca edita. Políticas específicas sobrescrevem.
 */
abstract class BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Model $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->canEdit();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->is_active && $user->canEdit();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->is_active && $user->isCoordinator();
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_active && $user->isCoordinator();
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->is_active && $user->isCoordinator();
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
