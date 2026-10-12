<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Models\ScoutingReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ScoutingReportPolicy extends BasePolicy
{
    /** @param ScoutingReport $model */
    public function view(User $user, Model $model): bool
    {
        return $user->is_active && ScoutingReport::query()->visibleTo($user)->whereKey($model->getKey())->exists();
    }

    /** Autor edita enquanto rascunho; coordenador+ pode corrigir. */
    public function update(User $user, Model $model): bool
    {
        if (! $user->is_active || ! $user->canEdit()) {
            return false;
        }

        return $user->isCoordinator() || ($model->author_id === $user->id && $model->status === ReportStatus::Rascunho);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->is_active && ($user->isAdmin() || ($model->author_id === $user->id && $model->status === ReportStatus::Rascunho));
    }
}
