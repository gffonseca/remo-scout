<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Pages\ImportarPlanilha;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('new')->label('Nova importação')->url(ImportarPlanilha::getUrl())];
    }
}
