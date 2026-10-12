<?php

namespace App\Filament\Resources\ScoutingReports\Pages;

use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditScoutingReport extends EditRecord
{
    protected static string $resource = ScoutingReportResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), SubmitReportAction::make(), DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
