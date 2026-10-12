<?php

namespace App\Filament\Resources\IdentityCandidates;

use App\Filament\Resources\IdentityCandidates\Pages\ListIdentityCandidates;
use App\Filament\Resources\Players\PlayerResource;
use App\Models\IdentityCandidate;
use App\Services\IdentityReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/** Fila de decisões de identidade (F14): nada é ligado por nome sozinho; aqui o humano decide. */
class IdentityCandidateResource extends Resource
{
    protected static ?string $model = IdentityCandidate::class;

    protected static ?string $slug = 'identidades-a-revisar';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'identidade a revisar';

    protected static ?string $pluralModelLabel = 'Identidades a revisar';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = IdentityCandidate::where('status', 'pendente')->distinct('player_id')->count('player_id');

        return $n > 0 ? (string) $n : null;
    }

    private static function run(callable $fn): void
    {
        try {
            Notification::make()->success()->title($fn())->send();
        } catch (\RuntimeException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['player.currentClub', 'shadow', 'decider']))
            ->description('Vínculos automáticos só acontecem com ID oficial ou nome + data de nascimento iguais. O resto passa por aqui. Descartar um vínculo provável desfaz o que veio daquela fonte.')
            ->columns([
                TextColumn::make('player.name')->label('Atleta no scout')->weight('bold')
                    ->url(fn ($record) => $record->player_id ? PlayerResource::getUrl('view', ['record' => $record->player_id]) : null)
                    ->description(fn ($record) => collect([$record->player?->currentClub?->name, $record->player?->birth_date?->format('d/m/Y')])->filter()->implode(' · ') ?: 'sem clube/nascimento'),
                TextColumn::make('kind')->label('Decisão')->badge()->formatStateUsing(fn ($state, $record) => $record->kindLabel())
                    ->color(fn ($state) => ['escolher' => 'info', 'confirmar' => 'warning', 'mesclar' => 'primary'][$state] ?? 'gray'),
                TextColumn::make('provider')->label('Fonte')->badge()->color('gray'),
                TextColumn::make('label')->label('Na fonte')->wrap()->description(fn ($record) => $record->evidence),
                TextColumn::make('score')->label('Aderência do nome')->alignEnd()->placeholder('—')
                    ->tooltip('Similaridade de nome (0–100+). Não confirma identidade sozinha.'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn ($state) => ['pendente' => 'warning', 'vinculado' => 'success', 'descartado' => 'gray'][$state] ?? 'gray')
                    ->description(fn ($record) => $record->decider ? $record->decider->name.' · '.$record->decided_at?->format('d/m/Y') : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pendente' => 'Pendente', 'vinculado' => 'Confirmado', 'descartado' => 'Descartado'])->default('pendente'),
                SelectFilter::make('kind')->label('Decisão')->options(['escolher' => 'Escolher correspondência', 'confirmar' => 'Confirmar vínculo provável', 'mesclar' => 'Mesclar cadastro provisório']),
                SelectFilter::make('provider')->label('Fonte')->options(fn () => IdentityCandidate::query()->distinct()->orderBy('provider')->pluck('provider', 'provider')),
            ])
            ->recordActions([
                Action::make('confirm')->label('É o mesmo atleta')->icon(Heroicon::OutlinedCheck)->color('success')->button()->size('sm')
                    ->visible(fn ($record) => $record->status === 'pendente' && auth()->user()->canEdit())
                    ->requiresConfirmation()->modalDescription(fn ($record) => match ($record->kind) {
                        'mesclar' => 'Move as atuações e vínculos do cadastro provisório para '.$record->player?->name.' e apaga o provisório.',
                        'escolher' => 'Liga este resultado ao atleta e descarta os outros candidatos da mesma fonte.',
                        default => 'Confirma o vínculo; dados da fonte passam a ser aplicados ao cadastro.',
                    })
                    ->action(fn ($record) => static::run(fn () => IdentityReview::confirm($record, auth()->user()))),
                Action::make('discard')->label('Não é')->icon(Heroicon::OutlinedXMark)->color('gray')->button()->size('sm')
                    ->visible(fn ($record) => $record->status === 'pendente' && auth()->user()->canEdit())
                    ->requiresConfirmation()->modalDescription(fn ($record) => $record->kind === 'confirmar'
                        ? 'Desfaz o vínculo e o que veio desta fonte (campos preenchidos por ela, foto, estatísticas).' : 'Descarta este candidato.')
                    ->action(fn ($record) => static::run(fn () => IdentityReview::discard($record, auth()->user()))),
            ])
            ->toolbarActions([
                BulkAction::make('confirmMany')->label('Confirmar selecionados')->requiresConfirmation()
                    ->modalDescription('Confirme só depois de conferir nome, clube e nascimento de cada linha.')
                    ->visible(fn () => auth()->user()->canEdit())
                    ->action(function (Collection $records) {
                        $ok = $fail = 0;
                        foreach ($records->where('status', 'pendente') as $r) {
                            try {
                                IdentityReview::confirm($r, auth()->user());
                                $ok++;
                            } catch (\RuntimeException) {
                                $fail++;
                            }
                        }
                        Notification::make()->info()->title("Confirmados: {$ok} · com conflito: {$fail}")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIdentityCandidates::route('/')];
    }
}
