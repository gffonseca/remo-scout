<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\Specialty;
use App\Models\TacticalRole;
use App\Services\Calibration;
use App\Services\CatalogApproval;
use Illuminate\Auth\Access\AuthorizationException;

/** F22 — aprovação só com critérios A–D observáveis por fase, só por coordenador; calibração mostra divergências. */
class CatalogApprovalTest extends ScoutTestCase
{
    private function guide(array $phases): array
    {
        return collect($phases)->mapWithKeys(fn ($ph) => [$ph => collect(['A', 'B', 'C', 'D'])->mapWithKeys(fn ($l) => [$l => "Comportamento observável do nível {$l} na fase"])->all()])->all();
    }

    public function test_role_needs_complete_guide_per_phase_and_coordinator(): void
    {
        $r = TacticalRole::first();
        $r->forceFill(['phases' => ['com_posse', 'sem_posse'], 'behaviors' => 'Ataca o espaço às costas da linha', 'status' => 'proposta',
            'level_guide' => $this->guide(['com_posse'])])->save();
        $gaps = CatalogApproval::gaps($r->fresh());
        $this->assertContains('critério A em Sem posse', $gaps);
        try {
            CatalogApproval::approve($r->fresh(), $this->user('coordenador'));
            $this->fail('não deveria aprovar com fase incompleta');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sem posse', $e->getMessage());
        }
        $r->forceFill(['level_guide' => $this->guide(['com_posse', 'sem_posse'])])->save();
        try {
            CatalogApproval::approve($r->fresh(), $this->user('analista'));
            $this->fail('analista não aprova');
        } catch (AuthorizationException) {
        }
        $coord = $this->user('coordenador');
        CatalogApproval::approve($r->fresh(), $coord);
        $r->refresh();
        $this->assertSame('ativo', $r->status->value);
        $this->assertSame($coord->id, $r->reviewed_by);
        $this->assertNotNull($r->reviewed_at);
    }

    public function test_analyst_editing_approved_criteria_sends_profile_back_to_proposal(): void
    {
        $r = TacticalRole::first();
        $r->forceFill(['phases' => ['com_posse'], 'behaviors' => 'Ataca o espaço às costas', 'level_guide' => $this->guide(['com_posse'])])->save();
        CatalogApproval::approve($r->fresh(), $this->user('coordenador'));
        $this->actingAs($this->user('analista'));
        $g = $r->fresh()->level_guide;
        $g['com_posse']['A'] = 'Outro texto mais exigente para o nível A';
        $r->fresh()->update(['level_guide' => $g]);
        $this->assertSame('proposta', $r->fresh()->status->value);
        $this->assertSame('Critério A: Outro texto mais exigente para o nível A', CatalogApproval::guideFor($r->fresh(), 'com_posse', 'A'));
    }

    public function test_specialty_approval_needs_four_levels(): void
    {
        $s = Specialty::first();
        $s->forceFill(['level_guide' => ['A' => 'Converte com regularidade alta sob pressão', 'B' => 'curto']])->save();
        $this->assertEqualsCanonicalizing(['critério B', 'critério C', 'critério D'], CatalogApproval::gaps($s->fresh()));
    }

    public function test_calibration_lists_divergent_authors_on_same_role_and_phase(): void
    {
        $r = TacticalRole::first();
        $p = Player::create(['name' => 'Calibrar']);
        [$a, $b, $c] = [$this->user('analista'), $this->user('analista'), $this->user('analista')];
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $r->id, 'kind' => 'observado', 'phase' => 'com_posse', 'level' => 'B', 'author_id' => $a->id, 'observed_on' => now()->subDays(5)]);
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $r->id, 'kind' => 'observado', 'phase' => 'com_posse', 'level' => 'D', 'author_id' => $b->id, 'observed_on' => now()->subDays(2)]);
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $r->id, 'kind' => 'observado', 'phase' => 'sem_posse', 'level' => 'A', 'author_id' => $c->id, 'observed_on' => now()]); // outra fase
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $r->id, 'kind' => 'potencial', 'phase' => 'com_posse', 'level' => 'A', 'author_id' => $c->id, 'observed_on' => now()]); // potencial fora
        $d = Calibration::divergences();
        $this->assertCount(1, $d);
        $this->assertSame(2, $d[0]['gap']);
        $this->actingAs($this->user('coordenador'));
        $this->get('/calibracao')->assertOk()->assertSee('Calibrar')->assertSee('2 nível(is)');
    }

    public function test_new_phase_without_criteria_demotes_active_role(): void
    {
        $r = TacticalRole::first();
        $r->forceFill(['phases' => ['com_posse'], 'behaviors' => 'Ataca o espaço às costas da linha', 'status' => 'proposta', 'level_guide' => $this->guide(['com_posse'])])->save();
        $coord = $this->user('coordenador');
        CatalogApproval::approve($r->fresh(), $coord);
        $this->actingAs($coord);
        $r = $r->fresh();
        $r->update(['phases' => ['com_posse', 'sem_posse']]); // nem o coordenador deixa fase sem critério num perfil ativo
        $this->assertSame('proposta', $r->fresh()->status->value);
    }
}
