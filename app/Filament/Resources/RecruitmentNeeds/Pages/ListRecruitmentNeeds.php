<?php

namespace App\Filament\Resources\RecruitmentNeeds\Pages;

use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentNeeds extends ListRecords
{
    protected static string $resource = RecruitmentNeedResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nova necessidade')];
    }
}
