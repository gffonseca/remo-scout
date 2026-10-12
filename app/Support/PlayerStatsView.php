<?php

namespace App\Support;

use App\Models\Player;
use App\Models\PlayerSeasonStat;
use Illuminate\Support\Collection;

/**
 * F12 — estatística do perfil com contexto: por fonte (nunca somadas), temporada dinâmica, data de coleta,
 * minutos desconhecidos ≠ zero, por 90 só com denominador válido e aviso de amostra pequena. Sem truncar linhas.
 */
class PlayerStatsView
{
    public const PROVIDERS = [
        'api_futebol' => ['API Futebol', 'escalações e eventos por partida'],
        'api_football' => ['API-Football', 'estatística por partida (Série A/B)'],
        'sumula_fpf' => ['Súmulas FPF', 'documento oficial da partida'],
        'sumula_cbf' => ['Súmulas CBF', 'documento oficial da partida'],
        'cbf' => ['CBF', 'total publicado no registro do atleta; a CBF não publica minutos'],
        'transfermarkt' => ['Transfermarkt', 'não oficial'],
        'sofascore' => ['Sofascore', 'não oficial; nota proprietária'],
    ];

    /** Ano inicial de uma temporada: "2026" → 2026; "2025/2026" ou "25/26" → 2025 (fim 2026). @return array{0:int,1:int}|null [início, fim] */
    public static function years(string $season): ?array
    {
        if (preg_match('/^(\d{4})$/', trim($season), $m)) {
            return [(int) $m[1], (int) $m[1]];
        }
        if (preg_match('/^(\d{2}|\d{4})\s*[\/-]\s*(\d{2}|\d{4})$/', trim($season), $m)) {
            $a = (int) $m[1] < 100 ? 2000 + (int) $m[1] : (int) $m[1];
            $b = (int) $m[2] < 100 ? 2000 + (int) $m[2] : (int) $m[2];

            return [$a, $b];
        }

        return null;
    }

    /** Recente = termina no ano corrente ou no anterior (no fuso de exibição). Muda sozinho na virada do ano. */
    public static function isRecent(string $season, ?int $currentYear = null): bool
    {
        $y = static::years($season);
        $currentYear ??= (int) now(config('scout.display_timezone'))->format('Y');

        return $y !== null && $y[1] >= $currentYear - 1;
    }

    /** @return array{recent: Collection<string, Collection>, older: Collection<string, Collection>} agrupados por fonte */
    public static function for(Player $p): array
    {
        $rows = $p->seasonStats()->get()
            ->filter(fn (PlayerSeasonStat $s) => $s->appearances !== null || $s->minutes !== null || $s->goals !== null)
            ->sortBy([fn ($a, $b) => (static::years($b->season)[1] ?? 0) <=> (static::years($a->season)[1] ?? 0), ['minutes', 'desc'], ['appearances', 'desc']]);
        [$recent, $older] = $rows->partition(fn ($s) => static::isRecent($s->season));

        return ['recent' => $recent->groupBy('provider'), 'older' => $older->groupBy('provider')];
    }

    public static function providerLabel(string $provider): string
    {
        return static::PROVIDERS[$provider][0] ?? $provider;
    }

    public static function providerNote(string $provider): ?string
    {
        return static::PROVIDERS[$provider][1] ?? null;
    }

    public static function minutes(PlayerSeasonStat $s): string
    {
        if ($s->minutes !== null) {
            return (string) $s->minutes;
        }
        $known = (int) ($s->extra['minutos_conhecidos'] ?? 0);

        return $known > 0 ? "≥{$known}" : ($s->provider === 'cbf' ? 'n/p' : 'indet.');
    }

    public static function minutesTitle(PlayerSeasonStat $s): ?string
    {
        if ($s->minutes !== null) {
            return null;
        }
        if ($s->provider === 'cbf') {
            return 'A CBF não publica minutos.';
        }
        $unknown = (int) ($s->extra['jogos_minutos_indeterminados'] ?? 0);

        return $unknown ? "Minutos indeterminados em {$unknown} jogo(s): fonte sem substituições/escalação completa. Não são zero." : 'Minutos não informados pela fonte.';
    }

    public static function goals(PlayerSeasonStat $s): string
    {
        if ($s->goals === null) {
            return '—';
        }

        return (int) ($s->extra['jogos_gols_nao_conferidos'] ?? 0) > 0 ? '≥'.$s->goals : (string) $s->goals;
    }

    /** Gols por 90 só com minutos completos (>0) e gols conferidos com o placar em todos os jogos. */
    public static function goalsPer90(PlayerSeasonStat $s): ?float
    {
        if ((int) ($s->extra['jogos_gols_nao_conferidos'] ?? 0) > 0) {
            return null;
        }

        return PlayerSeasonStat::per90($s->goals, $s->minutes);
    }

    public static function smallSample(PlayerSeasonStat $s): bool
    {
        return $s->minutes !== null && $s->minutes < (int) config('scout.min_minutes_ranking', 900);
    }

    public static function fetched(PlayerSeasonStat $s): ?string
    {
        return $s->fetched_at?->timezone(config('scout.display_timezone'))->format('d/m/Y');
    }
}
