<?php

namespace App\Filament\Resources\Specialties\Pages;

use App\Filament\Resources\Specialties\SpecialtyResource;
use App\Filament\Support\CatalogActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSpecialties extends ListRecords
{
    protected static string $resource = SpecialtyResource::class;

    public function getSubheading(): ?string
    {
        return 'Combináveis com qualquer posição compatível (ex.: goleiro construtor + pegador de pênaltis + batedor de faltas).';
    }

    protected function getHeaderActions(): array
    {
        return [CatalogActions::exportJson(), CatalogActions::importJson(), CreateAction::make()->label('Nova especialidade')];
    }
}
