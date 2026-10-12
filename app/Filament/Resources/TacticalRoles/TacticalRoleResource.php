<?php

namespace App\Filament\Resources\TacticalRoles;

use App\Enums\CatalogStatus;
use App\Enums\Position;
use App\Filament\Resources\TacticalRoles\RelationManagers\VersionsRelationManager;
use App\Filament\Support\CatalogActions;
use App\Models\TacticalRole;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TacticalRoleResource extends Resource
{
    protected static ?string $model = TacticalRole::class;

    protected static ?string $slug = 'papeis';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Papéis e especialidades';

    protected static ?string $modelLabel = 'papel tático';

    protected static ?string $pluralModelLabel = 'Papéis táticos';

    protected static ?string $navigationLabel = 'Papéis táticos';

    protected static ?int $navigationSort = 1;

    public const GROUPS = ['Goleiro', 'Zagueiro', 'Lateral/ala', 'Volante', 'Meio-campista central', 'Meia ofensivo', 'Ponta/meia de lado', 'Atacante'];

    /** Fases do modelo (decisão 12/10/2026). Transições são comportamentos do papel. */
    public const PHASES = ['com_posse' => 'Com posse', 'sem_posse' => 'Sem posse', 'bola_parada' => 'Bola parada'];

    /** Rótulos de valores antigos ainda presentes em versões do catálogo. */
    public const LEGACY_PHASES = ['transicao_ofensiva' => 'Transição ofensiva (comportamento)', 'transicao_defensiva' => 'Transição defensiva (comportamento)'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')->columns(3)->schema([
                TextInput::make('name')->label('Nome do papel')->required()->maxLength(120),
                Select::make('position_group')->label('Grupo de posição')->options(array_combine(self::GROUPS, self::GROUPS))->required(),
                TextInput::make('code')->label('Código')->required()->unique(ignoreRecord: true)->alphaDash()->maxLength(40)
                    ->disabledOn('edit')->helperText('Identificador estável usado em importação/exportação.'),
                TagsInput::make('aliases')->label('Apelidos/sinônimos')->columnSpan(2),
                Select::make('status')->label('Status')->options(CatalogStatus::class)->default('proposta')->required()
                    ->disabled(fn (string $operation) => $operation === 'edit' || ! auth()->user()->isCoordinator())->dehydrated(fn (string $operation) => $operation === 'create')
                    ->options(fn (string $operation) => $operation === 'edit' ? CatalogStatus::class : (auth()->user()->isCoordinator() ? ['proposta' => 'Proposta', 'arquivado' => 'Arquivado'] : ['proposta' => 'Proposta'])) // ativo só pelo Aprovar
                    ->helperText('Aprovar (ativo) só pelo botão "Aprovar perfil", com critérios A–D completos.'),
            ]),
            Section::make('Critérios observáveis por nível (A–D), por fase')->columns(2)
                ->description('Para cada fase marcada: o que se vê em campo em cada nível. O formulário de avaliação mostra este texto ao escolher o nível. Nenhum nível global substitui a avaliação por papel e fase.')
                ->schema(collect(self::PHASES)->map(fn ($label, $key) => Fieldset::make($label)
                    ->visible(fn (Get $get) => in_array($key, $get('phases') ?? [], true))
                    ->columns(1)->columnSpanFull()
                    ->schema(collect(['A', 'B', 'C', 'D'])->map(fn ($lv) => Textarea::make("level_guide.{$key}.{$lv}")->label("Nível {$lv}")->rows(2)
                        ->placeholder($lv === 'A' ? 'ex.: executa o papel com frequência e qualidade em nível de Série A' : null))->all()))->values()->all()),
            Section::make('Cartão do papel')->columns(2)->schema([
                Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull()->required(),
                CheckboxList::make('compatible_positions')->label('Posições compatíveis')->options(Position::options())->columns(3)->columnSpanFull(),
                CheckboxList::make('phases')->label('Fases do jogo')->options(self::PHASES)->columns(5)->columnSpanFull(),
                Textarea::make('behaviors')->label('Comportamentos observáveis (com e sem bola, transições)')->rows(3),
                Textarea::make('evaluation_suggestions')->label('Evidências sugeridas (dados e vídeo)')->rows(3),
                Textarea::make('mandatory_requirements')->label('Requisitos obrigatórios do papel')->rows(2),
                Textarea::make('desirable_requirements')->label('Requisitos desejáveis / dependências e trade-offs')->rows(2),
                TagsInput::make('criteria')->label('Critérios do relatório de scout')->columnSpanFull()
                    ->helperText('Cada critério vira uma linha avaliada por nível A–D no relatório. Sem pesos numéricos impostos.'),
                TextInput::make('source_url')->label('Fonte de referência (URL)')->url()->columnSpanFull(),
            ]),
            Section::make('Registro da alteração')->visibleOn('edit')->schema([
                Textarea::make('change_note')->label('O que mudou e por quê')->rows(2)->dehydrated(false)
                    ->helperText('Mudanças no cartão geram nova versão. Avaliações antigas mantêm a versão em que foram feitas.'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('position_group')->label('Grupo'),
                TextEntry::make('code')->label('Código'),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('version')->label('Versão')->prefix('v'),
                TextEntry::make('description')->label('Descrição')->columnSpanFull(),
                TextEntry::make('compatible_positions')->label('Posições compatíveis')->badge()->columnSpan(2)->placeholder('—'),
                TextEntry::make('phases')->label('Fases')->badge()->formatStateUsing(fn ($state) => self::PHASES[$state] ?? self::LEGACY_PHASES[$state] ?? $state)->columnSpan(2)->placeholder('—'),
                TextEntry::make('behaviors')->label('Comportamentos')->columnSpan(2)->placeholder('—'),
                TextEntry::make('evaluation_suggestions')->label('Evidências sugeridas')->columnSpan(2)->placeholder('—'),
                TextEntry::make('mandatory_requirements')->label('Obrigatórios')->columnSpan(2)->placeholder('—'),
                TextEntry::make('desirable_requirements')->label('Desejáveis / trade-offs')->columnSpan(2)->placeholder('—'),
                TextEntry::make('criteria')->label('Critérios do relatório')->listWithLineBreaks()->bulleted()->columnSpanFull()->placeholder('—'),
                TextEntry::make('aliases')->label('Apelidos')->badge()->placeholder('—'),
                TextEntry::make('source_url')->label('Fonte')->url(fn ($record) => $record->source_url, true)->limit(60)->columnSpan(3)->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('assessments'))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw("array_position(array['".implode("','", self::GROUPS)."']::text[], position_group)")->orderBy('name'))
            ->defaultGroup('position_group')
            ->columns([
                TextColumn::make('name')->label('Papel')->weight('bold')->searchable(['name', 'description'])
                    ->description(fn (TacticalRole $r) => $r->description),
                TextColumn::make('position_group')->label('Grupo')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('compatible_positions')->label('Posições')->badge()->limitList(4),
                TextColumn::make('assessments_count')->label('Atletas avaliados')->alignEnd(),
                TextColumn::make('version')->label('Versão')->prefix('v'),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                SelectFilter::make('position_group')->label('Grupo')->options(array_combine(self::GROUPS, self::GROUPS)),
                SelectFilter::make('status')->options(CatalogStatus::class),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
                ActionGroup::make([CatalogActions::activate(), CatalogActions::cloneAction(static::class), CatalogActions::toggleArchive()]),
            ]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTacticalRoles::route('/'),
            'create' => Pages\CreateTacticalRole::route('/create'),
            'view' => Pages\ViewTacticalRole::route('/{record}'),
            'edit' => Pages\EditTacticalRole::route('/{record}/edit'),
        ];
    }
}
