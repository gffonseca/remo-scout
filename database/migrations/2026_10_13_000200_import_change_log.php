<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F05: trilha campo a campo do que cada lote de importação criou ou alterou, para reverter com segurança.
 * Lotes anteriores a esta migração não têm trilha: a reversão deles continua só removendo o que criaram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 40); // player, agent, club
            $table->string('entity_id', 64);
            $table->string('action', 10); // created, updated
            $table->string('field', 60)->nullable(); // só em updated
            $table->jsonb('old_value')->nullable(); // valor bruto do banco antes
            $table->jsonb('new_value')->nullable(); // valor bruto gravado pelo lote (created: snapshot dos campos)
            $table->timestamps();
            $table->index(['import_batch_id', 'entity_type']);
        });
        Schema::table('import_batches', function (Blueprint $table) {
            $table->jsonb('revert_report')->nullable();
            $table->boolean('has_change_log')->default(false);
        });
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignUuid('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropConstrainedForeignId('import_batch_id'));
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropColumn(['revert_report', 'has_change_log']));
        Schema::dropIfExists('import_changes');
    }
};
