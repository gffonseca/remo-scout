<?php

namespace App\Services;

use Illuminate\Support\Carbon;
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
        if (static::same($before, $after)) {
            return false;
        }
        DB::table($table)->where('id', $id)->update($after + ['updated_at' => now()]);
        DB::table('data_fix_changes')->insert(['batch' => $batch, 'kind' => $kind, 'table_name' => $table, 'row_id' => $id,
            'before' => json_encode($before), 'after' => json_encode($after), 'actor' => $actor, 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Registra linha criada pelo lote (a reversão apaga, se ninguém a alterou depois). */
    public static function recordCreated(string $batch, string $kind, string $table, string $id, ?string $actor): void
    {
        $after = array_diff_key((array) DB::table($table)->where('id', $id)->first(), array_flip(['created_at', 'updated_at']));
        DB::table('data_fix_changes')->insert(['batch' => $batch, 'kind' => $kind, 'table_name' => $table, 'row_id' => $id,
            'before' => json_encode(null), 'after' => json_encode($after), 'actor' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Compara valores como o banco devolve: datas pelo instante, números e textos pela forma canônica. */
    private static function same(array $a, array $b): bool
    {
        foreach ($b as $k => $v) {
            $x = $a[$k] ?? null;
            if ($x === null || $v === null) {
                if ($x !== $v) {
                    return false;
                }

                continue;
            }
            if (is_string($x) && is_string($v) && in_array($x[0] ?? '', ['{', '['], true) && is_array($jx = json_decode($x, true)) && is_array($jv = json_decode($v, true))) {
                if (static::canon($jx) !== static::canon($jv)) { // jsonb devolve outra ordem/espaçamento
                    return false;
                }

                continue;
            }
            $date = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/';
            if (is_string($x) && is_string($v) && preg_match($date, $x) && preg_match($date, $v)) {
                if (Carbon::parse($x)->getTimestamp() !== Carbon::parse($v)->getTimestamp()) {
                    return false;
                }

                continue;
            }
            if ((string) (is_bool($x) ? (int) $x : $x) !== (string) (is_bool($v) ? (int) $v : $v)) {
                return false;
            }
        }

        return true;
    }

    private static function canon(array $a): array
    {
        ksort($a);

        return array_map(fn ($v) => is_array($v) ? static::canon($v) : $v, $a);
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
            $before = json_decode($c->before, true);
            $row = DB::table($c->table_name)->where('id', $c->row_id)->first();
            if (! $row || ! static::same((array) $row, $after)) {
                $out['conflicts'][] = $c->table_name.' '.$c->row_id; // apagada ou editada depois: não sobrescreve

                continue;
            }
            DB::transaction(function () use ($c, $before) {
                $before === null
                    ? DB::table($c->table_name)->where('id', $c->row_id)->delete()
                    : DB::table($c->table_name)->where('id', $c->row_id)->update($before + ['updated_at' => now()]);
                DB::table('data_fix_changes')->where('id', $c->id)->update(['reverted_at' => now()]);
            });
            $out['reverted']++;
        }

        return $out;
    }
}
