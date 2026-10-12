<?php

namespace App\Filament\Resources\Specialties\Pages;

use App\Filament\Resources\Specialties\SpecialtyResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSpecialty extends EditRecord
{
    protected static string $resource = SpecialtyResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->pendingChangeNote = $this->form->getRawState()['change_note'] ?? null;
        $record->update($data);

        return $record;
    }
}
