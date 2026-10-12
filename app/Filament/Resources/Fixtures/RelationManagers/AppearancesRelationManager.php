<?php

namespace App\Filament\Resources\Fixtures\RelationManagers;

use App\Filament\Resources\Players\PlayerResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AppearancesRelationManager extends RelationManager
{
    protected static string $relationship = 'appearances';

    protected static ?string $title = 'Atletas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['player', 'club']))
            ->defaultSort(fn ($query) => $query->orderBy('club_id')->orderByDesc('started')->orderByDesc('used'))
            ->paginated([50])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('club.name')->label('Clube'),
                TextColumn::make('shirt')->label('Nº'),
                TextColumn::make('player.name')->label('Atleta')->weight('bold')
                    ->url(fn ($record) => PlayerResource::getUrl('view', ['record' => $record->player_id])),
                IconColumn::make('started')->label('Titular')->boolean(),
                TextColumn::make('minutes')->label('Min.')->placeholder('indet.')
                    ->description(fn ($record) => $record->used && $record->minute_in !== null ? $record->minute_in.'’–'.$record->minute_out.'’' : null),
                TextColumn::make('goals')->label('Gols'),
                TextColumn::make('yellow_cards')->label('Am.'),
                TextColumn::make('red_cards')->label('Verm.'),
                TextColumn::make('position_label')->label('Posição')->placeholder('—'),
            ]);
    }
}
