<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Level: string implements HasLabel, HasColor
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case NaoObservado = 'nao_observado';

    public function getLabel(): string
    {
        return match ($this) {
            self::A => 'Nível A',
            self::B => 'Nível B',
            self::C => 'Nível C',
            self::D => 'Nível D',
            self::NaoObservado => 'Não observado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::A => 'success',
            self::B => 'info',
            self::C => 'warning',
            self::D => 'danger',
            self::NaoObservado => 'gray',
        };
    }

    /** Ordem para comparação: A é o nível mais alto. Não observado = null. */
    public function rank(): ?int
    {
        return match ($this) {
            self::A => 4, self::B => 3, self::C => 2, self::D => 1, self::NaoObservado => null,
        };
    }
}
