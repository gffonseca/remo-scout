<?php

namespace App\Filament\Resources\SquadPlans\Pages;

use App\Enums\CatalogStatus;
use App\Filament\Resources\SquadPlans\SquadPlanResource;
use App\Models\Player;
use App\Models\SquadPlan;
use App\Models\SquadPlanPick;
use App\Models\SquadPlanSlot;
use App\Models\TacticalRole;
use App\Services\SquadPlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Tela do plano: duas estruturas lado a lado, posto selecionado com titular/alternativas, carências e necessidades. */
class PlanSquad extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SquadPlanResource::class;

    protected string $view = 'filament.pages.planejador';

    #[Url(as: 'posto')]
    public ?string $slotId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): ?string
    {
        /** @var SquadPlan $p */
        $p = $this->getRecord();

        return 'Elenco: '.($p->club?->name ?? 'não definido').' · com posse '.$p->shape_with_ball.' · sem posse '.$p->shape_without_ball
            .($p->basedOn ? ' · cenário de "'.$p->basedOn->name.'"' : '');
    }

    public function canEditPlan(): bool
    {
        return SquadPlanResource::canEdit($this->getRecord());
    }

    public function data(): array
    {
        return SquadPlanner::view($this->getRecord());
    }

    public function selectSlot(string $id): void
    {
        $this->slotId = $this->slot($id)->id;
    }

    private function slot(?string $id): SquadPlanSlot
    {
        return SquadPlanSlot::where('squad_plan_id', $this->getRecord()->getKey())->findOrFail($id);
    }

    private function pick(string $id): SquadPlanPick
    {
        $pick = SquadPlanPick::with('slot')->findOrFail($id);
        abort_unless($pick->slot->squad_plan_id === $this->getRecord()->getKey(), 404);

        return $pick;
    }

    public function promotePick(string $id): void
    {
        abort_unless($this->canEditPlan(), 403);
        SquadPlanner::promote($this->pick($id));
        $this->getRecord()->touch();
    }

    public function removePick(string $id): void
    {
        abort_unless($this->canEditPlan(), 403);
        SquadPlanner::remove($this->pick($id));
        $this->getRecord()->touch();
    }

    public function addPlayerAction(): Action
    {
        return Action::make('addPlayer')->label('Adicionar atleta')->icon(Heroicon::OutlinedUserPlus)->size('sm')
            ->visible(fn () => $this->canEditPlan())
            ->modalHeading(fn (array $arguments) => 'Adicionar ao posto '.$this->slot($arguments['slot'] ?? null)->code)
            ->schema(fn (array $arguments) => [
                Select::make('player_id')->label('Atleta')->required()->searchable()
                    ->helperText('Sem digitar: atletas do elenco nesta posição. Digite para buscar qualquer atleta da base (alvo).')
                    ->options(fn () => $this->squadOptions($this->slot($arguments['slot'] ?? null)))
                    ->getSearchResultsUsing(fn (string $s) => Player::query()->whereNot(fn ($w) => $w->provisional())
                        ->whereRaw('lower(unaccent_name(name)) like lower(unaccent_name(?))', ["%{$s}%"])->with('currentClub')->limit(30)->get()
                        ->mapWithKeys(fn ($p) => [$p->id => $p->displayLabel()]))
                    ->getOptionLabelUsing(fn ($v) => Player::find($v)?->displayLabel())
                    // a lista inicial é só o elenco; alvos vêm da busca — vale qualquer atleta real da base
                    ->in(fn ($state) => filled($state) && Player::whereKey($state)->whereNot(fn ($w) => $w->provisional())->exists() ? [$state] : []),
                Radio::make('as')->label('Como')->options(['titular' => 'Titular (o atual vira alternativa 1)', 'alternativa' => 'Alternativa (fim da fila)'])
                    ->default('alternativa')->required(),
            ])
            ->action(function (array $data, array $arguments) {
                $slot = $this->slot($arguments['slot'] ?? null);
                SquadPlanner::addPick($slot, $data['player_id'], $data['as'] === 'titular');
                $this->getRecord()->touch();
                $this->slotId = $slot->id;
                Notification::make()->success()->title('Atleta adicionado ao posto '.$slot->code)->send();
            });
    }

    /** Elenco de referência que joga na posição do posto (principal ou secundária). */
    private function squadOptions(SquadPlanSlot $slot): array
    {
        $pos = $slot->position->value;

        return Player::whereIn('id', SquadPlanner::squadPlayerIds($this->getRecord()))
            ->where(fn ($q) => $q->where('primary_position', $pos)->orWhereJsonContains('positions', $pos))
            ->with('currentClub')->orderBy('name')->limit(60)->get()
            ->mapWithKeys(fn ($p) => [$p->id => $p->displayLabel()])->all();
    }

    public function setRoleAction(): Action
    {
        return Action::make('setRole')->label('Definir')->icon(Heroicon::OutlinedPuzzlePiece)->size('sm')->link()
            ->visible(fn () => $this->canEditPlan())
            ->fillForm(fn (array $arguments) => ['tactical_role_id' => $this->slot($arguments['slot'] ?? null)->tactical_role_id])
            ->schema(fn (array $arguments) => [
                Select::make('tactical_role_id')->label('Papel tático esperado neste posto')->placeholder('Sem papel definido')
                    ->helperText('O nível mostrado de cada atleta passa a ser o da avaliação observada vigente neste papel e nesta fase.')
                    ->options(fn () => TacticalRole::where('status', '!=', CatalogStatus::Arquivado->value)
                        ->whereJsonContains('compatible_positions', $this->slot($arguments['slot'] ?? null)->position->value)
                        ->orderBy('name')->pluck('name', 'id')),
            ])
            ->action(function (array $data, array $arguments) {
                $this->slot($arguments['slot'] ?? null)->update(['tactical_role_id' => $data['tactical_role_id'] ?: null]);
                $this->getRecord()->touch();
            });
    }

    protected function getHeaderActions(): array
    {
        $plan = $this->getRecord();

        return [
            Action::make('scenario')->label('Duplicar como cenário')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn () => SquadPlanResource::canCreate())
                ->schema([TextInput::make('name')->label('Nome do cenário')->required()->default('Cenário: '.$plan->name)])
                ->action(function (array $data) use ($plan) {
                    $copy = SquadPlanner::duplicate($plan, $data['name'], auth()->id());
                    $this->redirect(SquadPlanResource::getUrl('plan', ['record' => $copy]));
                }),
            Action::make('copyPhase')->label('Copiar escalação entre estruturas')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->visible(fn () => $this->canEditPlan())
                ->schema([Select::make('direction')->label('Direção')->required()->default('com_posse>sem_posse')
                    ->options(['com_posse>sem_posse' => 'Com posse → sem posse', 'sem_posse>com_posse' => 'Sem posse → com posse'])
                    ->helperText('Mesmo posto ou mesma posição livre. Não remove ninguém do destino.')])
                ->action(function (array $data) use ($plan) {
                    [$from, $to] = explode('>', $data['direction']);
                    $n = SquadPlanner::copyPhase($plan, $from, $to);
                    $plan->touch();
                    Notification::make()->success()->title($n ? "{$n} escolha(s) copiada(s)" : 'Nada novo para copiar')->send();
                }),
            Action::make('edit')->label('Editar plano')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->visible(fn () => $this->canEditPlan())->url(SquadPlanResource::getUrl('edit', ['record' => $plan])),
        ];
    }
}
