<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bloco 3 — comparador fixo (até 5 atletas por usuário) e preferências de interface por usuário (colunas/filtros salvos). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compare_pins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'player_id']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->jsonb('ui_preferences')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ui_preferences'));
        Schema::dropIfExists('compare_pins');
    }
};
