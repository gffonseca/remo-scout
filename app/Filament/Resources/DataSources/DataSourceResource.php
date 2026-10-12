<?php

namespace App\Filament\Resources\DataSources;

use App\Jobs\RunArtisanSync;
use App\Models\DataSource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

class DataSourceResource extends Resource
{
    protected static ?string $model = DataSource::class;

    protected static ?string $slug = 'fontes';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $modelLabel = 'fonte de dados';

    protected static ?string $pluralModelLabel = 'Fontes de dados';

    protected static ?int $navigationSort = 0;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->disabled(),
            Toggle::make('enabled')->label('Ativa')->inline(false),
            TextInput::make('base_url')->label('Endereço base')->url()->helperText('Ex.: trocar a instância pública do transfermarkt-api por uma própria.'),
            TextInput::make('min_interval_ms')->label('Intervalo mínimo entre chamadas (ms)')->numeric()->minValue(200),
            TextInput::make('daily_quota')->label('Cota diária (requisições)')->numeric()->minValue(1),
            TextInput::make('monthly_quota')->label('Cota mensal (requisições)')->numeric()->minValue(1),
            TextInput::make('expected_refresh_hours')->label('Atualização esperada a cada (horas)')->numeric()->minValue(1)
                ->helperText('Sem ingestão concluída nesse prazo, a fonte aparece como "Atrasada". Vazio = sob demanda.'),
            Textarea::make('notes')->label('Notas')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('kind')
            ->columns([
                TextColumn::make('name')->label('Fonte')->weight('bold')->wrap()
                    ->description(fn (DataSource $s) => ($s->transport === 'coletor_local' ? 'Via coletor local (Mac Mini, diário 06:20). ' : '').$s->notes),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state, DataSource $s) => $s->kindLabel())
                    ->color(fn ($state) => ['nao_oficial' => 'warning', 'api_comercial' => 'info', 'documento_publico' => 'success', 'oficial_credenciado' => 'primary'][$state] ?? 'gray'),
                ToggleColumn::make('enabled')->label('Ativa')->disabled(fn () => ! auth()->user()->isCoordinator()),
                TextColumn::make('health')->label('Saúde')->badge()
                    ->color(fn ($state) => ['ok' => 'success', 'degradado' => 'warning', 'bloqueado' => 'danger', 'sem_credencial' => 'gray', 'nao_testado' => 'gray'][$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ['ok' => 'OK', 'degradado' => 'Instável', 'bloqueado' => 'Bloqueada', 'sem_credencial' => 'Aguardando credencial', 'nao_testado' => 'Não testada'][$state] ?? $state)
                    ->tooltip(fn (DataSource $s) => $s->last_error ? 'Último erro: '.$s->last_error : null)
                    ->description(fn (DataSource $s) => $s->circuitOpen()
                        ? 'Pausada até '.$s->circuit_open_until->timezone(config('scout.display_timezone'))->format('d/m H:i').' ('.$s->consecutive_failures.' falhas seguidas)'
                        : null),
                TextColumn::make('quota')->label('Cota')->state(fn (DataSource $s) => collect([
                    $s->daily_quota ? $s->usedToday().' / '.$s->daily_quota.' hoje' : null,
                    $s->monthly_quota ? $s->usedThisMonth().' / '.$s->monthly_quota.' no mês' : null,
                ])->filter()->implode(' · ') ?: '—'),
                TextColumn::make('freshness')->label('Atualização')->badge()->state(fn (DataSource $s) => $s->freshnessLabel())
                    ->color(fn (DataSource $s) => ['em_dia' => 'success', 'atrasado' => 'danger', 'nunca' => 'warning'][$s->freshness()] ?? 'gray')
                    ->tooltip(fn (DataSource $s) => ($at = $s->lastIngestedAt()) ? 'Última ingestão concluída: '.$at->timezone(config('scout.display_timezone'))->format('d/m/Y H:i') : null),
                TextColumn::make('last_success_at')->label('Último contato ok')->since()->placeholder('nunca'),
                TextColumn::make('runs_count')->label('Execuções')->counts('runs')->alignEnd(),
            ])
            ->recordActions([
                Action::make('probe')->label('Testar')->icon(Heroicon::OutlinedSignal)->iconButton()
                    ->hidden(fn (DataSource $r) => $r->transport === 'coletor_local')
                    ->action(function (DataSource $record) {
                        Artisan::call('scout:probe-sources', ['--only' => $record->key]);
                        $out = trim(Artisan::output()) ?: 'Fonte desativada ou sem teste definido.';
                        Notification::make()->title('Teste de '.$record->name)->body(mb_substr($out, 0, 400))
                            ->{str_contains($out, ': OK') ? 'success' : 'warning'}()->send();
                    }),
                ActionGroup::make([
                    Action::make('syncApiFutebol')->label('Sincronizar partidas agora')->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (DataSource $r) => $r->key === 'api_futebol' && auth()->user()->isCoordinator())
                        ->requiresConfirmation()->modalDescription('Roda no worker, até 300 partidas por execução (cota diária respeitada).')
                        ->action(function () {
                            RunArtisanSync::dispatch('scout:sync-api-futebol', ['--max' => 300, '--user' => auth()->id()]);
                            Notification::make()->success()->title('Sincronização enviada ao worker')->body('Acompanhe em Execuções.')->send();
                        }),
                    Action::make('syncSquads')->label('Atualizar elencos agora')->icon(Heroicon::OutlinedUserGroup)
                        ->visible(fn (DataSource $r) => $r->key === 'dados_futebol' && auth()->user()->isCoordinator())
                        ->requiresConfirmation()->modalDescription('Remo e clubes dos atletas prioritários (até 40 clubes, cerca de 2 chamadas por clube). Só retrato de elenco; não altera cadastro.')
                        ->action(function () {
                            RunArtisanSync::dispatch('scout:sync-dados-futebol', ['--max' => 40, '--user' => auth()->id()]);
                            Notification::make()->success()->title('Atualização de elencos enviada ao worker')->send();
                        }),
                    Action::make('enrichPriority')->label('Enriquecer lista prioritária')->icon(Heroicon::OutlinedSparkles)
                        ->visible(fn (DataSource $r) => in_array($r->key, ['transfermarkt', 'sofascore'], true) && auth()->user()->isCoordinator())
                        ->requiresConfirmation()->modalDescription('Vincula e enriquece até 40 atletas prioritários, com intervalo entre chamadas. Casos ambíguos ficam para escolha manual.')
                        ->action(function (DataSource $r) {
                            RunArtisanSync::dispatch('scout:enrich', ['provider' => $r->key, '--scope' => 'priority', '--limit' => 40, '--user' => auth()->id()]);
                            Notification::make()->success()->title('Enriquecimento enviado ao worker')->send();
                        }),
                    EditAction::make()->label('Configurar')->visible(fn () => auth()->user()->isAdmin()),
                ]),
            ])
            ->paginated(false);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RunsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDataSources::route('/'),
            'edit' => Pages\EditDataSource::route('/{record}/edit'),
        ];
    }
}
