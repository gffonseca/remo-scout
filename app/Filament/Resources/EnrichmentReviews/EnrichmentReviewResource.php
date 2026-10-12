<?php

namespace App\Filament\Resources\EnrichmentReviews;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\EnrichmentReview;
use App\Services\PlayerEnrichment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/** Divergências entre o cadastro e fontes externas: nada é sobrescrito sem decisão humana. */
class EnrichmentReviewResource extends Resource
{
    protected static ?string $model = EnrichmentReview::class;

    protected static ?string $slug = 'revisoes-de-dados';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'revisão de dado';

    protected static ?string $pluralModelLabel = 'Revisões de dados';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $n = EnrichmentReview::where('status', 'pendente')->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public const FIELDS = ['birth_date' => 'Nascimento', 'height_cm' => 'Altura', 'preferred_foot' => 'Pé', 'primary_position' => 'Posição',
        'nationality' => 'Nacionalidade', 'second_nationality' => '2ª nacionalidade', 'full_name' => 'Nome completo', 'current_club_id' => 'Clube atual', 'transfermarkt_url' => 'Link TM'];

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('player'))
            ->columns([
                TextColumn::make('player.name')->label('Atleta')->weight('bold')->url(fn ($record) => PlayerResource::getUrl('view', ['record' => $record->player_id])),
                TextColumn::make('field')->label('Campo')->formatStateUsing(fn ($state) => self::FIELDS[$state] ?? $state),
                TextColumn::make('current_value')->label('No cadastro')->placeholder('vazio'),
                TextColumn::make('proposed_value')->label('Na fonte')->weight('bold'),
                TextColumn::make('provider')->label('Fonte')->badge()->color('warning'),
                TextColumn::make('status')->label('Status')->badge()->color(fn ($state) => ['pendente' => 'warning', 'aceito' => 'success', 'rejeitado' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('reviewer.name')->label('Revisado por')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(['pendente' => 'Pendente', 'aceito' => 'Aceito', 'rejeitado' => 'Rejeitado'])->default('pendente')])
            ->recordActions([
                Action::make('accept')->label('Aceitar')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn ($record) => $record->status === 'pendente' && auth()->user()->canEdit())
                    ->action(fn ($record) => PlayerEnrichment::accept($record, auth()->id())),
                Action::make('reject')->label('Manter cadastro')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn ($record) => $record->status === 'pendente' && auth()->user()->canEdit())
                    ->action(fn ($record) => $record->update(['status' => 'rejeitado', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()])),
            ])
            ->toolbarActions([
                BulkAction::make('acceptMany')->label('Aceitar selecionadas')->requiresConfirmation()
                    ->visible(fn () => auth()->user()->canEdit())
                    ->action(fn (Collection $records) => $records->where('status', 'pendente')->each(fn ($r) => PlayerEnrichment::accept($r, auth()->id()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEnrichmentReviews::route('/')];
    }
}
