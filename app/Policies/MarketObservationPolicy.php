<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Valores financeiros: visíveis só a quem tem permissão financeira. */
class MarketObservationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->canViewFinancial();
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->canEdit();
    }

    public function update(User $user, Model $model): bool
    {
        return $this->create($user);
    }
}
