<?php

namespace App\Support;

use App\Enums\Level;
use App\Models\Player;

/**
 * Indicadores de síntese do atleta (substituem "capacidade/potencial" de jogos por evidência real):
 * - nível atual = melhor nível observado em papel (classificação consolidada); fallback: último relatório submetido;
 * - potencial = melhor nível em avaliações do tipo "potencial";
 * - recomendação = parecer predominante nos relatórios submetidos;
 * - conhecimento = cobertura de evidências (relatórios, vídeos, dados básicos).
 * Nada é calculado a partir de ausência de dado: sem evidência → "não observado"/"sem observação".
 */
class PlayerInsight
{
    public static function bestLevel($assessments, string $kind): ?Level
    {
        return collect($assessments)->filter(fn ($a) => $a->kind?->value === $kind && $a->level?->rank())
            ->sortByDesc(fn ($a) => $a->level->rank())->first()?->level;
    }

    public static function currentLevel(Player $p): ?Level
    {
        return static::bestLevel($p->roleAssessments, 'observado')
            ?? collect($p->submittedReports ?? [])->first(fn ($r) => $r->level?->rank())?->level;
    }

    public static function potentialLevel(Player $p): ?Level
    {
        return static::bestLevel($p->roleAssessments, 'potencial');
    }

    /** @return array{label:string,color:string} */
    public static function recommendation(Player $p): array
    {
        $reports = collect($p->submittedReports ?? []);
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
        $reports = collect($p->submittedReports ?? [])->count();
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
