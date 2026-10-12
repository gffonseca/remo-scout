<?php

namespace App\Filament\Resources\Specialties\Pages;

use App\Filament\Resources\Specialties\SpecialtyResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSpecialty extends EditRecord
{
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('approve')->label('Aprovar perfil')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => auth()->user()->isCoordinator() && $this->getRecord()->status?->value !== 'ativo')
                ->modalDescription(fn () => ($g = \App\Services\CatalogApproval::gaps($this->getRecord()))
                    ? 'Ainda não pode ser aprovado. Faltam: '.implode('; ', $g).'.' : 'Critérios completos. Aprovar registra você e a data.')
                ->action(function () {
                    try {
                        \App\Services\CatalogApproval::approve($this->getRecord(), auth()->user());
                        \Filament\Notifications\Notification::make()->success()->title('Perfil aprovado')->send();
                        $this->refreshFormData(['status']);
                    } catch (\RuntimeException $e) {
                        \Filament\Notifications\Notification::make()->danger()->title('Não aprovado')->body($e->getMessage())->send();
                    }
                }),
            \Filament\Actions\Action::make('toProposal')->label('Voltar para proposta')->color('gray')
                ->visible(fn () => auth()->user()->isCoordinator() && $this->getRecord()->status?->value === 'ativo')
                ->requiresConfirmation()
                ->action(fn () => $this->getRecord()->forceFill(['status' => 'proposta', 'reviewed_at' => null])->save()),
            \Filament\Actions\Action::make('archive')->label('Arquivar')->color('gray')
                ->visible(fn () => auth()->user()->isCoordinator() && $this->getRecord()->status?->value !== 'arquivado')
                ->requiresConfirmation()->modalDescription('Arquivado não aparece para novas avaliações; as antigas continuam com a versão em que foram feitas.')
                ->action(fn () => $this->getRecord()->forceFill(['status' => 'arquivado'])->save()),
        ];
    }

    protected static string $resource = SpecialtyResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->pendingChangeNote = $this->form->getRawState()['change_note'] ?? null;
        $record->update($data);

        return $record;
    }
}
