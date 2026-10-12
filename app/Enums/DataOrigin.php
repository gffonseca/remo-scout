<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DataOrigin: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Importacao = 'importacao';
    case Provedor = 'provedor';
    case Sintetico = 'sintetico';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Importacao => 'Importação',
            self::Provedor => 'Provedor de dados',
            self::Sintetico => 'Sintético (demonstração)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Importacao => 'info',
            self::Provedor => 'primary',
            self::Sintetico => 'danger',
        };
    }
}
