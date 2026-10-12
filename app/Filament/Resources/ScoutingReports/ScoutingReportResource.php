<?php

namespace App\Filament\Resources\ScoutingReports;

use App\Enums\AssessmentKind;
use App\Enums\Confidence;
use App\Enums\Level;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\Verdict;
use App\Filament\Resources\ScoutingReports\Pages;
use App\Filament\Support\VideoFields;
use App\Models\Player;
use App\Models\RecruitmentNeed;
use App\Models\ScoutingReport;
use App\Models\TacticalRole;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ScoutingReportResource extends Resource
{
    protected static ?string $model = ScoutingReport::class;

    protected static ?string $slug = 'relatorios';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Prospecção';

    protected static ?string $modelLabel = 'relatório de scout';

    protected static ?string $pluralModelLabel = 'Relatórios de scout';

    protected static ?string $navigationLabel = 'Relatórios de scout';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function criteriaItems(?string $roleId): array
    {
        $role = $roleId ? TacticalRole::find($roleId) : null;

        return collect($role?->criteriaList() ?? ['Execução do papel (geral)'])
            ->map(fn ($c) => ['criterion' => $c, 'level' => Level::NaoObservado->value])->values()->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Atleta e contexto')->columns(3)->schema([
                Select::make('player_id')->label('Atleta')->required()->searchable()->columnSpan(2)
                    ->getSearchResultsUsing(fn (string $search) => Player::query()
                        ->whereRaw('lower(unaccent_name(name)) like lower(unaccent_name(?))', ["%{$search}%"])
                        ->with('currentClub')->limit(30)->get()->mapWithKeys(fn (Player $p) => [$p->id => $p->displayLabel()]))
                    ->getOptionLabelUsing(fn ($value) => Player::with('currentClub')->find($value)?->displayLabel())
                    ->default(fn () => request()->query('player')),
                Select::make('recruitment_need_id')->label('Necessidade (opcional)')
                    ->options(fn () => RecruitmentNeed::query()->where('status', 'aberta')->orderBy('title')->pluck('title', 'id'))
                    ->default(fn () => request()->query('need')),
                Select::make('tactical_role_id')->label('Papel avaliado')->required()->searchable()->live()
                    ->options(fn () => TacticalRole::query()->where('status', '!=', 'arquivado')->orderBy('position_group')->orderBy('name')->get()
                        ->groupBy('position_group')->map(fn ($g) => $g->pluck('name', 'id'))->toArray())
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('criteria_levels', static::criteriaItems($state)))
                    ->columnSpan(2),
                Select::make('assessment_type')->label('Tipo de avaliação')->options(AssessmentKind::class)->default('observado')->required(),
                Select::make('source_type')->label('Fonte da observação')->options(ReportSource::class)->default('video')->required(),
                DatePicker::make('match_date')->label('Data do jogo')->native(false)->displayFormat('d/m/Y')->maxDate(now()),
                TextInput::make('match_label')->label('Jogo')->placeholder('ex.: Cametá x Remo'),
                Select::make('competition_id')->label('Competição')->relationship('competition', 'name')->searchable()->preload(),
                TextInput::make('formation')->label('Esquema da equipe')->placeholder('ex.: 4-3-3'),
                TextInput::make('minutes_observed')->label('Minutos observados')->numeric()->minValue(0)->maxValue(130),
            ]),
            Section::make('Avaliação por nível de competição')
                ->description('Em que nível o atleta executa o papel: A, B, C ou D. "Não observado" = sem evidência, não é nota baixa.')
                ->columns(3)->schema([
                    Select::make('level')->label('Nível geral no papel')->options(Level::class)->default('nao_observado')->required(),
                    Select::make('confidence')->label('Confiança')->options(Confidence::class)->default('media')->required(),
                    Select::make('verdict')->label('Parecer')->options(Verdict::class)->default('inconclusivo')->required(),
                    Repeater::make('criteria_levels')->label('Critérios do papel')->columnSpanFull()
                        ->schema([
                            TextInput::make('criterion')->label('Critério')->readOnly()->columnSpan(2),
                            Select::make('level')->label('Nível')->options(Level::class)->required(),
                        ])->columns(3)
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->default(fn () => static::criteriaItems(null)),
                ]),
            Section::make('Relato')->columns(2)->schema([
                Textarea::make('context')->label('Contexto tático (função na equipe, adversário, placar, condições)')->rows(3)->columnSpanFull(),
                Textarea::make('positives')->label('Pontos favoráveis')->rows(4)->required(),
                Textarea::make('negatives')->label('Pontos desfavoráveis')->rows(4)->required()
                    ->helperText('Obrigatório: avaliação sem ações negativas vira highlight.'),
                Textarea::make('open_questions')->label('Dúvidas e próxima diligência')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Vídeos')->schema([
                Repeater::make('videos')->relationship()->label('')
                    ->schema(VideoFields::make())->columns(3)
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, $livewire) => $data + ['author_id' => auth()->id(), 'player_id' => $livewire->data['player_id'] ?? null])
                    ->addActionLabel('Adicionar trecho de vídeo')->defaultItems(0)->collapsible(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('player.name')->label('Atleta')->weight('bold')
                    ->url(fn (ScoutingReport $r) => \App\Filament\Resources\Players\PlayerResource::getUrl('view', ['record' => $r->player_id])),
                TextEntry::make('tacticalRole.name')->label('Papel avaliado'),
                TextEntry::make('level')->label('Nível')->badge(),
                TextEntry::make('verdict')->label('Parecer')->badge(),
                TextEntry::make('confidence')->label('Confiança')->badge(),
                TextEntry::make('assessment_type')->label('Tipo')->badge(),
                TextEntry::make('source_type')->label('Fonte')->badge(),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('author.name')->label('Autor'),
                TextEntry::make('match_label')->label('Jogo')->placeholder('—'),
                TextEntry::make('match_date')->label('Data do jogo')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('competition.name')->label('Competição')->placeholder('—'),
                TextEntry::make('formation')->label('Esquema')->placeholder('—'),
                TextEntry::make('minutes_observed')->label('Minutos observados')->placeholder('—'),
                TextEntry::make('need.title')->label('Necessidade')->placeholder('—'),
                TextEntry::make('submitted_at')->label('Submetido em')->dateTime('d/m/Y H:i')->placeholder('rascunho'),
            ]),
            Section::make('Critérios')->schema([
                RepeatableEntry::make('criteria_levels')->label('')->columns(2)->schema([
                    TextEntry::make('criterion')->label('Critério'),
                    TextEntry::make('level')->label('Nível')->badge()
                        ->formatStateUsing(fn ($state) => Level::tryFrom((string) $state)?->getLabel() ?? $state)
                        ->color(fn ($state) => Level::tryFrom((string) $state)?->getColor() ?? 'gray'),
                ]),
            ]),
            Section::make('Relato')->columns(2)->schema([
                TextEntry::make('context')->label('Contexto')->columnSpanFull()->placeholder('—'),
                TextEntry::make('positives')->label('Pontos favoráveis'),
                TextEntry::make('negatives')->label('Pontos desfavoráveis'),
                TextEntry::make('open_questions')->label('Dúvidas / próxima diligência')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Vídeos')->schema([
                RepeatableEntry::make('videos')->label('')->columns(5)->schema([
                    TextEntry::make('match_label')->label('Jogo')->placeholder('—'),
                    TextEntry::make('tag')->label('Ação')->placeholder('—'),
                    TextEntry::make('polarity')->label('Leitura')->badge(),
                    TextEntry::make('starts_at')->label('Trecho')->formatStateUsing(fn ($state, $record) => $state.($record->ends_at ? '–'.$record->ends_at : ''))->placeholder('—'),
                    TextEntry::make('url')->label('Link')->url(fn ($record) => $record->url, true)->limit(25),
                ])->placeholder('Nenhum vídeo'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['player.currentClub', 'author', 'tacticalRole']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('player.name')->label('Atleta')->weight('bold')->searchable()
                    ->description(fn (ScoutingReport $r) => $r->player?->currentClub?->name),
                TextColumn::make('tacticalRole.name')->label('Papel')->placeholder('—'),
                TextColumn::make('level')->label('Nível')->badge()->sortable(),
                TextColumn::make('verdict')->label('Parecer')->badge(),
                TextColumn::make('confidence')->label('Confiança')->badge()->toggleable(),
                TextColumn::make('source_type')->label('Fonte')->badge()->toggleable(),
                TextColumn::make('author.name')->label('Autor'),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                Filter::make('mine')->label('Somente meus')->toggle()->query(fn (Builder $query) => $query->where('author_id', auth()->id())),
                SelectFilter::make('status')->options(ReportStatus::class),
                SelectFilter::make('verdict')->label('Parecer')->options(Verdict::class),
                SelectFilter::make('level')->label('Nível')->options(Level::class),
                SelectFilter::make('author_id')->label('Autor')->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('recruitment_need_id')->label('Necessidade')->relationship('need', 'title'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->iconButton()])
            ->emptyStateHeading('Nenhum relatório')
            ->emptyStateDescription('Abra um atleta e clique em "Novo relatório de scout".');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScoutingReports::route('/'),
            'create' => Pages\CreateScoutingReport::route('/create'),
            'view' => Pages\ViewScoutingReport::route('/{record}'),
            'edit' => Pages\EditScoutingReport::route('/{record}/edit'),
        ];
    }
}
