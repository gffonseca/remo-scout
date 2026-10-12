<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX players_name_unaccent_idx ON players (lower(unaccent_name(name)))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS players_name_unaccent_idx');
    }
};
