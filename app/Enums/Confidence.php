<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Confidence: string implements HasLabel, HasColor
{
    case Baixa = 'baixa';
    case Media = 'media';
    case Alta = 'alta';

    public function getLabel(): string
    {
        return match ($this) {
            self::Baixa => 'Baixa',
            self::Media => 'Média',
            self::Alta => 'Alta',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Baixa => 'danger',
            self::Media => 'warning',
            self::Alta => 'success',
        };
    }
}
