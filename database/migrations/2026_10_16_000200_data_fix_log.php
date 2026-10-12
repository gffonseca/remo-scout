<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Trilha de correções em lote (backfills): antes/depois por linha, lote, autor e reversão. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_fix_changes', function (Blueprint $t) {
            $t->id();
            $t->string('batch', 60)->index();
            $t->string('kind', 40); // stats_club, photo
            $t->string('table_name', 60);
            $t->uuid('row_id');
            $t->jsonb('before');
            $t->jsonb('after');
            $t->string('actor', 120)->nullable();
            $t->timestampTz('reverted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_fix_changes');
    }
};
