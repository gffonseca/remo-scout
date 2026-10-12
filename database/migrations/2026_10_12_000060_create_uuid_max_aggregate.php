<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL não tem MAX(uuid). O Eloquent (latestOfMany/ofMany) usa MAX(chave) como desempate;
 * com chaves UUID ordenadas (v7, HasUuids) o maior UUID é o mais recente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_proc p JOIN pg_type t ON t.oid = ANY(p.proargtypes)
        WHERE p.proname = 'max' AND p.prokind = 'a' AND t.typname = 'uuid'
    ) THEN
        CREATE OR REPLACE FUNCTION uuid_larger(uuid, uuid) RETURNS uuid
            LANGUAGE sql IMMUTABLE PARALLEL SAFE
            AS 'SELECT CASE WHEN $1 IS NULL OR $1 < $2 THEN $2 ELSE $1 END';
        CREATE AGGREGATE max(uuid) (SFUNC = uuid_larger, STYPE = uuid, COMBINEFUNC = uuid_larger, PARALLEL = SAFE);
    END IF;
END
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP AGGREGATE IF EXISTS max(uuid); DROP FUNCTION IF EXISTS uuid_larger(uuid, uuid);');
    }
};
