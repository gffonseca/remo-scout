<?php

namespace App\Filament\Resources\SquadPlans;

use App\Models\Club;
use App\Models\SquadPlan;
use App\Support\Formations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Planejador de elenco (Bloco 3): planos e cenários com duas estruturas (com/sem posse). */
class SquadPlanResource extends Resource
{
    protected static ?string $model = SquadPlan::class;

    protected static ?string $slug = 'planejador';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Prospecção';

    protected static ?string $navigationLabel = 'Planejador de elenco';

    protected static ?string $modelLabel = 'plano de elenco';

    protected static ?string $pluralModelLabel = 'planos de elenco';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome do plano')->required()->maxLength(120)->placeholder('Ex.: Série B 2027 — base'),
            Select::make('club_id')->label('Elenco de referência')->searchable()->preload()
                ->options(fn () => Club::orderBy('name')->pluck('name', 'id'))
                ->default(fn () => Club::where('name', 'ilike', '%remo%')->orderBy('name')->value('id'))
                ->helperText('Atletas deste clube (cadastro ou retrato de elenco) contam como "do elenco"; os demais são alvos.'),
            Select::make('shape_with_ball')->label('Estrutura com posse')->options(Formations::options())->default('4-3-3')->required(),
            Select::make('shape_without_ball')->label('Estrutura sem posse')->options(Formations::options())->default('4-4-2')->required()
                ->helperText('Trocar a estrutura de um plano existente remove os postos que deixam de existir (e quem estava neles).'),
            Textarea::make('notes')->label('Notas')->columnSpanFull()->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['club', 'basedOn', 'creator']))
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (SquadPlan $r) => static::getUrl('plan', ['record' => $r]))
            ->columns([
                TextColumn::make('name')->label('Plano')->searchable()->weight('bold')
                    ->description(fn (SquadPlan $r) => $r->basedOn ? 'Cenário de: '.$r->basedOn->name : null),
                TextColumn::make('club.name')->label('Elenco')->placeholder('—'),
                TextColumn::make('shape_with_ball')->label('Com posse')->badge(),
                TextColumn::make('shape_without_ball')->label('Sem posse')->badge(),
                TextColumn::make('creator.name')->label('Criado por')->placeholder('—'),
                TextColumn::make('updated_at')->label('Atualizado')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('plan')->label('Abrir')->icon(Heroicon::OutlinedSquares2x2)->url(fn (SquadPlan $r) => static::getUrl('plan', ['record' => $r])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSquadPlans::route('/'),
            'create' => Pages\CreateSquadPlan::route('/novo'),
            'edit' => Pages\EditSquadPlan::route('/{record}/editar'),
            'plan' => Pages\PlanSquad::route('/{record}'),
        ];
    }
}
