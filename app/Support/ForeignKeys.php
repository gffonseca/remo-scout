<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Chaves estrangeiras que apontam para uma tabela, lidas do próprio PostgreSQL (cobre tabelas futuras). */
class ForeignKeys
{
    private static array $cache = [];

    /** @return list<object{tbl:string, col:string}> */
    public static function referencing(string $table): array
    {
        return static::$cache[$table] ??= DB::select('
            select kcu.table_name as tbl, kcu.column_name as col
            from information_schema.referential_constraints rc
            join information_schema.key_column_usage kcu on kcu.constraint_name = rc.constraint_name and kcu.constraint_schema = rc.constraint_schema
            join information_schema.key_column_usage pk on pk.constraint_name = rc.unique_constraint_name and pk.constraint_schema = rc.unique_constraint_schema
            where pk.table_name = ? and kcu.constraint_schema = current_schema()
            order by kcu.table_name, kcu.column_name', [$table]);
    }
}
