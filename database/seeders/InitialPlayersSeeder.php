<?php

namespace Database\Seeders;

use App\Enums\Position;
use App\Models\Club;
use App\Models\ImportBatch;
use App\Models\Player;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga inicial pedida por Gustavo em 11/10/2026:
 * (1) lista prioritária de 79 nomes; (2) atletas citados nos guias "Brasileirão 2026" Série A e Série B (PDF interno).
 * Somente dados presentes nas fontes (nome, clube, posição, pé, categoria). Nada inventado: o resto fica "não informado".
 * Idempotente pela chave (nome + clube). Registrado como lote de importação reversível.
 */
class InitialPlayersSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(__DIR__.'/data/initial-players.json'), true, flags: JSON_THROW_ON_ERROR);
        $filename = 'carga-inicial-lista-gustavo-e-guias-2026.json';
        if (ImportBatch::where('original_filename', $filename)->whereNull('reverted_at')->exists()) {
            $this->command?->info('Carga inicial já aplicada — nada a fazer.');

            return;
        }

        DB::transaction(function () use ($rows, $filename) {
            $batch = ImportBatch::create(['original_filename' => $filename, 'status' => 'preview', 'rows_total' => count($rows), 'entity' => 'players']);
            $created = $updated = 0;
            foreach ($rows as $r) {
                $club = $r['club'] ? Club::findOrCreateByName($r['club']) : null;
                $existing = Player::query()
                    ->whereRaw('lower(unaccent_name(name)) = lower(unaccent_name(?))', [$r['name']])
                    ->where(fn ($q) => $club ? $q->where('current_club_id', $club->id) : $q->whereNull('current_club_id'))
                    ->first();
                $positions = array_values(array_filter($r['positions'] ?? [], fn ($c) => Position::tryFrom($c)));
                if ($existing) {
                    if ($r['priority_list'] && ! $existing->is_priority) {
                        $existing->update(['is_priority' => true, 'priority_note' => 'Lista prioritária 11/10/2026']);
                        $updated++;
                    }

                    continue;
                }
                Player::create([
                    'name' => $r['name'],
                    'current_club_id' => $club?->id,
                    'primary_position' => $r['primary_position'] && Position::tryFrom($r['primary_position']) ? $r['primary_position'] : null,
                    'positions' => $positions ?: null,
                    'preferred_foot' => $r['preferred_foot'] ?? 'nao_informado',
                    'notes' => $r['notes'],
                    'is_priority' => (bool) $r['priority_list'],
                    'priority_note' => $r['priority_list'] ? 'Lista prioritária 11/10/2026' : null,
                    'data_origin' => 'importacao',
                    'import_batch_id' => $batch->id,
                ]);
                $created++;
            }
            $batch->update(['rows_created' => $created, 'rows_updated' => $updated, 'rows_skipped' => count($rows) - $created - $updated, 'status' => 'concluido', 'completed_at' => now()]);
            $this->command?->info("Carga inicial: {$created} criados, {$updated} marcados como prioritários.");
        });
    }
}
