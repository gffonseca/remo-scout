<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Como cada ID de clube de uma fonte foi ligado ao clube canônico: manual (confirmado por pessoa), nome exato/sinônimo,
 * núcleo/subconjunto do nome (semelhança: exige confirmação para corrigir dados) ou legado (anterior a este registro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_external_ids', function (Blueprint $t) {
            $t->string('method', 20)->default('legado');
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('club_external_ids', function (Blueprint $t) {
            $t->dropConstrainedForeignId('confirmed_by');
            $t->dropColumn(['method', 'confirmed_at']);
        });
    }
};
