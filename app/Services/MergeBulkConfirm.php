<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\IdentityCandidate;
use App\Models\Player;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Confirmação em lote dos itens "Mesclar cadastro provisório" com a constatação "Mesmo clube e nome compatível"
 * (pedido do Gustavo, 13/10/2026). O provisório (criado por escalação) é fundido no cadastro do scout pelo caminho
 * reversível de DuplicatePlayers (referências movidas no lote, provisório arquivado). Fica na fila: nascimento do
 * provisório diferente do cadastro, provisório inexistente ou já fundido. Com $divergentes (decisão do Gustavo, 14/10/2026)
 * o nascimento divergente também é fundido e prevalece o nascimento do cadastro do scout (o do provisório fica no arquivado).
 */
class MergeBulkConfirm
{
    /** @return Collection<int, array{candidate:IdentityCandidate, keep:?Player, drop:?Player, decision:string, reason:string, label:string}> */
    public static function evaluate(bool $divergentes = false): Collection
    {
        return IdentityCandidate::with(['player', 'shadow'])->where('kind', 'mesclar')->where('status', 'pendente')
            ->where('evidence', 'like', 'Mesmo clube e nome compatível%')->orderBy('label')->get()
            ->map(function (IdentityCandidate $c) use ($divergentes) {
                $keep = $c->player;
                $drop = $c->shadow;
                $row = ['candidate' => $c, 'keep' => $keep, 'drop' => $drop, 'label' => ($keep?->name ?? '?').' ← '.$c->label];
                if ($keep && ! $drop && $c->shadow_player_id && static::mergedInto((string) $c->shadow_player_id) === (string) $keep->id) {
                    return $row + ['decision' => 'fechar', 'reason' => 'Provisório já fundido neste cadastro em lote anterior; o item da fila é fechado.'];
                }
                if (! $keep || ! $drop || $keep->is($drop)) {
                    return $row + ['decision' => 'fila', 'reason' => 'Cadastro ou provisório não existe mais.'];
                }
                if ($keep->birth_date && $drop->birth_date && $keep->birth_date->toDateString() !== $drop->birth_date->toDateString()) {
                    $diff = 'Nascimento diverge: provisório '.Carbon::parse($drop->birth_date)->format('d/m/Y').' × cadastro '.$keep->birth_date->format('d/m/Y').'.';

                    return $divergentes
                        ? $row + ['decision' => 'confirmar', 'reason' => $diff.' Fundido mantendo o nascimento do cadastro do scout.']
                        : $row + ['decision' => 'fila', 'reason' => $diff];
                }

                return $row + ['decision' => 'confirmar', 'reason' => 'Mesmo clube e nome compatível.'];
            });
    }

    /** @return array{batch:?string, merged:int, kept_behind:int} */
    public static function apply(Collection $rows, ?int $userId, string $actor): array
    {
        $todo = $rows->where('decision', 'confirmar')->values();
        $close = $rows->where('decision', 'fechar')->values();
        if ($todo->isEmpty() && $close->isEmpty()) {
            return ['batch' => null, 'merged' => 0, 'kept_behind' => 0];
        }
        $pairs = $todo->map(fn ($r) => ['keep' => $r['keep'], 'drop' => $r['drop'], 'rule' => 'Mesclar provisório: mesmo clube e nome compatível (confirmação em lote)']);
        $res = $todo->isEmpty() ? ['batch' => null, 'merged' => 0, 'kept_behind' => 0] : DuplicatePlayers::apply($pairs, $userId, $actor);
        $res['batch'] ??= DataFix::newBatch('dedup');
        $now = now()->toDateTimeString();
        // itens cujo provisório já foi fundido neste mesmo cadastro em lote anterior: só fecha o item da fila (reversível)
        DB::transaction(function () use ($close, $res, $userId, $actor, $now) {
            foreach ($close as $r) {
                $c = $r['candidate'];
                DataFix::apply($res['batch'], 'dedup', 'identity_candidates', $c->id, ['status' => 'vinculado', 'decided_by' => $userId, 'decided_at' => $now,
                    'evidence' => trim(($c->evidence ?? '').' Fechado em lote: provisório já fundido neste cadastro.')], $actor);
            }
        });
        $res['closed'] = $close->count();
        DB::transaction(function () use ($todo, $res, $userId, $actor, $now) {
            foreach ($todo as $r) {
                $c = $r['candidate'];
                // o ID do provedor que veio do provisório passa a vínculo confirmado no cadastro do scout
                foreach (DB::table('player_external_ids')->where('player_id', $r['keep']->id)->where('provider', $c->provider)->where('external_id', $c->external_id)->pluck('id') as $xid) {
                    DataFix::apply($res['batch'], 'dedup', 'player_external_ids', (string) $xid, ['verified' => true, 'verified_by' => $userId, 'link_method' => 'manual'], $actor);
                }
                $note = str_starts_with($r['reason'], 'Nascimento diverge') ? ' '.$r['reason'] : '';
                DataFix::apply($res['batch'], 'dedup', 'identity_candidates', $c->id, ['status' => 'vinculado', 'decided_by' => $userId, 'decided_at' => $now,
                    'evidence' => trim(($c->evidence ?? '').' Confirmado em lote (mesmo clube e nome compatível).'.$note)], $actor);
            }
        });
        // estatística derivada das partidas: recalculada para quem recebeu atuações (derivado, recalculável)
        static::rebuildDerived($todo->map(fn ($r) => $r['keep']->id)->all());

        return $res;
    }

    /** Cadastro em que o provisório arquivado foi fundido (último evento de fusão), ou null. */
    public static function mergedInto(string $playerId): ?string
    {
        if (! Player::withTrashed()->whereKey($playerId)->whereNotNull('deleted_at')->exists()) {
            return null;
        }
        $id = AuditEvent::where('action', 'player_merge')->where('auditable_type', 'Player')
            ->whereRaw("changes->>'from' = ?", [$playerId])->latest('created_at')->value('auditable_id');

        return $id !== null ? (string) $id : null;
    }

    /** Totais derivados das partidas (100% recalculáveis das atuações): apagados e refeitos, como na fusão manual. */
    public static function rebuildDerived(array $playerIds): void
    {
        $providers = DB::table('appearances')->distinct()->pluck('provider')->all(); // fontes cujos totais são derivados das partidas
        foreach (array_unique($playerIds) as $id) {
            foreach ($providers as $prov) {
                DB::table('player_season_stats')->where('player_id', $id)->where('provider', $prov)->delete();
                SeasonStatsAggregator::rebuildProvider($prov, [$id]);
            }
        }
    }

    /** Depois de desfazer: recalcula os totais de todos os cadastros do lote (as atuações voltaram ao provisório). */
    public static function afterRevert(string $batch): void
    {
        static::rebuildDerived(DB::table('data_fix_changes')->where('batch', $batch)->where('table_name', 'players')->distinct()->pluck('row_id')->all());
    }
}
