<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Consolida participações (appearances) em estatística por temporada/competição/clube, por provedor.
 * - Minutos: total só quando todas as participações têm minutos determinados; senão null (não zero), com o parcial
 *   conhecido e o número de jogos indeterminados em "extra".
 * - Gols: em partidas cujos gols listados não conferem com o placar, o total é "pelo menos" (extra.jogos_gols_nao_conferidos).
 */
class SeasonStatsAggregator
{
    public static function rebuildProvider(string $provider, ?array $playerIds = null): int
    {
        $rows = DB::table('appearances as a')
            ->join('fixtures as f', 'f.id', '=', 'a.fixture_id')
            ->leftJoin('clubs as c', 'c.id', '=', 'a.club_id')
            ->where('a.provider', $provider)
            ->when($playerIds, fn ($q) => $q->whereIn('a.player_id', $playerIds))
            ->groupBy('a.player_id', 'f.competition_name', 'f.season', 'c.name')
            ->selectRaw("a.player_id, coalesce(f.competition_name, 'Competição') as comp, coalesce(f.season, '?') as season, c.name as club_name,
                sum(case when a.used then 1 else 0 end) as apps, sum(case when a.started then 1 else 0 end) as starts,
                case when bool_and(a.minutes is not null or not a.used) then sum(coalesce(a.minutes, 0)) else null end as minutes,
                sum(a.goals) as goals, sum(a.yellow_cards) as yellows, sum(a.red_cards) as reds,
                sum(case when not a.used then 1 else 0 end) as unused_bench,
                sum(case when a.used and a.minutes is not null then a.minutes else 0 end) as minutes_known,
                sum(case when a.used and a.minutes is null then 1 else 0 end) as minutes_unknown,
                sum(case when a.used and coalesce(f.field_quality->>'gols', 'conferem') <> 'conferem' then 1 else 0 end) as goals_unverified")
            ->get();

        $now = now()->startOfSecond();
        foreach ($rows as $r) {
            \App\Models\PlayerSeasonStat::updateOrCreate(
                ['player_id' => $r->player_id, 'provider' => $provider, 'competition_key' => $r->comp, 'season' => $r->season, 'club_name' => $r->club_name],
                [
                    'competition_name' => $r->comp, 'appearances' => $r->apps, 'starts' => $r->starts, 'minutes' => $r->minutes,
                    'goals' => $r->goals, 'yellow_cards' => $r->yellows, 'red_cards' => $r->reds,
                    'extra' => ['banco_sem_entrar' => (int) $r->unused_bench, 'minutos_conhecidos' => (int) $r->minutes_known,
                        'jogos_minutos_indeterminados' => (int) $r->minutes_unknown, 'jogos_gols_nao_conferidos' => (int) $r->goals_unverified],
                    'fetched_at' => $now,
                ]
            );
        }
        // Remove totais que não têm mais partidas por trás (escalação corrigida, partida reconciliada).
        \App\Models\PlayerSeasonStat::where('provider', $provider)
            ->when($playerIds, fn ($q) => $q->whereIn('player_id', $playerIds))
            ->where(fn ($q) => $q->whereNull('fetched_at')->orWhere('fetched_at', '<', $now))->delete();

        return $rows->count();
    }
}
