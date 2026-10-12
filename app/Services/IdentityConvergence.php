<?php

namespace App\Services;

use App\Models\CbfAthlete;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Sources\SourceException;
use App\Sources\Transfermarkt;
use App\Support\NameMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ligação por convergência (decisão do Gustavo em 12/10/2026, "opção B").
 *
 * Cadastro sem nascimento não pode ser ligado por nome + nascimento. Aqui o nascimento vem de DUAS fontes independentes:
 * o único candidato do Transfermarkt cujo clube atual é o do cadastro (nascimento lido do perfil) e um único registro CBF
 * do mesmo clube, com o MESMO nascimento e nome compatível. Qualquer ambiguidade mantém o atleta na fila humana.
 * Tudo entra num lote reversível (data_fix_changes): nascimento, ID CBF, vínculo Transfermarkt e decisões da fila.
 */
class IdentityConvergence
{
    public const METHOD = 'convergencia_cbf_tm';

    public const PROFILE_CACHE = 30 * 86400;

    /** Nome compatível entre o cadastro e o registro CBF (apelido ou nome civil), com nascimento e clube já iguais. */
    public const CBF_NAME_MIN = 80;

    /** @return array<int, array{player_id:string, player:string, club:?string, cbf_id:?string, birth:?string, tm_id:?string, tm_label:?string, decision:string, reason:string}> */
    public static function evaluate(bool $search = false, int $limit = 60, ?Transfermarkt $tm = null): array
    {
        $tm ??= new Transfermarkt;
        $rows = [];
        $players = Player::query()->with('currentClub')->whereNull('birth_date')->whereNotNull('current_club_id')->whereNot(fn ($w) => $w->provisional())
            ->whereDoesntHave('externalIds', fn ($x) => $x->where('provider', Transfermarkt::PROVIDER))
            ->when(! $search, fn ($q) => $q->whereHas('identityCandidates', fn ($c) => $c->where('provider', Transfermarkt::PROVIDER)->where('status', 'pendente')))
            ->orderByDesc('is_priority')->orderBy('name')->limit($limit)->get();

        foreach ($players as $p) {
            $row = ['player_id' => $p->id, 'player' => $p->name, 'club' => $p->currentClub?->name, 'cbf_id' => null, 'birth' => null, 'tm_id' => null, 'tm_label' => null];
            try {
                $cands = IdentityCandidate::where('player_id', $p->id)->where('provider', Transfermarkt::PROVIDER)->where('status', 'pendente')->get();
                if ($cands->isEmpty() && $search) {
                    PlayerEnrichment::linkTransfermarkt($p); // sem nascimento no cadastro: só gera candidatos
                    $cands = IdentityCandidate::where('player_id', $p->id)->where('provider', Transfermarkt::PROVIDER)->where('status', 'pendente')->get();
                }
                if ($cands->isEmpty()) {
                    $rows[] = $row + ['decision' => 'fila', 'reason' => 'Transfermarkt sem candidato.'];

                    continue;
                }
                // 1) Transfermarkt: candidatos no clube do cadastro, com o nascimento do perfil
                $tmHits = [];
                foreach ($cands as $c) {
                    $prof = null;
                    if (! static::clubMatches($p, $c->details['club']['id'] ?? null, $c->details['club']['name'] ?? null, $tm)) {
                        continue; // clube atual no Transfermarkt ≠ cadastro: nem consulta o perfil
                    }
                    $prof = $tm->profile((string) $c->external_id, self::PROFILE_CACHE);
                    $birth = substr((string) ($prof['dateOfBirth'] ?? ''), 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) {
                        $tmHits[] = ['c' => $c, 'birth' => $birth];
                    }
                }
            } catch (SourceException $e) {
                $rows[] = $row + ['decision' => 'erro', 'reason' => 'Transfermarkt: '.$e->getMessage()];
                if (in_array($e->kind, ['bloqueado', 'cota', 'desativada', 'credencial', 'circuito'], true)) {
                    break;
                }

                continue;
            }
            if (count($tmHits) !== 1) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => $tmHits ? 'Mais de um candidato do Transfermarkt no mesmo clube.' : 'Nenhum candidato do Transfermarkt no clube do cadastro (ou sem nascimento no perfil).'];

                continue;
            }
            $hit = $tmHits[0];
            $row['tm_id'] = (string) $hit['c']->external_id;
            $row['tm_label'] = $hit['c']->label;
            $row['birth'] = $hit['birth'];
            // 2) CBF, fonte independente: registro do mesmo clube, mesmo nascimento e nome compatível
            $cbf = CbfAthlete::where('club_id', $p->current_club_id)->whereDate('birth_date', $hit['birth'])
                ->where(fn ($q) => $q->whereNull('player_id')->orWhere('player_id', $p->id))->get()
                ->filter(fn (CbfAthlete $a) => max(NameMatcher::score($p->name, $a->nickname), NameMatcher::score($p->name, $a->name), NameMatcher::score($p->full_name, $a->name)) >= self::CBF_NAME_MIN)
                ->values();
            if ($cbf->count() !== 1) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => $cbf->isEmpty()
                    ? 'Transfermarkt indica nascimento '.Carbon::parse($hit['birth'])->format('d/m/Y').', sem registro CBF do clube que confirme.'
                    : 'Mais de um registro CBF do clube com esse nascimento.'];

                continue;
            }
            $a = $cbf->first();
            $row['cbf_id'] = $a->cbf_id;
            if (Player::where('cbf_id', $a->cbf_id)->where('id', '!=', $p->id)->exists()
                || PlayerExternalId::where('provider', Transfermarkt::PROVIDER)->where('external_id', $row['tm_id'])->where('player_id', '!=', $p->id)->exists()) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => 'ID CBF ou Transfermarkt já pertence a outro atleta.'];

                continue;
            }
            $rows[] = $row + ['decision' => 'ligar', 'reason' => 'Transfermarkt e CBF: nascimento '.Carbon::parse($hit['birth'])->format('d/m/Y').' e clube conferem (CBF: '.trim(($a->nickname ? $a->nickname.' — ' : '').$a->name).').'];
        }

        return $rows;
    }

    private static function clubMatches(Player $p, mixed $clubId, ?string $clubName, Transfermarkt $tm): bool
    {
        if (! $p->currentClub || ! $clubName) {
            return false;
        }
        if ($clubId && ($tm->clubIdentity((string) $clubId, [(string) $clubId => $clubName], false)['club_id'] ?? null) === $p->current_club_id) {
            return true;
        }

        return NameMatcher::clubScore($p->currentClub->name, $clubName) >= 50;
    }

    /** Grava as linhas "ligar" num lote reversível. @return array{batch:?string, linked:int} */
    public static function apply(array $rows, string $actor): array
    {
        $todo = array_values(array_filter($rows, fn ($r) => $r['decision'] === 'ligar'));
        if (! $todo) {
            return ['batch' => null, 'linked' => 0];
        }
        $batch = DataFix::newBatch('identity');

        return DB::transaction(function () use ($todo, $batch, $actor) {
            $n = 0;
            foreach ($todo as $r) {
                $p = Player::find($r['player_id']);
                if (! $p || $p->birth_date) {
                    continue;
                }
                $now = now()->toDateTimeString();
                $src = $p->field_sources ?? [];
                $src['birth_date'] = ['source' => 'cbf', 'at' => now()->toDateString(), 'method' => self::METHOD, 'reviewed_by' => 'convergencia'];
                $src['cbf_id'] = ['source' => 'cbf', 'at' => now()->toDateString(), 'method' => self::METHOD, 'reviewed_by' => 'convergencia'];
                DataFix::apply($batch, 'identity', 'players', $p->id, ['birth_date' => $r['birth'], 'cbf_id' => $r['cbf_id'],
                    'field_sources' => json_encode($src), 'enriched_at' => null], $actor);
                $a = CbfAthlete::where('cbf_id', $r['cbf_id'])->first();
                DataFix::apply($batch, 'identity', 'cbf_athletes', $a->id, ['player_id' => $p->id, 'link_status' => 'confirmado', 'detail_fetched_at' => null], $actor);
                $x = PlayerExternalId::create(['player_id' => $p->id, 'provider' => Transfermarkt::PROVIDER, 'external_id' => $r['tm_id'], 'verified' => true,
                    'link_method' => self::METHOD, 'evidence' => $r['reason']]);
                DataFix::recordCreated($batch, 'identity', 'player_external_ids', $x->id, $actor);
                foreach (IdentityCandidate::where('player_id', $p->id)->whereIn('provider', [Transfermarkt::PROVIDER, 'cbf'])->where('status', 'pendente')->get() as $c) {
                    $chosen = ($c->provider === 'cbf' && $c->external_id === $r['cbf_id']) || ($c->provider === Transfermarkt::PROVIDER && (string) $c->external_id === $r['tm_id']);
                    DataFix::apply($batch, 'identity', 'identity_candidates', $c->id, ['status' => $chosen ? 'vinculado' : 'descartado', 'decided_at' => $now,
                        'evidence' => $chosen ? 'Ligação por convergência: '.$r['reason'] : 'Descartado pela ligação por convergência (outro candidato confere).'], $actor);
                }
                $n++;
            }

            return ['batch' => $batch, 'linked' => $n];
        });
    }
}
