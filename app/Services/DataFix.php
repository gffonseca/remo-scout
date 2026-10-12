<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Correção em lote rastreável: grava antes/depois por linha, com lote e autor; reverte o lote inteiro (idempotente). */
class DataFix
{
    public static function newBatch(string $kind): string
    {
        return $kind.'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
    }

    /** Aplica $after na linha e registra. Só grava as colunas alteradas. */
    public static function apply(string $batch, string $kind, string $table, string $id, array $after, ?string $actor): bool
    {
        $row = (array) DB::table($table)->where('id', $id)->first();
        if (! $row) {
            return false;
        }
        $before = array_intersect_key($row, $after);
        if ($before == $after) {
            return false;
        }
        DB::table($table)->where('id', $id)->update($after + ['updated_at' => now()]);
        DB::table('data_fix_changes')->insert(['batch' => $batch, 'kind' => $kind, 'table_name' => $table, 'row_id' => $id,
            'before' => json_encode($before), 'after' => json_encode($after), 'actor' => $actor, 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /**
     * Reverte um lote. Linha editada depois da correção (valor atual ≠ "depois") não é sobrescrita: vira conflito.
     *
     * @return array{reverted:int, conflicts:list<string>, already:int}
     */
    public static function revert(string $batch): array
    {
        $out = ['reverted' => 0, 'conflicts' => [], 'already' => 0];
        foreach (DB::table('data_fix_changes')->where('batch', $batch)->orderByDesc('id')->get() as $c) {
            if ($c->reverted_at) {
                $out['already']++;

                continue;
            }
            $after = json_decode($c->after, true);
            $current = array_intersect_key((array) DB::table($c->table_name)->where('id', $c->row_id)->first(), $after);
            if ($current != $after) {
                $out['conflicts'][] = $c->table_name.' '.$c->row_id;

                continue;
            }
            DB::transaction(function () use ($c) {
                DB::table($c->table_name)->where('id', $c->row_id)->update(json_decode($c->before, true) + ['updated_at' => now()]);
                DB::table('data_fix_changes')->where('id', $c->id)->update(['reverted_at' => now()]);
            });
            $out['reverted']++;
        }

        return $out;
    }
}
