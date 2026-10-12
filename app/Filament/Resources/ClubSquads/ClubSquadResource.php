<?php

namespace App\Filament\Resources\ClubSquads;

use App\Filament\Resources\ClubSquads\Pages\ListClubSquads;
use App\Filament\Resources\Players\PlayerResource;
use App\Models\ClubSquadEntry;
use App\Support\Media;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Elenco atual por clube segundo a Dados Futebol: retrato com data, só consulta. Não altera cadastro. */
class ClubSquadResource extends Resource
{
    protected static ?string $model = ClubSquadEntry::class;

    protected static ?string $slug = 'elencos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Jogadores';

    protected static ?string $modelLabel = 'atleta de elenco';

    protected static ?string $pluralModelLabel = 'Elencos atuais';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['club', 'player']))
            ->description('Fonte: Dados Futebol, atualizado semanalmente (Remo e clubes dos atletas prioritários). Posição como veio da fonte, pouco confiável. Sem data de nascimento: vínculo com o scout só por revisão.')
            ->columns([
                TextColumn::make('display_name')->label('Atleta')->weight('bold')->state(fn (ClubSquadEntry $r) => $r->display_name ?: $r->name)
                    ->description(fn (ClubSquadEntry $r) => $r->display_name && $r->display_name !== $r->name ? $r->name : null)
                    ->searchable(['name', 'display_name'])->sortable(['name']),
                TextColumn::make('club.name')->label('Clube')->html()->formatStateUsing(fn ($state, ClubSquadEntry $r) => Media::crestTag($r->club).e($state))->sortable(),
                TextColumn::make('shirt_number')->label('Camisa')->alignEnd()->placeholder('—'),
                TextColumn::make('position_raw')->label('Posição (fonte)')->placeholder('não informada')->color('gray'),
                TextColumn::make('player.name')->label('No scout')->placeholder('não ligado')
                    ->url(fn (ClubSquadEntry $r) => $r->player_id ? PlayerResource::getUrl('view', ['record' => $r->player_id]) : null),
                TextColumn::make('left_at')->label('Situação')->badge()
                    ->state(fn (ClubSquadEntry $r) => $r->isCurrent() ? 'No elenco' : 'Saiu do elenco')
                    ->color(fn (ClubSquadEntry $r) => $r->isCurrent() ? 'success' : 'warning')
                    ->description(fn (ClubSquadEntry $r) => 'visto em '.$r->last_seen_at->timezone(config('scout.display_timezone'))->format('d/m/Y')),
            ])
            ->filters([
                SelectFilter::make('club_id')->label('Clube')->relationship('club', 'name')->searchable()->preload(),
                TernaryFilter::make('current')->label('Situação')->trueLabel('No elenco')->falseLabel('Saiu')->default(true)
                    ->queries(true: fn ($q) => $q->whereNull('left_at'), false: fn ($q) => $q->whereNotNull('left_at'), blank: fn ($q) => $q),
                TernaryFilter::make('linked')->label('Ligado ao scout')
                    ->queries(true: fn ($q) => $q->whereNotNull('player_id'), false: fn ($q) => $q->whereNull('player_id'), blank: fn ($q) => $q),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListClubSquads::route('/')];
    }
}
