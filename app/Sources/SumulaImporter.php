<?php

namespace App\Sources;

use App\Models\Appearance;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Player;
use App\Services\SeasonStatsAggregator;
use App\Support\ClubResolver;
use App\Support\NameMatcher;
use Illuminate\Support\Facades\DB;

/** Importa uma súmula (FPF/CBF) já interpretada: partida, atletas por ID CBF e minutos oficiais. Reimportar não duplica. */
class SumulaImporter
{
    /** @return array{fixture: Fixture, created:int, linked:int, appearances:int} */
    public static function import(array $d, string $provider, ?string $sourceUrl = null): array
    {
        if (! $d['home'] || ! $d['away'] || (! $d['players']['home'] && ! $d['players']['away'])) {
            throw new SourceException('Súmula sem times ou escalações reconhecíveis.', 'formato');
        }

        return DB::transaction(function () use ($d, $provider, $sourceUrl) {
            $home = ClubResolver::resolve($provider, null, $d['home'], true);
            $away = ClubResolver::resolve($provider, null, $d['away'], true);
            $compName = static::competitionName($d['competition']);
            $competition = $compName ? Competition::where('name', $compName)->first() : null;
            $ext = implode('|', [$d['season'] ?? '?', NameMatcher::normalize($d['competition'] ?? ''), $d['game_number'] ?? '?', $d['date'] ?? '?']);
            $fixture = Fixture::updateOrCreate(['provider' => $provider, 'external_id' => mb_substr($ext, 0, 120)], [
                'competition_id' => $competition?->id, 'competition_name' => $compName ?? $d['competition'], 'season' => $d['season'],
                'round_label' => $d['round'], 'played_on' => $d['date'], 'home_club_id' => $home?->id, 'away_club_id' => $away?->id,
                'home_score' => $d['score_home'], 'away_score' => $d['score_away'], 'status' => 'finalizado',
                'stoppage_1t' => $d['stoppage_1t'], 'stoppage_2t' => $d['stoppage_2t'], 'source_url' => $sourceUrl,
                'payload_hash' => hash('sha256', json_encode($d)), 'fetched_at' => now(),
            ]);

            $created = $linked = $count = 0;
            foreach (['home' => $home, 'away' => $away] as $side => $club) {
                $teamName = $side === 'home' ? $d['home'] : $d['away'];
                $rows = [];
                foreach ($d['players'][$side] as $shirt => $p) {
                    $rows[$shirt] = $p + ['in' => $p['starter'] ? 0 : null, 'out' => $p['starter'] ? 90 : null, 'used' => $p['starter'], 'goals' => 0, 'og' => 0, 'pen' => 0, 'y' => 0, 'r' => 0];
                }
                $same = fn ($t) => NameMatcher::clubScore($t, $teamName) >= 50;
                foreach ($d['subs'] as $s) {
                    if (! $same($s['team'])) {
                        continue;
                    }
                    $min = SumulaParser::absMinute($s['clock'], $s['half']);
                    if (isset($rows[$s['in']])) {
                        $rows[$s['in']]['used'] = true;
                        $rows[$s['in']]['in'] = $min;
                        $rows[$s['in']]['out'] ??= 90;
                    }
                    if (isset($rows[$s['out']])) {
                        $rows[$s['out']]['out'] = $min;
                    }
                }
                foreach ($d['goals'] as $g) {
                    if ($same($g['team']) && isset($rows[$g['shirt']])) {
                        $g['type'] === 'CT' ? $rows[$g['shirt']]['og']++ : $rows[$g['shirt']]['goals']++;
                        $rows[$g['shirt']]['pen'] += $g['type'] === 'PN' ? 1 : 0;
                    }
                }
                foreach (['yellows' => 'y', 'reds' => 'r'] as $key => $k) {
                    foreach ($d[$key] as $c) {
                        if ($same($c['team']) && isset($rows[$c['shirt']])) {
                            $rows[$c['shirt']][$k]++;
                            if ($k === 'r' && $rows[$c['shirt']]['used']) {
                                $rows[$c['shirt']]['out'] = min($rows[$c['shirt']]['out'] ?? 90, SumulaParser::absMinute($c['clock'], $c['half']));
                            }
                        }
                    }
                }
                foreach ($rows as $r) {
                    [$player, $how] = static::resolvePlayer($r, $club);
                    $created += $how === 'created' ? 1 : 0;
                    $linked += $how === 'linked' ? 1 : 0;
                    $minutes = $r['used'] ? max(0, min(90, (int) $r['out']) - (int) $r['in']) : 0;
                    Appearance::updateOrCreate(['fixture_id' => $fixture->id, 'player_id' => $player->id], [
                        'club_id' => $club?->id, 'provider' => $provider, 'started' => $r['starter'], 'used' => $r['used'],
                        'minute_in' => $r['used'] ? $r['in'] : null, 'minute_out' => $r['used'] ? $r['out'] : null, 'minutes' => $minutes,
                        'position_label' => $r['gk'] ? 'Goleiro' : null, 'shirt' => $r['shirt'], 'goals' => $r['goals'],
                        'penalty_goals' => $r['pen'], 'own_goals' => $r['og'], 'yellow_cards' => $r['y'], 'red_cards' => $r['r'],
                        'source_line' => $r['line'],
                    ]);
                    $count++;
                }
            }
            SeasonStatsAggregator::rebuildProvider($provider, $fixture->appearances()->pluck('player_id')->all());

            return ['fixture' => $fixture, 'created' => $created, 'linked' => $linked, 'appearances' => $count];
        });
    }

