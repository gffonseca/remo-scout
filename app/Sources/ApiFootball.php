<?php

namespace App\Sources;

use App\Models\Appearance;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\ProviderAthlete;
use App\Models\SyncRun;
use App\Services\SeasonStatsAggregator;
use App\Support\ClubResolver;
use App\Support\NameMatcher;
use Illuminate\Support\Facades\DB;

/**
 * API-Football (api-sports.io), plano Pro. Cobertura 2026 medida em 12/10/2026:
 * estatística individual por jogo em Série A/B (e Copa do Brasil nas fases finais); escalação + eventos em Série C e Nordeste.
 *
 * Regras:
 * - 1 chamada por partida (/fixtures?id= traz eventos, escalações e estatística individual).
 * - Minutos: do provedor quando todos os utilizados do lado têm minutos; senão calculados por escalação + substituições,
 *   só se 11 titulares identificados, trocas coerentes e gols dos eventos = placar. Senão null (nunca 90 nem zero).
 * - Estatística individual guardada como veio, só campos presentes (ausente ≠ 0). Fontes não são somadas.
 * - Identidade: vínculo automático só com nome compatível + mesma data de nascimento (cadastro do provedor);
 *   senão cadastro provisório + revisão "mesclar". Campo de lesão do provedor é descartado (sem dado de saúde).
 */
class ApiFootball
{
    public const PROVIDER = 'api_football';

    /** Liga → [nome exibido, tem estatística individual (esperado)]. */
    public const LEAGUES = [
        71 => ['Série A', true], 72 => ['Série B', true], 73 => ['Copa do Brasil', true], 75 => ['Série C', false], 612 => ['Copa do Nordeste', false],
    ];

    /** Nomes da API-Football que não batem com o cadastro (conferido em 12/10/2026 nas 135 equipes das 5 ligas). */
    public const CLUB_NAME_HINTS = [
        'Atletico Paranaense' => 'Athletico Paranaense', 'Sport Recife' => 'Sport', 'Nautico Recife' => 'Náutico', 'Guarani Campinas' => 'Guarani',
    ];

    public const REFRESH_RECENT_DAYS = 5;

    public const SQUAD_TTL_DAYS = 7;

    public SourceClient $client;

    private float $deadline;

    public function __construct(?SourceClient $client = null)
    {
        $this->client = $client ?? SourceClient::for(self::PROVIDER);
    }

    /** @return array<mixed> */
    private function get(string $path, array $query, ?SyncRun $run = null, ?int $cache = null): array
    {
        $run && $run->requests_made++;

        return $this->client->getJson($path, $query, $this->client->source->authHeaders(), $cache, static fn (string $body) => static::bodyError($body));
    }

