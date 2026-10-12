<?php

namespace App\Filament\Widgets;

use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use App\Models\ScoutingReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MarketStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $active = fn () => ProspectCase::query()->whereHas('stage', fn ($s) => $s->where('is_terminal', false));

        return [
            Stat::make('Necessidades abertas', RecruitmentNeed::where('status', 'aberta')->count())
                ->description(RecruitmentNeed::where('status', 'aberta')->where('priority', 'alta')->count().' de prioridade alta'),
            Stat::make('Candidatos em análise', $active()->count()),
            Stat::make('Em shortlist ou adiante', ProspectCase::whereHas('stage', fn ($s) => $s->where('is_shortlist', true))->count()),
            Stat::make('Relatórios (7 dias)', ScoutingReport::where('status', 'submetido')->where('submitted_at', '>=', now()->subDays(7))->count())
                ->description(ScoutingReport::where('status', 'rascunho')->where('author_id', auth()->id())->count().' rascunho(s) seu(s)'),
            Stat::make('Tarefas vencidas', $active()->whereNotNull('due_on')->whereDate('due_on', '<', today())->count())
                ->color('warning'),
        ];
    }
}
