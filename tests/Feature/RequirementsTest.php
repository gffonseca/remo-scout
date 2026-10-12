<?php

namespace Tests\Feature;

use App\Enums\RequirementStatus;
use App\Models\NeedRequirementCheck;
use App\Models\NeedRoleRequirement;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\RecruitmentNeed;
use App\Models\ScoutingReport;
use App\Models\TacticalRole;
use App\Services\RequirementCheck;
use App\Services\RequirementConfirmation;
use App\Support\PlayerInsight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** F03/F04/F10 — requisitos por papel/fase, estados explícitos e nível observado ≠ potencial. */
class RequirementsTest extends ScoutTestCase
{
    private TacticalRole $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role = TacticalRole::first();
    }

    private function need(array $extra = []): RecruitmentNeed
    {
        return RecruitmentNeed::create($extra + ['title' => 'Centroavante', 'position' => 'CA']);
    }

    private function assess(Player $p, string $kind, string $level, ?string $phase = null, ?string $on = null): PlayerRoleAssessment
    {
        return PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $this->role->id, 'kind' => $kind,
            'level' => $level, 'phase' => $phase, 'observed_on' => $on ?? now()->toDateString(), 'evidence' => 'jogos x, y']);
    }

    private function phased(RecruitmentNeed $n, ?string $phase, string $min = 'B'): void
    {
        NeedRoleRequirement::create(['recruitment_need_id' => $n->id, 'tactical_role_id' => $this->role->id, 'phase' => $phase, 'min_level' => $min]);
    }

    public function test_observed_level_decides_potential_never_does(): void
    {
        $n = $this->need();
        $this->phased($n, null, 'B');
        $p = Player::create(['name' => 'X', 'primary_position' => 'CA']);

        $this->assess($p, 'potencial', 'A');
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($n, $p));
        $this->assertStringContainsString('potencial', RequirementCheck::details($n, $p));

        $this->assess($p, 'observado', 'C');
        $this->assertStringStartsWith('Não atende', RequirementCheck::summary($n, $p));

        $this->assess($p, 'observado', 'B');
        $this->assertSame('Atende', RequirementCheck::summary($n, $p));
    }

    public function test_most_recent_observation_wins_over_older_higher_one(): void
    {
        $n = $this->need();
        $this->phased($n, null, 'B');
        $p = Player::create(['name' => 'X', 'primary_position' => 'CA']);
        $this->assess($p, 'observado', 'A', null, now()->subMonths(3)->toDateString());
        $this->assess($p, 'observado', 'C', null, now()->subWeek()->toDateString());
        $this->assertStringStartsWith('Não atende', RequirementCheck::summary($n, $p));
        $this->assertSame('C', PlayerInsight::currentLevel($p->fresh('roleAssessments'))->value);
    }

    public function test_phases_do_not_substitute_each_other(): void
    {
        $n = $this->need();
        $this->phased($n, 'sem_posse', 'B');
        $p = Player::create(['name' => 'X', 'primary_position' => 'CA']);
        $this->assess($p, 'observado', 'A', 'com_posse');
        $this->assess($p, 'observado', 'A', null); // geral também não substitui fase específica
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($n, $p));
        $this->assertStringContainsString('outra fase', RequirementCheck::details($n, $p));

        $this->assess($p, 'observado', 'B', 'sem_posse');
        $this->assertSame('Atende', RequirementCheck::summary($n, $p));
    }

    public function test_expired_observation_is_pending_not_confirmed(): void
    {
        config(['scout.assessment_valid_months' => 12]);
        $n = $this->need();
        $this->phased($n, null, 'B');
        $p = Player::create(['name' => 'X', 'primary_position' => 'CA']);
        $this->assess($p, 'observado', 'A', null, now()->subMonths(13)->toDateString());
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($n, $p));
        $this->assertStringContainsString('vencida', RequirementCheck::details($n, $p));
    }

    public function test_one_mandatory_failure_beats_pending_and_desirable_is_ignored(): void
    {
        $n = $this->need(['mandatory_requirements' => ['Disponível em janeiro']]);
        NeedRoleRequirement::create(['recruitment_need_id' => $n->id, 'tactical_role_id' => $this->role->id, 'phase' => 'bola_parada', 'min_level' => 'A', 'is_mandatory' => false]);
        $p = Player::create(['name' => 'X', 'primary_position' => 'LE']); // posição não atende
        $this->assertStringStartsWith('Não atende: Posição', RequirementCheck::summary($n, $p));
        $this->assertStringNotContainsString('Bola parada', RequirementCheck::summary($n, $p));
    }

    public function test_legacy_need_level_becomes_general_requirement_and_migration_copies_it(): void
    {
        $n = $this->need(['tactical_role_id' => $this->role->id, 'target_level' => 'B']);
        $p = Player::create(['name' => 'X', 'primary_position' => 'CA']);
        $this->assess($p, 'observado', 'B');
        $this->assertSame('Atende', RequirementCheck::summary($n, $p));
        $this->assertCount(1, RequirementCheck::roleRequirements($n));
        $this->phased($n, null, 'A'); // linha própria tem precedência e não duplica
        $this->assertCount(1, RequirementCheck::roleRequirements($n));
        $this->assertStringStartsWith('Não atende', RequirementCheck::summary($n, $p));
    }

    public function test_manual_requirement_lifecycle_and_permissions(): void
    {
        $text = 'Disponível em janeiro';
        $n = $this->need(['mandatory_requirements' => [$text]]);
        $p = Player::create(['name' => 'X', 'primary_position' => 'CA']);
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($n, $p));

        $analyst = $this->user('analista');
        RequirementConfirmation::record($analyst, $n, $p, $text, RequirementStatus::Pendente, 'Empresário confirmou por telefone');
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($n, $p));
        $this->assertStringContainsString('aguardando confirmação', RequirementCheck::details($n, $p));

        try {
            RequirementConfirmation::record($analyst, $n, $p, $text, RequirementStatus::Atende, 'x', 'https://exemplo.test/doc');
            $this->fail('analista não pode confirmar');
        } catch (AuthorizationException) {
        }
        try {
            RequirementConfirmation::record($this->user('leitor'), $n, $p, $text, RequirementStatus::Pendente, 'x');
            $this->fail('leitor não registra evidência');
        } catch (AuthorizationException) {
        }

        $coord = $this->user('coordenador');
        try {
            RequirementConfirmation::record($coord, $n, $p, $text, RequirementStatus::Atende, 'sem prova');
            $this->fail('confirmação exige link ou anexo');
        } catch (ValidationException) {
        }
        $c = RequirementConfirmation::record($coord, $n, $p, $text, RequirementStatus::Atende, 'Contrato termina em dez/2026', 'https://exemplo.test/bid');
        $this->assertSame($coord->id, $c->confirmed_by);
        $this->assertNotNull($c->confirmed_at);
        $this->assertSame('Atende', RequirementCheck::summary($n, $p));

        RequirementConfirmation::record($coord, $n, $p, $text, RequirementStatus::NaoAtende, 'Renovou até 2028', 'https://exemplo.test/bid2');
        $this->assertStringStartsWith('Não atende', RequirementCheck::summary($n, $p));
        $this->assertSame(1, NeedRequirementCheck::count()); // uma linha por requisito/candidato, histórico na auditoria

        // Editar o texto do requisito na necessidade não herda a confirmação antiga
        $n->update(['mandatory_requirements' => ['Disponível em fevereiro']]);
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($n->fresh(), $p));
    }

    public function test_requirement_outside_the_need_is_refused(): void
    {
        $n = $this->need(['mandatory_requirements' => ['A']]);
        $p = Player::create(['name' => 'X']);
        $this->expectException(ValidationException::class);
        RequirementConfirmation::record($this->user('coordenador'), $n, $p, 'B', RequirementStatus::Atende, 'x', 'https://exemplo.test');
    }

    public function test_current_level_ignores_potential_reports_and_counts_distinct_observers(): void
    {
        $u = $this->user('analista');
        $p = Player::create(['name' => 'X']);
        foreach ([1, 2, 3] as $i) {
            ScoutingReport::create(['player_id' => $p->id, 'author_id' => $u->id, 'assessment_type' => 'potencial', 'level' => 'A',
                'positives' => 'x', 'negatives' => 'y', 'status' => 'submetido', 'submitted_at' => now()->subDays($i)]);
        }
        $fresh = Player::with(['roleAssessments', 'submittedReports'])->find($p->id);
        $this->assertNull(PlayerInsight::currentLevel($fresh));
        $this->assertSame('A', PlayerInsight::potentialLevel($fresh)->value);
        // 3 relatórios do mesmo autor contam como 1 observador: score = 1×2 (sem vídeos, sem dados básicos)
        $this->assertSame(2, PlayerInsight::knowledge($fresh)['score']);
    }

    public function test_migration_maps_old_phases_and_legacy_need_level_without_losing_data(): void
    {
        $migration = require database_path('migrations/2026_10_13_000100_phases_and_requirement_states.php');
        $migration->down(); // estado anterior ao Bloco 1

        $p = Player::create(['name' => 'X']);
        $rows = ['transicao_defensiva' => 'pressão pós-perda', 'geral' => 'visão geral', 'com_posse' => 'construção', 'outra_coisa' => 'texto livre'];
        $ids = [];
        foreach ($rows as $phase => $ev) {
            $ids[$phase] = (string) \Illuminate\Support\Str::uuid7();
            DB::table('player_role_assessments')->insert(['id' => $ids[$phase], 'player_id' => $p->id, 'tactical_role_id' => $this->role->id,
                'kind' => 'observado', 'level' => 'B', 'phase' => $phase, 'evidence' => $ev, 'created_at' => '2026-05-01 10:00:00', 'updated_at' => now()]);
        }
        $need = RecruitmentNeed::create(['title' => 'Legado', 'position' => 'CA', 'tactical_role_id' => $this->role->id, 'target_level' => 'C']);

        $migration->up();

        $get = fn ($k) => DB::table('player_role_assessments')->find($ids[$k]);
        $this->assertSame('sem_posse', $get('transicao_defensiva')->phase);
        $this->assertSame('[fase original: transição defensiva] pressão pós-perda', $get('transicao_defensiva')->evidence);
        $this->assertNull($get('geral')->phase);
        $this->assertSame('visão geral', $get('geral')->evidence);
        $this->assertSame('com_posse', $get('com_posse')->phase);
        $this->assertNull($get('outra_coisa')->phase);
        $this->assertSame('[fase original: outra_coisa] texto livre', $get('outra_coisa')->evidence);
        $this->assertSame('2026-05-01', (string) $get('com_posse')->observed_on);

        $req = DB::table('need_role_requirements')->where('recruitment_need_id', $need->id)->first();
        $this->assertSame('C', $req->min_level);
        $this->assertNull($req->phase);
        $this->assertSame('C', $need->fresh()->target_level->value); // coluna antiga preservada
    }
}
