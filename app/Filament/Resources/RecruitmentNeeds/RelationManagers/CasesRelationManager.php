<?php

namespace App\Filament\Resources\RecruitmentNeeds\RelationManagers;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\ProspectStage;
use App\Enums\RequirementStatus;
use App\Services\RequirementCheck;
use App\Services\RequirementConfirmation;
use Filament\Forms\Components\FileUpload;
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
                    ->badge()->color(fn ($state) => RequirementCheck::color($state))
                    ->tooltip(fn (ProspectCase $c) => RequirementCheck::details($need, $c->player)),
                TextColumn::make('role_level')->label('Nível observado no papel')
                    ->state(fn (ProspectCase $c) => $need->tactical_role_id
                        ? (RequirementCheck::observedLevelLabel($c->player, $need->tactical_role_id))
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
                Action::make('requirements')->label('Requisitos')->icon(Heroicon::OutlinedClipboardDocumentCheck)->iconButton()
                    ->tooltip('Registrar evidência / confirmar requisito obrigatório')
                    ->visible(fn () => auth()->user()->canEdit() && filled($need->mandatory_requirements))
                    ->modalDescription(fn () => auth()->user()->isCoordinator()
                        ? 'Você pode confirmar (Atende / Não atende / Não se aplica) com evidência e link ou anexo.'
                        : 'Registre a evidência; a confirmação é feita por coordenador ou admin.')
                    ->schema([
                        Select::make('requirement')->label('Requisito obrigatório')->required()
                            ->options(fn () => collect($need->mandatory_requirements ?? [])->filter()->mapWithKeys(fn ($t) => [$t => $t])),
                        Select::make('status')->label('Situação')->required()->default(RequirementStatus::Pendente->value)
                            ->options(fn () => auth()->user()->isCoordinator()
                                ? collect(RequirementStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])
                                : [RequirementStatus::Pendente->value => 'Pendente (evidência para revisão)']),
                        Textarea::make('evidence')->label('Evidência')->rows(3)->required()
                            ->helperText('O que foi verificado, com quem, quando. Ex.: "Empresário confirmou por telefone em 12/10 que o atleta está livre a partir de jan/2027".'),
                        TextInput::make('evidence_url')->label('Link da evidência')->url()->maxLength(1000),
                        FileUpload::make('evidence_path')->label('Anexo')->disk('media')->directory('evidencias')->visibility('private')->maxSize(10240),
                    ])
                    ->action(function (ProspectCase $c, array $data) use ($need) {
                        try {
                            RequirementConfirmation::record(auth()->user(), $need, $c->player, $data['requirement'], RequirementStatus::from($data['status']),
                                $data['evidence'], $data['evidence_url'] ?? null, $data['evidence_path'] ?? null);
                            Notification::make()->success()->title('Requisito atualizado')->send();
                        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        }
                    }),
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
