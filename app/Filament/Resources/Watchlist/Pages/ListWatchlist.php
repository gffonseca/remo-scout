<?php

namespace App\Filament\Resources\Watchlist\Pages;

use App\Filament\Resources\Watchlist\WatchlistResource;
use App\Models\WatchlistItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWatchlist extends ListRecords
{
    protected static string $resource = WatchlistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Monitorar atleta')
                ->mutateDataUsing(fn (array $d) => $d + ['created_by' => auth()->id(), 'status' => 'ativo'])
                ->before(function (array $data, CreateAction $action) {
                    if (WatchlistItem::where('player_id', $data['player_id'])->where('status', 'ativo')->exists()) {
                        \Filament\Notifications\Notification::make()->warning()->title('Atleta já está na watchlist')->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
