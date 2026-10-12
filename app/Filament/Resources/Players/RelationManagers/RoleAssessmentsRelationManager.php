<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Enums\AssessmentKind;
use App\Enums\Confidence;
use App\Enums\Level;
use App\Models\PlayerRoleAssessment;
use App\Models\TacticalRole;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleAssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'roleAssessments';

    protected static ?string $title = 'Papéis táticos';

    protected static ?string $modelLabel = 'classificação de papel';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('tactical_role_id')->label('Papel tático')->required()->searchable()->columnSpanFull()
                ->options(fn () => TacticalRole::query()->where('status', '!=', 'arquivado')->orderBy('position_group')->orderBy('name')->get()
                    ->groupBy('position_group')->map(fn ($g) => $g->pluck('name', 'id'))->toArray()),
            Select::make('kind')->label('Tipo')->options(AssessmentKind::class)->default('observado')->required(),
            Select::make('level')->label('Nível em que executa o papel')->options(Level::class)->default('nao_observado')->required()
                ->helperText('"Não observado" ≠ nível baixo: significa sem evidência.'),
            Select::make('confidence')->label('Confiança')->options(Confidence::class)->default('media')->required(),
            Select::make('phase')->label('Fase do jogo')->options([
                'com_posse' => 'Com posse', 'sem_posse' => 'Sem posse', 'transicao_ofensiva' => 'Transição ofensiva',
                'transicao_defensiva' => 'Transição defensiva', 'bola_parada' => 'Bola parada', 'geral' => 'Geral',
            ])->default('geral'),
            TextInput::make('period_label')->label('Período/contexto')->placeholder('ex.: Série C 2026, 4-3-3'),
            Textarea::make('evidence')->label('Evidência (jogos, vídeos, dados)')->rows(3)->columnSpanFull()->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tacticalRole', 'author']))
            ->columns([
                TextColumn::make('tacticalRole.name')->label('Papel')->weight('bold')
                    ->description(fn (PlayerRoleAssessment $r) => $r->tacticalRole?->position_group),
                TextColumn::make('level')->label('Nível')->badge(),
                TextColumn::make('kind')->label('Tipo')->badge(),
                TextColumn::make('confidence')->label('Confiança')->badge(),
                TextColumn::make('phase')->label('Fase')->placeholder('—'),
                TextColumn::make('period_label')->label('Período')->placeholder('—'),
                TextColumn::make('role_version')->label('Versão do papel')
                    ->formatStateUsing(fn ($state, PlayerRoleAssessment $r) => 'v'.$state.($r->tacticalRole && $r->tacticalRole->version > $state ? ' (papel revisado: v'.$r->tacticalRole->version.')' : '')),
                TextColumn::make('author.name')->label('Autor'),
                TextColumn::make('updated_at')->label('Data')->date('d/m/Y'),
            ])
            ->headerActions([CreateAction::make()->label('Classificar papel')->mutateDataUsing(fn (array $d) => $d + ['author_id' => auth()->id()])])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->emptyStateHeading('Papel tático não avaliado')
            ->emptyStateDescription('Sem classificação, o atleta aparece como "não avaliado" — nunca como "padrão".');
    }
}
