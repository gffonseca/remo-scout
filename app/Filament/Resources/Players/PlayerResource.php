<?php

namespace App\Filament\Resources\Players;

use App\Enums\Confirmation;
use App\Enums\ContractStatus;
use App\Enums\MarketStatus;
use App\Enums\DataOrigin;
use App\Enums\Foot;
use App\Enums\Level;
use App\Enums\Position;
use App\Filament\Resources\Players\Pages;
use App\Filament\Resources\Players\RelationManagers;
use App\Models\Agent;
use App\Models\Club;
use App\Models\Player;
use App\Models\Specialty;
use App\Models\TacticalRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use UnitEnum;

class PlayerResource extends Resource
{
    protected static ?string $model = Player::class;

    protected static ?string $slug = 'atletas';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Jogadores';

    protected static ?string $modelLabel = 'atleta';

    protected static ?string $pluralModelLabel = 'Atletas';

    protected static ?string $navigationLabel = 'Jogadores';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'full_name', 'cbf_id'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Posição' => $record->primary_position?->value,
            'Clube' => $record->currentClub?->name,
            'Idade' => $record->age,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidade')
                ->description('Nome de jogo é obrigatório. Campos desconhecidos ficam em branco (não avaliado ≠ zero).')
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label('Nome de jogo')->required()->maxLength(120),
                    TextInput::make('full_name')->label('Nome completo')->maxLength(200),
                    TagsInput::make('aliases')->label('Outros nomes / grafias')->placeholder('Enter para adicionar'),
                    DatePicker::make('birth_date')->label('Nascimento')->native(false)->displayFormat('d/m/Y')->maxDate(now()->subYears(15)),
                    TextInput::make('nationality')->label('Nacionalidade')->default('Brasil')->maxLength(60),
                    TextInput::make('second_nationality')->label('2ª nacionalidade')->maxLength(60),
                    TextInput::make('cbf_id')->label('ID CBF')->helperText('Da súmula/BID. Chave mestra de identidade quando existir.')
                        ->unique(ignoreRecord: true)->maxLength(32)->regex('/^[0-9]+$/')->validationMessages(['regex' => 'Somente números.']),
                    Select::make('preferred_foot')->label('Pé preferencial')->options(Foot::class)->default(Foot::NaoInformado->value)->required(),
                    TextInput::make('height_cm')->label('Altura (cm)')->numeric()->minValue(140)->maxValue(215),
                    FileUpload::make('photo_path')->label('Foto de perfil')->disk('media')->directory('players')->visibility('private')
                        ->image()->imageEditor()->imageCropAspectRatio('1:1')->maxSize(3072)
                        ->helperText('Upload próprio tem prioridade sobre a foto vinda de fonte externa (Transfermarkt/Sofascore).'),
                ]),
            Section::make('Posições')
                ->columns(2)
                ->schema([
                    Select::make('primary_position')->label('Posição principal')->options(Position::options())->searchable(),
                    Select::make('positions')->label('Outras posições')->options(Position::options())->multiple()->searchable(),
                ]),
            Section::make('Clube e links externos')
                ->description('Links abrem o portal para consulta manual. O sistema não coleta dados desses sites por aqui.')
                ->columns(3)
                ->schema([
                    Select::make('current_club_id')->label('Clube atual')->relationship('currentClub', 'name')->searchable()->preload(false)
                        ->createOptionForm([
                            TextInput::make('name')->label('Nome')->required(),
                            TextInput::make('country')->label('País')->default('Brasil')->required(),
                            TextInput::make('state')->label('UF')->maxLength(2),
                        ]),
                    Select::make('rights_holder_club_id')->label('Clube detentor dos direitos')->relationship('rightsHolderClub', 'name')->searchable()
                        ->helperText('Se diferente do clube atual (ex.: emprestado).'),
                    Select::make('data_origin')->label('Origem do cadastro')->options(DataOrigin::class)->default(DataOrigin::Manual->value)->required()
                        ->disabled(fn (?Player $record) => $record !== null)->dehydrated(),
                    TextInput::make('sofascore_url')->label('Sofascore')->url()->maxLength(255),
                    TextInput::make('wyscout_url')->label('Wyscout')->url()->maxLength(255),
                    TextInput::make('transfermarkt_url')->label('Transfermarkt')->url()->maxLength(255),
                    TextInput::make('ogol_url')->label('ogol / zerozero')->url()->maxLength(255),
                    TextInput::make('other_url')->label('Outro link')->url()->maxLength(255),
                ]),
            Section::make('Mercado e representação')->columns(3)->schema([
                Select::make('agent_id')->label('Empresário / agência')->relationship('agent', 'name')->searchable()->preload(false)
                    ->getOptionLabelFromRecordUsing(fn (Agent $a) => $a->label())
                    ->createOptionForm(\App\Filament\Resources\Agents\AgentResource::formFields())
                    ->helperText('Contato pelo cadastro do empresário.'),
                TextInput::make('agent_name')->label('Empresário (texto livre, se sem cadastro)'),
                Select::make('market_status')->label('Situação de mercado')->options(MarketStatus::class)->default('desconhecido')->required(),
                TextInput::make('economic_rights_note')->label('Direitos econômicos (observado)')->placeholder('ex.: 70% clube X / 30% atleta')->columnSpan(2),
                Select::make('eu_passport')->label('Passaporte comunitário (documento)')->options([1 => 'Sim — documento conferido', 0 => 'Não possui'])->placeholder('Não informado')->live()
                    ->helperText('Cidadania declarada por fonte não é passaporte. "Sim" exige descrever o documento conferido.'),
                Textarea::make('eu_passport_evidence')->label('Documento conferido')->rows(2)
                    ->visible(fn ($get) => (string) $get('eu_passport') === '1')->required(fn ($get) => (string) $get('eu_passport') === '1')
                    ->placeholder('ex.: cópia do passaporte italiano nº final 123, validade 2031, recebida do empresário em 10/10/2026'),
                TagsInput::make('languages')->label('Idiomas')->placeholder('ex.: português, espanhol'),
                \Filament\Forms\Components\Toggle::make('is_priority')->label('Lista prioritária')->inline(false),
                TextInput::make('priority_note')->label('Nota da prioridade'),
            ]),
            Section::make('Contato do atleta')
                ->description('Dado pessoal: visível só a perfis com edição. Use apenas para fins de recrutamento; registre a origem do contato.')
                ->visible(fn () => auth()->user()->canEdit())
                ->columns(4)->schema([
                    TextInput::make('phone')->label('Telefone')->tel()->maxLength(40),
                    TextInput::make('whatsapp')->label('WhatsApp')->tel()->maxLength(40),
                    TextInput::make('email')->label('E-mail')->email()->maxLength(150),
                    TextInput::make('contact_note')->label('Origem/autorização do contato')->placeholder('ex.: passado pelo empresário'),
                ]),
            Section::make('Faixa salarial e pedida')
                ->description('Visível só com permissão financeira. Cada alteração gera registro datado no histórico de mercado. Sem valor = "não informada".')
                ->visible(fn () => auth()->user()->canViewFinancial())
                ->columns(4)->schema([
                    TextInput::make('salary_min')->label('Salário mínimo da faixa')->numeric()->minValue(0),
                    TextInput::make('salary_max')->label('Salário máximo da faixa')->numeric()->minValue(0)->gte('salary_min'),
                    Select::make('salary_currency')->label('Moeda')->options(['BRL' => 'BRL', 'USD' => 'USD', 'EUR' => 'EUR', 'ARS' => 'ARS', 'UYU' => 'UYU'])->default('BRL')->required(),
                    Select::make('salary_periodicity')->label('Periodicidade')->options(['mensal' => 'Mensal', 'anual' => 'Anual'])->default('mensal')->required(),
                    Select::make('salary_confirmation')->label('Confirmação')->options(Confirmation::class)->default('nao_verificado')->required(),
                    DatePicker::make('salary_observed_on')->label('Informação de')->native(false)->displayFormat('d/m/Y'),
                    TextInput::make('asking_price')->label('Pedida de transferência/empréstimo')->numeric()->minValue(0),
                    Select::make('asking_price_currency')->label('Moeda da pedida')->options(['BRL' => 'BRL', 'USD' => 'USD', 'EUR' => 'EUR'])->default('BRL')->required(),
                ]),
            Section::make('Observações')->schema([
                Textarea::make('notes')->label('Notas gerais')->rows(3),
                Toggle::make('ignore_duplicates')->label('Revisei os possíveis homônimos e confirmo que é outro atleta')
                    ->visible(fn (string $operation) => $operation === 'create')->dehydrated(false),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Tabs::make('perfil')->columnSpanFull()->persistTabInQueryString()->tabs([
                \Filament\Schemas\Components\Tabs\Tab::make('Visão geral')->icon(Heroicon::OutlinedUser)->schema([
                    \Filament\Schemas\Components\View::make('scout.player-profile'),
                ]),
                \Filament\Schemas\Components\Tabs\Tab::make('Dados cadastrais')->icon(Heroicon::OutlinedIdentification)->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('name')->label('Nome de jogo')->weight('bold')->size('lg'),
                TextEntry::make('full_name')->label('Nome completo')->placeholder('não informado'),
                TextEntry::make('age')->label('Idade')->placeholder('não informada')
                    ->formatStateUsing(fn ($state, Player $record) => $state.' anos ('.$record->birth_date->format('d/m/Y').')'),
                TextEntry::make('cbf_id')->label('ID CBF')->placeholder('não informado')->copyable(),
                TextEntry::make('primary_position')->label('Posição')->placeholder('não informada')
                    ->formatStateUsing(fn ($state) => $state instanceof Position ? $state->value.' — '.$state->getLabel() : $state),
                TextEntry::make('positions')->label('Outras posições')->badge()->placeholder('—'),
                TextEntry::make('preferred_foot')->label('Pé')->badge(),
                TextEntry::make('height_cm')->label('Altura')->suffix(' cm')->placeholder('não informada'),
                TextEntry::make('currentClub.name')->label('Clube atual')->placeholder('não informado')
                    ->helperText(fn (Player $record) => \App\Support\PlayerInsight::squadNote($record)),
                TextEntry::make('latestContract.status')->label('Situação contratual')->badge()->placeholder('sem observação'),
                TextEntry::make('latestContract.contract_end')->label('Fim de contrato (observado)')->date('d/m/Y')->placeholder('não informado')
                    ->helperText(fn (Player $r) => $r->latestContract ? $r->latestContract->confirmation->getLabel().' · '.$r->latestContract->observed_on->format('d/m/Y') : null),
                TextEntry::make('data_origin')->label('Origem')->badge(),
                TextEntry::make('links')->label('Links externos')->columnSpan(2)->html()
                    ->state(fn (Player $r) => static::linksHtml($r)),
                TextEntry::make('nationality')->label('Nacionalidade')->placeholder('não informada'),
                TextEntry::make('agent.name')->label('Empresário')->placeholder(fn (Player $r) => $r->agent_name ?: 'não informado')
                    ->formatStateUsing(fn ($state, Player $r) => $r->agent?->label())
                    ->helperText(fn (Player $r) => $r->agent ? collect([$r->agent->phone, $r->agent->email])->filter()->implode(' · ') : null)
                    ->url(fn (Player $r) => $r->agent ? \App\Filament\Resources\Agents\AgentResource::getUrl('index', ['tableSearch' => $r->agent->name]) : null),
                TextEntry::make('market_status')->label('Situação de mercado')->badge(),
                TextEntry::make('contact')->label('Contato do atleta')->visible(fn () => auth()->user()->canEdit())
                    ->state(fn (Player $r) => collect([$r->phone, $r->whatsapp ? 'WhatsApp '.$r->whatsapp : null, $r->email])->filter()->implode(' · ') ?: null)
                    ->placeholder('não informado')->helperText(fn (Player $r) => $r->contact_note)
                    ->url(fn (Player $r) => ($d = preg_replace('/\D/', '', (string) $r->whatsapp)) ? 'https://wa.me/'.(strlen($d) <= 11 ? '55'.$d : $d) : null, true),
                TextEntry::make('rightsHolderClub.name')->label('Detentor dos direitos')->placeholder('igual ao atual / não informado'),
                TextEntry::make('economic_rights_note')->label('Direitos econômicos')->placeholder('não informado'),
                TextEntry::make('salary')->label('Faixa salarial')->state(fn (Player $r) => $r->salaryRangeLabel())
                    ->helperText(fn (Player $r) => $r->salary_observed_on ? 'informação de '.$r->salary_observed_on->format('d/m/Y') : null)
                    ->visible(fn () => auth()->user()->canViewFinancial()),
                TextEntry::make('asking_price')->label('Pedida')->placeholder('não informada')
                    ->formatStateUsing(fn ($state, Player $r) => $r->asking_price_currency.' '.number_format((float) $state, 0, ',', '.'))
                    ->visible(fn () => auth()->user()->canViewFinancial()),
                TextEntry::make('eu_passport')->label('Passaporte comunitário')->formatStateUsing(fn ($state) => $state ? 'Sim (documento conferido)' : 'Não possui')->placeholder('não informado')
                    ->helperText(fn ($record) => $record->eu_passport ? $record->eu_passport_evidence.' — '.($record->eu_passport_confirmed_at?->format('d/m/Y') ?? '') : null),
                TextEntry::make('citizenships')->label('Cidadanias declaradas (fontes)')->placeholder('—')
                    ->state(fn ($record) => collect($record->citizenships ?? [])->map(fn ($c) => $c['country'].' · '.$c['source'].($c['at'] ? ' '.$c['at'] : ''))->all())->listWithLineBreaks(),
                TextEntry::make('languages')->label('Idiomas')->badge()->placeholder('não informado'),
                TextEntry::make('priority_note')->label('Lista prioritária')->visible(fn (Player $r) => $r->is_priority)->placeholder('sim'),
                TextEntry::make('notes')->label('Notas')->columnSpanFull()->placeholder('—'),
            ]),
                ]),
            ]),
        ]);
    }

    public static function linksHtml(Player $r): HtmlString
    {
        $links = array_filter([
            'Sofascore' => $r->sofascore_url, 'Wyscout' => $r->wyscout_url, 'Transfermarkt' => $r->transfermarkt_url,
            'ogol' => $r->ogol_url, 'Outro' => $r->other_url,
        ]);
        if (! $links) {
            return new HtmlString('<span class="text-gray-400">nenhum link cadastrado</span>');
        }

        return new HtmlString(collect($links)->map(fn ($u, $l) => '<a class="underline text-primary-400" target="_blank" rel="noopener noreferrer" href="'.e($u).'">'.e($l).' ↗</a>')->implode(' · '));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['currentClub', 'latestContract', 'agent', 'roleAssessments.tacticalRole', 'submittedReports' => fn ($r) => $r->visibleTo(auth()->user())])->withCount('videoReferences')->withCount(['scoutingReports as reports_count' => fn ($r) => $r->where('status', 'submetido')]))
            ->defaultSort('updated_at', 'desc')
            ->persistFiltersInSession()
            ->paginated([25, 50, 100])->defaultPaginationPageOption(25)
            ->persistSortInSession()
            ->columns([
                TextColumn::make('is_priority')->label('')->state(fn (Player $r) => $r->is_priority ? '★' : null)->color('warning')->weight('bold')->tooltip(fn (Player $r) => $r->is_priority ? ($r->priority_note ?: 'Lista prioritária') : null)->width('1%'),
                ImageColumn::make('avatar')->label('')->state(fn (Player $r) => $r->photoUrl())->circular()->imageSize(32)
                    ->defaultImageUrl(asset('images/player-silhouette.svg'))->extraImgAttributes(['loading' => 'lazy', 'referrerpolicy' => 'no-referrer', 'onerror' => "this.onerror=null;this.src='/images/player-silhouette.svg'"])->width('1%'),
                TextColumn::make('name')->label('Atleta')->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                    ->whereRaw('lower(unaccent_name(name)) like lower(unaccent_name(?))', ["%{$search}%"])
                    ->orWhereRaw('lower(unaccent_name(coalesce(full_name, \'\'))) like lower(unaccent_name(?))', ["%{$search}%"])
                    ->orWhere('cbf_id', $search)))
                    ->sortable()->weight('bold')
                    ->description(fn (Player $r) => $r->full_name && $r->full_name !== $r->name ? $r->full_name : null),
                TextColumn::make('nationality')->label('Nação')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)->limit(12),
                TextColumn::make('primary_position')->label('Posição')->sortable()
                    ->formatStateUsing(fn ($state, Player $r) => collect([$state instanceof Position ? $state->value : $state])->merge($r->positions ?? [])->unique()->implode(', '))
                    ->description(fn (Player $r) => \App\Support\PlayerInsight::bestObservedAssessment($r)?->tacticalRole?->name)
                    ->placeholder('n/i'),
                TextColumn::make('birth_date')->label('Idade')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state) => $state?->age)->placeholder('—'),
                TextColumn::make('preferred_foot')->label('Pé')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currentClub.name')->label('Clube')->searchable()->sortable()->placeholder('—')
                    ->html()->formatStateUsing(fn ($state, Player $r) => \App\Support\Media::crestTag($r->currentClub).e($state))
                    ->description(fn (Player $r) => $r->currentClub?->current_division),
                TextColumn::make('latestContract.status')->label('Contrato')->badge()->placeholder('sem obs.')->toggleable()
                    ->description(fn (Player $r) => $r->latestContract?->contract_end ? 'até '.$r->latestContract->contract_end->format('m/Y') : null),
                TextColumn::make('level_now')->label('Nível')->alignCenter()->badge()
                    ->state(fn (Player $r) => \App\Support\PlayerInsight::currentLevel($r)?->value ?? 'n/o')
                    ->color(fn ($state) => \App\Enums\Level::tryFrom($state)?->getColor() ?? 'gray')
                    ->tooltip('Melhor nível observado (A–D). n/o = não observado'),
                TextColumn::make('level_pot')->label('Potencial')->alignCenter()->badge()->toggleable()
                    ->state(fn (Player $r) => \App\Support\PlayerInsight::potentialLevel($r)?->value ?? 'n/o')
                    ->color(fn ($state) => \App\Enums\Level::tryFrom($state)?->getColor() ?? 'gray'),
                TextColumn::make('market_status')->label('Mercado')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('salary_max')->label('Faixa salarial')->toggleable()
                    ->state(fn (Player $r) => $r->salaryRangeLabel())->color(fn (Player $r) => $r->salary_min === null && $r->salary_max === null ? 'gray' : null)
                    ->visible(fn () => auth()->user()->canViewFinancial()),
                TextColumn::make('agent.name')->label('Empresário')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recommendation')->label('Recomendação')->badge()
                    ->state(fn (Player $r) => \App\Support\PlayerInsight::recommendation($r)['label'])
                    ->color(fn (Player $r) => \App\Support\PlayerInsight::recommendation($r)['color']),
                TextColumn::make('knowledge')->label('Conhecimento')->badge()->toggleable()
                    ->state(fn (Player $r) => \App\Support\PlayerInsight::knowledge($r)['label'])
                    ->color(fn (Player $r) => \App\Support\PlayerInsight::knowledge($r)['color']),
                TextColumn::make('reports_count')->label('Relatórios')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('data_origin')->label('Origem')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cbf_id')->label('ID CBF')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                TextColumn::make('updated_at')->label('Atualizado')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('position')->label('Posição (principal ou outra)')->options(Position::options())->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $codes) => $q->where(function ($w) use ($codes) {
                        foreach ($codes as $c) {
                            $w->orWhere('primary_position', $c)->orWhereJsonContains('positions', $c);
                        }
                    }))),
                Filter::make('age')->label('Idade')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('min')->label('Idade mín.')->numeric(),
                            TextInput::make('max')->label('Idade máx.')->numeric(),
                        ]),
                        Select::make('missing')->label('Sem nascimento informado')->options(['incluir' => 'Incluir (pendente de informação)', 'excluir' => 'Excluir'])->default('incluir'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['min']) && blank($data['max'])) {
                            return $query;
                        }

                        return $query->where(function ($w) use ($data) {
                            $w->where(function ($a) use ($data) {
                                if (filled($data['min'])) {
                                    $a->whereDate('birth_date', '<=', now()->subYears((int) $data['min']));
                                }
                                if (filled($data['max'])) {
                                    $a->whereDate('birth_date', '>', now()->subYears((int) $data['max'] + 1));
                                }
                            });
                            if (($data['missing'] ?? 'incluir') === 'incluir') {
                                $w->orWhereNull('birth_date');
                            }
                        });
                    })
                    ->indicateUsing(fn (array $data) => filled($data['min']) || filled($data['max'])
                        ? 'Idade '.($data['min'] ?: '…').'–'.($data['max'] ?: '…').(($data['missing'] ?? 'incluir') === 'incluir' ? ' (+ sem nascimento)' : '') : null),
                Filter::make('is_priority')->label('Lista prioritária')->toggle()->query(fn (Builder $query) => $query->where('is_priority', true)),
                SelectFilter::make('market_status')->label('Situação de mercado')->options(MarketStatus::class)->multiple(),
                SelectFilter::make('agent_id')->label('Empresário')->relationship('agent', 'name')->searchable(),
                Filter::make('salary_cap')->label('Salário até')
                    ->visible(fn () => auth()->user()->canViewFinancial())
                    ->schema([
                        TextInput::make('max')->label('Salário mensal até (BRL)')->numeric(),
                        Select::make('missing')->label('Sem faixa informada')->options(['incluir' => 'Incluir (pendente)', 'excluir' => 'Excluir'])->default('incluir'),
                    ])
                    ->query(fn (Builder $query, array $data) => blank($data['max'] ?? null) ? $query : $query->where(function ($w) use ($data) {
                        $w->where(fn ($a) => $a->where('salary_currency', 'BRL')->where('salary_periodicity', 'mensal')->whereRaw('coalesce(salary_min, salary_max) <= ?', [(float) $data['max']]));
                        if (($data['missing'] ?? 'incluir') === 'incluir') {
                            $w->orWhere(fn ($n) => $n->whereNull('salary_min')->whereNull('salary_max'));
                        }
                    }))
                    ->indicateUsing(fn (array $data) => filled($data['max'] ?? null) ? 'Salário ≤ R$ '.number_format((float) $data['max'], 0, ',', '.').(($data['missing'] ?? 'incluir') === 'incluir' ? ' (+ não informados)' : '') : null),
                SelectFilter::make('preferred_foot')->label('Pé')->options(Foot::class)->multiple(),
                SelectFilter::make('division')->label('Divisão do clube atual')->multiple()
                    ->options(['Série A' => 'Série A', 'Série B' => 'Série B', 'Série C' => 'Série C', 'Série D' => 'Série D', 'Sem divisão' => 'Sem divisão nacional', 'Exterior' => 'Exterior'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereHas('currentClub', fn ($c) => $c->whereIn('current_division', $v)))),
                SelectFilter::make('current_club_id')->label('Clube')->relationship('currentClub', 'name')->searchable()->multiple(),
                SelectFilter::make('contract_status')->label('Situação contratual (última obs.)')->options(ContractStatus::class)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('latestContract', fn ($c) => $c->where('status', $v)))),
                Filter::make('contract_end_before')->label('Contrato termina até')
                    ->schema([DatePicker::make('until')->label('Contrato termina até')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query->when($data['until'] ?? null, fn ($q, $d) => $q->whereHas('latestContract', fn ($c) => $c->whereDate('contract_end', '<=', $d)))),
                SelectFilter::make('role')->label('Papel avaliado')->multiple()->searchable()
                    ->options(fn () => TacticalRole::query()->where('status', '!=', 'arquivado')->orderBy('position_group')->orderBy('name')->get()->mapWithKeys(fn ($r) => [$r->id => $r->label()]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $ids) => $q->whereHas('roleAssessments', fn ($a) => $a->whereIn('tactical_role_id', $ids)->where('level', '!=', 'nao_observado')))),
                Filter::make('role_level')->label('Nível mínimo observado no papel')
                    ->schema([Select::make('level')->label('Nível observado mínimo (avaliação vigente, qualquer papel; potencial não conta)')->options(['A' => 'A', 'B' => 'B ou melhor', 'C' => 'C ou melhor', 'D' => 'D ou melhor'])])
                    ->query(function (Builder $query, array $data) {
                        $lvl = $data['level'] ?? null;
                        if (! $lvl) {
                            return $query;
                        }
                        $ok = array_slice(['A', 'B', 'C', 'D'], 0, array_search($lvl, ['A', 'B', 'C', 'D']) + 1);

                        return $query->whereHas('roleAssessments', fn ($a) => $a->currentObserved()->whereIn('level', $ok));
                    }),
                SelectFilter::make('specialty')->label('Especialidade')->multiple()
                    ->options(fn () => Specialty::query()->where('status', '!=', 'arquivado')->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $ids) => $q->whereHas('specialtyAssessments', fn ($a) => $a->whereIn('specialty_id', $ids)->where('level', '!=', 'nao_observado')))),
                SelectFilter::make('data_origin')->label('Origem')->options(DataOrigin::class),
                \Filament\Tables\Filters\TernaryFilter::make('provisional')->label('Provisórios de partidas')
                    ->placeholder('Mostrar todos')->trueLabel('Só provisórios')->falseLabel('Ocultar')->default(false)
                    ->queries(true: fn (Builder $q) => $q->provisional(), false: fn (Builder $q) => $q->whereNot(fn ($w) => $w->provisional()),
                        blank: fn (Builder $q) => $q),
                Filter::make('no_reports')->label('Sem relatório submetido')->toggle()
                    ->query(fn (Builder $query) => $query->whereDoesntHave('scoutingReports', fn ($r) => $r->where('status', 'submetido'))),
                TrashedFilter::make(),
            ], layout: \Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->recordActions([
                ViewAction::make()->label('Abrir'),
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Filament\Actions\AddPlayersToNeedBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nenhum atleta encontrado')
            ->emptyStateDescription('Cadastre um atleta ou importe a planilha do departamento.')
            ->emptyStateActions([
                Action::make('import')->label('Importar planilha')->url(fn () => \App\Filament\Pages\ImportarPlanilha::getUrl())->icon(Heroicon::OutlinedArrowUpTray),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RoleAssessmentsRelationManager::class,
            RelationManagers\SpecialtyAssessmentsRelationManager::class,
            RelationManagers\ScoutingReportsRelationManager::class,
            RelationManagers\VideoReferencesRelationManager::class,
            RelationManagers\ContractObservationsRelationManager::class,
            RelationManagers\MarketObservationsRelationManager::class,
            RelationManagers\ProspectCasesRelationManager::class,
            RelationManagers\RosterSpellsRelationManager::class,
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlayers::route('/'),
            'create' => Pages\CreatePlayer::route('/create'),
            'view' => Pages\ViewPlayer::route('/{record}'),
            'edit' => Pages\EditPlayer::route('/{record}/edit'),
        ];
    }
}
