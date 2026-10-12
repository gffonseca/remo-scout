<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Confirmation: string implements HasColor, HasLabel
{
    case Confirmado = 'confirmado';
    case Estimado = 'estimado';
    case Rumor = 'rumor';
    case NaoVerificado = 'nao_verificado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Confirmado => 'Confirmado',
            self::Estimado => 'Estimado',
            self::Rumor => 'Rumor',
            self::NaoVerificado => 'Não verificado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Confirmado => 'success',
            self::Estimado => 'info',
            self::Rumor => 'warning',
            self::NaoVerificado => 'gray',
        };
    }
}
