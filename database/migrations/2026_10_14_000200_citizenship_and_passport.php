<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F15: cidadania (declarada por fonte) ≠ passaporte comunitário (documento conferido).
 * - citizenships: [{country, source, at}] — o que fontes dizem; não confirma documento.
 * - eu_passport só pode ser "Sim" com evidência, responsável e data (regra no modelo).
 * - "Sim" existentes sem evidência voltam a não informado; a nacionalidade vira cidadania com a fonte conhecida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->jsonb('citizenships')->nullable();
            $table->text('eu_passport_evidence')->nullable();
            $table->foreignId('eu_passport_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('eu_passport_confirmed_at')->nullable();
        });
        foreach (DB::table('players')->select('id', 'nationality', 'second_nationality', 'field_sources', 'eu_passport', 'notes')->get() as $p) {
            $src = json_decode((string) $p->field_sources, true) ?: [];
            $cit = [];
            foreach (['nationality', 'second_nationality'] as $f) {
                if (filled($p->{$f})) {
                    $cit[] = ['country' => $p->{$f}, 'source' => $src[$f]['source'] ?? 'cadastro', 'at' => $src[$f]['at'] ?? null];
                }
            }
            $upd = ['citizenships' => $cit ? json_encode($cit) : null];
            if ($p->eu_passport === true) {
                $upd['eu_passport'] = null;
                $upd['notes'] = trim(($p->notes ? $p->notes."\n" : '').'[12/10/2026] Passaporte comunitário estava marcado sem documento conferido (possível inferência de cidadania). Voltou a "não informado": confirmar com documento.');
            }
            DB::table('players')->where('id', $p->id)->update($upd);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eu_passport_confirmed_by');
            $table->dropColumn(['citizenships', 'eu_passport_evidence', 'eu_passport_confirmed_at']);
        });
    }
};
