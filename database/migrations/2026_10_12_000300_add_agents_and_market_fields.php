<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Empresários/agências e campos de mercado no atleta (pedido de 11/10/2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('agency')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('license_number', 60)->nullable(); // licença FIFA/CBF de agente, quando informada
            $table->string('city')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('name');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->nullable()->after('agent_name')->constrained('agents')->nullOnDelete();
            $table->foreignUuid('rights_holder_club_id')->nullable()->after('current_club_id')->constrained('clubs')->nullOnDelete();
            $table->string('economic_rights_note')->nullable(); // ex.: "60% clube X, 40% atleta" (observado)
            $table->string('market_status', 20)->default('desconhecido'); // disponivel, negociavel, indisponivel, desconhecido
            $table->jsonb('languages')->nullable();
            $table->boolean('eu_passport')->nullable(); // null = não informado
            $table->boolean('is_priority')->default(false);
            $table->string('priority_note')->nullable();
            // Faixa salarial vigente (resumo); histórico completo em market_observations.
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->string('salary_currency', 3)->default('BRL');
            $table->string('salary_periodicity', 20)->default('mensal');
            $table->string('salary_confirmation', 20)->default('nao_verificado');
            $table->date('salary_observed_on')->nullable();
            $table->decimal('asking_price', 15, 2)->nullable(); // pedida de transferência/empréstimo
            $table->string('asking_price_currency', 3)->default('BRL');
            $table->index('is_priority');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->dropConstrainedForeignId('rights_holder_club_id');
            $table->dropColumn(['economic_rights_note', 'market_status', 'languages', 'eu_passport', 'is_priority', 'priority_note', 'salary_min', 'salary_max', 'salary_currency', 'salary_periodicity', 'salary_confirmation', 'salary_observed_on', 'asking_price', 'asking_price_currency']);
        });
        Schema::dropIfExists('agents');
    }
};
