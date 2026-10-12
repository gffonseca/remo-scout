<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReportSource: string implements HasColor, HasLabel
{
    case Video = 'video';
    case Presencial = 'presencial';
    case Dados = 'dados';
    case Misto = 'misto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Video => 'Vídeo',
            self::Presencial => 'Presencial',
            self::Dados => 'Dados',
            self::Misto => 'Misto',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Video => 'info',
            self::Presencial => 'success',
            self::Dados => 'primary',
            self::Misto => 'gray',
        };
    }
}
