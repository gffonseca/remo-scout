<?php

namespace App\Services;

use App\Models\Specialty;
use App\Models\TacticalRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * F22 — perfil só vira "ativo" (aprovado) por coordenador/admin e com critérios observáveis A–D completos:
 * papel = cada fase declarada com os 4 níveis descritos + comportamentos; especialidade = 4 níveis.
 * Edição de critérios por quem não aprova devolve o perfil para "proposta".
 */
class CatalogApproval
{
    public const MIN_CHARS = 15;

    /** @return list<string> o que falta para aprovar */
    public static function gaps(TacticalRole|Specialty $item): array
    {
        $gaps = [];
        $guide = $item->level_guide ?? [];
        if ($item instanceof TacticalRole) {
            $phases = array_values(array_intersect($item->phases ?? [], array_keys(\App\Filament\Resources\TacticalRoles\TacticalRoleResource::PHASES)));
            if ($phases === []) {
                $gaps[] = 'nenhuma fase do jogo marcada';
            }
            if (mb_strlen(trim((string) $item->behaviors)) < self::MIN_CHARS) {
                $gaps[] = 'comportamentos observáveis não descritos';
            }
            foreach ($phases as $ph) {
                foreach (['A', 'B', 'C', 'D'] as $lv) {
                    if (mb_strlen(trim((string) ($guide[$ph][$lv] ?? ''))) < self::MIN_CHARS) {
                        $gaps[] = 'critério '.$lv.' em '.\App\Enums\GamePhase::label(\App\Enums\GamePhase::tryFrom($ph));
                    }
                }
            }
        } else {
            foreach (['A', 'B', 'C', 'D'] as $lv) {
                if (mb_strlen(trim((string) ($guide[$lv] ?? ''))) < self::MIN_CHARS) {
                    $gaps[] = 'critério '.$lv;
                }
            }
        }

        return $gaps;
    }

    public static function approve(TacticalRole|Specialty $item, User $user): void
    {
        if (! $user->is_active || ! $user->isCoordinator()) {
            throw new AuthorizationException('Só coordenador ou admin aprova perfis do catálogo.');
        }
        if ($gaps = static::gaps($item)) {
            throw new \RuntimeException('Faltam: '.implode('; ', $gaps).'.');
        }
        $item->forceFill(['status' => 'ativo', 'reviewed_by' => $user->id, 'reviewed_at' => now()])->save();
    }

    /** Critério do nível para mostrar no formulário de avaliação. */
    public static function guideFor(?TacticalRole $role, ?string $phase, ?string $level): ?string
    {
        if (! $role || ! $level || ! in_array($level, ['A', 'B', 'C', 'D'], true)) {
            return null;
        }
        $g = $role->level_guide ?? [];
        $text = $phase ? ($g[$phase][$level] ?? null) : null;

        return $text ? "Critério {$level}: {$text}" : null;
    }
}
