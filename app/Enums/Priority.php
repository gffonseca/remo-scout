<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Priority: string implements HasLabel, HasColor
{
    case Alta = 'alta';
    case Media = 'media';
    case Baixa = 'baixa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Alta => 'Alta',
            self::Media => 'Média',
            self::Baixa => 'Baixa',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Alta => 'danger',
            self::Media => 'warning',
            self::Baixa => 'gray',
        };
    }
}
