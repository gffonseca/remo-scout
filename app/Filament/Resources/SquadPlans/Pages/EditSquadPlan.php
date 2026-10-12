<?php

namespace App\Filament\Resources\SquadPlans\Pages;

use App\Filament\Resources\SquadPlans\SquadPlanResource;
use App\Services\SquadPlanner;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSquadPlan extends EditRecord
{
    protected static string $resource = SquadPlanResource::class;

    protected function afterSave(): void
    {
        SquadPlanner::syncSlots($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return SquadPlanResource::getUrl('plan', ['record' => $this->getRecord()]);
    }
}
