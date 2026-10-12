<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ImportBatchPolicy extends BasePolicy
{
    public function revert(User $user, Model $model): bool
    {
        return $user->is_active && $user->isCoordinator() && $model->reverted_at === null && $model->status !== 'preview';
    }
}
