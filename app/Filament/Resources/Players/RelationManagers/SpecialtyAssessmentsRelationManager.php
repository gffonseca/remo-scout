<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Enums\Confidence;
use App\Enums\Level;
use App\Models\PlayerSpecialtyAssessment;
use App\Models\Specialty;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpecialtyAssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'specialtyAssessments';

    protected static ?string $title = 'Especialidades';

    protected static ?string $modelLabel = 'especialidade';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('specialty_id')->label('Especialidade')->required()->searchable()->columnSpanFull()
                ->options(fn () => Specialty::query()->where('status', '!=', 'arquivado')->orderBy('name')->pluck('name', 'id')),
            Select::make('level')->label('Nível')->options(Level::class)->default('nao_observado')->required(),
            Select::make('confidence')->label('Confiança')->options(Confidence::class)->default('baixa')->required(),
            TextInput::make('sample_attempts')->label('Tentativas/eventos (denominador)')->numeric()->minValue(0)
                ->helperText('Ex.: pênaltis enfrentados válidos. Vazio = amostra não levantada.'),
            TextInput::make('sample_successes')->label('Sucessos')->numeric()->minValue(0)
                ->lte('sample_attempts')->requiredWith('sample_attempts')
                ->helperText('Ex.: defesas. Fora/trave NÃO contam como defesa.'),
            TextInput::make('period_label')->label('Período')->placeholder('ex.: 2025–2026, jogos oficiais'),
            Textarea::make('evidence')->label('Evidência')->rows(3)->columnSpanFull()->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['specialty', 'author']))
            ->columns([
                TextColumn::make('specialty.name')->label('Especialidade')->weight('bold'),
                TextColumn::make('level')->label('Nível')->badge(),
                TextColumn::make('sample')->label('Amostra')
                    ->state(function (PlayerSpecialtyAssessment $r) {
                        if (($r->sample_attempts ?? 0) === 0 || $r->sample_successes === null) {
                            return 'sem amostra — taxa não calculada';
                        }
                        $warn = $r->sample_attempts < 5 ? ' · amostra pequena' : '';

                        return $r->sample_successes.'/'.$r->sample_attempts.' ('.number_format($r->success_rate * 100, 0, ',', '.').'%)'.$warn;
                    }),
                TextColumn::make('confidence')->label('Confiança')->badge(),
                TextColumn::make('period_label')->label('Período')->placeholder('—'),
                TextColumn::make('author.name')->label('Autor'),
                TextColumn::make('updated_at')->label('Data')->date('d/m/Y'),
            ])
            ->headerActions([CreateAction::make()->label('Registrar especialidade')->mutateDataUsing(fn (array $d) => $d + ['author_id' => auth()->id()])])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->emptyStateHeading('Nenhuma especialidade registrada');
    }
}
