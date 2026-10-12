<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API-Football (plano Pro assinado pelo Gustavo em 12/10/2026).
 * - appearances.stats: estatística individual da partida como veio do provedor (só campos presentes; ausente ≠ 0).
 * - provider_athletes: cadastro do provedor (nome, nascimento, altura, foto) usado para vínculo por nome + nascimento.
 *   Campo de lesão do provedor é descartado na entrada (sem dado de saúde).
 * - Fonte ligada com cota abaixo do contrato (7.000 de 7.500/dia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appearances', fn (Blueprint $t) => $t->jsonb('stats')->nullable());
        Schema::create('provider_athletes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 40);
            $t->string('external_id', 40);
            $t->string('name');
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->date('birth_date')->nullable();
            $t->unsignedSmallInteger('height_cm')->nullable();
            $t->string('photo_url')->nullable();
            $t->string('team_external_id', 40)->nullable();
            $t->string('season', 20)->nullable();
            $t->timestampTz('fetched_at');
            $t->timestamps();
            $t->unique(['provider', 'external_id']);
            $t->index(['provider', 'team_external_id']);
            $t->index('birth_date');
        });
        DB::table('data_sources')->where('key', 'api_football')->update([
            'enabled' => true, 'daily_quota' => 7000, 'min_interval_ms' => 250, 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('data_sources')->where('key', 'api_football')->update(['enabled' => false]);
        Schema::dropIfExists('provider_athletes');
        Schema::table('appearances', fn (Blueprint $t) => $t->dropColumn('stats'));
    }
};
