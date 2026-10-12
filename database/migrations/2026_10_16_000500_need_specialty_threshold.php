<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Especialidade obrigatória exige limiar (nível mínimo e, opcional, amostra mínima) — existir avaliação não basta. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_need_specialty', function (Blueprint $t) {
            $t->string('min_level', 20)->nullable();
            $t->unsignedSmallInteger('min_attempts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_need_specialty', fn (Blueprint $t) => $t->dropColumn(['min_level', 'min_attempts']));
    }
};
