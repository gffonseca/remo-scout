<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F14: vínculo automático só com ID oficial confirmado ou nome + nascimento iguais.
 * Vínculos antigos feitos só por nome + clube viram "prováveis" e entram na fila "Identidades a revisar".
 * Nenhum dado é apagado aqui; descartar o vínculo na revisão desfaz o que veio daquela fonte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_external_ids', function (Blueprint $table) {
            $table->string('link_method', 30)->nullable(); // manual, id_oficial, nome_nascimento, nome_clube_legado
            $table->text('evidence')->nullable();
        });
        Schema::table('cbf_athletes', function (Blueprint $table) {
            $table->string('link_status', 12)->nullable(); // provavel | confirmado
        });
        Schema::table('identity_candidates', function (Blueprint $table) {
            $table->string('kind', 12)->default('escolher'); // escolher (entre resultados), confirmar (vínculo provável), mesclar (cadastro provisório)
            $table->foreignUuid('shadow_player_id')->nullable()->constrained('players')->cascadeOnDelete();
            $table->text('evidence')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
        });

        $now = now();
        DB::table('player_external_ids')->where('verified', true)->update(['link_method' => 'manual']);
        foreach (DB::table('player_external_ids')->where('verified', false)->get() as $x) {
            DB::table('player_external_ids')->where('id', $x->id)->update(['link_method' => 'nome_clube_legado']);
            DB::table('identity_candidates')->updateOrInsert(
                ['player_id' => $x->player_id, 'provider' => $x->provider, 'external_id' => $x->external_id],
                ['id' => (string) \Illuminate\Support\Str::uuid7(), 'kind' => 'confirmar', 'status' => 'pendente', 'score' => 0,
                    'label' => "Vínculo automático antigo (só nome + clube) com {$x->provider} #{$x->external_id}",
                    'evidence' => 'Criado antes da regra de 12/10/2026 (nome + nascimento). Confirme ou descarte; descartar desfaz os dados dessa fonte.',
                    'created_at' => $now, 'updated_at' => $now],
            );
        }

        // CBF: vínculo por ID oficial = confirmado; por nome + clube (field_sources.cbf_id.source = cbf) = provável
        foreach (DB::table('cbf_athletes')->whereNotNull('player_id')->get() as $a) {
            $p = DB::table('players')->where('id', $a->player_id)->first(['id', 'cbf_id', 'field_sources']);
            $src = json_decode((string) ($p->field_sources ?? ''), true) ?: [];
            $byName = ($src['cbf_id']['source'] ?? null) === 'cbf';
            DB::table('cbf_athletes')->where('id', $a->id)->update(['link_status' => $byName ? 'provavel' : 'confirmado']);
            if ($byName) {
                DB::table('identity_candidates')->updateOrInsert(
                    ['player_id' => $a->player_id, 'provider' => 'cbf', 'external_id' => $a->cbf_id],
                    ['id' => (string) \Illuminate\Support\Str::uuid7(), 'kind' => 'confirmar', 'status' => 'pendente', 'score' => 0,
                        'label' => trim(($a->nickname ? $a->nickname.' — ' : '').$a->name.' · '.($a->club_name ?? 'sem clube').' · CBF #'.$a->cbf_id),
                        'evidence' => 'Ligado em 12/10/2026 só por nome + clube de inscrição. Nascimento CBF: '.($a->birth_date ?? 'não coletado').'.',
                        'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('identity_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shadow_player_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['kind', 'evidence', 'decided_at']);
        });
        Schema::table('cbf_athletes', fn (Blueprint $t) => $t->dropColumn('link_status'));
        Schema::table('player_external_ids', fn (Blueprint $t) => $t->dropColumn(['link_method', 'evidence']));
    }
};
