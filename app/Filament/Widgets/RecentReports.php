<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use App\Models\ScoutingReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentReports extends TableWidget
{
    protected static ?int $sort = 4;

    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Relatórios recentes';

    public function table(Table $table): Table
    {
        return $table
            ->query(ScoutingReport::query()->visibleTo(auth()->user())->with(['player', 'author']))
            ->defaultSort('created_at', 'desc')
            ->paginated([5])->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('player.name')->label('Atleta')->weight('bold'),
                TextColumn::make('level')->label('Nível')->badge(),
                TextColumn::make('verdict')->label('Parecer')->badge(),
                TextColumn::make('author.name')->label('Autor'),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->recordUrl(fn ($record) => ScoutingReportResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nenhum relatório ainda');
    }
}
