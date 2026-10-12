<?php

namespace App\Filament\Resources\ClubSquads\Pages;

use App\Filament\Concerns\PersistsTablePreferences;
use App\Filament\Resources\ClubSquads\ClubSquadResource;
use Filament\Resources\Pages\ListRecords;

class ListClubSquads extends ListRecords
{
    use PersistsTablePreferences;

    protected static string $resource = ClubSquadResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->resetTablePreferencesAction()];
    }
}
