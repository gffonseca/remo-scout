<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F13 — qualidade por campo da partida (escalação/substituições/gols por lado, duração 90/120). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', fn (Blueprint $t) => $t->jsonb('field_quality')->nullable());
    }

    public function down(): void
    {
        Schema::table('fixtures', fn (Blueprint $t) => $t->dropColumn('field_quality'));
    }
};
