<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Filament\Pages\QuadroProspeccao;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProspectCasesRelationManager extends RelationManager
{
    protected static string $relationship = 'prospectCases';

    protected static ?string $title = 'Prospecção';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['need', 'stage', 'owner']))
            ->columns([
                TextColumn::make('need.title')->label('Necessidade')->weight('bold'),
                TextColumn::make('stage.name')->label('Etapa')->badge()->color(fn ($record) => $record->stage->color),
                TextColumn::make('priority')->label('Prioridade')->badge(),
                TextColumn::make('owner.name')->label('Responsável')->placeholder('—'),
                TextColumn::make('next_action')->label('Próxima ação')->placeholder('—'),
                TextColumn::make('due_on')->label('Prazo')->date('d/m/Y')->placeholder('—'),
            ])
            ->recordUrl(fn ($record) => QuadroProspeccao::getUrl(['need' => $record->recruitment_need_id]))
            ->emptyStateHeading('Fora do quadro de prospecção')
            ->emptyStateDescription('Use "Adicionar a necessidade" no topo da página.');
    }
}
