<?php

namespace App\Sources;

use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\ClubSquadEntry;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\SyncRun;
use App\Support\ClubResolver;
use App\Support\NameMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Dados Futebol (API comercial, plano Pro). Uso: ELENCO ATUAL por clube (nome, camisa).
 * Limites medidos em 12/10/2026: sem data de nascimento, idade sempre nula, posição pouco confiável,
 * estatística só por time e incompleta. Por isso:
 * - nada aqui altera o cadastro do atleta; o elenco é um retrato com origem e data;
 * - vínculo com o atleta só por ID confirmado; nome + mesmo clube vira item em "Identidades a revisar";
 * - clube só é mapeado sozinho quando a busca devolve exatamente um time que resolve para ele.
 */
class DadosFutebol
{
    public const PROVIDER = 'dados_futebol';

    public SourceClient $client;

    public function __construct(?SourceClient $client = null)
    {
        $this->client = $client ?? SourceClient::for(self::PROVIDER);
    }

    private function get(string $path, array $query = [], ?int $cache = null): array
    {
        return $this->client->getJson($path, $query, $this->client->source->authHeaders(), $cache);
    }

    /** ID do time na fonte; null = ambíguo ou não achado (mapeamento manual). */
    public function teamIdFor(Club $club, SyncRun $run): ?string
    {
        $map = ClubExternalId::where('provider', self::PROVIDER)->where('club_id', $club->id)->value('external_id');
        if ($map) {
            return (string) $map;
        }
        $run->requests_made++;
        $hits = collect($this->get('/v1/times', ['q' => mb_substr($club->name, 0, 60)], 86400)['data'] ?? [])
            ->filter(fn ($t) => isset($t['id'], $t['nome']))
            ->filter(fn ($t) => ClubResolver::byName($t['nome'], $club->state)?->id === $club->id)
            ->values();
        if ($hits->count() !== 1) {
            $run->note("{$club->name}: ".($hits->isEmpty() ? 'time não encontrado' : $hits->count().' times possíveis').' na Dados Futebol — mapear manualmente.');

            return null;
        }
        ClubExternalId::firstOrCreate(['provider' => self::PROVIDER, 'external_id' => (string) $hits[0]['id']],
            ['club_id' => $club->id, 'external_name' => $hits[0]['nome']]);

        return (string) $hits[0]['id'];
    }

    /** @return array{seen:int, new:int, left:int, linked:int, review:int} */
    public function syncSquad(Club $club, SyncRun $run): ?array
    {
        $teamId = $this->teamIdFor($club, $run);
        if (! $teamId) {
            return null;
        }
        $run->requests_made++;
        $rows = collect($this->get("/v1/times/{$teamId}/jogadores", [], 6 * 3600)['data'] ?? [])
            ->filter(fn ($r) => isset($r['id']) && filled($r['nome'] ?? $r['nome_popular'] ?? null));
        $run->received += $rows->count();
        if ($rows->isEmpty()) {
            $run->note("{$club->name}: elenco veio vazio; retrato anterior mantido.");

            return ['seen' => 0, 'new' => 0, 'left' => 0, 'linked' => 0, 'review' => 0];
        }

        return DB::transaction(function () use ($club, $rows, $run) {
            $now = now();
            $stats = ['seen' => $rows->count(), 'new' => 0, 'left' => 0, 'linked' => 0, 'review' => 0];
            $links = PlayerExternalId::where('provider', self::PROVIDER)->where('verified', true)
                ->whereIn('external_id', $rows->pluck('id')->map(fn ($v) => (string) $v))->pluck('player_id', 'external_id');
            foreach ($rows as $r) {
                $ext = (string) $r['id'];
                $e = ClubSquadEntry::firstOrNew(['provider' => self::PROVIDER, 'club_id' => $club->id, 'external_player_id' => $ext]);
                if (! $e->exists) {
                    $e->first_seen_at = $now;
                    $stats['new']++;
                }
                $shirt = $r['numero_camisa'] ?? null;
                $e->fill([
                    'name' => mb_substr((string) ($r['nome'] ?? $r['nome_popular']), 0, 250),
                    'display_name' => isset($r['nome_popular']) ? mb_substr((string) $r['nome_popular'], 0, 250) : null,
                    'shirt_number' => is_numeric($shirt) && $shirt > 0 && $shirt < 1000 ? (int) $shirt : null, // ausente fica nulo, nunca 0
                    'position_raw' => isset($r['posicao']) ? mb_substr((string) $r['posicao'], 0, 40) : null,
                    'player_id' => $links[$ext] ?? null,
                    'last_seen_at' => $now, 'left_at' => null, 'raw' => static::whitelist($r),
                ])->save();
                if ($e->player_id) {
                    $stats['linked']++;
                } elseif ($this->proposeIdentity($club, $e)) {
                    $stats['review']++;
                }
            }
            $stats['left'] = ClubSquadEntry::where('provider', self::PROVIDER)->where('club_id', $club->id)
                ->whereNull('left_at')->where('last_seen_at', '<', $now)->update(['left_at' => $now]);
            $run->created += $stats['new'];
            $run->updated += $stats['linked'];
            $run->note("{$club->name}: {$stats['seen']} no elenco · {$stats['new']} novos · {$stats['left']} saíram · {$stats['linked']} ligados ao scout · {$stats['review']} para revisão");

            return $stats;
        });
    }

    /** Só os campos usados; nada além disso da linha do provedor é guardado. */
    public static function whitelist(array $r): array
    {
        return array_intersect_key($r, array_flip(['id', 'nome', 'nome_popular', 'numero_camisa', 'posicao', 'gols']));
    }

    /** Nome + mesmo clube nunca liga sozinho (fonte sem nascimento): só cria item de revisão, e só se houver um único atleta compatível. */
    private function proposeIdentity(Club $club, ClubSquadEntry $e): bool
    {
        $matches = Player::where('current_club_id', $club->id)->get(['id', 'name', 'full_name'])
            ->filter(fn ($p) => max(NameMatcher::score($p->name, $e->display_name ?? $e->name), NameMatcher::score($p->name, $e->name),
                NameMatcher::score($p->full_name ?? $p->name, $e->name)) >= 92)
            ->values();
        if ($matches->count() !== 1 || PlayerExternalId::where('provider', self::PROVIDER)->where('player_id', $matches[0]->id)->exists()) {
            return false;
        }
        $c = IdentityCandidate::firstOrCreate(
            ['player_id' => $matches[0]->id, 'provider' => self::PROVIDER, 'external_id' => $e->external_player_id],
            ['label' => mb_substr($e->name.($e->shirt_number ? " (camisa {$e->shirt_number})" : '').' — elenco do '.$club->name, 0, 250),
                'details' => $e->raw, 'score' => 92, 'status' => 'pendente', 'kind' => 'escolher',
                'evidence' => 'Nome + mesmo clube no elenco da Dados Futebol. A fonte não informa nascimento: confirmar por outra evidência.'],
        );

        return $c->wasRecentlyCreated;
    }
}
