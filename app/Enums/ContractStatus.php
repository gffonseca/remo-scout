<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContractStatus: string implements HasLabel, HasColor
{
    case Vigente = 'vigente';
    case Emprestado = 'emprestado';
    case Livre = 'livre';
    case Desconhecido = 'desconhecido';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vigente => 'Contrato vigente',
            self::Emprestado => 'Emprestado',
            self::Livre => 'Livre/sem clube',
            self::Desconhecido => 'Desconhecido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Vigente => 'success',
            self::Emprestado => 'info',
            self::Livre => 'warning',
            self::Desconhecido => 'gray',
        };
    }
}
