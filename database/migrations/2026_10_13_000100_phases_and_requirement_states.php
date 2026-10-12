<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloco 1 (F03/F04): requisitos por papel e fase, estados por requisito com evidência e confirmação.
 * Não destrutiva: colunas antigas de necessidade (tactical_role_id, target_level) continuam e viram
 * requisito "geral"; fases antigas de transição são mapeadas e o valor original fica anotado na evidência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_role_assessments', function (Blueprint $table) {
            $table->date('observed_on')->nullable()->after('period_label'); // data da observação (validade)
            $table->index(['player_id', 'tactical_role_id', 'kind', 'phase']);
        });
        DB::table('player_role_assessments')->whereNull('observed_on')->update(['observed_on' => DB::raw('created_at::date')]);
        foreach (['transicao_ofensiva' => ['com_posse', 'transição ofensiva'], 'transicao_defensiva' => ['sem_posse', 'transição defensiva']] as $old => [$new, $label]) {
            DB::table('player_role_assessments')->where('phase', $old)->update([
                'phase' => $new,
                'evidence' => DB::raw("concat('[fase original: {$label}] ', coalesce(evidence, ''))"),
            ]);
        }
        DB::table('player_role_assessments')->where('phase', 'geral')->update(['phase' => null]);
        DB::table('player_role_assessments')->whereNotNull('phase')->whereNotIn('phase', ['com_posse', 'sem_posse', 'bola_parada'])
            ->update(['evidence' => DB::raw("concat('[fase original: ', phase, '] ', coalesce(evidence, ''))"), 'phase' => null]);

        Schema::create('need_role_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recruitment_need_id')->constrained()->cascadeOnDelete();
            // restrict: excluir um papel não pode fazer um requisito obrigatório sumir em silêncio
            $table->foreignUuid('tactical_role_id')->constrained()->restrictOnDelete();
            $table->string('phase', 20)->nullable(); // null = geral
            $table->string('min_level', 2); // A, B, C, D
            $table->boolean('is_mandatory')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
            // nullsNotDistinct: no PostgreSQL, NULL repetido não violaria o unique; "geral" deve ser único também
            $table->unique(['recruitment_need_id', 'tactical_role_id', 'phase'])->nullsNotDistinct();
        });
        foreach (DB::table('recruitment_needs')->whereNotNull('tactical_role_id')->whereIn('target_level', ['A', 'B', 'C', 'D'])->get() as $n) {
            DB::table('need_role_requirements')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(), 'recruitment_need_id' => $n->id, 'tactical_role_id' => $n->tactical_role_id,
                'phase' => null, 'min_level' => $n->target_level, 'is_mandatory' => true,
                'notes' => 'Migrado do nível-alvo da necessidade', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('need_requirement_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recruitment_need_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_key', 40); // sha1 do texto normalizado
            $table->text('requirement_text');
            $table->string('status', 20)->default('pendente');
            $table->text('evidence')->nullable();
            $table->string('evidence_url', 1000)->nullable();
            $table->string('evidence_path')->nullable(); // anexo no disco "media"
            $table->foreignId('evidence_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evidence_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['recruitment_need_id', 'player_id', 'requirement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('need_requirement_checks');
        Schema::dropIfExists('need_role_requirements');
        Schema::table('player_role_assessments', function (Blueprint $table) {
            $table->dropIndex(['player_id', 'tactical_role_id', 'kind', 'phase']);
            $table->dropColumn('observed_on');
        });
    }
};
