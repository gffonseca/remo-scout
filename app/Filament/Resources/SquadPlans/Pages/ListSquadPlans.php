<?php

namespace App\Filament\Resources\SquadPlans\Pages;

use App\Filament\Resources\SquadPlans\SquadPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSquadPlans extends ListRecords
{
    protected static string $resource = SquadPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo plano')];
    }
}
