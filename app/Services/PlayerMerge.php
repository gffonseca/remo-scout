<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Player;
use App\Support\ForeignKeys;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Mescla um cadastro provisório ($from, ex.: criado a partir de escalação) no cadastro definitivo ($into) — F14.
 * Move todas as referências (descobertas pelas FKs do banco). Se a mesma informação já existir no destino
 * (ex.: atuação na mesma partida), a do provisório é descartada e listada no relatório. Depois apaga o provisório.
 * Só é chamado por decisão humana na fila "Identidades a revisar".
 */
class PlayerMerge
{
    /** @return array{moved:int, dropped:list<string>} */
    public static function merge(Player $from, Player $into, ?int $userId): array
    {
        if ($from->is($into)) {
            throw new \InvalidArgumentException('Não é possível mesclar um cadastro nele mesmo.');
        }

        return DB::transaction(function () use ($from, $into, $userId) {
            $moved = 0;
            $dropped = [];
            foreach (ForeignKeys::referencing('players') as $fk) {
                if ($fk->tbl === 'identity_candidates' && $fk->col === 'shadow_player_id') {
                    continue; // a própria candidatura de mescla é resolvida por quem chamou
                }
                foreach (DB::table($fk->tbl)->where($fk->col, $from->id)->pluck('id') as $rowId) {
                    try {
                        DB::transaction(fn () => DB::table($fk->tbl)->where('id', $rowId)->update([$fk->col => $into->id]));
                        $moved++;
                    } catch (UniqueConstraintViolationException) {
                        DB::table($fk->tbl)->where('id', $rowId)->delete(); // destino já tem o equivalente
                        $dropped[] = "{$fk->tbl}#{$rowId}";
                    }
                }
            }
            // agregados derivados de partidas: reconstruídos para todo provedor com atuações no destino
            // (derivados 100% das partidas: apagados e recalculados — o unique com clube NULL não impede linhas repetidas)
            foreach (DB::table('appearances')->where('player_id', $into->id)->distinct()->pluck('provider') as $prov) {
                DB::table('player_season_stats')->where('player_id', $into->id)->where('provider', $prov)->delete();
                SeasonStatsAggregator::rebuildProvider($prov, [$into->id]);
            }
            AuditEvent::record('player_merge', $into, ['from' => $from->id, 'from_name' => $from->name, 'moved' => $moved, 'dropped' => count($dropped), 'by' => $userId]);
            $from->forceDelete();

            return ['moved' => $moved, 'dropped' => $dropped];
        });
    }
}
