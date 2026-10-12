<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Player;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePlayer extends CreateRecord
{
    protected static string $resource = PlayerResource::class;

    /** Bloqueia criação quando há possível homônimo, até o usuário confirmar que é outra pessoa. */
    protected function beforeCreate(): void
    {
        $data = $this->form->getRawState();
        $dups = Player::possibleDuplicates($data['name'] ?? '', $data['birth_date'] ?? null, $data['cbf_id'] ?? null);
        if ($dups->isNotEmpty() && ! ($data['ignore_duplicates'] ?? false)) {
            $list = $dups->map(fn (Player $p) => '• '.$p->displayLabel().($p->cbf_id ? ' — ID CBF '.$p->cbf_id : ''))->implode("\n");
            Notification::make()->warning()->persistent()
                ->title('Possível atleta já cadastrado')
                ->body("Verifique antes de criar:\n{$list}\n\nSe for outra pessoa, marque a confirmação no fim do formulário.")
                ->send();
            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
