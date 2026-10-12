<?php

namespace App\Filament\Resources\Watchlist;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Player;
use App\Models\User;
use App\Models\WatchlistItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Watchlist: responsável, motivo, próxima revisão; alertas no sino (deduplicados). */
class WatchlistResource extends Resource
{
    protected static ?string $model = WatchlistItem::class;

    protected static ?string $slug = 'watchlist';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Prospecção';

    protected static ?string $modelLabel = 'atleta monitorado';

    protected static ?string $pluralModelLabel = 'Watchlist';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->check()) {
            return null;
        }
        $n = WatchlistItem::where('status', 'ativo')->where('owner_id', auth()->id())
            ->whereDate('next_review_on', '<', now(config('scout.display_timezone'))->toDateString())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function formFields(bool $withPlayer = true): array
    {
        return array_values(array_filter([
            $withPlayer ? Select::make('player_id')->label('Atleta')->required()->searchable()
                ->getSearchResultsUsing(fn (string $s) => Player::query()->whereNot(fn ($w) => $w->provisional())
                    ->whereRaw('lower(unaccent_name(name)) like lower(unaccent_name(?))', ["%{$s}%"])->with('currentClub')->limit(30)->get()
                    ->mapWithKeys(fn ($p) => [$p->id => $p->displayLabel()]))
                ->getOptionLabelUsing(fn ($v) => Player::find($v)?->displayLabel()) : null,
            Select::make('owner_id')->label('Responsável')->required()->default(fn () => auth()->id())
                ->options(fn () => User::where('is_active', true)->get()->filter->canEdit()->pluck('name', 'id')),
            DatePicker::make('next_review_on')->label('Próxima revisão')->native(false)->displayFormat('d/m/Y')->default(now()->addDays(30))->required(),
            Textarea::make('reason')->label('Motivo do monitoramento')->required()->minLength(10)->rows(3)->columnSpanFull(),
        ]));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components(static::formFields());
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('next_review_on')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['player.currentClub', 'owner', 'alerts' => fn ($a) => $a->limit(1)]))
            ->columns([
                TextColumn::make('player.name')->label('Atleta')->weight('bold')->searchable()
                    ->url(fn (WatchlistItem $r) => PlayerResource::getUrl('view', ['record' => $r->player_id]))
                    ->description(fn (WatchlistItem $r) => $r->player?->currentClub?->name),
                TextColumn::make('owner.name')->label('Responsável'),
                TextColumn::make('reason')->label('Motivo')->limit(60)->tooltip(fn (WatchlistItem $r) => $r->reason)->wrap(),
                TextColumn::make('next_review_on')->label('Próxima revisão')->date('d/m/Y')->sortable()
                    ->color(fn (WatchlistItem $r) => $r->isOverdue() ? 'danger' : null)
                    ->description(fn (WatchlistItem $r) => $r->isOverdue() ? 'vencida' : ($r->last_reviewed_at ? 'revisado '.$r->last_reviewed_at->format('d/m') : null)),
                TextColumn::make('last_alert')->label('Último alerta')->state(fn (WatchlistItem $r) => $r->alerts->first()?->message)->placeholder('—')->wrap()
                    ->description(fn (WatchlistItem $r) => $r->alerts->first()?->created_at?->timezone(config('scout.display_timezone'))->format('d/m H:i')),
                TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($s) => $s === 'ativo' ? 'Ativo' : 'Encerrado')
                    ->color(fn ($s) => $s === 'ativo' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(['ativo' => 'Ativo', 'encerrado' => 'Encerrado'])->default('ativo'),
                Filter::make('mine')->label('Só os meus')->toggle()->query(fn (Builder $query) => $query->where('owner_id', auth()->id())),
                Filter::make('overdue')->label('Revisão vencida')->toggle()
                    ->query(fn (Builder $query) => $query->whereDate('next_review_on', '<', now(config('scout.display_timezone'))->toDateString())),
            ])
            ->recordActions([
                Action::make('reviewed')->label('Revisado')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (WatchlistItem $r) => $r->status === 'ativo' && auth()->user()->canEdit())
                    ->schema([DatePicker::make('next_review_on')->label('Próxima revisão')->native(false)->displayFormat('d/m/Y')->default(now()->addDays(30))->required()->minDate(now())])
                    ->action(function (WatchlistItem $r, array $data) {
                        $r->update(['next_review_on' => $data['next_review_on'], 'last_reviewed_at' => now()]);
                        Notification::make()->success()->title('Revisão registrada')->send();
                    }),
                EditAction::make()->iconButton()->visible(fn (WatchlistItem $r) => $r->status === 'ativo'),
                Action::make('close')->label('Encerrar')->icon(Heroicon::OutlinedXCircle)->color('gray')->iconButton()
                    ->visible(fn (WatchlistItem $r) => $r->status === 'ativo' && auth()->user()->canEdit())
                    ->schema([Textarea::make('closed_reason')->label('Motivo do encerramento')->required()])
                    ->action(fn (WatchlistItem $r, array $data) => $r->update(['status' => 'encerrado', 'closed_reason' => $data['closed_reason']])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWatchlist::route('/'),
        ];
    }
}
