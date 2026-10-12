<?php

namespace App\Filament\Resources\DataSources\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Execuções';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextEntry::make('job')->label('Tarefa'),
            TextEntry::make('status')->label('Status')->badge(),
            TextEntry::make('started_at')->label('Início')->dateTime('d/m/Y H:i:s'),
            TextEntry::make('finished_at')->label('Fim')->dateTime('d/m/Y H:i:s')->placeholder('em andamento'),
            TextEntry::make('messages')->label('Registro')->listWithLineBreaks()->columnSpanFull()->placeholder('—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m H:i'),
                TextColumn::make('job')->label('Tarefa'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn ($state) => ['concluido' => 'success', 'parcial' => 'warning', 'falhou' => 'danger', 'em_execucao' => 'info'][$state] ?? 'gray'),
                TextColumn::make('requests_made')->label('Req.')->alignEnd(),
                TextColumn::make('received')->label('Recebidos')->alignEnd(),
                TextColumn::make('created')->label('Criados')->alignEnd(),
                TextColumn::make('updated')->label('Atualizados')->alignEnd(),
                TextColumn::make('discarded')->label('A revisar')->alignEnd(),
                TextColumn::make('errors')->label('Erros')->alignEnd(),
                TextColumn::make('requester.name')->label('Por')->placeholder('agendado'),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
