<?php

namespace App\Filament\Resources\SquadPlans\Pages;

use App\Filament\Resources\SquadPlans\SquadPlanResource;
use App\Services\SquadPlanner;
use Filament\Resources\Pages\CreateRecord;

class CreateSquadPlan extends CreateRecord
{
    protected static string $resource = SquadPlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        SquadPlanner::syncSlots($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return SquadPlanResource::getUrl('plan', ['record' => $this->getRecord()]);
    }
}
