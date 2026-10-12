<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F17 — coletor local: lote com chave de idempotência (reenvio não reprocessa nem soma contador),
 * manifesto de páginas esperadas × recebidas por competição e fila de tarefas sob demanda (Sofascore pelo Mac).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sync_run_id')->constrained()->cascadeOnDelete();
            $t->string('batch_key', 100);
            $t->string('kind', 30);
            $t->string('competition', 120)->nullable();
            $t->unsignedInteger('page')->nullable();
            $t->unsignedInteger('total_pages')->nullable();
            $t->unsignedInteger('items')->default(0);
            $t->jsonb('result')->nullable();
            $t->timestamps();
            $t->unique(['sync_run_id', 'batch_key']);
            $t->index(['sync_run_id', 'competition']);
        });
        Schema::create('collector_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('source', 40);
            $t->string('kind', 40);
            $t->foreignUuid('player_id')->nullable()->constrained()->cascadeOnDelete();
            $t->jsonb('pending_paths')->nullable(); // endereços que o servidor precisa que o Mac busque
            $t->string('status', 20)->default('pendente'); // pendente | aguardando_coletor | concluida | falhou
            $t->unsignedSmallInteger('rounds')->default(0);
            $t->text('result')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('finished_at')->nullable();
            $t->timestamps();
            $t->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_tasks');
        Schema::dropIfExists('collector_batches');
    }
};
