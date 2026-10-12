<?php

namespace App\Filament\Resources\TacticalRoles\Pages;

use App\Filament\Resources\TacticalRoles\TacticalRoleResource;
use App\Filament\Support\CatalogActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTacticalRole extends ViewRecord
{
    protected static string $resource = TacticalRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [CatalogActions::activate(), CatalogActions::cloneAction(TacticalRoleResource::class), CatalogActions::toggleArchive(), EditAction::make()];
    }
}
