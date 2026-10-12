<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('city')->nullable()->after('state');
            $table->jsonb('aliases')->nullable();
            $table->jsonb('competitions')->nullable(); // ex.: {"2026": ["Série B", "Copa Verde"]}
            $table->string('current_division', 20)->nullable(); // Série A/B/C/D/sem divisão (temporada de referência)
        });

        // Contatos do atleta (pedido de 11/10/2026). Dado pessoal: visível só a perfis com edição; auditado.
        Schema::table('players', function (Blueprint $table) {
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_note')->nullable(); // ex.: "contato autorizado pelo empresário"
        });
    }

    public function down(): void
    {
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn(['city', 'aliases', 'competitions', 'current_division']));
        Schema::table('players', fn (Blueprint $t) => $t->dropColumn(['phone', 'whatsapp', 'email', 'contact_note']));
    }
};
