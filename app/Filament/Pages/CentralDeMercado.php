<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MarketStats;
use App\Filament\Widgets\OpenNeeds;
use App\Filament\Widgets\RecentReports;
use App\Filament\Widgets\TaskList;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CentralDeMercado extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Mercado';

    protected static ?string $navigationLabel = 'Central de mercado';

    protected static ?string $title = 'Central de mercado';

    protected static ?int $navigationSort = 1;

    public function getWidgets(): array
    {
        return [MarketStats::class, TaskList::class, OpenNeeds::class, RecentReports::class];
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 2];
    }
}
