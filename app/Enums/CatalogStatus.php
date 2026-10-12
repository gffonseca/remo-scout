<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CatalogStatus: string implements HasColor, HasLabel
{
    case Proposta = 'proposta';
    case Ativo = 'ativo';
    case Arquivado = 'arquivado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposta => 'Proposta (a calibrar)',
            self::Ativo => 'Ativo',
            self::Arquivado => 'Arquivado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposta => 'warning',
            self::Ativo => 'success',
            self::Arquivado => 'gray',
        };
    }
}
