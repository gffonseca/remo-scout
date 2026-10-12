<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F20 — matriz de integrações: cadastro ≠ teste de saúde ≠ ingestão ≠ cobertura conferida ≠ uso autorizado.
 * "Operacional" só com ingestão ponta a ponta + cobertura conferida + uso autorizado.
 * Valores iniciais no DataSourcesSeeder (só onde ainda estiverem vazios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $t) {
            $t->string('usage_authorization', 20)->default('pendente'); // autorizado | restrito | nao_autorizado | pendente
            $t->text('usage_note')->nullable();
            $t->foreignId('usage_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('usage_authorized_at')->nullable();
            $t->text('coverage_note')->nullable();
            $t->foreignId('coverage_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('coverage_checked_at')->nullable();
        });
        // valores iniciais (decisões já tomadas e coberturas medidas) ficam no DataSourcesSeeder: roda depois e alcança fontes novas.
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $t) {
            $t->dropConstrainedForeignId('usage_authorized_by');
            $t->dropConstrainedForeignId('coverage_checked_by');
            $t->dropColumn(['usage_authorization', 'usage_note', 'usage_authorized_at', 'coverage_note', 'coverage_checked_at']);
        });
    }
};