    /** API-Football devolve erros dentro de HTTP 200 ("errors"): viram falha da fonte (circuito), nunca sucesso. */
    public static function bodyError(string $body): ?array
    {
        $errors = json_decode($body, true)['errors'] ?? [];
        if (empty($errors)) {
            return null;
        }
        $msg = 'API-Football: '.mb_substr(is_array($errors) ? implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($errors), $errors)) : (string) $errors, 0, 300);
        $kind = preg_match('/request|limit|rate/i', $msg) ? 'limite' : (preg_match('/token|key|plan|subscription|access/i', $msg) ? 'bloqueado' : 'http');

        return [$kind, $msg, $kind === 'bloqueado' ? 'bloqueado' : 'degradado'];
    }

    public static function season(): int
    {
        return (int) now(config('scout.display_timezone'))->format('Y');
    }

    /** Sincroniza partidas finalizadas: novas e recentes (reconsulta para correções). */
    public function sync(SyncRun $run, array $leagueIds, int $max, ?int $season = null, int $maxSeconds = 1200): void
    {
        $season ??= static::season();
        $this->deadline = microtime(true) + $maxSeconds;
        $done = 0;
        $touched = [];
        foreach ($leagueIds as $lid) {
            $name = self::LEAGUES[$lid][0] ?? "Liga {$lid}";
            $list = collect($this->get('/fixtures', ['league' => $lid, 'season' => $season], $run, 3600)['response'] ?? [])
                ->filter(fn ($f) => in_array($f['fixture']['status']['short'] ?? '', ['FT', 'AET', 'PEN'], true))
                ->sortByDesc(fn ($f) => $f['fixture']['timestamp'] ?? 0)->values();
            [$new, $refresh] = $this->selectForFetch($list->all());
            $run->note("{$name} {$season}: {$list->count()} finalizadas; ".count($new).' novas, '.count($refresh).' para reconferir.');
            foreach (array_merge($new, $refresh) as $f) {
                if ($done >= $max || microtime(true) > $this->deadline) {
                    $run->note("Limite da execução atingido ({$done} partidas); o restante fica para a próxima.");
                    break 2;
                }
                $id = $f['fixture']['id'];
                try {
                    $full = $this->get('/fixtures', ['id' => $id], $run)['response'][0] ?? null;
                    if (! $full) {
                        $run->note("Partida {$id}: resposta vazia.");

                        continue;
                    }
                    $this->ensureSquads($full, $season, $run);
                    $fx = $this->ingestFixture($full, $name, (string) $season, $run);
                    $touched = array_merge($touched, $fx->appearances()->pluck('player_id')->all());
                    $run->received++;
                    $done++;
                } catch (SourceException $e) {
                    $run->errors++;
                    $run->note("Partida {$id}: {$e->getMessage()}");
                    if (in_array($e->kind, ['cota', 'circuito', 'limite', 'bloqueado', 'credencial', 'desativada'], true)
                        || $this->client->source->fresh()->circuitOpen()) {
                        break 2; // problema da fonte, não da partida: para e não gasta cota
                    }
                }
                $run->save();
            }
        }
        if ($touched) {
            SeasonStatsAggregator::rebuildProvider(self::PROVIDER, array_values(array_unique($touched)));
        }
    }

    /** @return array{0: list<array>, 1: list<array>} [novas, reconferir] */
    public function selectForFetch(array $finished): array
    {
        $known = Fixture::where('provider', self::PROVIDER)->whereIn('external_id', array_map(fn ($f) => (string) $f['fixture']['id'], $finished))
            ->whereNotNull('fetched_at')->get(['external_id', 'data_quality', 'played_on', 'fetched_at'])->keyBy('external_id');
        $new = $refresh = [];
        foreach ($finished as $f) {
            $k = $known->get((string) $f['fixture']['id']);
            if (! $k) {
                $new[] = $f;
            } elseif ($k->fetched_at->lt(now()->subHours(20)) && $k->played_on && $k->played_on->gte(now()->subDays(self::REFRESH_RECENT_DAYS))) {
                $refresh[] = $f;
            }
        }

        return [$new, $refresh];
    }

    /** Carrega (1×/semana por time) o cadastro do provedor com nascimento, usado no vínculo por nome + nascimento. */
    public function ensureSquads(array $f, int $season, SyncRun $run): void
    {
        foreach (['home', 'away'] as $side) {
            $tid = (string) ($f['teams'][$side]['id'] ?? '');
            if ($tid === '') {
                continue;
            }
            $fresh = ProviderAthlete::where('provider', self::PROVIDER)->where('team_external_id', $tid)->where('season', (string) $season)
                ->where('fetched_at', '>', now()->subDays(self::SQUAD_TTL_DAYS))->exists();
            if ($fresh) {
                continue;
            }
            $page = 1;
            do {
                $json = $this->get('/players', ['team' => $tid, 'season' => $season, 'page' => $page], $run, 86400);
                foreach ($json['response'] ?? [] as $row) {
                    $this->storeAthlete($row['player'] ?? [], $tid, (string) $season);
                }
                $total = (int) ($json['paging']['total'] ?? 1);
            } while (++$page <= min($total, 10));
        }
    }

    public function storeAthlete(array $p, ?string $teamId, ?string $season): ?ProviderAthlete
    {
        if (empty($p['id']) || empty($p['name'])) {
            return null;
        }
        $birth = $p['birth']['date'] ?? null;
        $height = isset($p['height']) && preg_match('/^(\d{3})/', (string) $p['height'], $m) ? (int) $m[1] : null;

        // lista branca: nome, nascimento, altura, foto e time. "injured" e afins nunca são lidos.
        return ProviderAthlete::updateOrCreate(['provider' => self::PROVIDER, 'external_id' => (string) $p['id']], [
            'name' => mb_substr((string) $p['name'], 0, 250),
            'firstname' => isset($p['firstname']) ? mb_substr((string) $p['firstname'], 0, 250) : null,
            'lastname' => isset($p['lastname']) ? mb_substr((string) $p['lastname'], 0, 250) : null,
            'birth_date' => $birth && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) ? $birth : null,
            'height_cm' => $height && $height >= 140 && $height <= 220 ? $height : null,
            'photo_url' => isset($p['photo']) && str_starts_with((string) $p['photo'], 'https://') ? $p['photo'] : null,
            'team_external_id' => $teamId, 'season' => $season, 'fetched_at' => now(),
        ]);
    }

    public function ingestFixture(array $d, string $compName, string $season, SyncRun $run): Fixture
    {
        $hash = hash('sha256', json_encode([$d['fixture']['status'] ?? null, $d['goals'] ?? null, $d['events'] ?? [], $d['lineups'] ?? [], $d['players'] ?? []]));
        $existing = Fixture::where('provider', self::PROVIDER)->where('external_id', (string) $d['fixture']['id'])->first();
        if ($existing && $existing->payload_hash === $hash) {
            $existing->update(['fetched_at' => now()]);

            return $existing;
        }

        return DB::transaction(function () use ($d, $compName, $season, $run, $hash) {
            $teams = [];
            foreach (['home', 'away'] as $side) {
                $t = $d['teams'][$side] ?? [];
                $name = self::CLUB_NAME_HINTS[$t['name'] ?? ''] ?? ($t['name'] ?? null);
                $known = ClubResolver::byName($name) !== null
                    || \App\Models\ClubExternalId::where('provider', self::PROVIDER)->where('external_id', (string) ($t['id'] ?? ''))->exists();
                $teams[$side] = ClubResolver::resolve(self::PROVIDER, (string) ($t['id'] ?? ''), $name, true);
                if (! $known && $teams[$side]) {
                    $run->note("Clube novo criado a partir da API-Football: {$teams[$side]->name} (conferir se é duplicata).");
                }
            }
            $extraTime = ($d['score']['extratime']['home'] ?? null) !== null || ($d['fixture']['status']['short'] ?? '') === 'AET';
            $duration = $extraTime ? 120 : 90;
            $played = isset($d['fixture']['date']) ? \Illuminate\Support\Carbon::parse($d['fixture']['date'])->timezone(config('scout.display_timezone')) : null;
            $fixture = Fixture::updateOrCreate(['provider' => self::PROVIDER, 'external_id' => (string) $d['fixture']['id']], [
                'competition_name' => $compName, 'season' => $season, 'round_label' => $d['league']['round'] ?? null,
                'played_on' => $played?->toDateString(), 'home_club_id' => $teams['home']?->id, 'away_club_id' => $teams['away']?->id,
                'home_score' => $d['goals']['home'] ?? null, 'away_score' => $d['goals']['away'] ?? null,
                'status' => $d['fixture']['status']['short'] ?? null,
                'source_url' => 'https://v3.football.api-sports.io/fixtures?id='.$d['fixture']['id'],
                'payload_hash' => $hash, 'fetched_at' => now(),
            ]);

            $events = $d['events'] ?? [];
            $goalEvents = array_values(array_filter($events, fn ($e) => ($e['type'] ?? '') === 'Goal' && ($e['detail'] ?? '') !== 'Missed Penalty'));
            $scoreKnown = isset($d['goals']['home'], $d['goals']['away']);
            $scoreSum = (int) ($d['goals']['home'] ?? 0) + (int) ($d['goals']['away'] ?? 0);
            $goalsIdentified = count(array_filter($goalEvents, fn ($e) => ! empty($e['player']['id'])));
            $goalsOk = $scoreKnown && $goalsIdentified === $scoreSum;
            $fq = ['duracao' => $duration, 'prorrogacao' => $extraTime, 'gols' => ! $scoreKnown ? 'placar_ausente' : ($goalsOk ? 'conferem' : 'divergem'),
                'gols_listados' => $goalsIdentified, 'placar_total' => $scoreKnown ? $scoreSum : null];
            $notes = [];
            $statsByPlayer = [];
            foreach ($d['players'] ?? [] as $t) {
                foreach ($t['players'] ?? [] as $pp) {
                    if (! empty($pp['player']['id'])) {
                        $statsByPlayer[(string) $pp['player']['id']] = $pp['statistics'][0] ?? [];
                    }
                }
            }
            $lineups = collect($d['lineups'] ?? [])->keyBy(fn ($l) => (string) ($l['team']['id'] ?? ''));

            foreach (['home', 'away'] as $side) {
                $club = $teams[$side];
                $tid = (string) ($d['teams'][$side]['id'] ?? '');
                $lu = $lineups->get($tid);
                $label = $side === 'home' ? 'mandante' : 'visitante';
                $rows = [];
                foreach ([['startXI', true], ['substitutes', false]] as [$key, $starter]) {
                    foreach ($lu[$key] ?? [] as $x) {
                        $pid = (string) ($x['player']['id'] ?? '');
                        if ($pid === '') {
                            continue;
                        }
                        $rows[$pid] = ['name' => $x['player']['name'] ?? 'Sem nome', 'started' => $starter, 'used' => $starter,
                            'in' => $starter ? 0 : null, 'out' => $starter ? $duration : null, 'pos' => $x['player']['pos'] ?? null,
                            'shirt' => $x['player']['number'] ?? null, 'goals' => 0, 'pen' => 0, 'og' => 0, 'ast' => 0, 'y' => 0, 'r' => 0];
                    }
                }
                $starters = $lu['startXI'] ?? [];
                $identified = count(array_filter($starters, fn ($x) => ! empty($x['player']['id'])));
                $lineupState = ! $lu || $starters === [] ? 'ausente' : ($identified === 11 ? 'completa' : 'incompleta');

                $subsOk = true;
                $subsCount = 0;
                foreach ($events as $e) {
                    if (($e['type'] ?? '') !== 'subst' || (string) ($e['team']['id'] ?? '') !== $tid) {
                        continue;
                    }
                    $subsCount++;
                    $out = (string) ($e['player']['id'] ?? '');
                    $in = (string) ($e['assist']['id'] ?? '');
                    $min = static::minute($e['time'] ?? [], $duration);
                    // coerência: quem sai está em campo e quem entra estava no banco
                    if ($min === null || ! isset($rows[$out], $rows[$in]) || ! $rows[$out]['used'] || $rows[$in]['used']) {
                        $subsOk = false;
                    }
                    if (isset($rows[$in])) {
                        $rows[$in]['used'] = true;
                        $rows[$in]['in'] = $min;
                        $rows[$in]['out'] = $min === null ? null : $duration;
                    }
                    if (isset($rows[$out]) && $min !== null) {
                        $rows[$out]['out'] = $min;
                    }
                }
                foreach ($events as $e) {
                    $pid = (string) ($e['player']['id'] ?? '');
                    $type = $e['type'] ?? '';
                    $detail = $e['detail'] ?? '';
                    if ($type === 'Goal' && $detail !== 'Missed Penalty') {
                        if (isset($rows[$pid])) {
                            if ($detail === 'Own Goal') {
                                $rows[$pid]['og']++;
                            } else {
                                $rows[$pid]['goals']++;
                                $rows[$pid]['pen'] += $detail === 'Penalty' ? 1 : 0;
                            }
                        }
                        $aid = (string) ($e['assist']['id'] ?? '');
                        if ($aid !== '' && isset($rows[$aid]) && $detail !== 'Own Goal') {
                            $rows[$aid]['ast']++;
                        }
                    } elseif ($type === 'Card' && isset($rows[$pid])) {
                        $isRed = in_array($detail, ['Red Card', 'Second Yellow card', 'Second Yellow Card'], true);
                        $rows[$pid]['y'] += $detail === 'Yellow Card' || str_starts_with($detail, 'Second Yellow') ? 1 : 0;
                        $rows[$pid]['r'] += $isRed ? 1 : 0;
                        if ($isRed && $rows[$pid]['used'] && ($m = static::minute($e['time'] ?? [], $duration)) !== null) {
                            $rows[$pid]['out'] = min($rows[$pid]['out'] ?? $duration, $m);
                        }
                    }
                }

                // minutos do provedor valem quando todo utilizado do lado tem minutos informados
                $used = array_keys(array_filter($rows, fn ($r) => $r['used']));
                $providerMinutes = $used !== [] && collect($used)->every(fn ($pid) => isset($statsByPlayer[$pid]['games']['minutes']));
                $computedOk = $lineupState === 'completa' && $subsCount > 0 && $subsOk && $goalsOk;
                $minutesState = $providerMinutes && $lineupState === 'completa' ? 'provedor' : ($computedOk ? 'determinados' : 'indeterminados');
                $fq[$label] = ['escalacao' => $lineupState, 'titulares_identificados' => $identified,
                    'substituicoes' => $subsCount === 0 ? 'ausentes' : ($subsOk ? 'registradas' : 'incoerentes'),
                    'minutos' => $minutesState, 'estatistica_individual' => $providerMinutes];
                if ($minutesState === 'indeterminados') {
                    $notes[] = "{$label}: escalação {$lineupState} ({$identified}/11), substituições ".$fq[$label]['substituicoes'];
                }

                $seen = [];
                foreach ($rows as $pid => $r) {
                    $player = $this->resolvePlayer($pid, $r['name'], $club, $run);
                    $seen[] = $player->id;
                    $st = $statsByPlayer[$pid] ?? null;
                    if (! $r['used']) {
                        if ($minutesState === 'provedor' && (int) ($st['games']['minutes'] ?? 0) > 0) {
                            $r['used'] = true; // provedor informa minutos mesmo sem o evento de troca
                        } elseif ($minutesState !== 'provedor' && $fq[$label]['substituicoes'] !== 'registradas') {
                            $r['used'] = null; // sem trocas confiáveis: participação do reserva é desconhecida
                        }
                    }
                    $minutes = $r['used'] === false ? 0
                        : ($r['used'] === null ? null : match ($minutesState) {
                            'provedor' => min($duration, (int) $st['games']['minutes']),
                            'determinados' => $r['in'] !== null ? max(0, min($duration, (int) ($r['out'] ?? $duration)) - $r['in']) : null,
                            default => null,
                        });
                    Appearance::updateOrCreate(['fixture_id' => $fixture->id, 'player_id' => $player->id], [
                        'club_id' => $club?->id, 'provider' => self::PROVIDER, 'started' => $r['started'], 'used' => $r['used'],
                        'minute_in' => $r['used'] ? $r['in'] : null, 'minute_out' => $r['used'] ? $r['out'] : null, 'minutes' => $minutes,
                        'position_label' => $r['pos'], 'shirt' => $r['shirt'] !== null ? (string) $r['shirt'] : null,
                        'goals' => $r['goals'], 'penalty_goals' => $r['pen'], 'own_goals' => $r['og'],
                        'yellow_cards' => $r['y'], 'red_cards' => $r['r'],
                        'stats' => $st ? static::pickStats($st, $r['ast']) : null,
                    ]);
                }
                if ($seen !== []) {
                    Appearance::where('fixture_id', $fixture->id)->where('provider', self::PROVIDER)
                        ->when($club, fn ($q) => $q->where('club_id', $club->id), fn ($q) => $q->whereNull('club_id'))
                        ->whereNotIn('player_id', $seen)->delete();
                }
            }
            if (! $goalsOk) {
                $notes[] = "gols identificados {$goalsIdentified}/".($scoreKnown ? $scoreSum : '?');
            }
            $complete = ! in_array('indeterminados', [$fq['mandante']['minutos'], $fq['visitante']['minutos']], true);
            $fixture->update(['data_quality' => $complete ? 'completo' : 'parcial', 'field_quality' => $fq,
                'quality_note' => $notes ? 'Fonte incompleta — '.implode('; ', $notes).'. Minutos indeterminados não viram 90 nem zero.' : null]);

            return $fixture;
        });
    }

    /** Minuto do evento: decorrido no período, limitado à duração; acréscimo não soma. Null se ausente. */
    public static function minute(array $time, int $duration): ?int
    {
        $e = $time['elapsed'] ?? null;

        return is_numeric($e) ? max(0, min($duration, (int) $e)) : null;
    }

    /** Lista branca da estatística individual, só campos presentes (null do provedor = não medido, não zero). */
    public static function pickStats(array $s, int $assistsFromEvents = 0): array
    {
        $map = [
            'nota' => ['games', 'rating'], 'finalizacoes' => ['shots', 'total'], 'finalizacoes_no_alvo' => ['shots', 'on'],
            'assistencias' => ['goals', 'assists'], 'passes' => ['passes', 'total'], 'passes_certos' => ['passes', 'accuracy'],
            'passes_decisivos' => ['passes', 'key'], 'desarmes' => ['tackles', 'total'], 'bloqueios' => ['tackles', 'blocks'],
            'interceptacoes' => ['tackles', 'interceptions'], 'duelos' => ['duels', 'total'], 'duelos_ganhos' => ['duels', 'won'],
            'dribles_tentados' => ['dribbles', 'attempts'], 'dribles_certos' => ['dribbles', 'success'], 'driblado' => ['dribbles', 'past'],
            'faltas_sofridas' => ['fouls', 'drawn'], 'faltas_cometidas' => ['fouls', 'committed'], 'impedimentos' => ['offsides', null],
            'defesas' => ['goals', 'saves'], 'gols_sofridos' => ['goals', 'conceded'], 'penaltis_sofridos' => ['penalty', 'won'],
        ];
        $out = [];
        foreach ($map as $k => [$a, $b]) {
            $v = $b === null ? ($s[$a] ?? null) : ($s[$a][$b] ?? null);
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $out[$k] = $k === 'nota' ? round((float) $v, 2) : (int) $v;
            }
        }
        if (! isset($out['assistencias']) && $assistsFromEvents) {
            $out['assistencias'] = $assistsFromEvents;
        }

        return $out;
    }

    /**
     * Identidade: ID já ligado → atleta. Senão, cadastro do provedor com nascimento + nome compatível com exatamente
     * um atleta do scout de mesmo nascimento → vínculo automático. Senão, cadastro provisório + revisão "mesclar".
     */
    public function resolvePlayer(string $pid, string $name, ?Club $club, SyncRun $run): Player
    {
        $ext = PlayerExternalId::where('provider', self::PROVIDER)->where('external_id', $pid)->first();
        if ($ext?->player) {
            return $ext->player;
        }
        $pa = ProviderAthlete::where('provider', self::PROVIDER)->where('external_id', $pid)->first();
        $names = array_filter([$name, $pa?->name, trim(($pa?->firstname ?? '').' '.($pa?->lastname ?? ''))]);
        $score = fn (Player $p) => max(array_map(fn ($n) => max(NameMatcher::score($p->name, $n), NameMatcher::score($p->full_name ?? $p->name, $n)), $names));

        if ($pa?->birth_date) {
            $same = Player::whereDate('birth_date', $pa->birth_date)->where('data_origin', '!=', 'provedor')->get(['id', 'name', 'full_name'])
                ->filter(fn ($p) => $score($p) >= 88)->values();
            if ($same->count() === 1 && ! PlayerExternalId::where('provider', self::PROVIDER)->where('player_id', $same[0]->id)->exists()) {
                PlayerExternalId::create(['player_id' => $same[0]->id, 'provider' => self::PROVIDER, 'external_id' => $pid, 'verified' => true,
                    'link_method' => 'nome_nascimento', 'evidence' => 'Nome compatível e nascimento '.$pa->birth_date->format('d/m/Y').' igual ao cadastro (API-Football).']);
                $run->updated++;

                return Player::find($same[0]->id);
            }
        }

        $src = [];
        $attrs = ['name' => $pa?->name ?: $name, 'current_club_id' => $club?->id, 'data_origin' => 'provedor',
            'notes' => 'Cadastro provisório criado a partir de partida (API-Football). Completar identidade ou mesclar.'];
        if ($pa?->birth_date) {
            $attrs['birth_date'] = $pa->birth_date->toDateString();
            $src['birth_date'] = ['source' => self::PROVIDER, 'at' => now()->toDateString()];
        }
        if ($pa?->height_cm) {
            $attrs['height_cm'] = $pa->height_cm;
            $src['height_cm'] = ['source' => self::PROVIDER, 'at' => now()->toDateString()];
        }
        $player = Player::create($attrs);
        if ($src) {
            $player->forceFill(['field_sources' => $src])->save();
        }
        $run->created++;
        PlayerExternalId::create(['player_id' => $player->id, 'provider' => self::PROVIDER, 'external_id' => $pid,
            'verified' => false, 'link_method' => 'cadastro_provisorio']);

        $hits = $club ? Player::where('current_club_id', $club->id)->where('id', '!=', $player->id)->where('data_origin', '!=', 'provedor')
            ->get(['id', 'name', 'full_name', 'birth_date'])
            ->filter(fn ($p) => $score($p) >= 88 && ! ($p->birth_date && $pa?->birth_date && ! $p->birth_date->equalTo($pa->birth_date))) : collect();
        foreach ($hits->take(3) as $h) {
            IdentityCandidate::firstOrCreate(['player_id' => $h->id, 'provider' => self::PROVIDER, 'external_id' => $pid], [
                'kind' => 'mesclar', 'status' => 'pendente', 'shadow_player_id' => $player->id, 'score' => $score($h),
                'label' => mb_substr($name.' — '.($club?->name ?? 'sem clube').' (partida API-Football)', 0, 250),
                'evidence' => $pa?->birth_date && ! $h->birth_date
                    ? 'Mesmo clube e nome compatível; provedor informa nascimento '.$pa->birth_date->format('d/m/Y').', cadastro do scout sem nascimento.'
                    : 'Mesmo clube e nome compatível; sem nascimento no provedor para confirmar.',
            ]);
        }

        return $player;
    }
}
