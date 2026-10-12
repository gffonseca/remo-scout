<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Watchlist (Bloco 3): responsável, motivo e próxima revisão. Um item ativo por atleta.
 * Alertas só dentro do sistema (sino), deduplicados por chave (decisão do Q&A: sem e-mail/WhatsApp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $t->foreignId('owner_id')->constrained('users');
            $t->text('reason');
            $t->date('next_review_on')->nullable();
            $t->string('status', 12)->default('ativo'); // ativo | encerrado
            $t->text('closed_reason')->nullable();
            $t->foreignUuid('last_seen_club_id')->nullable()->constrained('clubs')->nullOnDelete(); // para alerta de troca de clube
            $t->timestampTz('last_reviewed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        DB::statement("create unique index watchlist_one_active_per_player on watchlist_items (player_id) where status = 'ativo'");
        Schema::create('watchlist_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('watchlist_item_id')->constrained()->cascadeOnDelete();
            $t->string('dedupe_key', 160);
            $t->string('kind', 30);
            $t->text('message');
            $t->timestamps();
            $t->unique(['watchlist_item_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_alerts');
        Schema::dropIfExists('watchlist_items');
    }
};
