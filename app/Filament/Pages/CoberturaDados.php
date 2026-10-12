<?php

namespace App\Filament\Pages;

use App\Models\Club;
use App\Services\DataCoverage;
use App\Support\PlayerStatsView;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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
                Notification::make()->success()->title("{$r['rows']} linha(s) de estatística agora em {$r['club']}")->body('Lote '.$r['batch'].' (reversível).')->send();
            });
    }

    public function data(): array
    {
        $rows = DataCoverage::rows();
        $field = $this->tipo === 'foto' ? 'photo_reason' : 'stats_reason';
        $list = $rows->whereNotNull($field)
            ->when($this->motivo, fn ($c) => $c->where($field, $this->motivo))
            ->when($this->prioritarios, fn ($c) => $c->where('priority', true));

        return [
            'total' => $rows->count(),
            'stats' => $rows->whereNotNull('stats_reason')->countBy('stats_reason')->all(),
            'photo' => $rows->whereNotNull('photo_reason')->countBy('photo_reason')->all(),
            'list' => $list->take(300)->values(),
            'listCount' => $list->count(),
            'field' => $field,
            'clubs' => DataCoverage::unresolvedClubs(),
            'reasons' => $this->tipo === 'foto' ? DataCoverage::PHOTO_REASONS : DataCoverage::STATS_REASONS,
        ];
    }
}
