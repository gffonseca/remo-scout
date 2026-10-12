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

        $detail = static::providerStats($provider, $playerIds);
        $now = now()->startOfSecond();
        foreach ($rows as $r) {
            $det = $detail[$r->player_id.'|'.$r->comp.'|'.$r->season.'|'.$r->club_name] ?? null;
            \App\Models\PlayerSeasonStat::updateOrCreate(
                ['player_id' => $r->player_id, 'provider' => $provider, 'competition_key' => $r->comp, 'season' => $r->season, 'club_name' => $r->club_name],
                [
                    'competition_name' => $r->comp, 'appearances' => $r->apps, 'starts' => $r->starts, 'minutes' => $r->minutes,
                    'goals' => $r->goals, 'yellow_cards' => $r->yellows, 'red_cards' => $r->reds,
                    'assists' => isset($det['estatistica']['assistencias']) ? (int) $det['estatistica']['assistencias'] : null,
                    'rating' => $det['nota_media'] ?? null,
                    'extra' => ['banco_sem_entrar' => (int) $r->unused_bench, 'minutos_conhecidos' => (int) $r->minutes_known,
                        'jogos_minutos_indeterminados' => (int) $r->minutes_unknown, 'jogos_gols_nao_conferidos' => (int) $r->goals_unverified]
                        + ($det ? ['estatistica' => $det['estatistica'], 'jogos_com_estatistica' => $det['jogos'],
                            'minutos_com_estatistica' => $det['minutos']] : []),
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

    /**
     * Soma da estatística individual por partida (appearances.stats), só onde o campo existe; nota = média ponderada por minutos.
     * Chave: jogador|competição|temporada|clube.
     *
     * @return array<string, array{estatistica: array<string, int>, nota_media: ?float, jogos: int, minutos: int}>
     */
    private static function providerStats(string $provider, ?array $playerIds): array
    {
        $base = DB::table('appearances as a')
            ->join('fixtures as f', 'f.id', '=', 'a.fixture_id')
            ->leftJoin('clubs as c', 'c.id', '=', 'a.club_id')
            ->where('a.provider', $provider)->where('a.used', true)->whereNotNull('a.stats')
            ->when($playerIds, fn ($q) => $q->whereIn('a.player_id', $playerIds));
        $sums = (clone $base)->crossJoin(DB::raw('jsonb_each(a.stats) as kv'))
            ->groupBy('a.player_id', 'f.competition_name', 'f.season', 'c.name', 'kv.key')
            ->selectRaw("a.player_id, coalesce(f.competition_name, 'Competição') as comp, coalesce(f.season, '?') as season, c.name as club_name, kv.key,
                sum((kv.value #>> '{}')::numeric) as total,
                sum(case when a.minutes is not null then (kv.value #>> '{}')::numeric * a.minutes end) as wsum,
                sum(case when a.minutes is not null then a.minutes end) as wmin")
            ->get();
        $games = (clone $base)->groupBy('a.player_id', 'f.competition_name', 'f.season', 'c.name')
            ->selectRaw("a.player_id, coalesce(f.competition_name, 'Competição') as comp, coalesce(f.season, '?') as season, c.name as club_name,
                count(*) as n, sum(coalesce(a.minutes, 0)) as mins")->get();
        $out = [];
        foreach ($games as $g) {
            $out[$g->player_id.'|'.$g->comp.'|'.$g->season.'|'.$g->club_name] = ['estatistica' => [], 'nota_media' => null, 'jogos' => (int) $g->n, 'minutos' => (int) $g->mins];
        }
        foreach ($sums as $r) {
            $k = $r->player_id.'|'.$r->comp.'|'.$r->season.'|'.$r->club_name;
            if ($r->key === 'nota') {
                $out[$k]['nota_media'] = $r->wmin > 0 ? round((float) $r->wsum / (float) $r->wmin, 2) : null;
            } else {
                $out[$k]['estatistica'][$r->key] = (int) $r->total;
            }
        }

        return $out;
    }
}
