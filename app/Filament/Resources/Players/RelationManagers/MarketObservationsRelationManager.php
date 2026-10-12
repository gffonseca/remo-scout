<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Enums\Confirmation;
use App\Enums\MarketKind;
use App\Filament\Concerns\RelationCountBadge;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Visível só com permissão financeira (MarketObservationPolicy). */
class MarketObservationsRelationManager extends RelationManager
{
    use RelationCountBadge;

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    protected static string $relationship = 'marketObservations';

    protected static ?string $title = 'Mercado (valores)';

    protected static ?string $modelLabel = 'observação de mercado';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('kind')->label('Componente')->options(MarketKind::class)->required()
                ->helperText('Valor de mercado é referência externa: não é preço negociado nem salário.'),
            TextInput::make('amount')->label('Valor (ou mínimo da faixa)')->numeric()->minValue(0),
            TextInput::make('amount_max')->label('Máximo da faixa')->numeric()->minValue(0)->gte('amount'),
            Select::make('currency')->label('Moeda')->options(['BRL' => 'BRL', 'USD' => 'USD', 'EUR' => 'EUR', 'ARS' => 'ARS', 'UYU' => 'UYU'])->default('BRL')->required(),
            Select::make('periodicity')->label('Periodicidade')->options(['unico' => 'Pagamento único', 'mensal' => 'Mensal', 'anual' => 'Anual'])->placeholder('Não se aplica'),
            Select::make('confirmation')->label('Confirmação')->options(Confirmation::class)->default('nao_verificado')->required(),
            DatePicker::make('observed_on')->label('Data')->native(false)->displayFormat('d/m/Y')->default(now())->required(),
            TextInput::make('source_label')->label('Fonte'),
            TextInput::make('source_url')->label('URL')->url(),
            Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('observed_on', 'desc')
            ->columns([
                TextColumn::make('observed_on')->label('Data')->date('d/m/Y'),
                TextColumn::make('kind')->label('Componente')->badge(),
                TextColumn::make('amount')->label('Valor')->alignEnd()
                    ->formatStateUsing(fn ($state, $record) => $record->currency.' '.number_format((float) $state, 2, ',', '.').($record->amount_max ? ' – '.number_format((float) $record->amount_max, 2, ',', '.') : ''))
                    ->placeholder('não informado'),
                TextColumn::make('periodicity')->label('Periodicidade')->placeholder('—'),
                TextColumn::make('confirmation')->label('Confirmação')->badge(),
                TextColumn::make('source_label')->label('Fonte')->url(fn ($record) => $record->source_url, true)->placeholder('—'),
                TextColumn::make('author.name')->label('Autor')->placeholder('importação'),
            ])
            ->headerActions([CreateAction::make()->label('Registrar valor')->mutateDataUsing(fn (array $d) => $d + ['author_id' => auth()->id()])])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->emptyStateHeading('Sem informação de mercado')
            ->emptyStateDescription('Sem valores informados, viabilidade financeira fica "não informada".');
    }
}
