<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAgents extends ManageRecords
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo empresário')->mutateDataUsing(fn (array $d) => $d + ['created_by' => auth()->id()])];
    }
}
