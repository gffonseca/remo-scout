<?php

namespace App\Services;

use App\Models\PlayerRoleAssessment;
use Illuminate\Support\Collection;

/**
 * F22 — calibração: mesmo atleta, papel e fase avaliados (observado) por autores diferentes em janela curta,
 * com níveis diferentes. É a lista para a reunião do departamento discutir divergências.
 */
class Calibration
{
    public const WINDOW_DAYS = 45;

    /** @return Collection<int, array{player:\App\Models\Player, role:string, phase:string, gap:int, items:Collection}> */
    public static function divergences(int $days = self::WINDOW_DAYS): Collection
    {
        $rows = PlayerRoleAssessment::with(['player', 'tacticalRole', 'author'])
            ->where('kind', 'observado')->whereNotNull('author_id')->whereIn('level', ['A', 'B', 'C', 'D'])
            ->where('observed_on', '>=', now()->subMonths((int) config('scout.assessment_valid_months', 12)))
            ->get();

        return $rows->groupBy(fn ($a) => $a->player_id.'|'.$a->tactical_role_id.'|'.($a->phase?->value ?? ''))
            ->map(function ($g) use ($days) {
                // última avaliação de cada autor no grupo
                $latest = $g->sortByDesc(fn ($a) => [$a->observed_on?->timestamp ?? 0, $a->created_at?->timestamp ?? 0])->unique('author_id')->values();
                if ($latest->count() < 2) {
                    return null;
                }
                $span = $latest->max(fn ($a) => $a->observed_on->timestamp) - $latest->min(fn ($a) => $a->observed_on->timestamp);
                $ranks = $latest->map(fn ($a) => $a->level->rank());
                if ($span > $days * 86400 || $ranks->unique()->count() < 2) {
                    return null;
                }
                $first = $latest->first();

                return ['player' => $first->player, 'role' => $first->tacticalRole?->name, 'phase' => \App\Enums\GamePhase::label($first->phase),
                    'gap' => $ranks->max() - $ranks->min(), 'items' => $latest];
            })
            ->filter()->sortByDesc('gap')->values();
    }
}
