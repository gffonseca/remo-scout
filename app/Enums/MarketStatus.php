<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MarketStatus: string implements HasColor, HasLabel
{
    case Disponivel = 'disponivel';
    case Negociavel = 'negociavel';
    case Indisponivel = 'indisponivel';
    case Desconhecido = 'desconhecido';

    public function getLabel(): string
    {
        return match ($this) {
            self::Disponivel => 'Disponível',
            self::Negociavel => 'Negociável',
            self::Indisponivel => 'Indisponível',
            self::Desconhecido => 'Desconhecida',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Disponivel => 'success', self::Negociavel => 'info', self::Indisponivel => 'danger', self::Desconhecido => 'gray',
        };
    }
}
