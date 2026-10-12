<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\QuadroProspeccao;
use App\Models\ProspectCase;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TaskList extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Próximas ações';

    public function table(Table $table): Table
    {
        return $table
            ->query(ProspectCase::query()->with(['player', 'need', 'stage', 'owner'])
                ->whereHas('stage', fn ($s) => $s->where('is_terminal', false))
                ->whereNotNull('next_action'))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('due_on is null, due_on'))
            ->paginated([5, 10, 25])->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('due_on')->label('Prazo')->date('d/m')->placeholder('sem prazo')
                    ->color(fn ($record) => $record->due_on?->isPast() ? 'danger' : null),
                TextColumn::make('next_action')->label('Ação')->weight('bold'),
                TextColumn::make('player.name')->label('Atleta'),
                TextColumn::make('need.title')->label('Necessidade')->limit(30),
                TextColumn::make('stage.name')->label('Etapa')->badge()->color(fn ($record) => $record->stage->color),
                TextColumn::make('owner.name')->label('Responsável')->placeholder('—'),
            ])
            ->filters([
                Filter::make('mine')->label('Só minhas')->toggle()->default()->query(fn (Builder $query) => $query->where('owner_id', auth()->id())),
            ])
            ->recordUrl(fn ($record) => QuadroProspeccao::getUrl(['need' => $record->recruitment_need_id]))
            ->emptyStateHeading('Nenhuma ação pendente');
    }
}
