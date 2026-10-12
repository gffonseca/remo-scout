<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use App\Models\RecruitmentNeed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OpenNeeds extends TableWidget
{
    protected static ?int $sort = 3;

    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Necessidades abertas';

    public function table(Table $table): Table
    {
        return $table
            ->query(RecruitmentNeed::query()->where('status', 'aberta')->withCount([
                'cases as active_count' => fn ($c) => $c->whereHas('stage', fn ($s) => $s->where('is_terminal', false)),
                'cases as short_count' => fn ($c) => $c->whereHas('stage', fn ($s) => $s->where('is_shortlist', true)),
            ]))
            ->defaultSort(fn ($query) => $query->orderByRaw("case priority when 'alta' then 1 when 'media' then 2 else 3 end"))
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->label('Vaga')->weight('bold')->limit(40),
                TextColumn::make('priority')->label('Prioridade')->badge(),
                TextColumn::make('active_count')->label('Em análise')->alignEnd(),
                TextColumn::make('short_count')->label('Shortlist')->alignEnd(),
            ])
            ->recordUrl(fn ($record) => RecruitmentNeedResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nenhuma necessidade aberta')
            ->emptyStateDescription('Cadastre a primeira vaga em "Elenco e necessidades".');
    }
}
