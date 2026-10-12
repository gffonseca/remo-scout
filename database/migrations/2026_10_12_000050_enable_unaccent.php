<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        // Wrapper IMMUTABLE para permitir índice funcional de busca por nome sem acento.
        DB::statement("CREATE OR REPLACE FUNCTION unaccent_name(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS \$\$ SELECT public.unaccent('public.unaccent', \$1) \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS unaccent_name(text)');
    }
};
