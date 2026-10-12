<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssessmentKind: string implements HasLabel, HasColor
{
    case Observado = 'observado';
    case Potencial = 'potencial';

    public function getLabel(): string
    {
        return match ($this) {
            self::Observado => 'Observado em jogo',
            self::Potencial => 'Capacidade potencial',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Observado => 'success',
            self::Potencial => 'info',
        };
    }
}
