<?php

namespace App\Filament\Resources\TacticalRoles\RelationManagers;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Histórico de versões';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            KeyValueEntry::make('snapshot')->label('Conteúdo desta versão')
                ->state(fn ($record) => collect($record->snapshot)->map(fn ($v) => is_array($v) ? implode(' · ', $v) : (string) $v)->all()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label('Versão')->prefix('v'),
                TextColumn::make('change_note')->label('Alteração')->placeholder('—')->wrap(),
                TextColumn::make('author.name')->label('Por')->placeholder('sistema/seed'),
                TextColumn::make('created_at')->label('Em')->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
