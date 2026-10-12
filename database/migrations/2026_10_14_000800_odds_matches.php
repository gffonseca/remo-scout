<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * football-data.co.uk — resultados e odds de fechamento (Brasil: só Série A). Tabela própria: não se mistura com as
 * partidas das fontes de escalação e não cria clubes. Uso: contexto de força dos clubes (probabilidade implícita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odds_matches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 40);
            $t->string('league', 60);
            $t->string('season', 20);
            $t->date('played_on');
            $t->string('home_name');
            $t->string('away_name');
            $t->foreignUuid('home_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $t->foreignUuid('away_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $t->unsignedSmallInteger('home_goals')->nullable();
            $t->unsignedSmallInteger('away_goals')->nullable();
            $t->jsonb('odds')->nullable();      // casas como vieram (fechamento)
            $t->jsonb('implied')->nullable();   // {h,d,a} sem margem, da média de fechamento
            $t->timestampTz('fetched_at');
            $t->timestamps();
            $t->unique(['provider', 'league', 'season', 'played_on', 'home_name', 'away_name']);
            $t->index(['season', 'home_club_id']);
            $t->index(['season', 'away_club_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds_matches');
    }
};
