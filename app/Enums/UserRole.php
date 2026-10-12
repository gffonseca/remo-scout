<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel, HasColor
{
    case Admin = 'admin';
    case Coordenador = 'coordenador';
    case Analista = 'analista';
    case Leitor = 'leitor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrador técnico',
            self::Coordenador => 'Coordenador de mercado',
            self::Analista => 'Analista/scout',
            self::Leitor => 'Leitor autorizado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Coordenador => 'warning',
            self::Analista => 'primary',
            self::Leitor => 'gray',
        };
    }

    public function canEdit(): bool
    {
        return $this !== self::Leitor;
    }

    public function isCoordinatorOrAbove(): bool
    {
        return in_array($this, [self::Admin, self::Coordenador], true);
    }
}
