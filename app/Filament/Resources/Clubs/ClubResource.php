<?php

namespace App\Filament\Resources\Clubs;

use App\Models\Club;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ClubResource extends Resource
{
    protected static ?string $model = Club::class;

    protected static ?string $slug = 'clubes';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'clube';

    protected static ?string $pluralModelLabel = 'Clubes';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('short_name')->label('Sigla/nome curto'),
            TextInput::make('country')->label('País')->default('Brasil')->required(),
            TextInput::make('state')->label('UF')->maxLength(2),
            TextInput::make('city')->label('Cidade'),
            \Filament\Forms\Components\Select::make('current_division')->label('Divisão nacional (referência)')->options(['Série A' => 'Série A', 'Série B' => 'Série B', 'Série C' => 'Série C', 'Série D' => 'Série D', 'Sem divisão' => 'Sem divisão nacional', 'Exterior' => 'Exterior']),
            \Filament\Forms\Components\TagsInput::make('aliases')->label('Outros nomes'),
            TextInput::make('cbf_code')->label('Código CBF'),
            Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('players'))
            ->columns([
                TextColumn::make('name')->label('Clube')->searchable()->weight('bold'),
                TextColumn::make('current_division')->label('Divisão')->badge()->sortable()->placeholder('—')
                    ->color(fn ($state) => ['Série A' => 'success', 'Série B' => 'info', 'Série C' => 'warning', 'Série D' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('city')->label('Cidade')->placeholder('—')->toggleable(),
                TextColumn::make('state')->label('UF')->placeholder('—')->sortable(),
                TextColumn::make('competitions')->label('Competições 2026')->badge()->state(fn ($record) => $record->competitions['2026'] ?? [])->toggleable(),
                TextColumn::make('country')->label('País'),
                TextColumn::make('players_count')->label('Atletas cadastrados')->alignEnd(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('current_division')->label('Divisão')->options(['Série A' => 'Série A', 'Série B' => 'Série B', 'Série C' => 'Série C', 'Série D' => 'Série D', 'Sem divisão' => 'Sem divisão', 'Exterior' => 'Exterior'])->multiple(),
                \Filament\Tables\Filters\SelectFilter::make('state')->label('UF')->options(fn () => Club::query()->whereNotNull('state')->distinct()->orderBy('state')->pluck('state', 'state'))->multiple(),
            ])
            ->recordActions([EditAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageClubs::route('/')];
    }
}
