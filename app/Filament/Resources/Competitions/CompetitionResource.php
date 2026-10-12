<?php

namespace App\Filament\Resources\Competitions;

use App\Models\Competition;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CompetitionResource extends Resource
{
    protected static ?string $model = Competition::class;

    protected static ?string $slug = 'competicoes';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'competição';

    protected static ?string $pluralModelLabel = 'Competições';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('country')->label('País/âmbito')->default('Brasil')->required(),
            Select::make('scope')->label('Abrangência')->options(['nacional' => 'Nacional', 'estadual' => 'Estadual', 'regional' => 'Regional', 'continental' => 'Continental'])->required(),
            TextInput::make('tier_label')->label('Nível/divisão'),
            TextInput::make('code_cbf')->label('Código CBF (súmulas)'),
            Toggle::make('is_priority')->label('Mercado prioritário (a confirmar pelo departamento)'),
            Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('country')
            ->columns([
                TextColumn::make('name')->label('Competição')->searchable()->weight('bold'),
                TextColumn::make('country')->label('País')->sortable(),
                TextColumn::make('scope')->label('Abrangência')->badge(),
                TextColumn::make('tier_label')->label('Nível')->placeholder('—'),
                TextColumn::make('code_cbf')->label('Cód. CBF')->placeholder('—'),
                IconColumn::make('is_priority')->label('Prioritária')->boolean(),
            ])
            ->recordActions([EditAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCompetitions::route('/')];
    }
}