    /** @return array{0: Player, 1: string} */
    private static function resolvePlayer(array $r, ?Club $club): array
    {
        if ($p = Player::where('cbf_id', $r['cbf_id'])->first()) {
            return [$p, 'existing'];
        }
        $candidates = $club ? Player::where('current_club_id', $club->id)->whereNull('cbf_id')->get(['id', 'name', 'full_name']) : collect();
        $hits = $candidates->filter(fn ($p) => NameMatcher::score($p->name, $r['nick']) >= 88
            || ($r['full'] && NameMatcher::score($p->name, $r['full']) >= 88)
            || ($r['full'] && $p->full_name && NameMatcher::score($p->full_name, $r['full']) >= 88));
        if ($hits->count() === 1) {
            $p = Player::find($hits->first()->id);
            $p->update(['cbf_id' => $r['cbf_id'], 'full_name' => $p->full_name ?: $r['full']]);

            return [$p, 'linked'];
        }
        $p = Player::create([
            'name' => $r['nick'] ?: ($r['full'] ?? 'Sem nome'), 'full_name' => $r['full'],
            'cbf_id' => $r['cbf_id'], 'current_club_id' => $club?->id, 'primary_position' => $r['gk'] ? 'GOL' : null,
            'data_origin' => 'provedor', 'notes' => 'Criado a partir de súmula oficial (ID CBF). Completar identidade.',
        ]);

        return [$p, 'created'];
    }

    public static function competitionName(?string $raw): ?string
    {
        $n = NameMatcher::normalize($raw);

        return match (true) {
            str_contains($n, 'paraense') => 'Campeonato Paraense',
            str_contains($n, 'serie a') && str_contains($n, 'brasileiro') => 'Brasileirão Série A',
            str_contains($n, 'serie b') && str_contains($n, 'brasileiro') => 'Brasileirão Série B',
            str_contains($n, 'serie c') && str_contains($n, 'brasileiro') => 'Brasileirão Série C',
            str_contains($n, 'serie d') && str_contains($n, 'brasileiro') => 'Brasileirão Série D',
            str_contains($n, 'copa do brasil') => 'Copa do Brasil',
            str_contains($n, 'copa verde') => 'Copa Verde',
            str_contains($n, 'nordeste') => 'Copa do Nordeste',
            default => null,
        };
    }
}
