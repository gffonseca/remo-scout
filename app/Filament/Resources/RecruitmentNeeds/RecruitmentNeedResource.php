<?php

namespace App\Filament\Resources\RecruitmentNeeds;

use App\Enums\Foot;
use App\Enums\GamePhase;
use App\Enums\Horizon;
use App\Enums\Level;
use App\Enums\NeedStatus;
use App\Enums\Position;
use App\Enums\Priority;
use App\Models\RecruitmentNeed;
use App\Models\TacticalRole;
use App\Models\User;
use App\Services\RequirementCheck;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RecruitmentNeedResource extends Resource
{
    protected static ?string $model = RecruitmentNeed::class;

    protected static ?string $slug = 'necessidades';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Mercado';

    protected static ?string $modelLabel = 'necessidade';

    protected static ?string $pluralModelLabel = 'Necessidades do elenco';

    protected static ?string $navigationLabel = 'Elenco e necessidades';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vaga')->columns(3)->schema([
                TextInput::make('title')->label('Título da necessidade')->required()->columnSpan(2)
                    ->placeholder('ex.: Lateral esquerdo que progride e sustenta duelos'),
                Select::make('status')->label('Status')->options(NeedStatus::class)->default('aberta')->required(),
                Select::make('position')->label('Posição')->options(Position::options())->required()->searchable(),
                Select::make('tactical_role_id')->label('Papel principal (busca)')->searchable()
                    ->helperText('Usado na busca de candidatos. Níveis exigidos ficam em "Requisitos por papel e fase".')
                    ->options(fn () => TacticalRole::query()->where('status', '!=', 'arquivado')->orderBy('position_group')->orderBy('name')->get()
                        ->groupBy('position_group')->map(fn ($g) => $g->pluck('name', 'id'))->toArray()),
                Select::make('specialties')->label('Especialidades desejadas')->relationship('specialties', 'name')->multiple()->preload(),
                Select::make('preferred_foot')->label('Pé')->options(Foot::class)->placeholder('Indiferente'),
                TextInput::make('age_min')->label('Idade mínima')->numeric()->minValue(15)->maxValue(45),
                TextInput::make('age_max')->label('Idade máxima')->numeric()->minValue(15)->maxValue(45)->gte('age_min'),
                // Nível exigido fica só em "Requisitos por papel e fase" (uma única fonte da regra).
                Select::make('priority')->label('Prioridade')->options(Priority::class)->default('media')->required(),
                Select::make('horizon')->label('Horizonte')->options(Horizon::class)->default('proxima_janela')->required(),
                TextInput::make('window_label')->label('Janela/temporada')->placeholder('ex.: Janela jan/2027'),
                Select::make('owner_id')->label('Responsável')->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->default(fn () => auth()->id()),
            ]),
            Section::make('Requisitos por papel e fase')
                ->description('Nível OBSERVADO mínimo por papel e fase. Potencial não conta; avaliação de outra fase não substitui; avaliação vencida fica pendente.')
                ->schema([
                    Repeater::make('roleRequirements')->relationship()->hiddenLabel()->defaultItems(0)->columns(4)
                        ->addActionLabel('Adicionar requisito de papel')
                        ->schema([
                            Select::make('tactical_role_id')->label('Papel')->required()->searchable()
                                ->options(fn () => TacticalRole::query()->where('status', '!=', 'arquivado')->orderBy('position_group')->orderBy('name')->get()
                                    ->groupBy('position_group')->map(fn ($g) => $g->pluck('name', 'id'))->toArray()),
                            Select::make('phase')->label('Fase')->options(collect(GamePhase::cases())->mapWithKeys(fn ($p) => [$p->value => $p->getLabel()]))
                                ->placeholder('Geral (sem fase)'),
                            Select::make('min_level')->label('Nível mínimo observado')->required()
                                ->options(collect(Level::cases())->reject(fn ($l) => $l === Level::NaoObservado)->mapWithKeys(fn ($l) => [$l->value => $l->getLabel()])),
                            Toggle::make('is_mandatory')->label('Obrigatório')->default(true)->inline(false),
                        ]),
                ]),
            Section::make('Requisitos')->columns(2)->schema([
                TagsInput::make('mandatory_requirements')->label('Obrigatórios')->placeholder('Enter para adicionar')
                    ->helperText('Ficam Pendentes para cada candidato até coordenador/admin confirmar com evidência (link ou anexo). Obrigatório não atendido não é compensado por ranking.'),
                TagsInput::make('desirable_requirements')->label('Desejáveis')->placeholder('Enter para adicionar'),
                Textarea::make('context')->label('Contexto (modelo de jogo, referência no elenco, alternativas)')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Condições financeiras informadas')
                ->description('Sem valores, o sistema mostra "não informado" — nunca "viável".')
                ->visible(fn () => auth()->user()->canViewFinancial())
                ->columns(3)->schema([
                    TextInput::make('budget_transfer_max')->label('Teto de transferência/empréstimo')->numeric()->minValue(0),
                    TextInput::make('budget_salary_max')->label('Teto salarial mensal')->numeric()->minValue(0),
                    Select::make('budget_currency')->label('Moeda')->options(['BRL' => 'BRL', 'USD' => 'USD', 'EUR' => 'EUR'])->default('BRL')->required(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('position')->label('Posição')->formatStateUsing(fn ($state) => $state instanceof Position ? $state->value.' — '.$state->getLabel() : $state),
                TextEntry::make('tacticalRole.name')->label('Papel desejado')->placeholder('não definido'),
                TextEntry::make('specialties.name')->label('Especialidades')->badge()->placeholder('—'),
                TextEntry::make('role_requirements_list')->label('Requisitos de papel/fase')->columnSpan(2)->listWithLineBreaks()->placeholder('nenhum')
                    ->state(fn (RecruitmentNeed $record) => RequirementCheck::roleRequirements($record)
                        ->map(fn ($r) => $r['role_name'].' · '.GamePhase::label($r['phase']).' ≥ '.$r['min_level']->value.($r['is_mandatory'] ? '' : ' (desejável)'))->all()),
                TextEntry::make('age_range')->label('Idade')->state(fn (RecruitmentNeed $r) => $r->age_min || $r->age_max ? ($r->age_min ?? '…').'–'.($r->age_max ?? '…') : 'indiferente'),
                TextEntry::make('preferred_foot')->label('Pé')->placeholder('indiferente'),
                TextEntry::make('priority')->label('Prioridade')->badge(),
                TextEntry::make('horizon')->label('Horizonte')->badge()->helperText(fn (RecruitmentNeed $r) => $r->window_label),
                TextEntry::make('mandatory_requirements')->label('Obrigatórios')->listWithLineBreaks()->bulleted()->placeholder('—')->columnSpan(2),
                TextEntry::make('desirable_requirements')->label('Desejáveis')->listWithLineBreaks()->bulleted()->placeholder('—')->columnSpan(2),
                TextEntry::make('budget')->label('Condições financeiras')->state(fn (RecruitmentNeed $r) => $r->budgetLabel())
                    ->visible(fn () => auth()->user()->canViewFinancial())->columnSpan(2),
                TextEntry::make('owner.name')->label('Responsável')->placeholder('—'),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('context')->label('Contexto')->columnSpanFull()->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tacticalRole', 'owner'])->withCount([
                'cases as cases_count' => fn ($c) => $c->whereHas('stage', fn ($s) => $s->where('is_terminal', false)),
                'cases as shortlist_count' => fn ($c) => $c->whereHas('stage', fn ($s) => $s->where('is_shortlist', true)),
            ]))
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('title')->label('Necessidade')->weight('bold')->searchable()
                    ->description(fn (RecruitmentNeed $r) => $r->tacticalRole?->name),
                TextColumn::make('position')->label('Pos.')->badge()->formatStateUsing(fn ($state) => $state?->value),
                TextColumn::make('priority')->label('Prioridade')->badge()->sortable(),
                TextColumn::make('horizon')->label('Horizonte')->badge(),
                TextColumn::make('cases_count')->label('Em análise')->alignEnd(),
                TextColumn::make('shortlist_count')->label('Shortlist')->alignEnd(),
                TextColumn::make('owner.name')->label('Responsável')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(NeedStatus::class)->default('aberta'),
                SelectFilter::make('priority')->label('Prioridade')->options(Priority::class),
            ])
            ->recordActions([ViewAction::make()->label('Abrir'), EditAction::make()->iconButton()])
            ->emptyStateHeading('Nenhuma necessidade cadastrada')
            ->emptyStateDescription('Comece pela vaga: posição, papel desejado, requisitos e horizonte.');
    }

    public static function getRelations(): array
    {
        return [RelationManagers\CasesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecruitmentNeeds::route('/'),
            'create' => Pages\CreateRecruitmentNeed::route('/create'),
            'view' => Pages\ViewRecruitmentNeed::route('/{record}'),
            'search' => Pages\SearchCandidates::route('/{record}/pesquisa'),
            'edit' => Pages\EditRecruitmentNeed::route('/{record}/edit'),
        ];
    }
}
