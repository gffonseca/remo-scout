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
 * o registro CBF provável do atleta (mesmo clube de inscrição e nome compatível) e um candidato do Transfermarkt
 * cujo perfil traz a MESMA data de nascimento e o mesmo clube do cadastro. Só com um único candidato nessas condições.
 * Tudo entra num lote reversível (data_fix_changes): nascimento, ID CBF, vínculo Transfermarkt e decisões da fila.
 */
class IdentityConvergence
{
    public const METHOD = 'convergencia_cbf_tm';

    public const PROFILE_CACHE = 30 * 86400;

    /** @return array<int, array{player_id:string, player:string, club:?string, cbf_id:?string, birth:?string, tm_id:?string, tm_label:?string, decision:string, reason:string}> */
    public static function evaluate(bool $search = false, int $limit = 60, ?Transfermarkt $tm = null): array
    {
        $tm ??= new Transfermarkt;
        $rows = [];
        $players = Player::query()->with('currentClub')->whereNull('birth_date')->whereNot(fn ($w) => $w->provisional())
            ->whereHas('cbfAthletes', fn ($a) => $a->where('link_status', 'provavel')->whereNotNull('birth_date'))
            ->whereDoesntHave('externalIds', fn ($x) => $x->where('provider', Transfermarkt::PROVIDER))
            ->orderByDesc('is_priority')->orderBy('name')->limit($limit)->get();

        foreach ($players as $p) {
            $row = ['player_id' => $p->id, 'player' => $p->name, 'club' => $p->currentClub?->name, 'cbf_id' => null, 'birth' => null, 'tm_id' => null, 'tm_label' => null];
            $cbf = CbfAthlete::where('player_id', $p->id)->where('link_status', 'provavel')->whereNotNull('birth_date')->get();
            if ($cbf->count() !== 1 || ! $cbf->first()->club_id || $cbf->first()->club_id !== $p->current_club_id) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => 'Registro CBF ambíguo ou de outro clube.'];

                continue;
            }
            $a = $cbf->first();
            $birth = Carbon::parse($a->birth_date)->toDateString();
            $row['cbf_id'] = $a->cbf_id;
            $row['birth'] = $birth;
            if (Player::where('cbf_id', $a->cbf_id)->where('id', '!=', $p->id)->exists()) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => 'ID CBF já pertence a outro atleta.'];

                continue;
            }
            try {
                $cands = IdentityCandidate::where('player_id', $p->id)->where('provider', Transfermarkt::PROVIDER)->where('status', 'pendente')->get();
                if ($cands->isEmpty() && $search) {
                    PlayerEnrichment::linkTransfermarkt($p); // sem nascimento no cadastro: só gera candidatos
                    $cands = IdentityCandidate::where('player_id', $p->id)->where('provider', Transfermarkt::PROVIDER)->where('status', 'pendente')->get();
                }
                if ($cands->isEmpty()) {
                    $rows[] = $row + ['decision' => 'fila', 'reason' => $search ? 'Transfermarkt sem candidato.' : 'Sem candidatos do Transfermarkt ainda.'];

                    continue;
                }
                $expected = Carbon::parse($birth)->age;
                $matches = [];
                foreach ($cands as $c) {
                    $age = $c->details['age'] ?? null;
                    if ($age !== null && abs((int) $age - $expected) > 1) {
                        continue; // idade da busca incompatível: nem consulta o perfil
                    }
                    $prof = $tm->profile((string) $c->external_id, self::PROFILE_CACHE);
                    if (substr((string) ($prof['dateOfBirth'] ?? ''), 0, 10) !== $birth) {
                        continue;
                    }
                    $matches[] = [$c, $prof];
                }
            } catch (SourceException $e) {
                $rows[] = $row + ['decision' => 'erro', 'reason' => 'Transfermarkt: '.$e->getMessage()];
                if (in_array($e->kind, ['bloqueado', 'cota', 'desativada', 'credencial', 'circuito'], true)) {
                    break;
                }

                continue;
            }
            if (count($matches) !== 1) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => count($matches) ? 'Mais de um candidato com o mesmo nascimento.' : 'Nenhum candidato do Transfermarkt com o nascimento da CBF.'];

                continue;
            }
            [$c, $prof] = $matches[0];
            $row['tm_id'] = (string) $c->external_id;
            $row['tm_label'] = $c->label;
            if (! static::sameClub($p, $prof, $tm)) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => 'Nascimento confere, mas o clube no Transfermarkt ('.($prof['club']['name'] ?? '?').') difere do cadastro.'];

                continue;
            }
            if (PlayerExternalId::where('provider', Transfermarkt::PROVIDER)->where('external_id', $row['tm_id'])->where('player_id', '!=', $p->id)->exists()) {
                $rows[] = $row + ['decision' => 'fila', 'reason' => 'ID Transfermarkt já pertence a outro atleta.'];

                continue;
            }
            $rows[] = $row + ['decision' => 'ligar', 'reason' => 'CBF e Transfermarkt: nascimento '.Carbon::parse($birth)->format('d/m/Y').' e clube conferem.'];
        }

        return $rows;
    }

    private static function sameClub(Player $p, array $prof, Transfermarkt $tm): bool
    {
        if (! $p->currentClub || empty($prof['club']['name'])) {
            return false;
        }
        if (! empty($prof['club']['id']) && ($tm->clubIdentity((string) $prof['club']['id'], [(string) $prof['club']['id'] => $prof['club']['name']], false)['club_id'] ?? null) === $p->current_club_id) {
            return true;
        }

        return NameMatcher::clubScore($p->currentClub->name, $prof['club']['name']) >= 50;
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
                DataFix::apply($batch, 'identity', 'cbf_athletes', $a->id, ['link_status' => 'confirmado', 'detail_fetched_at' => null], $actor);
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
