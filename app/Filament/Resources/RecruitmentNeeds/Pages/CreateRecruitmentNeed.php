<?php

namespace App\Filament\Resources\RecruitmentNeeds\Pages;

use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentNeed extends CreateRecord
{
    protected static string $resource = RecruitmentNeedResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
