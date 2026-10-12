<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Filament\Concerns\RelationCountBadge;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RosterSpellsRelationManager extends RelationManager
{
    use RelationCountBadge;

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedClock;

    protected static string $relationship = 'rosterSpells';

    protected static ?string $title = 'Histórico de clubes';

    protected static ?string $modelLabel = 'passagem';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('club_id')->label('Clube')->relationship('club', 'name')->searchable()->required()
                ->createOptionForm([TextInput::make('name')->label('Nome')->required(), TextInput::make('country')->label('País')->default('Brasil')->required()]),
            Select::make('spell_type')->label('Tipo')->options(['definitivo' => 'Definitivo', 'emprestimo' => 'Empréstimo', 'base' => 'Base'])->default('definitivo')->required(),
            DatePicker::make('started_on')->label('Início')->native(false)->displayFormat('d/m/Y'),
            DatePicker::make('ended_on')->label('Fim')->native(false)->displayFormat('d/m/Y')->afterOrEqual('started_on'),
            TextInput::make('source_url')->label('Fonte (URL)')->url()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('club.name')->label('Clube')->weight('bold'),
                TextColumn::make('spell_type')->label('Tipo')->badge(),
                TextColumn::make('started_on')->label('Início')->date('m/Y')->placeholder('—'),
                TextColumn::make('ended_on')->label('Fim')->date('m/Y')->placeholder('atual/—'),
                TextColumn::make('source_url')->label('Fonte')->url(fn ($record) => $record->source_url, true)->limit(25)->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Adicionar passagem')])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()]);
    }
}
