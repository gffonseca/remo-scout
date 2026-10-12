<?php

namespace App\Filament\Resources\RecruitmentNeeds\Pages;

use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentNeed extends EditRecord
{
    protected static string $resource = RecruitmentNeedResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
