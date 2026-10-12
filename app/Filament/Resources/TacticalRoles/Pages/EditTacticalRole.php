<?php

namespace App\Filament\Resources\TacticalRoles\Pages;

use App\Filament\Resources\TacticalRoles\TacticalRoleResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTacticalRole extends EditRecord
{
    protected static string $resource = TacticalRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->pendingChangeNote = $this->form->getRawState()['change_note'] ?? null;
        $record->update($data);

        return $record;
    }
}
