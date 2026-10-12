<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Verdict: string implements HasColor, HasLabel
{
    case Avancar = 'avancar';
    case Monitorar = 'monitorar';
    case Descartar = 'descartar';
    case Inconclusivo = 'inconclusivo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Avancar => 'Avançar',
            self::Monitorar => 'Monitorar',
            self::Descartar => 'Descartar',
            self::Inconclusivo => 'Inconclusivo',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Avancar => 'success',
            self::Monitorar => 'info',
            self::Descartar => 'danger',
            self::Inconclusivo => 'gray',
        };
    }
}
