<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\ContractObservation;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\ProspectCase;
use App\Models\ProspectStage;
use App\Models\RecruitmentNeed;
use App\Models\ScoutingReport;
use App\Models\TacticalRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados SINTÉTICOS de demonstração (nomes inventados, clubes fictícios). Nunca rodar em produção.
 * Uso: php artisan db:seed --class=DemoSeeder (somente APP_ENV local/testing ou SCOUT_DEMO_MODE=true).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ! config('scout.demo_mode')) {
            $this->command?->error('DemoSeeder bloqueado em produção.');

            return;
        }
        $pwd = 'demo-'.str_repeat('x', 8);
        $admin = User::firstOrCreate(['email' => 'admin@demo.local'], ['name' => 'Admin Demo', 'password' => $pwd, 'role' => 'admin', 'can_view_financial' => true]);
        $coord = User::firstOrCreate(['email' => 'coordenador@demo.local'], ['name' => 'Coordenador Demo', 'password' => $pwd, 'role' => 'coordenador']);
        $a1 = User::firstOrCreate(['email' => 'analista1@demo.local'], ['name' => 'Analista Um', 'password' => $pwd, 'role' => 'analista']);
        $a2 = User::firstOrCreate(['email' => 'analista2@demo.local'], ['name' => 'Analista Dois', 'password' => $pwd, 'role' => 'analista']);
        User::firstOrCreate(['email' => 'leitor@demo.local'], ['name' => 'Leitor Demo', 'password' => $pwd, 'role' => 'leitor']);

        $clubs = collect(['Atlético Fictício do Norte', 'Esporte Clube Exemplo', 'Sociedade Demo FC', 'União Sintética'])
            ->map(fn ($n) => Club::firstOrCreate(['name' => $n, 'country' => 'Brasil'], ['state' => 'PA']));

        $people = [
            ['Lateral Exemplo A', 'LE', '2000-04-10', 'esquerdo', 176], ['Lateral Exemplo B', 'LE', '1997-09-02', 'esquerdo', 180],
            ['Lateral Exemplo C', 'ALE', null, 'nao_informado', null], ['Zagueiro Exemplo A', 'ZAG', '1999-01-20', 'direito', 188],
            ['Volante Exemplo A', 'VOL', '1996-06-15', 'direito', 181], ['Ponta Exemplo A', 'PE', '2002-11-30', 'direito', 172],
            ['Centroavante Exemplo A', 'CA', '1995-03-08', 'direito', 186], ['Goleiro Exemplo A', 'GOL', '1998-07-21', 'direito', 191],
        ];
        $players = collect($people)->map(fn ($p, $i) => Player::firstOrCreate(['name' => $p[0]], [
            'primary_position' => $p[1], 'birth_date' => $p[2], 'preferred_foot' => $p[3], 'height_cm' => $p[4],
            'current_club_id' => $clubs[$i % 4]->id, 'nationality' => 'Brasil', 'data_origin' => 'sintetico',
            'notes' => 'Atleta fictício para demonstração.',
        ]));

        ContractObservation::firstOrCreate(['player_id' => $players[0]->id, 'observed_on' => '2026-10-01'], ['status' => 'vigente', 'contract_end' => '2026-12-31', 'club_id' => $clubs[0]->id, 'confirmation' => 'estimado', 'source_label' => 'Exemplo sintético', 'author_id' => $coord->id]);
        ContractObservation::firstOrCreate(['player_id' => $players[1]->id, 'observed_on' => '2026-10-01'], ['status' => 'livre', 'confirmation' => 'rumor', 'source_label' => 'Exemplo sintético', 'author_id' => $coord->id]);

        $role = TacticalRole::where('code', 'role_11')->first(); // Lateral equilibrado
        $need = RecruitmentNeed::firstOrCreate(['title' => '[DEMO] Lateral esquerdo que progride e sustenta duelos'], [
            'position' => 'LE', 'tactical_role_id' => $role?->id, 'preferred_foot' => 'esquerdo', 'age_min' => 21, 'age_max' => 30,
            'target_level' => 'B', 'mandatory_requirements' => ['Disponível para a janela de janeiro'], 'desirable_requirements' => ['Bom cruzamento'],
            'horizon' => 'proxima_janela', 'window_label' => 'Janela jan/2027 (exemplo)', 'priority' => 'alta', 'owner_id' => $coord->id,
        ]);
        foreach ($players->take(3) as $p) {
            ProspectCase::open($need, $p, $coord->id, 'Demonstração');
        }
        $short = ProspectStage::where('key', 'shortlist')->first();
        $case = ProspectCase::where('recruitment_need_id', $need->id)->where('player_id', $players[0]->id)->first();
        if ($case && $case->prospect_stage_id !== $short->id) {
            $case->moveTo($short, 'Demonstração: avaliado em vídeo', $coord->id, 'Pedir vídeo completo de 2 jogos', now()->addDays(3));
            $case->update(['justification' => 'Exemplo sintético de justificativa.']);
        }
        if ($role) {
            PlayerRoleAssessment::firstOrCreate(['player_id' => $players[0]->id, 'tactical_role_id' => $role->id], ['level' => 'B', 'kind' => 'observado', 'confidence' => 'media', 'evidence' => 'Exemplo sintético: 3 jogos em vídeo.', 'author_id' => $coord->id]);
            foreach ([[$a1, 'B', 'avancar'], [$a2, 'C', 'monitorar']] as [$u, $lvl, $verdict]) {
                ScoutingReport::firstOrCreate(['player_id' => $players[0]->id, 'author_id' => $u->id], [
                    'tactical_role_id' => $role->id, 'recruitment_need_id' => $need->id, 'level' => $lvl, 'verdict' => $verdict,
                    'criteria_levels' => collect($role->criteriaList())->map(fn ($c) => ['criterion' => $c, 'level' => $lvl])->all(),
                    'source_type' => 'video', 'match_label' => 'Jogo fictício A x B', 'positives' => 'Exemplo: boa progressão por condução.',
                    'negatives' => 'Exemplo: perde duelos aéreos no segundo poste.', 'confidence' => 'media', 'status' => 'submetido', 'submitted_at' => now(),
                ]);
            }
        }
    }
}
