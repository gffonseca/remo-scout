<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->string('data_quality', 20)->default('completo'); // completo | parcial (sem substituições/gols coerentes → minutos indeterminados)
            $table->text('quality_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', fn (Blueprint $t) => $t->dropColumn(['data_quality', 'quality_note']));
    }
};
