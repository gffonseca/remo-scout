<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F22 — critérios observáveis A–D por fase (papel) e por nível (especialidade); aprovação com data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tactical_roles', function (Blueprint $t) {
            $t->jsonb('level_guide')->nullable(); // {"com_posse": {"A": "...", "B": "...", "C": "...", "D": "..."}, ...}
            $t->timestampTz('reviewed_at')->nullable();
        });
        Schema::table('specialties', function (Blueprint $t) {
            $t->jsonb('level_guide')->nullable(); // {"A": "...", ...}
            $t->timestampTz('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tactical_roles', fn (Blueprint $t) => $t->dropColumn(['level_guide', 'reviewed_at']));
        Schema::table('specialties', fn (Blueprint $t) => $t->dropColumn(['level_guide', 'reviewed_at']));
    }
};
