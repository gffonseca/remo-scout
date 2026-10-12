<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DataSourcePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->canEdit();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $user->is_active && $user->isCoordinator();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
