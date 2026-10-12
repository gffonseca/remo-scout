<?php

namespace App\Filament\Resources\RecruitmentNeeds\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use App\Services\NeedSearch;
use App\Services\RequirementCheck;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Pesquisa salva da necessidade (F11): mesmos critérios e estados, pendências com filtro explícito. */
class SearchCandidates extends Page
{
    use InteractsWithRecord;

    protected static string $resource = RecruitmentNeedResource::class;

    protected string $view = 'filament.pages.need-search';

    #[Url]
    public string $show = 'viaveis';

    #[Url]
    public ?string $pendingIn = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        return 'Pesquisa: '.$this->getRecord()->title;
    }

    /** @return array{rows:\Illuminate\Support\Collection, all:\Illuminate\Support\Collection, counts:array, pendingOptions:array, criteria:list<string>} */
    public function data(): array
    {
        /** @var RecruitmentNeed $need */
        $need = $this->getRecord();
        $all = NeedSearch::run($need);
        $financial = auth()->user()->canViewFinancial();
        $hideBudget = fn ($items) => collect($items)->reject(fn ($i) => ! $financial && $i['kind'] === 'budget')->values()->all();

        return [
            'rows' => NeedSearch::filter($all, $this->show, $this->pendingIn)->map(fn ($r) => ['items' => $hideBudget($r['items'])] + $r),
            'counts' => $all->countBy('class')->all() + ['total' => $all->count()],
            'pendingOptions' => $all->where('class', 'pendente')->pluck('pending')->flatten()->countBy()->sortDesc()->all(),
            'criteria' => static::criteria($need, $financial),
        ];
    }

    /** O que a necessidade exige, no mesmo texto da tela da necessidade. @return list<string> */
    public static function criteria(RecruitmentNeed $n, bool $financial): array
    {
        return array_values(array_filter([
            'Posição '.$n->position->value,
            $n->age_min || $n->age_max ? 'Idade '.($n->age_min ?? '—').'–'.($n->age_max ?? '—') : null,
            $n->preferred_foot ? 'Pé '.$n->preferred_foot->getLabel().' (ambidestro atende)' : null,
            ...RequirementCheck::roleRequirements($n)->where('is_mandatory', true)
                ->map(fn ($r) => 'Nível observado '.$r['role_name'].' · '.\App\Enums\GamePhase::label($r['phase']).' ≥ '.$r['min_level']->value)->all(),
            ...$n->specialties()->wherePivot('is_mandatory', true)->pluck('name')->map(fn ($s) => 'Especialidade: '.$s)->all(),
            ...collect($n->mandatory_requirements ?? [])->filter()->map(fn ($t) => 'Obrigatório: '.$t)->all(),
            $financial && $n->budget_salary_max !== null ? 'Teto salarial (faixa inteira dentro = atende; intersecta = negociar)' : null,
            $financial && $n->budget_transfer_max !== null ? 'Teto de transferência' : null,
        ]));
    }

    public function addToBoard(string $playerId): void
    {
        abort_unless(auth()->user()->canEdit(), 403);
        $c = ProspectCase::open($this->getRecord(), Player::findOrFail($playerId), auth()->id(), 'Incluído pela pesquisa da necessidade.');
        Notification::make()->success()->title($c->wasRecentlyCreated ? 'Adicionado ao quadro' : 'Já estava no quadro')->send();
    }

    public function playerUrl(Player $p): string
    {
        return PlayerResource::getUrl('view', ['record' => $p]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Exportar pesquisa (CSV)')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->url(fn () => route('export.need-search', ['need' => $this->getRecord(), 'show' => $this->show, 'pending' => $this->pendingIn])),
            Action::make('back')->label('Voltar à necessidade')->color('gray')
                ->url(fn () => RecruitmentNeedResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
