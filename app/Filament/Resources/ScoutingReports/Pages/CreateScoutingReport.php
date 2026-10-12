<?php

namespace App\Filament\Resources\ScoutingReports\Pages;

use App\Filament\Resources\ScoutingReports\ScoutingReportResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScoutingReport extends CreateRecord
{
    protected static string $resource = ScoutingReportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = auth()->id();
        $data['status'] = 'rascunho';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
