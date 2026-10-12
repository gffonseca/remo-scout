<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registros públicos da CBF recebidos pelo coletor local (IP brasileiro, Mac Mini).
 * Somente campos em lista branca são gravados — CPF/documentos nunca entram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('transport', 20)->default('direto'); // direto | coletor_local
        });

        Schema::create('cbf_athletes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('cbf_id', 32)->unique();
            $table->string('name');
            $table->string('nickname')->nullable();
            $table->string('club_cbf_id', 32)->nullable();
            $table->string('club_name')->nullable();
            $table->string('club_state', 2)->nullable();
            $table->foreignUuid('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->jsonb('competitions')->nullable(); // {"2026": ["Série D", ...]}
            $table->date('birth_date')->nullable();
            $table->jsonb('stats')->nullable(); // totais CBF por temporada, como publicados
            $table->foreignUuid('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('detail_fetched_at')->nullable();
            $table->timestamps();
            $table->index('club_cbf_id');
            $table->index('player_id');
        });

        Schema::create('cbf_intermediaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('person_type', 20)->nullable(); // Física | Jurídica
            $table->date('published_on')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['normalized_name', 'person_type']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignUuid('cbf_intermediary_id')->nullable()->constrained('cbf_intermediaries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropConstrainedForeignId('cbf_intermediary_id'));
        Schema::dropIfExists('cbf_intermediaries');
        Schema::dropIfExists('cbf_athletes');
        Schema::table('data_sources', fn (Blueprint $t) => $t->dropColumn('transport'));
    }
};
