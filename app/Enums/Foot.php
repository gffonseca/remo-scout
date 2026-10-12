<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Foot: string implements HasLabel, HasColor
{
    case Direito = 'direito';
    case Esquerdo = 'esquerdo';
    case Ambidestro = 'ambidestro';
    case NaoInformado = 'nao_informado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direito => 'Direito',
            self::Esquerdo => 'Esquerdo',
            self::Ambidestro => 'Ambidestro (observado)',
            self::NaoInformado => 'Não informado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Direito => 'gray',
            self::Esquerdo => 'gray',
            self::Ambidestro => 'gray',
            self::NaoInformado => 'gray',
        };
    }
}
