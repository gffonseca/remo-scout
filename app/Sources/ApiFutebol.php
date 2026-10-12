<?php

namespace App\Sources;

use App\Models\Appearance;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\SyncRun;
use App\Support\ClubResolver;
use App\Support\NameMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * API Futebol (api-futebol.com.br) — calendário, partidas, escalações, substituições, gols e cartões.
 * Sem estatística individual além disso. Plano atual cobre Brasileirão A, B, C, D e Copa do Brasil.
 * Minutos: regra documentada em SCORING.md (regulamentares 0–90; acréscimos não somados).
 */
class ApiFutebol
{
    public const PROVIDER = 'api_futebol';

    /** campeonato_id => nome da competição interna */
    public const COMPETITIONS = [
        10 => 'Brasileirão Série A', 14 => 'Brasileirão Série B', 16 => 'Brasileirão Série C',
        17 => 'Brasileirão Série D', 2 => 'Copa do Brasil',
    ];

    private SourceClient $client;

    public function __construct(?SourceClient $client = null)
    {
        $this->client = $client ?? SourceClient::for('api_futebol');
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->client->source->credential()];
    }

    public function competition(int $id): array
    {
        return $this->client->getJson("/v1/campeonatos/{$id}", [], $this->headers());
    }

    /** @return list<array> partidas resumidas (achata a estrutura por fase/rodada/chave) */
    public function matches(int $competitionId): array
    {
        $json = $this->client->getJson("/v1/campeonatos/{$competitionId}/partidas", [], $this->headers());
        $out = [];
        $walk = function ($x) use (&$walk, &$out) {
            if (is_array($x)) {
                if (isset($x['partida_id'])) {
                    $out[$x['partida_id']] = $x;

                    return;
                }
                foreach ($x as $v) {
                    $walk($v);
                }
            }
        };
        $walk($json['partidas'] ?? $json);

        return array_values($out);
    }

    public function match(int $id): array
    {
        return $this->client->getJson("/v1/partidas/{$id}", [], $this->headers());
    }

    /** Sincroniza partidas finalizadas ainda não processadas (ou alteradas). Limita chamadas por execução. */
    public function sync(SyncRun $run, array $competitionIds, int $maxDetails = 300): void
    {
        $budget = $maxDetails;
        foreach ($competitionIds as $cid) {
            $compName = self::COMPETITIONS[$cid] ?? "API Futebol #{$cid}";
            $competition = Competition::where('name', $compName)->first();
            try {
                $season = (string) ($this->competition($cid)['edicao_atual']['temporada'] ?? now()->year);
                $list = $this->matches($cid);
                $run->requests_made += 2;
            } catch (SourceException $e) {
                $run->errors++;
                $run->note("{$compName}: {$e->getMessage()}");

                continue;
            }
            $finished = array_filter($list, fn ($m) => ($m['status'] ?? '') === 'finalizado');
            $known = Fixture::where('provider', self::PROVIDER)->whereIn('external_id', array_map(fn ($m) => (string) $m['partida_id'], $finished))
                ->whereNotNull('fetched_at')->pluck('external_id')->all();
            $pending = array_filter($finished, fn ($m) => ! in_array((string) $m['partida_id'], $known, true));
            $run->note("{$compName} {$season}: ".count($finished).' finalizadas, '.count($pending).' a processar');
            foreach ($pending as $m) {
                if ($budget <= 0) {
                    $run->note('Limite de chamadas desta execução atingido; continua na próxima.');
                    break 2;
                }
                try {
                    $detail = $this->match((int) $m['partida_id']);
                    $budget--;
                    $run->requests_made++;
                    $run->received++;
                    $this->ingestMatch($detail, $competition, $compName, $season, $run);
                } catch (SourceException $e) {
                    $run->errors++;
                    $run->note("Partida {$m['partida_id']}: {$e->getMessage()}");
                    if (in_array($e->kind, ['cota', 'bloqueado', 'credencial', 'desativada'], true)) {
                        break 2;
                    }
                }
                $run->save();
            }
        }
        \App\Services\SeasonStatsAggregator::rebuildProvider(self::PROVIDER);
    }

    public function ingestMatch(array $d, ?Competition $competition, string $compName, string $season, SyncRun $run): Fixture
    {
        return DB::transaction(function () use ($d, $competition, $compName, $season, $run) {
            $home = ClubResolver::resolve(self::PROVIDER, (string) ($d['time_mandante']['time_id'] ?? ''), $d['time_mandante']['nome_popular'] ?? null, true);
            $away = ClubResolver::resolve(self::PROVIDER, (string) ($d['time_visitante']['time_id'] ?? ''), $d['time_visitante']['nome_popular'] ?? null, true);
            $fixture = Fixture::updateOrCreate(['provider' => self::PROVIDER, 'external_id' => (string) $d['partida_id']], [
                'competition_id' => $competition?->id, 'competition_name' => $compName, 'season' => $season,
                'round_label' => $d['rodada'] ?? ($d['fase']['nome'] ?? null),
                'played_on' => isset($d['data_realizacao_iso']) ? Carbon::parse($d['data_realizacao_iso'])->toDateString() : null,
                'home_club_id' => $home?->id, 'away_club_id' => $away?->id,
                'home_score' => $d['placar_mandante'] ?? null, 'away_score' => $d['placar_visitante'] ?? null,
                'status' => $d['status'] ?? null,
                'home_formation' => $d['escalacoes']['mandante']['esquema_tatico'] ?? null,
                'away_formation' => $d['escalacoes']['visitante']['esquema_tatico'] ?? null,
                'source_url' => 'https://api.api-futebol.com.br/v1/partidas/'.$d['partida_id'],
                'payload_hash' => hash('sha256', json_encode($d)), 'fetched_at' => now(),
            ]);

            // Qualidade: sem substituições registradas ou gols listados ≠ placar → minutos indeterminados (null), nunca 90 presumido.
            $subsCount = count($d['substituicoes']['mandante'] ?? []) + count($d['substituicoes']['visitante'] ?? []);
            $goalsListed = count(array_filter($d['gols']['mandante'] ?? [], fn ($g) => ! empty($g['atleta']))) + count(array_filter($d['gols']['visitante'] ?? [], fn ($g) => ! empty($g['atleta'])));
            $scoreSum = (int) ($d['placar_mandante'] ?? 0) + (int) ($d['placar_visitante'] ?? 0);
            $nullAthletes = 0;
            foreach (['mandante', 'visitante'] as $sd) {
                foreach (($d['escalacoes'][$sd]['titulares'] ?? []) as $t) {
                    $nullAthletes += empty($t['atleta']) ? 1 : 0;
                }
            }
            $complete = $subsCount > 0 && $goalsListed === $scoreSum && $nullAthletes === 0;
            $fixture->update(['data_quality' => $complete ? 'completo' : 'parcial', 'quality_note' => $complete ? null
                : "Fonte incompleta: substituições={$subsCount}, gols listados={$goalsListed}/{$scoreSum}, titulares sem identificação={$nullAthletes}"]);

            foreach (['mandante' => $home, 'visitante' => $away] as $side => $club) {
                $lineup = $d['escalacoes'][$side] ?? null;
                if (! $lineup) {
                    continue;
                }
                $rows = [];
                foreach (['titulares' => true, 'reservas' => false] as $key => $starter) {
                    foreach ($lineup[$key] ?? [] as $p) {
                        $aid = (string) ($p['atleta']['atleta_id'] ?? '');
                        if ($aid === '') {
                            continue;
                        }
                        $rows[$aid] = [
                            'name' => $p['atleta']['nome_popular'] ?? 'Sem nome', 'started' => $starter, 'used' => $starter,
                            'in' => $starter ? 0 : null, 'out' => $starter ? 90 : null,
                            'position' => is_array($p['posicao'] ?? null) && isset($p['posicao']['nome']) ? $p['posicao']['nome'] : null,
                            'shirt' => $p['camisa'] ?? null, 'goals' => 0, 'pen' => 0, 'og' => 0, 'y' => 0, 'r' => 0,
                        ];
                    }
                }
                foreach ($d['substituicoes'][$side] ?? [] as $s) {
                    $min = self::absMinute($s['minuto'] ?? null, $s['periodo_slug'] ?? null);
                    $in = (string) ($s['entrou']['atleta_id'] ?? '');
                    $out = (string) ($s['saiu']['atleta_id'] ?? '');
                    if ($min === null) {
                        continue;
                    }
                    if (isset($rows[$in])) {
                        $rows[$in]['used'] = true;
                        $rows[$in]['in'] = $min;
                        $rows[$in]['out'] ??= 90;
                    }
                    if (isset($rows[$out])) {
                        $rows[$out]['out'] = $min;
                    }
                }
                foreach ($d['gols'][$side] ?? [] as $g) {
                    $aid = (string) ($g['atleta']['atleta_id'] ?? '');
                    // gol contra aparece do lado beneficiado; atribui ao autor quando ele estiver na escalação deste lado
                    if (isset($rows[$aid])) {
                        if (! empty($g['gol_contra'])) {
                            $rows[$aid]['og']++;
                        } else {
                            $rows[$aid]['goals']++;
                            $rows[$aid]['pen'] += ! empty($g['penalti']) ? 1 : 0;
                        }
                    }
                }
                foreach (['amarelo' => 'y', 'vermelho' => 'r'] as $type => $k) {
                    foreach ($d['cartoes'][$type][$side] ?? [] as $c) {
                        $aid = (string) ($c['atleta']['atleta_id'] ?? '');
                        if (isset($rows[$aid])) {
                            $rows[$aid][$k]++;
                            if ($k === 'r') {
                                $min = self::absMinute($c['minuto'] ?? null, $c['periodo_slug'] ?? null);
                                if ($min !== null && $rows[$aid]['used']) {
                                    $rows[$aid]['out'] = min($rows[$aid]['out'] ?? 90, $min);
                                }
                            }
                        }
                    }
                }
                foreach ($rows as $aid => $r) {
                    $player = $this->resolvePlayer($aid, $r['name'], $club, $run);
                    $minutes = ! $complete ? ($r['used'] ? null : 0)
                        : ($r['used'] && $r['in'] !== null ? max(0, min(90, (int) ($r['out'] ?? 90)) - $r['in']) : ($r['used'] ? null : 0));
                    Appearance::updateOrCreate(['fixture_id' => $fixture->id, 'player_id' => $player->id], [
                        'club_id' => $club?->id, 'provider' => self::PROVIDER, 'started' => $r['started'], 'used' => $r['used'],
                        'minute_in' => $r['used'] ? $r['in'] : null, 'minute_out' => $r['used'] ? $r['out'] : null, 'minutes' => $minutes,
                        'position_label' => $r['position'], 'shirt' => $r['shirt'], 'goals' => $r['goals'], 'penalty_goals' => $r['pen'],
                        'own_goals' => $r['og'], 'yellow_cards' => $r['y'], 'red_cards' => $r['r'],
                    ]);
                }
            }

            return $fixture;
        });
    }

    /** "26:44" no 2º tempo → 71. Acréscimo (>45 no período) é limitado ao fim regulamentar. Prorrogação → 90. */
    public static function absMinute(?string $clock, ?string $periodSlug): ?int
    {
        if (! $clock || ! preg_match('/^(\d{1,3}):\d{2}$/', trim($clock), $m)) {
            return null;
        }
        $min = min(45, (int) $m[1]);

        return match ($periodSlug) {
            'primeiro-tempo' => $min,
            'segundo-tempo' => 45 + $min,
            default => str_contains((string) $periodSlug, 'prorrog') ? 90 : null,
        };
    }

    private function resolvePlayer(string $aid, string $name, ?Club $club, SyncRun $run): Player
    {
        $ext = PlayerExternalId::where('provider', self::PROVIDER)->where('external_id', $aid)->first();
        if ($ext) {
            return $ext->player;
        }
        // tenta casar com atleta do mesmo clube (nome igual/compatível) que ainda não tenha id deste provedor
        $candidates = $club ? Player::where('current_club_id', $club->id)
            ->whereDoesntHave('externalIds', fn ($q) => $q->where('provider', self::PROVIDER))->get(['id', 'name']) : collect();
        $hits = $candidates->filter(fn ($p) => NameMatcher::score($p->name, $name) >= 95);
        if ($hits->count() === 1) {
            $player = Player::find($hits->first()->id);
            $run->updated++;
        } else {
            $player = Player::create(['name' => $name, 'current_club_id' => $club?->id, 'data_origin' => 'provedor',
                'notes' => 'Criado a partir de escalação (API Futebol). Completar identidade.']);
            $run->created++;
        }
        PlayerExternalId::create(['player_id' => $player->id, 'provider' => self::PROVIDER, 'external_id' => $aid, 'verified' => false]);

        return $player;
    }
}
