<?php

namespace App\Filament\Resources\Fixtures;

use App\Models\Fixture;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class FixtureResource extends Resource
{
    protected static ?string $model = Fixture::class;

    protected static ?string $slug = 'partidas';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'partida';

    protected static ?string $pluralModelLabel = 'Partidas';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextEntry::make('label')->label('Partida')->state(fn (Fixture $f) => $f->label())->weight('bold')->columnSpan(2),
            TextEntry::make('played_on')->label('Data')->date('d/m/Y'),
            TextEntry::make('competition_name')->label('Competição'),
            TextEntry::make('round_label')->label('Rodada/fase')->placeholder('—'),
            TextEntry::make('home_formation')->label('Esquema mandante')->placeholder('—'),
            TextEntry::make('away_formation')->label('Esquema visitante')->placeholder('—'),
            TextEntry::make('provider')->label('Fonte')->badge(),
            TextEntry::make('data_quality')->label('Qualidade')->badge()->color(fn ($state) => $state === 'completo' ? 'success' : 'warning')
                ->helperText(fn (Fixture $f) => $f->quality_note),
            TextEntry::make('source_url')->label('Origem')->url(fn (Fixture $f) => $f->source_url, true)->limit(60)->columnSpan(3)->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('played_on', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['homeClub', 'awayClub'])->withCount(['appearances as used_count' => fn ($a) => $a->where('used', true)]))
            ->columns([
                TextColumn::make('played_on')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('label')->label('Partida')->state(fn (Fixture $f) => $f->label())->weight('bold'),
                TextColumn::make('competition_name')->label('Competição')->searchable(),
                TextColumn::make('season')->label('Temp.'),
                TextColumn::make('used_count')->label('Atletas usados')->alignEnd(),
                TextColumn::make('data_quality')->label('Qualidade')->badge()->color(fn ($state) => $state === 'completo' ? 'success' : 'warning')
                    ->tooltip(fn (Fixture $f) => $f->quality_note),
                TextColumn::make('provider')->label('Fonte')->badge(),
            ])
            ->filters([
                SelectFilter::make('competition_name')->label('Competição')->options(fn () => Fixture::query()->distinct()->orderBy('competition_name')->pluck('competition_name', 'competition_name')),
                SelectFilter::make('provider')->label('Fonte')->options(['api_futebol' => 'API Futebol', 'sumula_fpf' => 'Súmula FPF', 'sumula_cbf' => 'Súmula CBF']),
                SelectFilter::make('data_quality')->label('Qualidade')->options(['completo' => 'Completo', 'parcial' => 'Parcial']),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\AppearancesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFixtures::route('/'),
            'view' => Pages\ViewFixture::route('/{record}'),
        ];
    }
}
