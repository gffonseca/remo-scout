<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\ProspectStage;
use Illuminate\Database\Seeder;

/** Etapas do kanban e universo de competições candidatas (editáveis; não são decisão institucional). */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            ['identificado', 'Identificado', false, false, 'gray'],
            ['triagem', 'Triagem', false, false, 'gray'],
            ['dados', 'Análise de dados', false, false, 'info'],
            ['video', 'Vídeo', false, false, 'info'],
            ['presencial', 'Observação presencial', false, false, 'info'],
            ['shortlist', 'Shortlist', true, false, 'success'],
            ['diligencia', 'Diligência', true, false, 'warning'],
            ['decisao', 'Encaminhado à decisão', true, false, 'warning'],
            ['arquivado', 'Arquivado', false, true, 'danger'],
        ];
        foreach ($stages as $i => [$key, $name, $short, $term, $color]) {
            ProspectStage::updateOrCreate(['key' => $key], ['name' => $name, 'sort' => ($i + 1) * 10, 'is_shortlist' => $short, 'is_terminal' => $term, 'color' => $color]);
        }

        $br = [
            ['Brasileirão Série A', 'nacional', 'Série A', '142'], ['Brasileirão Série B', 'nacional', 'Série B', '242'],
            ['Brasileirão Série C', 'nacional', 'Série C', '342'], ['Brasileirão Série D', 'nacional', 'Série D', '542'],
            ['Copa do Brasil', 'nacional', 'Copa', '424'], ['Copa do Nordeste', 'regional', 'Copa regional', null],
            ['Copa Verde', 'regional', 'Copa regional', null], ['Campeonato Paraense', 'estadual', 'Estadual', null],
            ['Campeonato Amazonense', 'estadual', 'Estadual', null], ['Campeonato Maranhense', 'estadual', 'Estadual', null],
            ['Campeonato Cearense', 'estadual', 'Estadual', null], ['Campeonato Pernambucano', 'estadual', 'Estadual', null],
            ['Campeonato Baiano', 'estadual', 'Estadual', null], ['Paulista A1', 'estadual', 'Estadual', null],
            ['Paulista A2', 'estadual', 'Estadual', null], ['Campeonato Carioca', 'estadual', 'Estadual', null],
            ['Campeonato Mineiro', 'estadual', 'Estadual', null], ['Campeonato Gaúcho', 'estadual', 'Estadual', null],
        ];
        foreach ($br as [$name, $scope, $tier, $code]) {
            Competition::updateOrCreate(['name' => $name, 'country' => 'Brasil'], ['scope' => $scope, 'tier_label' => $tier, 'code_cbf' => $code, 'is_priority' => true]);
        }
        $sa = [
            ['Liga Profesional', 'Argentina', '1ª divisão'], ['Primera Nacional', 'Argentina', '2ª divisão'],
            ['Primera División', 'Uruguai', '1ª divisão'], ['Primera División', 'Paraguai', '1ª divisão'],
            ['LigaPro Serie A', 'Equador', '1ª divisão'], ['Primera A', 'Colômbia', '1ª divisão'],
            ['Primera División', 'Chile', '1ª divisão'], ['Liga 1', 'Peru', '1ª divisão'],
            ['División Profesional', 'Bolívia', '1ª divisão'],
            ['Copa Libertadores', 'América do Sul', 'Continental'], ['Copa Sul-Americana', 'América do Sul', 'Continental'],
        ];
        foreach ($sa as [$name, $country, $tier]) {
            Competition::updateOrCreate(['name' => $name, 'country' => $country], ['scope' => $country === 'América do Sul' ? 'continental' : 'nacional', 'tier_label' => $tier, 'is_priority' => true]);
        }
    }
}
