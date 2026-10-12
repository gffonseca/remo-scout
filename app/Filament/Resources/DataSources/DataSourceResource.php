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
            Textarea::make('notes')->label('Notas')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('kind')
            ->columns([
                TextColumn::make('name')->label('Fonte')->weight('bold')->description(fn (DataSource $s) => $s->notes)->wrap(),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state, DataSource $s) => $s->kindLabel())
                    ->color(fn ($state) => ['nao_oficial' => 'warning', 'api_comercial' => 'info', 'documento_publico' => 'success', 'oficial_credenciado' => 'primary'][$state] ?? 'gray'),
                ToggleColumn::make('enabled')->label('Ativa')->disabled(fn () => ! auth()->user()->isCoordinator()),
                TextColumn::make('health')->label('Saúde')->badge()
                    ->color(fn ($state) => ['ok' => 'success', 'degradado' => 'warning', 'bloqueado' => 'danger', 'sem_credencial' => 'gray', 'nao_testado' => 'gray'][$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ['ok' => 'OK', 'degradado' => 'Instável', 'bloqueado' => 'Bloqueada', 'sem_credencial' => 'Aguardando credencial', 'nao_testado' => 'Não testada'][$state] ?? $state)
                    ->tooltip(fn (DataSource $s) => $s->last_error ? 'Último erro: '.$s->last_error : null),
                TextColumn::make('quota')->label('Cota hoje')->state(fn (DataSource $s) => $s->daily_quota ? ($s->daily_quota - $s->remainingQuota()).' / '.$s->daily_quota : '—'),
                TextColumn::make('last_success_at')->label('Último sucesso')->since()->placeholder('nunca'),
                TextColumn::make('runs_count')->label('Execuções')->counts('runs')->alignEnd(),
            ])
            ->recordActions([
                Action::make('probe')->label('Testar')->icon(Heroicon::OutlinedSignal)->iconButton()
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
