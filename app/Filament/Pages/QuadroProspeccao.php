<?php

namespace App\Filament\Pages;

use App\Models\ProspectCase;
use App\Models\ProspectStage;
use App\Models\RecruitmentNeed;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Quadro de prospecção (kanban). Mover exige motivo; ação alternativa por teclado/formulário, sem arrastar. */
class QuadroProspeccao extends Page
{
    protected string $view = 'filament.pages.quadro-prospeccao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Prospecção';

    protected static ?string $navigationLabel = 'Quadro de prospecção';

    protected static ?string $title = 'Quadro de prospecção';

    protected static ?int $navigationSort = 1;

    #[Url]
    public ?string $need = null;

    #[Url]
    public ?string $owner = null;

    #[Url]
    public bool $showArchived = false;

    public function getNeeds(): array
    {
        return RecruitmentNeed::query()->where('status', '!=', 'fechada')->orderBy('title')->pluck('title', 'id')->all();
    }

    public function getOwners(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function getColumns()
    {
        $stages = ProspectStage::ordered()->when(! $this->showArchived, fn ($c) => $c->where('is_terminal', false));
        $cases = ProspectCase::query()
            ->with(['player.currentClub', 'need', 'owner'])
            ->when($this->need, fn ($q) => $q->where('recruitment_need_id', $this->need))
            ->when($this->owner, fn ($q) => $q->where('owner_id', $this->owner))
            ->orderByRaw("case priority when 'alta' then 1 when 'media' then 2 else 3 end")
            ->orderByRaw('due_on is null, due_on')
            ->get()
            ->groupBy('prospect_stage_id');

        return $stages->map(fn ($s) => ['stage' => $s, 'cases' => $cases->get($s->id, collect())]);
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->label('Mover')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->size('xs')->link()
            ->visible(fn () => auth()->user()->canEdit())
            ->modalHeading(fn (array $arguments) => 'Mover '.(ProspectCase::with('player')->find($arguments['case'] ?? null)?->player?->name ?? 'candidato'))
            ->fillForm(function (array $arguments) {
                $c = ProspectCase::find($arguments['case'] ?? null);

                return ['stage_id' => $c?->prospect_stage_id, 'next_action' => $c?->next_action, 'due_on' => $c?->due_on, 'owner_id' => $c?->owner_id];
            })
            ->schema([
                Select::make('stage_id')->label('Etapa')->options(fn () => ProspectStage::ordered()->pluck('name', 'id'))->required(),
                Textarea::make('reason')->label('Motivo')->rows(2)->required(),
                TextInput::make('next_action')->label('Próxima ação'),
                DatePicker::make('due_on')->label('Prazo')->native(false)->displayFormat('d/m/Y'),
                Select::make('owner_id')->label('Responsável')->options(fn () => $this->getOwners()),
            ])
            ->action(function (array $data, array $arguments) {
                $c = ProspectCase::findOrFail($arguments['case']);
                $c->owner_id = $data['owner_id'] ?? $c->owner_id;
                $c->moveTo(ProspectStage::findOrFail($data['stage_id']), $data['reason'], auth()->id(), $data['next_action'] ?? null, $data['due_on'] ?? null);
                Notification::make()->success()->title('Candidato movido')->send();
            });
    }
}
