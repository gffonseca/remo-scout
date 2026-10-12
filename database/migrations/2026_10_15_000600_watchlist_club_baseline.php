<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisão do Bloco 3: last_seen_club_id nulo significava "sem linha de base" E "sem clube".
 * Com a marca de linha de base, atleta sem clube que assina passa a gerar alerta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlist_items', fn (Blueprint $t) => $t->timestampTz('club_baseline_at')->nullable());
        // Itens com clube registrado já têm linha de base; os nulos ficam ambíguos e são inicializados na próxima execução.
        DB::table('watchlist_items')->whereNotNull('last_seen_club_id')->update(['club_baseline_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('watchlist_items', fn (Blueprint $t) => $t->dropColumn('club_baseline_at'));
    }
};
