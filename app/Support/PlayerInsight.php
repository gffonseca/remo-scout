<?php

namespace App\Support;

use App\Enums\Level;
use App\Models\Player;

/**
 * Indicadores de síntese do atleta (substituem "capacidade/potencial" de jogos por evidência real):
 * - nível atual = melhor nível observado (avaliação mais recente por papel+fase); fallback: último relatório OBSERVADO;
 * - potencial = idem para avaliações/relatórios do tipo "potencial" (nunca se mistura com o atual);
 * - recomendação = parecer predominante nos relatórios submetidos;
 * - conhecimento = cobertura de evidências (observadores distintos, vídeos, dados básicos).
 * Nada é calculado a partir de ausência de dado: sem evidência → "não observado"/"sem observação".
 */
class PlayerInsight
{
    /**
     * Melhor nível entre as avaliações MAIS RECENTES de cada papel+fase do tipo pedido (F10).
     * Uma avaliação antiga mais alta não supera a reavaliação mais recente do mesmo papel/fase.
     */
    /**
     * Melhor nível entre as avaliações MAIS RECENTES de cada papel+fase do tipo pedido (F10).
     * Uma avaliação antiga mais alta não supera a reavaliação mais recente do mesmo papel/fase.
     * Para "observado", só avaliações dentro da validade contam (vencida = não sabemos o nível atual).
     */
    public static function bestLevel($assessments, string $kind): ?Level
    {
        return static::bestAssessment($assessments, $kind)?->level;
    }

    public static function bestAssessment($assessments, string $kind): ?\App\Models\PlayerRoleAssessment
    {
        return collect($assessments)->filter(fn ($a) => $a->kind?->value === $kind)
            ->sortByDesc(fn ($a) => [($a->observed_on ?? $a->created_at)?->timestamp ?? 0, $a->created_at?->timestamp ?? 0, (string) $a->id])
            ->unique(fn ($a) => $a->tactical_role_id.'|'.($a->phase?->value ?? 'geral'))
            ->filter(fn ($a) => $a->level?->rank() && ($kind !== 'observado' || ! $a->isExpired()))
            ->sortByDesc(fn ($a) => $a->level->rank())->first();
    }

    public static function bestObservedAssessment(Player $p): ?\App\Models\PlayerRoleAssessment
    {
        return static::bestAssessment($p->roleAssessments, 'observado');
    }

    /**
     * Nível atual = somente evidência observada e vigente: avaliação de papel do tipo "observado" ou, na falta dela,
     * o relatório submetido mais recente do tipo "observado" dentro da validade. Potencial nunca vira nível atual.
     */
    public static function currentLevel(Player $p): ?Level
    {
        $cutoff = now()->subMonths((int) config('scout.assessment_valid_months', 12));

        return static::bestLevel($p->roleAssessments, 'observado')
            ?? collect($p->submittedReports ?? [])
                ->filter(fn ($r) => ($r->assessment_type?->value ?? $r->assessment_type) === 'observado' && $r->level?->rank()
                    && ($r->match_date ?? $r->submitted_at)?->gte($cutoff))
                ->sortByDesc(fn ($r) => $r->submitted_at?->timestamp ?? 0)->first()?->level;
    }

    public static function potentialLevel(Player $p): ?Level
    {
        return static::bestLevel($p->roleAssessments, 'potencial')
            ?? collect($p->submittedReports ?? [])
                ->filter(fn ($r) => ($r->assessment_type?->value ?? $r->assessment_type) === 'potencial' && $r->level?->rank())
                ->sortByDesc(fn ($r) => $r->submitted_at?->timestamp ?? 0)->first()?->level;
    }

    /** @return array{label:string,color:string} */
    public static function recommendation(Player $p): array
    {
        // Um voto por observador: vale o relatório submetido mais recente de cada autor.
        $reports = collect($p->submittedReports ?? [])->sortByDesc(fn ($r) => $r->submitted_at?->timestamp ?? 0)->unique('author_id');
        if ($reports->isEmpty()) {
            return ['label' => 'Sem parecer', 'color' => 'gray'];
        }
        $counts = $reports->countBy(fn ($r) => $r->verdict->value)->sortDesc();
        $top = $counts->keys()->first();
        $verdict = \App\Enums\Verdict::from($top);
        $split = $counts->count() > 1 ? ' ('.$counts->first().'/'.$reports->count().')' : '';

        return ['label' => $verdict->getLabel().$split, 'color' => $verdict->getColor()];
    }

    /** @return array{label:string,color:string,score:int} */
    public static function knowledge(Player $p): array
    {
        // Conhecimento cresce com observadores distintos, não com repetição do mesmo autor.
        $reports = collect($p->submittedReports ?? [])->pluck('author_id')->unique()->count();
        $videos = (int) ($p->video_references_count ?? $p->videoReferences()->count());
        $basics = (int) ($p->birth_date !== null) + (int) ($p->primary_position !== null) + (int) ($p->current_club_id !== null);
        $score = min($reports, 3) * 2 + min($videos, 2) + $basics; // 0..11
        if ($reports === 0) {
            return ['label' => 'Sem observação', 'color' => 'gray', 'score' => $score];
        }

        return $score >= 9 ? ['label' => 'Completo', 'color' => 'success', 'score' => $score]
            : ($score >= 6 ? ['label' => 'Parcial', 'color' => 'info', 'score' => $score] : ['label' => 'Limitado', 'color' => 'warning', 'score' => $score]);
    }

    /** Coordenadas (x,y) num campo 100x150 (ataque para cima). */
    public const PITCH = [
        'GOL' => [50, 140], 'ZAG' => [50, 118], 'ZAGD' => [66, 118], 'ZAGE' => [34, 118],
        'LD' => [84, 108], 'LE' => [16, 108], 'ALD' => [88, 88], 'ALE' => [12, 88],
        'VOL' => [50, 96], 'MC' => [50, 78], 'MEI' => [50, 58], 'MD' => [84, 66], 'ME' => [16, 66],
        'PD' => [84, 40], 'PE' => [16, 40], 'SA' => [50, 38], 'CA' => [50, 20],
    ];
}
