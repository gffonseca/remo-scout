<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elenco atual por clube segundo um provedor (Dados Futebol). Retrato de origem, não cadastro:
 * o vínculo com o atleta do scout só existe por ID externo confirmado; nome + clube vira revisão (F14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_squad_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('club_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 40);
            $t->string('external_player_id', 40);
            $t->string('name');
            $t->string('display_name')->nullable();
            $t->unsignedSmallInteger('shirt_number')->nullable();
            $t->string('position_raw', 40)->nullable(); // como veio; a fonte erra posição com frequência
            $t->foreignUuid('player_id')->nullable()->constrained()->nullOnDelete();
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->timestampTz('left_at')->nullable(); // ausente no retrato mais recente
            $t->jsonb('raw')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'club_id', 'external_player_id']);
            $t->index(['player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_squad_entries');
    }
};
