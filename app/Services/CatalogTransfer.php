<?php

namespace App\Services;

use App\Models\Specialty;
use App\Models\TacticalRole;
use Illuminate\Support\Facades\DB;

/** Importação/exportação JSON do catálogo (formato compatível com catalogo-papeis-remo-scout.json). */
class CatalogTransfer
{
    public static function export(): array
    {
        return [
            'version' => 'remo-scout-export-1',
            'exported_at' => now()->toIso8601String(),
            'roles' => TacticalRole::query()->orderBy('position_group')->orderBy('name')->get()->map(fn (TacticalRole $r) => [
                'id' => $r->code, 'position_group' => $r->position_group, 'name' => $r->name, 'aliases' => $r->aliases,
                'description' => $r->description, 'compatible_positions' => $r->compatible_positions, 'phases' => $r->phases,
                'behaviors' => $r->behaviors, 'mandatory_requirements' => $r->mandatory_requirements,
                'desirable_requirements' => $r->desirable_requirements, 'criteria' => $r->criteria,
                'evaluation_suggestions' => $r->evaluation_suggestions, 'source_url' => $r->source_url,
                'status' => $r->status->value, 'catalog_version' => $r->version,
            ])->all(),
            'specialties' => Specialty::query()->orderBy('name')->get()->map(fn (Specialty $s) => [
                'id' => $s->code, 'name' => $s->name, 'aliases' => $s->aliases, 'description' => $s->description,
                'compatible_positions' => $s->compatible_positions, 'evaluation_suggestions' => $s->evaluation_suggestions,
                'status' => $s->status->value, 'catalog_version' => $s->version,
            ])->all(),
        ];
    }

    /**
     * Upsert por código. Itens existentes geram nova versão (histórico preservado); nada é apagado.
     *
     * @return array{roles_created:int,roles_updated:int,specialties_created:int,specialties_updated:int}
     */
    public static function import(array $payload): array
    {
        $stats = ['roles_created' => 0, 'roles_updated' => 0, 'specialties_created' => 0, 'specialties_updated' => 0];
        $validStatus = ['proposta', 'ativo', 'arquivado'];
        $statusOf = fn ($s) => in_array($s, $validStatus, true) ? $s : 'proposta';

        DB::transaction(function () use ($payload, &$stats, $statusOf) {
            foreach ($payload['roles'] ?? [] as $r) {
                if (empty($r['id']) || empty($r['name']) || empty($r['position_group'])) {
                    throw new \InvalidArgumentException('Papel sem id/name/position_group no JSON.');
                }
                $attrs = array_filter([
                    'position_group' => $r['position_group'], 'name' => $r['name'], 'aliases' => $r['aliases'] ?? null,
                    'description' => $r['description'] ?? null, 'compatible_positions' => $r['compatible_positions'] ?? null,
                    'phases' => $r['phases'] ?? null, 'behaviors' => $r['behaviors'] ?? null,
                    'mandatory_requirements' => $r['mandatory_requirements'] ?? null,
                    'desirable_requirements' => $r['desirable_requirements'] ?? null, 'criteria' => $r['criteria'] ?? null,
                    'evaluation_suggestions' => $r['evaluation_suggestions'] ?? null, 'source_url' => $r['source_url'] ?? null,
                    'status' => $statusOf($r['status'] ?? 'proposta'),
                ], fn ($v) => $v !== null);
                $model = TacticalRole::where('code', $r['id'])->first();
                if ($model) {
                    $model->fill($attrs);
                    $model->pendingChangeNote = 'Importação JSON';
                    if ($model->isDirty()) {
                        $model->save();
                        $stats['roles_updated']++;
                    }
                } else {
                    TacticalRole::create($attrs + ['code' => $r['id']]);
                    $stats['roles_created']++;
                }
            }
            foreach ($payload['specialties'] ?? [] as $s) {
                if (empty($s['id']) || empty($s['name'])) {
                    throw new \InvalidArgumentException('Especialidade sem id/name no JSON.');
                }
                $attrs = array_filter([
                    'name' => $s['name'], 'aliases' => $s['aliases'] ?? null, 'description' => $s['description'] ?? null,
                    'compatible_positions' => $s['compatible_positions'] ?? null, 'evaluation_suggestions' => $s['evaluation_suggestions'] ?? null,
                    'status' => $statusOf($s['status'] ?? 'proposta'),
                ], fn ($v) => $v !== null);
                $model = Specialty::where('code', $s['id'])->first();
                if ($model) {
                    $model->fill($attrs);
                    $model->pendingChangeNote = 'Importação JSON';
                    if ($model->isDirty()) {
                        $model->save();
                        $stats['specialties_updated']++;
                    }
                } else {
                    Specialty::create($attrs + ['code' => $s['id']]);
                    $stats['specialties_created']++;
                }
            }
        });

        return $stats;
    }
}
