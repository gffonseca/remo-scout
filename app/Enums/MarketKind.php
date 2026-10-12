<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MarketKind: string implements HasLabel, HasColor
{
    case ValorMercado = 'valor_mercado';
    case PedidaTransferencia = 'pedida_transferencia';
    case Salario = 'salario';
    case Luvas = 'luvas';
    case Comissao = 'comissao';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::ValorMercado => 'Valor de mercado (referência)',
            self::PedidaTransferencia => 'Pedida de transferência/empréstimo',
            self::Salario => 'Salário',
            self::Luvas => 'Luvas',
            self::Comissao => 'Comissão/intermediação',
            self::Outro => 'Outro componente',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ValorMercado => 'gray',
            self::PedidaTransferencia => 'warning',
            self::Salario => 'info',
            self::Luvas => 'info',
            self::Comissao => 'info',
            self::Outro => 'gray',
        };
    }
}
