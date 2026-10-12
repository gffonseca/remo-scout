<?php

namespace App\Filament\Pages;

use App\Jobs\RunArtisanSync;
use App\Models\Club;
use App\Models\Player;
use App\Services\DataCoverage;
use App\Services\DataFix;
use App\Services\PhotoBackfill;
use App\Support\PlayerStatsView;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

/** Por que faltam estatística e foto — causa verificável por atleta e o próximo passo. */
class CoberturaDados extends Page
{
    protected string $view = 'filament.pages.cobertura-dados';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Dados e importação';

    protected static ?string $navigationLabel = 'Cobertura de dados';

    protected static ?string $title = 'Cobertura de dados dos atletas';

    protected static ?string $slug = 'cobertura';

    #[Url]
    public string $tipo = 'estatistica';

    #[Url]
    public ?string $motivo = null;

    #[Url]
    public bool $prioritarios = false;

    public function linkClubAction(): Action
    {
        return Action::make('linkClub')->label('Vincular')->size('sm')->link()
            ->visible(fn () => auth()->user()->isCoordinator())
            ->modalHeading(fn (array $arguments) => 'Vincular clube da fonte '.PlayerStatsView::providerLabel($arguments['provider'] ?? '').' · ID '.($arguments['external_id'] ?? ''))
            ->modalDescription('Confirme pelo próprio clube (escudo, cidade, elenco na fonte). Não vincule por nome parecido. A correção fica registrada em lote e pode ser desfeita.')
            ->schema([Select::make('club_id')->label('Clube do cadastro')->required()->searchable()
                ->getSearchResultsUsing(fn (string $q) => Club::where('name', 'ilike', '%'.$q.'%')->orderBy('name')->limit(30)->get()
                    ->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->state ? ' ('.$c->state.')' : '').' · '.$c->country]))
                ->getOptionLabelUsing(fn ($v) => Club::find($v)?->name)
                ->in(fn ($state) => filled($state) && Club::whereKey($state)->exists() ? [$state] : [])])
            ->action(function (array $data, array $arguments) {
                abort_unless(auth()->user()->isCoordinator(), 403);
                $r = DataCoverage::linkClub((string) $arguments['provider'], (string) $arguments['external_id'], $data['club_id'], auth()->user());
                Notification::make()->success()->title("{$r['rows']} linha(s) de estatística agora em {$r['club']}")->body('Lote '.$r['batch'].' (reversível).'.($r['skipped'] ? " {$r['skipped']} linha(s) já ligadas a outro clube ficaram como estavam." : ''))->send();
            });
    }

    /** Correções em lote pela tela (só admin): enfileiram o comando com trilha; cada lote pode ser desfeito aqui. */
    public function fixAction(): Action
    {
        return Action::make('fix')->size('sm')
            ->label(fn (array $arguments) => self::FIXES[$arguments['correcao'] ?? '']['label'] ?? 'Aplicar')
            ->visible(fn () => auth()->user()->isAdmin())
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => self::FIXES[$arguments['correcao'] ?? '']['label'] ?? '')
            ->modalDescription(fn (array $arguments) => (self::FIXES[$arguments['correcao'] ?? '']['help'] ?? '').(($arguments['correcao'] ?? '') === 'enriquecer' ? ' Roda no worker em segundo plano.' : ' Roda no worker em segundo plano; o lote aparece em "Lotes de correção" e pode ser desfeito.'))
            ->action(function (array $arguments) {
                abort_unless(auth()->user()->isAdmin(), 403);
                $fix = self::FIXES[$arguments['correcao'] ?? ''] ?? abort(404);
                RunArtisanSync::dispatch($fix['command'], $fix['params'] + ['--actor' => 'user:'.auth()->id()]);
                Cache::forget('coverage.rows');
                Notification::make()->success()->title(($arguments['correcao'] ?? '') === 'enriquecer' ? 'Enriquecimento enviado ao worker' : 'Correção enviada ao worker')->body('Atualize a página em alguns minutos para ver o lote.')->send();
            });
    }

    public const FIXES = [
        'clubes' => ['label' => 'Vincular clubes e buscar rótulos', 'command' => 'scout:stats-clubs', 'params' => ['--apply' => true, '--labels' => true],
            'help' => 'Liga ao clube do cadastro as linhas cujo ID tem vínculo confiável e busca no Transfermarkt o nome dos clubes sem rótulo (com cache). Vínculo por semelhança não é aplicado.'],
        'fotos' => ['label' => 'Aplicar fotos da API-Football', 'command' => 'scout:photos', 'params' => ['--backfill' => true, '--apply' => true],
            'help' => 'Copia a foto da API-Football para atletas com vínculo confiável e sem foto. Não substitui upload nem foto da CBF.'],
        'checar' => ['label' => 'Verificar URLs de foto (200)', 'command' => 'scout:photos', 'params' => ['--check' => true, '--apply' => true, '--limit' => 200],
            'help' => 'Confere até 200 URLs externas (host permitido, resposta, tipo, tamanho) e grava o estado. Não apaga URL.'],
        'enriquecer' => ['label' => 'Enriquecer no Transfermarkt (40)', 'command' => 'scout:enrich', 'params' => ['provider' => 'transfermarkt', '--scope' => 'scout', '--limit' => 40],
            'help' => 'Para até 40 atletas do scout (watchlist → necessidades abertas → lista prioritária → demais; quem nunca foi tentado primeiro): vincula só por nome + nascimento (o resto vai para "Identidades a revisar") e traz perfil, carreira por temporada, valor de mercado e contrato observados. Fonte não oficial; dado divergente vira revisão, não sobrescrita. Leva até 20 min; o resultado fica em "Histórico de importações".'],
    ];

    public function revertBatch(string $batch): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $r = DataFix::revert($batch);
        Cache::forget('coverage.rows');
        Notification::make()->success()->title("Lote desfeito: {$r['reverted']} linha(s)")
            ->body($r['conflicts'] ? count($r['conflicts']).' linha(s) editadas depois ficaram como estão.' : null)->send();
    }

    public function data(): array
    {
        // cada clique de filtro não refaz a consulta; o cache guarda só arrays (o cache de produção não desserializa objetos)
        $rows = collect(Cache::remember('coverage.rows', 60, fn () => DataCoverage::rows()->map(fn ($r) => (array) $r)->all()))->map(fn ($r) => (object) $r);
        $field = $this->tipo === 'foto' ? 'photo_reason' : 'stats_state';
        $list = $rows->when($this->tipo === 'foto', fn ($c) => $c->whereNotNull('photo_reason'), fn ($c) => $c->whereIn('stats_state', array_merge(DataCoverage::MISSING, ['parcial', 'desatualizado'])))
            ->when($this->motivo, fn ($c) => $c->where($field, $this->motivo))
            ->when($this->prioritarios, fn ($c) => $c->where('priority', true));

        return [
            'total' => $rows->count(),
            'states' => $rows->countBy('stats_state')->all(),
            'photo' => $rows->whereNotNull('photo_reason')->countBy('photo_reason')->all(),
            'with_photo' => $rows->whereNull('photo_reason')->count(),
            'list' => $list->take(300)->values(),
            'listCount' => $list->count(),
            'field' => $field,
            'clubs' => DataCoverage::unresolvedClubs(),
            'clubPreview' => DataCoverage::clubBackfillPreview(),
            'photoPreview' => PhotoBackfill::candidates()->count(),
            'toEnrich' => Player::whereIn('id', $rows->pluck('id'))->where(fn ($w) => $w->whereNull('enriched_at')->orWhere('enriched_at', '<', now()->subDays(14)))->count(),
            'photoToCheck' => Player::whereNotNull('photo_url')->whereNull('photo_path')->whereNull('photo_checked_at')->count(),
            'batches' => DataCoverage::batches(),
            'runs' => DataCoverage::sourceRuns(),
            'reasons' => $this->tipo === 'foto' ? DataCoverage::PHOTO_REASONS : DataCoverage::STATS_STATES,
        ];
    }
}
