<?php

namespace App\Filament\Resources\Specialties;

use App\Enums\CatalogStatus;
use App\Enums\Position;
use App\Filament\Resources\Specialties\Pages;
use App\Filament\Resources\TacticalRoles\RelationManagers\VersionsRelationManager;
use App\Filament\Support\CatalogActions;
use App\Models\Specialty;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SpecialtyResource extends Resource
{
    protected static ?string $model = Specialty::class;

    protected static ?string $slug = 'especialidades';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Papéis e especialidades';

    protected static ?string $modelLabel = 'especialidade';

    protected static ?string $pluralModelLabel = 'Especialidades';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label('Nome')->required()->maxLength(120),
                TextInput::make('code')->label('Código')->required()->unique(ignoreRecord: true)->alphaDash()->disabledOn('edit'),
                Select::make('status')->label('Status')->default('proposta')->required()
                    ->disabled(fn (string $operation) => $operation === 'edit' || ! auth()->user()->isCoordinator())->dehydrated(fn (string $operation) => $operation === 'create')
                    ->options(fn () => auth()->user()->isCoordinator() ? CatalogStatus::class : ['proposta' => 'Proposta'])
                    ->helperText('Aprovar só pelo botão "Aprovar", com critérios A–D completos.'),
                TagsInput::make('aliases')->label('Sinônimos')->columnSpanFull(),
                Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
                Textarea::make('evaluation_suggestions')->label('Como avaliar (amostra, denominador, vídeo)')->rows(3)->columnSpanFull(),
                CheckboxList::make('compatible_positions')->label('Posições compatíveis (vazio = qualquer posição)')->options(Position::options())->columns(3)->columnSpanFull(),
            ]),
            Section::make('Critérios observáveis por nível (A–D)')->columns(2)->schema(
                collect(['A', 'B', 'C', 'D'])->map(fn ($lv) => Textarea::make("level_guide.{$lv}")->label("Nível {$lv}")->rows(2))->all()),
            Section::make('Registro da alteração')->visibleOn('edit')->schema([
                Textarea::make('change_note')->label('O que mudou e por quê')->rows(2)->dehydrated(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('assessments'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Especialidade')->weight('bold')->searchable()
                    ->description(fn (Specialty $s) => $s->evaluation_suggestions),
                TextColumn::make('compatible_positions')->label('Posições')->badge()->placeholder('qualquer'),
                TextColumn::make('assessments_count')->label('Atletas')->alignEnd(),
                TextColumn::make('version')->label('Versão')->prefix('v'),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([SelectFilter::make('status')->options(CatalogStatus::class)])
            ->recordActions([
                EditAction::make()->iconButton(),
                ActionGroup::make([CatalogActions::activate(), CatalogActions::cloneAction(static::class), CatalogActions::toggleArchive()]),
            ]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpecialties::route('/'),
            'create' => Pages\CreateSpecialty::route('/create'),
            'edit' => Pages\EditSpecialty::route('/{record}/edit'),
        ];
    }
}
