<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planejador de elenco (Bloco 3): duas estruturas (com e sem posse), posições com titular e alternativas,
 * carências cruzadas com as necessidades abertas e cenários (cópia de um plano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squad_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->foreignUuid('club_id')->nullable()->constrained('clubs')->nullOnDelete(); // elenco de referência (normalmente o Remo)
            $t->string('shape_with_ball', 12);
            $t->string('shape_without_ball', 12);
            $t->uuid('based_on_id')->nullable(); // cenário derivado (FK abaixo: autorreferência precisa da PK criada)
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('squad_plans', fn (Blueprint $t) => $t->foreign('based_on_id')->references('id')->on('squad_plans')->nullOnDelete());
        Schema::create('squad_plan_slots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('squad_plan_id')->constrained()->cascadeOnDelete();
            $t->string('phase', 12); // com_posse | sem_posse
            $t->string('code', 12);
            $t->string('position', 6);
            $t->foreignUuid('tactical_role_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('x');
            $t->unsignedTinyInteger('y');
            $t->unsignedTinyInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['squad_plan_id', 'phase', 'code']);
        });
        Schema::create('squad_plan_picks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('squad_plan_slot_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('depth'); // 1 = titular; 2+ = alternativas em ordem
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['squad_plan_slot_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_plan_picks');
        Schema::dropIfExists('squad_plan_slots');
        Schema::dropIfExists('squad_plans');
    }
};
