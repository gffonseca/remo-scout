<?php

namespace App\Filament\Resources\RecruitmentNeeds\RelationManagers;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\ProspectStage;
use App\Services\RequirementCheck;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CasesRelationManager extends RelationManager
{
    protected static string $relationship = 'cases';

    protected static ?string $title = 'Candidatos e shortlist';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $need = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['player.currentClub', 'player.latestContract', 'player.roleAssessments', 'stage', 'owner']))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('shortlist_rank is null, shortlist_rank')->orderBy('updated_at', 'desc'))
            ->columns([
                TextColumn::make('shortlist_rank')->label('#')->placeholder('—')->alignCenter(),
                TextColumn::make('player.name')->label('Atleta')->weight('bold')
                    ->description(fn (ProspectCase $c) => collect([$c->player->primary_position?->value, $c->player->age ? $c->player->age.' anos' : null, $c->player->currentClub?->name])->filter()->implode(' · '))
                    ->url(fn (ProspectCase $c) => PlayerResource::getUrl('view', ['record' => $c->player_id])),
                TextColumn::make('stage.name')->label('Etapa')->badge()->color(fn (ProspectCase $c) => $c->stage->color),
                TextColumn::make('requirements')->label('Requisitos obrigatórios')
                    ->state(fn (ProspectCase $c) => RequirementCheck::summary($need, $c->player))
                    ->badge()->color(fn ($state) => str_starts_with($state, 'Reprova') ? 'danger' : (str_starts_with($state, 'Pendente') ? 'warning' : 'success'))
                    ->tooltip(fn (ProspectCase $c) => RequirementCheck::details($need, $c->player)),
                TextColumn::make('role_level')->label('Nível no papel')
                    ->state(fn (ProspectCase $c) => $need->tactical_role_id
                        ? ($c->player->roleAssessments->where('tactical_role_id', $need->tactical_role_id)->sortByDesc('updated_at')->first()?->level?->getLabel() ?? 'Não avaliado')
                        : '—'),
                TextColumn::make('player.latestContract.status')->label('Contrato')->badge()->placeholder('sem obs.'),
                TextColumn::make('priority')->label('Prioridade')->badge(),
                TextColumn::make('owner.name')->label('Responsável')->placeholder('—'),
                TextColumn::make('next_action')->label('Próxima ação')->placeholder('—')->limit(30),
                TextColumn::make('due_on')->label('Prazo')->date('d/m')->placeholder('—')
                    ->color(fn (ProspectCase $c) => $c->due_on && $c->due_on->isPast() ? 'danger' : null),
            ])
            ->filters([
                Filter::make('active')->label('Ocultar arquivados')->toggle()->default()
                    ->query(fn (Builder $query) => $query->whereHas('stage', fn ($s) => $s->where('is_terminal', false))),
                Filter::make('shortlist')->label('Somente shortlist')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('stage', fn ($s) => $s->where('is_shortlist', true))),
            ])
            ->headerActions([
                Action::make('add')->label('Adicionar candidato')->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => auth()->user()->canEdit())
                    ->schema([
                        Select::make('player_id')->label('Atleta')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $s) => Player::query()->whereRaw('lower(unaccent_name(name)) like lower(unaccent_name(?))', ["%{$s}%"])->with('currentClub')->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => $p->displayLabel()])),
                        Textarea::make('reason')->label('Motivo')->rows(2),
                    ])
                    ->action(function (array $data) use ($need) {
                        $c = ProspectCase::open($need, Player::findOrFail($data['player_id']), auth()->id(), $data['reason'] ?? null);
                        Notification::make()->success()->title($c->wasRecentlyCreated ? 'Candidato adicionado' : 'Atleta já estava nesta necessidade')->send();
                    }),
            ])
            ->recordActions([
                Action::make('move')->label('Mover')->icon(Heroicon::OutlinedArrowsRightLeft)->iconButton()
                    ->visible(fn () => auth()->user()->canEdit())
                    ->fillForm(fn (ProspectCase $c) => ['stage_id' => $c->prospect_stage_id, 'next_action' => $c->next_action, 'due_on' => $c->due_on])
                    ->schema([
                        Select::make('stage_id')->label('Etapa')->options(fn () => ProspectStage::ordered()->pluck('name', 'id'))->required(),
                        Textarea::make('reason')->label('Motivo da mudança')->rows(2)->required(),
                        TextInput::make('next_action')->label('Próxima ação'),
                        DatePicker::make('due_on')->label('Prazo')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->action(function (ProspectCase $c, array $data) {
                        $c->moveTo(ProspectStage::findOrFail($data['stage_id']), $data['reason'], auth()->id(), $data['next_action'] ?? null, $data['due_on'] ?? null);
                        Notification::make()->success()->title('Etapa atualizada')->send();
                    }),
                Action::make('rank')->label('Ordem na shortlist')->icon(Heroicon::OutlinedHashtag)->iconButton()
                    ->visible(fn (ProspectCase $c) => auth()->user()->canEdit() && $c->stage->is_shortlist)
                    ->fillForm(fn (ProspectCase $c) => ['shortlist_rank' => $c->shortlist_rank, 'justification' => $c->justification])
                    ->schema([
                        TextInput::make('shortlist_rank')->label('Posição na shortlist')->numeric()->minValue(1)->required(),
                        Textarea::make('justification')->label('Justificativa')->rows(3)->required(),
                    ])
                    ->action(fn (ProspectCase $c, array $data) => $c->update($data)),
            ])
            ->emptyStateHeading('Nenhum candidato ainda')
            ->emptyStateDescription('Use "Buscar candidatos" no topo ou adicione um atleta diretamente.');
    }
}
