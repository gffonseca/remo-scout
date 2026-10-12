<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Estado de um requisito de uma necessidade para um candidato. Desconhecido = Pendente, nunca "atende". */
enum RequirementStatus: string implements HasColor, HasLabel
{
    case Pendente = 'pendente';
    case Atende = 'atende';
    case NaoAtende = 'nao_atende';
    case NaoAplicavel = 'nao_aplicavel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Atende => 'Atende',
            self::NaoAtende => 'Não atende',
            self::NaoAplicavel => 'Não se aplica',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendente => 'warning',
            self::Atende => 'success',
            self::NaoAtende => 'danger',
            self::NaoAplicavel => 'gray',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Pendente => '?',
            self::Atende => '✔',
            self::NaoAtende => '✘',
            self::NaoAplicavel => '–',
        };
    }
}
