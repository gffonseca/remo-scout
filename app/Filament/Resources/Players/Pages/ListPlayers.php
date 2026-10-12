<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Concerns\PersistsTablePreferences;
use App\Filament\Pages\ImportarPlanilha;
use App\Filament\Resources\Players\PlayerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPlayers extends ListRecords
{
    use PersistsTablePreferences;

    protected static string $resource = PlayerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->resetTablePreferencesAction(),
            Action::make('import')->label('Importar planilha')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->url(ImportarPlanilha::getUrl())->visible(fn () => auth()->user()->canEdit()),
            CreateAction::make()->label('Novo atleta'),
        ];
    }
}
