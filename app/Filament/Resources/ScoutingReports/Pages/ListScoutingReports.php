<?php

namespace App\Filament\Resources\ScoutingReports\Pages;

use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScoutingReports extends ListRecords
{
    protected static string $resource = ScoutingReportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo relatório')];
    }
}
