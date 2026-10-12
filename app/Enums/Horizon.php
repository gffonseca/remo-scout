<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Horizon: string implements HasColor, HasLabel
{
    case Imediato = 'imediato';
    case ProximaJanela = 'proxima_janela';
    case ProximaTemporada = 'proxima_temporada';
    case Monitoramento = 'monitoramento';

    public function getLabel(): string
    {
        return match ($this) {
            self::Imediato => 'Imediato',
            self::ProximaJanela => 'Próxima janela',
            self::ProximaTemporada => 'Próxima temporada',
            self::Monitoramento => 'Monitoramento contínuo',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Imediato => 'danger',
            self::ProximaJanela => 'warning',
            self::ProximaTemporada => 'info',
            self::Monitoramento => 'gray',
        };
    }
}
