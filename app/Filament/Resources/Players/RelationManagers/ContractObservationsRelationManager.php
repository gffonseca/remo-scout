<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Enums\Confirmation;
use App\Enums\ContractStatus;
use App\Filament\Concerns\RelationCountBadge;
use App\Models\Club;
use App\Support\Bid;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ContractObservationsRelationManager extends RelationManager
{
    use RelationCountBadge;

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedDocumentCheck;

    protected static string $relationship = 'contractObservations';

    protected static ?string $title = 'Contrato (observações)';

    protected static ?string $modelLabel = 'observação contratual';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('status')->label('Situação')->options(ContractStatus::class)->required()->default('desconhecido'),
            Select::make('club_id')->label('Clube detentor')->relationship('club', 'name')->searchable(),
            DatePicker::make('contract_end')->label('Fim de contrato')->native(false)->displayFormat('d/m/Y'),
            Select::make('loan_possible')->label('Empréstimo possível?')->options([1 => 'Sim', 0 => 'Não'])->placeholder('Não informado'),
            Select::make('confirmation')->label('Grau de confirmação')->options(Confirmation::class)->required()->default('nao_verificado'),
            DatePicker::make('observed_on')->label('Data da observação')->native(false)->displayFormat('d/m/Y')->default(now())->required(),
            TextInput::make('source_label')->label('Fonte')->placeholder('ex.: BID CBF, empresário, clube'),
            TextInput::make('source_url')->label('URL da fonte')->url()->columnSpan(2),
            Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('observed_on', 'desc')
            ->columns([
                TextColumn::make('observed_on')->label('Observado em')->date('d/m/Y'),
                TextColumn::make('status')->label('Situação')->badge(),
                TextColumn::make('club.name')->label('Clube')->placeholder('—'),
                TextColumn::make('contract_end')->label('Fim')->date('d/m/Y')->placeholder('não informado'),
                IconColumn::make('loan_possible')->label('Empréstimo')->boolean()->placeholder('—'),
                TextColumn::make('confirmation')->label('Confirmação')->badge(),
                TextColumn::make('source_label')->label('Fonte')->url(fn ($record) => $record->source_url, true)->placeholder('—'),
                TextColumn::make('author.name')->label('Autor')->placeholder('importação'),
            ])
            ->headerActions([
                Action::make('bid')->label('Registrar consulta ao BID')->icon('heroicon-o-document-check')->color('gray')
                    ->visible(fn () => auth()->user()->canEdit())
                    ->modalDescription(fn () => new HtmlString('Abra o <a href="'.e(Bid::url($this->getOwnerRecord())).'" target="_blank" rel="noopener" class="underline">BID da CBF</a>, resolva o CAPTCHA e copie aqui o que aparece. Fica registrado como observação confirmada, com data, link e seu nome.'))
                    ->schema([
                        Select::make('tipo')->label('Tipo de contrato')->options(Bid::CONTRACT_TYPES)->required(),
                        TextInput::make('numero')->label('Nº do contrato')->maxLength(40),
                        Select::make('club_id')->label('Clube')->options(fn () => Club::orderBy('name')->pluck('name', 'id'))->searchable(),
                        DatePicker::make('inicio')->label('Data início')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('fim')->label('Fim de contrato (se constar)')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('publicacao')->label('Publicação no BID')->native(false)->displayFormat('d/m/Y')->maxDate(now())->required(),
                        Textarea::make('notas')->label('Observações')->rows(2),
                    ])
                    ->action(function (array $data) {
                        Bid::record($this->getOwnerRecord(), $data, auth()->user());
                        Notification::make()->success()->title('Consulta ao BID registrada')->send();
                    }),
                CreateAction::make()->label('Registrar observação')->mutateDataUsing(fn (array $d) => $d + ['author_id' => auth()->id()]),
            ])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->emptyStateHeading('Sem observação contratual')
            ->emptyStateDescription('Contrato, empréstimo e disponibilidade são observações datadas com fonte — não verdade permanente.');
    }
}
