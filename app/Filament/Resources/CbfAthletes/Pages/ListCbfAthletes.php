<?php

namespace App\Filament\Resources\CbfAthletes\Pages;

use App\Filament\Concerns\PersistsTablePreferences;
use App\Filament\Resources\CbfAthletes\CbfAthleteResource;
use Filament\Resources\Pages\ListRecords;

class ListCbfAthletes extends ListRecords
{
    use PersistsTablePreferences;

    protected static string $resource = CbfAthleteResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->resetTablePreferencesAction()];
    }
}
