<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto do atleta e escudo do clube: URL de fonte (com origem) ou arquivo enviado pelo departamento (bucket privado). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('photo_path')->nullable(); // enviado manualmente (prioridade)
            $table->string('photo_source', 40)->nullable(); // transfermarkt, sofascore, upload
        });
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('crest_url')->nullable();
            $table->string('crest_path')->nullable();
            $table->string('crest_source', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $t) => $t->dropColumn(['photo_path', 'photo_source']));
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn(['crest_url', 'crest_path', 'crest_source']));
    }
};
