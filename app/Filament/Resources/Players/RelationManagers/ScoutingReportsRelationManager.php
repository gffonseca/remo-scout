<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Filament\Concerns\RelationCountBadge;
use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use App\Models\ScoutingReport;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Relatórios do atleta respeitando a avaliação independente (escopo visibleTo). */
class ScoutingReportsRelationManager extends RelationManager
{
    use RelationCountBadge;

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedDocumentText;

    protected static string $relationship = 'scoutingReports';

    protected static function scopeBadgeQuery(Builder $query): Builder
    {
        return $query->visibleTo(auth()->user());
    }

    protected static ?string $title = 'Relatórios de scout';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->visibleTo(auth()->user())->with(['author', 'tacticalRole']))
            ->columns([
                TextColumn::make('created_at')->label('Data')->date('d/m/Y'),
                TextColumn::make('author.name')->label('Autor'),
                TextColumn::make('tacticalRole.name')->label('Papel avaliado')->placeholder('—'),
                TextColumn::make('level')->label('Nível')->badge(),
                TextColumn::make('verdict')->label('Parecer')->badge(),
                TextColumn::make('confidence')->label('Confiança')->badge(),
                TextColumn::make('source_type')->label('Fonte')->badge(),
                TextColumn::make('match_label')->label('Jogo')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->headerActions([
                Action::make('new')->label('Novo relatório')->url(fn () => ScoutingReportResource::getUrl('create', ['player' => $this->getOwnerRecord()->id]))
                    ->visible(fn () => auth()->user()->canEdit()),
            ])
            ->recordUrl(fn (ScoutingReport $r) => ScoutingReportResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading('Nenhum relatório visível')
            ->emptyStateDescription('Relatórios de outros analistas aparecem depois que você submeter o seu (avaliação independente).');
    }
}
