<?php

namespace App\Filament\Resources\ScoutingReports\Pages;

use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewScoutingReport extends ViewRecord
{
    protected static string $resource = ScoutingReportResource::class;

    protected function getHeaderActions(): array
    {
        return [SubmitReportAction::make(), EditAction::make()];
    }
}
