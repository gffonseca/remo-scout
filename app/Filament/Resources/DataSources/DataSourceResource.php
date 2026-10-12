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
                    ->description(fn (DataSource $s) => (['coletor_local' => $s->key === 'sofascore' ? '' : 'Via coletor local (Mac Mini, diário 06:20). ', 'consulta_manual' => 'Consulta assistida (sem coleta automática). '][$s->transport] ?? '')
                        .($s->key === 'sofascore' ? 'Sob demanda pelo Mac (tarefas a cada 15 min): '.\App\Models\CollectorTask::where('source', 'sofascore')->where('status', 'aguardando_coletor')->count().' aguardando. ' : '')
                        .$s->notes),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state, DataSource $s) => $s->kindLabel())
                    ->color(fn ($state) => ['nao_oficial' => 'warning', 'api_comercial' => 'info', 'documento_publico' => 'success', 'oficial_credenciado' => 'primary'][$state] ?? 'gray'),
                ToggleColumn::make('enabled')->label('Ativa')->disabled(fn () => ! auth()->user()->isCoordinator()),
                TextColumn::make('health')->label('Saúde')->badge()
                    ->color(fn ($state) => ['ok' => 'success', 'degradado' => 'warning', 'bloqueado' => 'danger', 'atrasado' => 'danger', 'sem_credencial' => 'gray', 'nao_testado' => 'gray'][$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ['ok' => 'OK', 'degradado' => 'Instável', 'bloqueado' => 'Bloqueada', 'atrasado' => 'Atrasada', 'sem_credencial' => 'Aguardando credencial', 'nao_testado' => 'Não testada'][$state] ?? $state)
                    ->tooltip(fn (DataSource $s) => $s->last_error ? 'Último erro: '.$s->last_error : null)
                    ->description(fn (DataSource $s) => $s->circuitOpen()
                        ? 'Pausada até '.$s->circuit_open_until->timezone(config('scout.display_timezone'))->format('d/m H:i').' ('.$s->consecutive_failures.' falhas seguidas)'
                        : null),
                TextColumn::make('integration')->label('Integração')->badge()->state(fn (DataSource $s) => $s->integrationLabel())
                    ->color(fn (DataSource $s) => ['operacional' => 'success', 'ingerindo' => 'warning', 'testada' => 'info', 'manual' => 'info', 'nao_autorizada' => 'danger'][$s->integrationStage()] ?? 'gray')
                    ->description(fn (DataSource $s) => ['autorizado' => 'Uso autorizado', 'restrito' => 'Uso restrito', 'nao_autorizado' => 'Uso não autorizado', 'pendente' => 'Autorização pendente'][$s->usage_authorization] ?? null)
                    ->tooltip(fn (DataSource $s) => collect([
                        $s->integrationGaps() ? 'Falta: '.implode('; ', $s->integrationGaps()).'.' : null,
                        $s->coverage_note ? 'Cobertura ('.$s->coverage_checked_at?->timezone(config('scout.display_timezone'))->format('d/m/Y').'): '.$s->coverage_note : null,
                        $s->usage_note ? 'Uso: '.$s->usage_note : null,
                    ])->filter()->implode(' ')),
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
                    ->hidden(fn (DataSource $r) => in_array($r->transport, ['coletor_local', 'consulta_manual'], true))
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
                    Action::make('syncApiFootball')->label('Sincronizar partidas agora')->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (DataSource $r) => $r->key === 'api_football' && auth()->user()->isCoordinator())
                        ->requiresConfirmation()->modalDescription('Série A, B, Copa do Brasil, Série C e Nordeste da temporada atual; até 400 partidas por execução (1 chamada por partida).')
                        ->action(function () {
                            RunArtisanSync::dispatch('scout:sync-api-football', ['--max' => 400, '--user' => auth()->id()]);
                            Notification::make()->success()->title('Sincronização enviada ao worker')->body('Acompanhe em Execuções.')->send();
                        }),
                    Action::make('syncFootballData')->label('Importar agora')->icon(Heroicon::OutlinedArrowDownTray)
                        ->visible(fn (DataSource $r) => $r->key === 'football_data' && auth()->user()->isCoordinator())
                        ->requiresConfirmation()->modalDescription('Baixa o CSV da Série A (1 chamada) e atualiza a força dos clubes desde 2022.')
                        ->action(function () {
                            RunArtisanSync::dispatch('scout:sync-football-data', ['--user' => auth()->id()]);
                            Notification::make()->success()->title('Importação enviada ao worker')->send();
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
                    Action::make('coverage')->label('Registrar conferência de cobertura')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->visible(fn () => auth()->user()->isCoordinator())
                        ->modalDescription('Descreva a amostra conferida (competições, jogos, campos) e o resultado. Sem isso a fonte não vira "Operacional".')
                        ->fillForm(fn (DataSource $r) => ['coverage_note' => $r->coverage_note])
                        ->schema([Textarea::make('coverage_note')->label('Amostra conferida e resultado')->rows(4)->required()->minLength(20)])
                        ->action(function (DataSource $r, array $data) {
                            $r->update(['coverage_note' => $data['coverage_note'], 'coverage_checked_at' => now(), 'coverage_checked_by' => auth()->id()]);
                            Notification::make()->success()->title('Conferência registrada')->send();
                        }),
                    Action::make('usage')->label('Autorização de uso')->icon(Heroicon::OutlinedShieldCheck)
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->fillForm(fn (DataSource $r) => ['usage_authorization' => $r->usage_authorization, 'usage_note' => $r->usage_note])
                        ->schema([
                            \Filament\Forms\Components\Select::make('usage_authorization')->label('Situação')->required()
                                ->options(['autorizado' => 'Autorizado', 'restrito' => 'Restrito (condições na nota)', 'nao_autorizado' => 'Não autorizado', 'pendente' => 'Pendente']),
                            Textarea::make('usage_note')->label('Base da decisão (contrato, termos, quem autorizou)')->rows(3)->required(),
                        ])
                        ->action(function (DataSource $r, array $data) {
                            $r->update($data + ['usage_authorized_by' => auth()->id(), 'usage_authorized_at' => now()]);
                            Notification::make()->success()->title('Autorização registrada')->send();
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
