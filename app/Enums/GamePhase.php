<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Fases do jogo para papéis e requisitos (decisão de 12/10/2026). Transições são comportamentos dentro do papel.
 * Ausência de fase (null) = avaliação/requisito "geral", que não substitui nem é substituído por uma fase específica.
 */
enum GamePhase: string implements HasLabel
{
    case ComPosse = 'com_posse';
    case SemPosse = 'sem_posse';
    case BolaParada = 'bola_parada';

    public function getLabel(): string
    {
        return match ($this) {
            self::ComPosse => 'Com posse',
            self::SemPosse => 'Sem posse',
            self::BolaParada => 'Bola parada',
        };
    }

    public static function label(self|string|null $phase): string
    {
        $p = $phase instanceof self ? $phase : self::tryFrom((string) $phase);

        return $p?->getLabel() ?? 'Geral';
    }

    /** Opções de formulário incluindo "Geral" (vazio). */
    public static function formOptions(): array
    {
        return ['' => 'Geral (sem fase específica)'] + collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->getLabel()])->all();
    }
}
