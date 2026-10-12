<?php

namespace App\Filament\Resources\CbfAthletes;

use App\Filament\Resources\CbfAthletes\Pages\ListCbfAthletes;
use App\Filament\Resources\Players\PlayerResource;
use App\Models\CbfAthlete;
use App\Models\Player;
use App\Support\Media;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Inscritos nas competições CBF (registro público trazido pelo coletor local). Consulta e porta de entrada para o scout. */
class CbfAthleteResource extends Resource
{
    protected static ?string $model = CbfAthlete::class;

    protected static ?string $slug = 'inscritos-cbf';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Jogadores';

    protected static ?string $modelLabel = 'inscrito CBF';

    protected static ?string $pluralModelLabel = 'Inscritos CBF';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['club', 'player']))
            ->description('Registro público da CBF, atualizado diariamente pelo coletor local. Ninguém entra no scout sem que vocês cadastrem.')
            ->columns([
                TextColumn::make('nickname')->label('Atleta')->weight('bold')->formatStateUsing(fn ($state, CbfAthlete $r) => $state ? mb_convert_case(mb_strtolower($state), MB_CASE_TITLE) : $r->name)
                    ->description(fn (CbfAthlete $r) => $r->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                        ->whereRaw('lower(unaccent_name(name)) like lower(unaccent_name(?))', ["%{$search}%"])
                        ->orWhereRaw('lower(unaccent_name(coalesce(nickname, \'\'))) like lower(unaccent_name(?))', ["%{$search}%"])
                        ->orWhere('cbf_id', $search)))->sortable(['name']),
                TextColumn::make('birth_date')->label('Idade')->alignEnd()->formatStateUsing(fn ($state) => $state?->age)->placeholder('—')->sortable(),
                TextColumn::make('club_name')->label('Clube de inscrição')->html()->sortable()
                    ->formatStateUsing(fn ($state, CbfAthlete $r) => Media::crestTag($r->club).e($r->club?->name ?? $state))
                    ->description(fn (CbfAthlete $r) => $r->club_state),
                TextColumn::make('competitions')->label('Competições')->badge()
                    ->state(fn (CbfAthlete $r) => collect($r->competitions ?? [])->sortKeysDesc()->flatMap(fn ($list, $year) => collect($list)->map(fn ($c) => "{$c} {$year}"))->take(4)->values()->all()),
                TextColumn::make('stats')->label('Jogos/gols CBF')->alignEnd()
                    ->state(fn (CbfAthlete $r) => ($s = collect($r->stats ?? [])->sortKeysDesc()->first()) ? ($s['qtd_partidas'] ?? '—').' / '.($s['qtd_gols'] ?? '—') : null)
                    ->placeholder('sem detalhe')->tooltip('Totais publicados pela CBF na temporada mais recente; sem minutos.'),
                TextColumn::make('player.name')->label('No scout')->badge()->color('success')->placeholder('—')
                    ->url(fn (CbfAthlete $r) => $r->player_id ? PlayerResource::getUrl('view', ['record' => $r->player_id]) : null),
                TextColumn::make('cbf_id')->label('ID CBF')->toggleable(isToggledHiddenByDefault: true)->copyable(),
                TextColumn::make('last_seen_at')->label('Visto na CBF')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('competition')->label('Competição')->options(collect(config('scout.collector_competitions'))->mapWithKeys(fn ($l) => [$l => $l]))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereRaw("exists (select 1 from jsonb_each(competitions) e where e.value ? ?)", [$data['value']]) : $query),
                SelectFilter::make('club_state')->label('UF do clube')->multiple()
                    ->options(fn () => CbfAthlete::query()->whereNotNull('club_state')->distinct()->orderBy('club_state')->pluck('club_state', 'club_state')),
                SelectFilter::make('club_id')->label('Clube')->relationship('club', 'name')->searchable()->preload(),
                TernaryFilter::make('in_scout')->label('No scout')->trueLabel('Já cadastrados')->falseLabel('Fora do scout')
                    ->queries(true: fn ($query) => $query->whereNotNull('player_id'), false: fn ($query) => $query->whereNull('player_id')),
                TernaryFilter::make('age_u23')->label('Sub-23')->trueLabel('Até 23 anos')->falseLabel('24+')
                    ->queries(true: fn ($query) => $query->where('birth_date', '>', now()->subYears(24)), false: fn ($query) => $query->where('birth_date', '<=', now()->subYears(24))),
            ])
            ->recordActions([
                Action::make('addToScout')->label('Cadastrar no scout')->icon(Heroicon::OutlinedUserPlus)->button()->size('sm')
                    ->visible(fn (CbfAthlete $record) => ! $record->player_id && auth()->user()?->canEdit())
                    ->requiresConfirmation()->modalDescription('Cria o atleta com nome, ID CBF, clube e nascimento do registro CBF. O detalhe (foto oficial, totais) chega na próxima coleta.')
                    ->action(function (CbfAthlete $record) {
                        $p = Player::where('cbf_id', $record->cbf_id)->first() ?? Player::create([
                            'name' => $record->nickname ? mb_convert_case(mb_strtolower($record->nickname), MB_CASE_TITLE) : $record->name,
                            'full_name' => mb_convert_case(mb_strtolower($record->name), MB_CASE_TITLE),
                            'cbf_id' => $record->cbf_id, 'data_origin' => 'provedor', 'current_club_id' => $record->club_id, 'birth_date' => $record->birth_date,
                            'field_sources' => ['cbf_id' => ['source' => 'cbf', 'at' => now()->toDateString()]],
                        ]);
                        $record->update(['player_id' => $p->id]);
                        Notification::make()->success()->title("{$p->name} cadastrado no scout")
                            ->actions([Action::make('ver')->label('Abrir perfil')->url(PlayerResource::getUrl('view', ['record' => $p]))])->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCbfAthletes::route('/')];
    }
}
