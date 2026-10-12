<?php

namespace Database\Seeders;

use App\Enums\Position;
use App\Models\Specialty;
use App\Models\TacticalRole;
use Illuminate\Database\Seeder;

/**
 * Seed do catálogo proposto (40 papéis em 8 grupos + 12 especialidades) a partir de
 * data/catalogo-papeis-remo-scout.json. Status "proposta": rótulos editáveis, sem pesos.
 * Idempotente: não sobrescreve itens já existentes (preserva edições do departamento).
 */
class CatalogSeeder extends Seeder
{
    private const GROUP_CRITERIA = [
        'Goleiro' => ['Posicionamento e defesa de finalizações', 'Domínio da área e cruzamentos', 'Jogo com os pés sob pressão', 'Controle da profundidade'],
        'Zagueiro' => ['Duelos (terrestres e aéreos)', 'Cobertura e proteção da profundidade', 'Saída de bola e progressão', 'Organização da linha'],
        'Lateral/ala' => ['Defesa do corredor 1x1', 'Apoio ofensivo e amplitude', 'Cruzamento/último passe', 'Recomposição e transição defensiva'],
        'Volante' => ['Proteção do espaço à frente da zaga', 'Recuperação e pressão', 'Circulação e progressão sob pressão', 'Posicionamento entre fases'],
        'Meio-campista central' => ['Conexão entre setores', 'Progressão (passe/condução)', 'Chegada à área', 'Trabalho defensivo e coberturas'],
        'Meia ofensivo' => ['Criação de chances', 'Recepção entrelinhas', 'Finalização/infiltração', 'Pressão e reação à perda'],
        'Ponta/meia de lado' => ['1x1 e condução', 'Criação a partir do lado', 'Ameaça ao gol/profundidade', 'Recomposição defensiva'],
        'Atacante' => ['Finalização e movimentos na área', 'Jogo de costas/apoio', 'Ataque à profundidade', 'Pressão sobre a saída adversária'],
    ];

    private const COMMON_CRITERIA = ['Leitura de jogo e tomada de decisão', 'Exigência física observada (sem medição)'];

    public function run(): void
    {
        $catalog = json_decode(file_get_contents(__DIR__.'/data/catalogo-papeis-remo-scout.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($catalog['roles'] as $r) {
            if (TacticalRole::where('code', $r['id'])->exists()) {
                continue;
            }
            TacticalRole::create([
                'code' => $r['id'],
                'position_group' => $r['position_group'],
                'name' => $r['name'],
                'description' => $r['description'],
                'evaluation_suggestions' => $r['evaluation_suggestions'],
                'source_url' => $r['source_url'],
                'compatible_positions' => Position::codesForGroup($r['position_group']),
                'criteria' => array_merge(self::GROUP_CRITERIA[$r['position_group']] ?? [], self::COMMON_CRITERIA),
                'status' => 'proposta',
            ]);
        }

        foreach ($catalog['specialties'] as $s) {
            if (Specialty::where('code', $s['id'])->exists()) {
                continue;
            }
            Specialty::create([
                'code' => $s['id'],
                'name' => $s['name'],
                'evaluation_suggestions' => $s['evaluation_suggestions'],
                'compatible_positions' => [], // qualquer posição (ex.: goleiro batedor de faltas)
                'status' => 'proposta',
            ]);
        }
    }
}
