<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NeedStatus: string implements HasLabel, HasColor
{
    case Aberta = 'aberta';
    case Pausada = 'pausada';
    case Fechada = 'fechada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Pausada => 'Pausada',
            self::Fechada => 'Fechada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aberta => 'success',
            self::Pausada => 'warning',
            self::Fechada => 'gray',
        };
    }
}
