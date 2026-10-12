<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F16: revisão guarda valor bruto tipado (ex.: UUID do clube) separado do rótulo exibido.
 * Revisões antigas: o rótulo "Clube (#uuid)" é convertido para o UUID; demais campos usam o texto como valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_reviews', function (Blueprint $table) {
            $table->jsonb('current_raw')->nullable();   // valor do cadastro quando a revisão foi aberta
            $table->jsonb('proposed_raw')->nullable();  // valor que será gravado se aceito
            $table->text('decision_note')->nullable();  // motivo de conflito/rejeição
        });
        foreach (DB::table('enrichment_reviews')->get() as $r) {
            $proposed = $r->proposed_value;
            if ($r->field === 'current_club_id' && preg_match('/#([0-9a-f\-]{36})\)$/', (string) $proposed, $m)) {
                $proposed = $m[1];
            }
            DB::table('enrichment_reviews')->where('id', $r->id)->update([
                'proposed_raw' => json_encode($proposed),
                'current_raw' => json_encode($r->field === 'current_club_id' ? DB::table('players')->where('id', $r->player_id)->value('current_club_id') : $r->current_value),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('enrichment_reviews', fn (Blueprint $t) => $t->dropColumn(['current_raw', 'proposed_raw', 'decision_note']));
    }
};
