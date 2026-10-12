<?php

namespace App\Filament\Resources\TacticalRoles\Pages;

use App\Filament\Resources\TacticalRoles\TacticalRoleResource;
use App\Filament\Support\CatalogActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTacticalRoles extends ListRecords
{
    protected static string $resource = TacticalRoleResource::class;

    public function getSubheading(): ?string
    {
        return 'Catálogo proposto e editável — não é uma taxonomia oficial. Posição, papel e especialidade são dimensões combináveis.';
    }

    protected function getHeaderActions(): array
    {
        return [CatalogActions::exportJson(), CatalogActions::importJson(), CreateAction::make()->label('Novo papel')];
    }
}
