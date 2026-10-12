<?php

namespace App\Sources;

use App\Models\OddsMatch;
use App\Models\SyncRun;
use App\Support\ClubResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * football-data.co.uk — CSV público de resultados e odds de fechamento. Brasil: só Série A (2012→).
 * Sem atletas. Uso no CIFUT: força dos clubes segundo o mercado (probabilidade implícita sem margem),
 * para contextualizar o time em que o atleta joga. Não cria clubes nem partidas das outras fontes.
 */
class FootballData
{
    public const PROVIDER = 'football_data';

    public const FILES = ['BRA' => '/new/BRA.csv'];

    public const CLUB_NAME_HINTS = ['Botafogo RJ' => 'Botafogo', 'Sport Recife' => 'Sport', 'Flamengo RJ' => 'Flamengo'];

    /** Casas de fechamento: prefixo da coluna → rótulo. */
    public const BOOKS = ['PSC' => 'pinnacle', 'AvgC' => 'media', 'MaxC' => 'maxima', 'BFEC' => 'betfair', 'B365C' => 'bet365'];

    public function __construct(public ?SourceClient $client = null)
    {
        $this->client ??= SourceClient::for(self::PROVIDER);
    }

    public function fetch(string $file = 'BRA', ?SyncRun $run = null): string
    {
        $run && $run->requests_made++;

        return $this->client->getRaw(self::FILES[$file] ?? throw new SourceException("Arquivo {$file} não previsto"), [], [], 3600);
    }

    /** @return array{lidas:int, gravadas:int, sem_clube:list<string>, descartadas:int} */
    public function import(string $csv, ?int $fromSeason = null, ?SyncRun $run = null): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $csv), fn ($l) => trim($l) !== ''));
        $head = str_getcsv(array_shift($lines) ?? '');
        foreach (['Season', 'Date', 'Home', 'Away', 'HG', 'AG'] as $col) {
            if (! in_array($col, $head, true)) {
                throw new SourceException("CSV sem a coluna {$col}: formato mudou", 'formato');
            }
        }
        $out = ['lidas' => 0, 'gravadas' => 0, 'sem_clube' => [], 'descartadas' => 0];
        $now = now();
        DB::transaction(function () use ($lines, $head, $fromSeason, $now, &$out) {
            foreach ($lines as $line) {
                $v = str_getcsv($line);
                if (count($v) !== count($head)) {
                    $out['descartadas']++;

                    continue;
                }
                $r = array_combine($head, $v);
                $out['lidas']++;
                if ($fromSeason && (int) $r['Season'] < $fromSeason) {
                    continue;
                }
                $date = rescue(fn () => Carbon::createFromFormat('d/m/Y', $r['Date'])->toDateString(), null, false);
                if (! $date || $r['Home'] === '' || $r['Away'] === '') {
                    $out['descartadas']++;

                    continue;
                }
                $odds = [];
                foreach (self::BOOKS as $prefix => $book) {
                    $h = static::odd($r[$prefix.'H'] ?? null);
                    $d = static::odd($r[$prefix.'D'] ?? null);
                    $a = static::odd($r[$prefix.'A'] ?? null);
                    if ($h && $d && $a) {
                        $odds[$book] = ['h' => $h, 'd' => $d, 'a' => $a];
                    }
                }
                $clubs = [];
                foreach (['Home', 'Away'] as $side) {
                    $clubs[$side] = ClubResolver::byName(self::CLUB_NAME_HINTS[$r[$side]] ?? $r[$side]);
                    if (! $clubs[$side]) {
                        $out['sem_clube'][$r[$side]] = true;
                    }
                }
                OddsMatch::updateOrCreate(
                    ['provider' => self::PROVIDER, 'league' => $r['League'] ?? 'Serie A', 'season' => $r['Season'], 'played_on' => $date,
                        'home_name' => $r['Home'], 'away_name' => $r['Away']],
                    ['home_club_id' => $clubs['Home']?->id, 'away_club_id' => $clubs['Away']?->id,
                        'home_goals' => is_numeric($r['HG']) ? (int) $r['HG'] : null, 'away_goals' => is_numeric($r['AG']) ? (int) $r['AG'] : null,
                        'odds' => $odds ?: null, 'implied' => static::implied($odds), 'fetched_at' => $now],
                );
                $out['gravadas']++;
            }
        });
        $out['sem_clube'] = array_keys($out['sem_clube']);
        if ($run) {
            $run->received += $out['lidas'];
            $run->updated += $out['gravadas'];
            $run->discarded += $out['descartadas'];
            $run->note("{$out['gravadas']} jogos gravados".($out['sem_clube'] ? '; sem clube no cadastro: '.implode(', ', $out['sem_clube']) : ''));
        }

        return $out;
    }

    private static function odd(?string $v): ?float
    {
        return is_numeric($v) && (float) $v > 1.0 ? (float) $v : null;
    }

    /** Probabilidade implícita sem a margem da casa (normalização proporcional). Média de mercado; senão Pinnacle; senão Bet365. */
    public static function implied(array $odds): ?array
    {
        $o = $odds['media'] ?? $odds['pinnacle'] ?? $odds['bet365'] ?? null;
        if (! $o) {
            return null;
        }
        $inv = ['h' => 1 / $o['h'], 'd' => 1 / $o['d'], 'a' => 1 / $o['a']];
        $sum = array_sum($inv);

        return array_map(fn ($x) => round($x / $sum, 4), $inv) + ['margem' => round($sum - 1, 4)];
    }

    /**
     * Força por clube e temporada: pontos reais por jogo × pontos esperados pelo mercado (3·p(vitória) + p(empate)).
     *
     * @return Collection<int, object>
     */
    public static function clubStrength(string $season)
    {
        $sql = <<<'SQL'
            select club_id, count(*) as jogos,
                   round(avg(pts)::numeric, 2) as pontos_por_jogo,
                   round(avg(xpts)::numeric, 2) as pontos_esperados,
                   round(avg(pts - xpts)::numeric, 2) as diferenca,
                   round(avg(pwin)::numeric, 3) as prob_vitoria_media
            from (
                select home_club_id as club_id,
                       case when home_goals > away_goals then 3 when home_goals = away_goals then 1 else 0 end as pts,
                       3 * (implied->>'h')::numeric + (implied->>'d')::numeric as xpts, (implied->>'h')::numeric as pwin
                from odds_matches where season = ? and home_club_id is not null and implied is not null and home_goals is not null
                union all
                select away_club_id,
                       case when away_goals > home_goals then 3 when home_goals = away_goals then 1 else 0 end,
                       3 * (implied->>'a')::numeric + (implied->>'d')::numeric, (implied->>'a')::numeric
                from odds_matches where season = ? and away_club_id is not null and implied is not null and home_goals is not null
            ) x group by club_id order by pontos_esperados desc
            SQL;

        return collect(DB::select($sql, [$season, $season]));
    }
}
