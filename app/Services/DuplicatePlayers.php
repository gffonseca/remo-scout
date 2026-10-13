<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Player;
use App\Support\ForeignKeys;
use App\Support\NameMatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cadastros duplicados do mesmo atleta (pedido do Gustavo, 12/10/2026: "remova duplicados").
 * Só pares com evidência forte:
 *  R1 — mesmo clube atual, mesmo nascimento (ambos preenchidos) e nome compatível;
 *  R2 — prévia de convergência: o registro CBF de um tem o mesmo nascimento do único candidato do Transfermarkt do outro,
 *       mesmo clube e nome compatível.
 * Mantém: cadastro do scout (não provisório) → prioritário → mais casos/relatórios/watchlist → mais antigo.
 *
 * Fusão REVERSÍVEL (lote em data_fix_changes): cada referência movida é registrada; o duplicado é arquivado
 * (exclusão lógica), nunca apagado; campos vazios do mantido são completados com os do duplicado.
 * Referência que colidiria com uma igual no mantido fica no arquivado (nada é apagado).
 */
class DuplicatePlayers
{
    public const NAME_MIN = 85;

    /** @return Collection<int, array{keep:Player, drop:Player, rule:string}> */
    public static function find(array $convergenceRows = []): Collection
    {
        $pairs = collect();
        $seen = [];
        $add = function (Player $a, Player $b, string $rule) use (&$pairs, &$seen) {
            $k = collect([$a->id, $b->id])->sort()->implode('|');
            if (isset($seen[$k]) || $a->is($b)) {
                return;
            }
            $seen[$k] = true;
            [$keep, $drop] = static::order($a, $b);
            $pairs->push(['keep' => $keep, 'drop' => $drop, 'rule' => $rule]);
        };
        $rows = DB::select(<<<'SQL'
            select a.id as a, b.id as b from players a
            join players b on b.current_club_id = a.current_club_id and b.birth_date = a.birth_date and b.id > a.id and b.deleted_at is null
            where a.deleted_at is null and a.current_club_id is not null and a.birth_date is not null
            SQL);
        foreach ($rows as $r) {
            $a = Player::find($r->a);
            $b = Player::find($r->b);
            if ($a && $b && static::compatible($a, $b)) {
                $add($a, $b, 'Mesmo clube, mesmo nascimento ('.$a->birth_date->format('d/m/Y').') e nome compatível');
            }
        }
        foreach ($convergenceRows as $row) {
            if (($row['decision'] ?? null) !== 'duplicado' || empty($row['duplicate_of'])) {
                continue;
            }
            $a = Player::find($row['player_id']);
            $b = Player::find($row['duplicate_of']);
            if ($a && $b && $a->current_club_id && $a->current_club_id === $b->current_club_id) {
                $add($a, $b, $row['reason']);
            }
        }

        return $pairs->values();
    }

    public static function compatible(Player $a, Player $b): bool
    {
        return max(NameMatcher::score($a->name, $b->name), NameMatcher::score($a->full_name, $b->name), NameMatcher::score($a->name, $b->full_name),
            NameMatcher::score($a->full_name, $b->full_name)) >= self::NAME_MIN;
    }

    /** @return array{0:Player, 1:Player} [mantido, arquivado] */
    private static function order(Player $a, Player $b): array
    {
        $score = function (Player $p) {
            $origin = $p->data_origin instanceof \BackedEnum ? $p->data_origin->value : (string) $p->data_origin;

            return [
                $origin === 'provedor' ? 0 : 1,
                (int) $p->is_priority,
                DB::table('prospect_cases')->where('player_id', $p->id)->count() + DB::table('watchlist_items')->where('player_id', $p->id)->count()
                    + DB::table('scouting_reports')->where('player_id', $p->id)->count(),
                -($p->created_at?->getTimestamp() ?? 0),
            ];
        };

        return $score($a) >= $score($b) ? [$a, $b] : [$b, $a];
    }

    /** Funde os pares num único lote reversível. @return array{batch:?string, merged:int, kept_behind:int} */
    public static function apply(Collection $pairs, ?int $userId, string $actor): array
    {
        if ($pairs->isEmpty()) {
            return ['batch' => null, 'merged' => 0, 'kept_behind' => 0];
        }
        $batch = DataFix::newBatch('dedup');
        $behind = 0;
        $merged = 0;
        foreach ($pairs as $pair) {
            DB::transaction(function () use ($pair, $batch, $actor, $userId, &$behind, &$merged) {
                $keep = Player::find($pair['keep']->id);
                $drop = Player::find($pair['drop']->id);
                if (! $keep || ! $drop) {
                    return;
                }
                // 1) campos vazios do mantido recebem os do arquivado (o ID CBF, único, sai do arquivado antes)
                $skip = ['id', 'name', 'created_at', 'updated_at', 'deleted_at', 'data_origin', 'is_priority', 'enriched_at', 'field_sources'];
                $fill = [];
                foreach ($drop->getAttributes() as $col => $val) {
                    if (! in_array($col, $skip, true) && $val !== null && $keep->getRawOriginal($col) === null) {
                        $fill[$col] = $val;
                    }
                }
                if (isset($fill['cbf_id'])) {
                    DataFix::apply($batch, 'dedup', 'players', $drop->id, ['cbf_id' => null], $actor);
                }
                // 2) referências: cada linha movida fica no lote
                foreach (ForeignKeys::referencing('players') as $fk) {
                    if ($fk->tbl === 'identity_candidates' && $fk->col === 'shadow_player_id') {
                        continue;
                    }
                    foreach (DB::table($fk->tbl)->where($fk->col, $drop->id)->pluck('id') as $rowId) {
                        try {
                            DB::transaction(fn () => DataFix::apply($batch, 'dedup', $fk->tbl, (string) $rowId, [$fk->col => $keep->id], $actor));
                        } catch (UniqueConstraintViolationException) {
                            $behind++; // equivalente já existe no mantido: fica no arquivado
                        }
                    }
                }
                if ($fill) {
                    $src = $keep->field_sources ?? [];
                    foreach (array_keys($fill) as $col) {
                        $src[$col] = ['source' => 'fusao', 'at' => now()->toDateString(), 'from' => $drop->id];
                    }
                    DataFix::apply($batch, 'dedup', 'players', $keep->id, $fill + ['field_sources' => json_encode($src), 'enriched_at' => null], $actor);
                }
                // 3) arquiva o duplicado (exclusão lógica, reversível)
                DataFix::apply($batch, 'dedup', 'players', $drop->id, ['deleted_at' => now()->toDateTimeString(),
                    'notes' => trim(($drop->notes ? $drop->notes."\n" : '').'Duplicado de '.$keep->name.' (id '.$keep->id.'), arquivado em '.now()->format('d/m/Y').': '.$pair['rule'].'.')], $actor);
                AuditEvent::record('player_merge', $keep, ['from' => $drop->id, 'from_name' => $drop->name, 'batch' => $batch, 'rule' => $pair['rule'], 'by' => $userId]);
                $merged++;
            });
        }

        return ['batch' => $batch, 'merged' => $merged, 'kept_behind' => $behind];
    }
}
