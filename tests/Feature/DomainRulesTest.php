<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\PlayerSpecialtyAssessment;
use App\Models\ProspectCase;
use App\Models\ProspectStage;
use App\Models\RecruitmentNeed;
use App\Models\ScoutingReport;
use App\Models\Specialty;
use App\Models\TacticalRole;
use App\Services\CatalogTransfer;
use App\Services\RequirementCheck;

class DomainRulesTest extends ScoutTestCase
{
    public function test_catalog_seed_has_40_roles_in_8_groups_and_12_specialties(): void
    {
        $this->assertSame(40, TacticalRole::count());
        $this->assertSame(8, TacticalRole::distinct()->count('position_group'));
        $this->assertSame(12, Specialty::count());
    }

    public function test_new_role_without_code_change_assessed_and_searchable(): void
    {
        $coord = $this->user('coordenador');
        $this->actingAs($coord);
        $role = TacticalRole::create(['code' => 'zagueiro-que-ultrapassa', 'position_group' => 'Zagueiro', 'name' => 'Zagueiro que ultrapassa', 'description' => 'Conduz e aparece no terço final.', 'compatible_positions' => ['ZAG'], 'criteria' => ['Condução', 'Recomposição'], 'status' => 'proposta']);
        $p = Player::create(['name' => 'Atleta Teste', 'primary_position' => 'ZAG']);
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $role->id, 'level' => 'B', 'kind' => 'observado', 'confidence' => 'media', 'evidence' => 'vídeo', 'author_id' => $coord->id]);
        $found = Player::whereHas('roleAssessments', fn ($q) => $q->where('tactical_role_id', $role->id))->pluck('id');
        $this->assertTrue($found->contains($p->id));
    }

    public function test_role_edit_versions_and_preserves_old_assessment_version(): void
    {
        $this->actingAs($this->user('coordenador'));
        $role = TacticalRole::where('code', 'role_11')->first();
        $p = Player::create(['name' => 'Versão Teste']);
        $a = PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $role->id, 'level' => 'C', 'evidence' => 'x']);
        $this->assertSame(1, $a->role_version);
        $role->pendingChangeNote = 'Ajuste de descrição';
        $role->update(['description' => 'Nova descrição calibrada com o departamento.']);
        $this->assertSame(2, $role->fresh()->version);
        $this->assertSame(1, $a->fresh()->role_version);
        $this->assertSame(2, $role->versions()->count());
        $role->update(['status' => 'arquivado']); // arquivar não muda versão nem apaga avaliação
        $this->assertSame(2, $role->fresh()->version);
        $this->assertTrue(PlayerRoleAssessment::whereKey($a->id)->exists());
    }

    public function test_multiple_roles_and_specialties_and_goalkeeper_free_kick(): void
    {
        $p = Player::create(['name' => 'Goleiro Completo', 'primary_position' => 'GOL']);
        foreach (['role_01', 'role_03'] as $code) {
            PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => TacticalRole::where('code', $code)->value('id'), 'level' => 'B', 'evidence' => 'x']);
        }
        foreach (['specialty_01', 'specialty_03'] as $code) { // pegador de pênaltis + batedor de faltas diretas
            PlayerSpecialtyAssessment::create(['player_id' => $p->id, 'specialty_id' => Specialty::where('code', $code)->value('id'), 'level' => 'C', 'evidence' => 'x']);
        }
        $this->assertSame(2, $p->roleAssessments()->count());
        $this->assertSame(2, $p->specialtyAssessments()->count());
    }

    public function test_specialty_rate_requires_denominator(): void
    {
        $p = Player::create(['name' => 'Pênaltis']);
        $sid = Specialty::where('code', 'specialty_01')->value('id');
        $none = PlayerSpecialtyAssessment::create(['player_id' => $p->id, 'specialty_id' => $sid, 'evidence' => 'x']);
        $this->assertNull($none->success_rate);
        $zero = PlayerSpecialtyAssessment::create(['player_id' => $p->id, 'specialty_id' => $sid, 'sample_attempts' => 0, 'sample_successes' => 0, 'evidence' => 'x']);
        $this->assertNull($zero->success_rate);
        $ok = PlayerSpecialtyAssessment::create(['player_id' => $p->id, 'specialty_id' => $sid, 'sample_attempts' => 8, 'sample_successes' => 3, 'evidence' => 'x']);
        $this->assertSame(0.375, $ok->success_rate);
        $this->expectException(\InvalidArgumentException::class);
        PlayerSpecialtyAssessment::create(['player_id' => $p->id, 'specialty_id' => $sid, 'sample_attempts' => 2, 'sample_successes' => 3, 'evidence' => 'x']);
    }

    public function test_requirement_check_distinguishes_fail_from_missing_data(): void
    {
        $need = RecruitmentNeed::create(['title' => 'LE', 'position' => 'LE', 'age_min' => 21, 'age_max' => 28, 'preferred_foot' => 'esquerdo']);
        $unknown = Player::create(['name' => 'Sem dados', 'primary_position' => 'LE']);
        $old = Player::create(['name' => 'Veterano', 'primary_position' => 'LE', 'birth_date' => now()->subYears(34)->toDateString(), 'preferred_foot' => 'esquerdo']);
        $ok = Player::create(['name' => 'Ideal', 'primary_position' => 'LE', 'birth_date' => now()->subYears(24)->toDateString(), 'preferred_foot' => 'esquerdo']);
        $this->assertStringStartsWith('Pendente', RequirementCheck::summary($need, $unknown));
        $this->assertStringStartsWith('Não atende', RequirementCheck::summary($need, $old));
        $this->assertSame('Atende', RequirementCheck::summary($need, $ok));
    }

    public function test_budget_unknown_is_not_viable(): void
    {
        $need = RecruitmentNeed::create(['title' => 'X', 'position' => 'CA']);
        $this->assertSame('Orçamento não informado', $need->budgetLabel());
    }

    public function test_prospect_flow_history_and_archive_requires_reason(): void
    {
        $u = $this->user('analista');
        $need = RecruitmentNeed::create(['title' => 'CA', 'position' => 'CA']);
        $p = Player::create(['name' => 'Centroavante']);
        $case = ProspectCase::open($need, $p, $u->id);
        $again = ProspectCase::open($need, $p, $u->id);
        $this->assertSame($case->id, $again->id);
        $case->moveTo(ProspectStage::where('key', 'shortlist')->first(), 'Aprovado em vídeo', $u->id);
        $this->assertSame(1, $case->fresh()->shortlist_rank);
        $this->assertSame(2, $case->history()->count());
        $this->expectException(\InvalidArgumentException::class);
        $case->moveTo(ProspectStage::where('key', 'arquivado')->first(), '', $u->id);
    }

    public function test_independent_evaluation_visibility(): void
    {
        $a1 = $this->user('analista');
        $a2 = $this->user('analista');
        $coord = $this->user('coordenador');
        $p = Player::create(['name' => 'Avaliado']);
        $r1 = ScoutingReport::create(['player_id' => $p->id, 'author_id' => $a1->id, 'level' => 'B', 'positives' => 'x', 'negatives' => 'y', 'status' => 'submetido', 'submitted_at' => now()]);
        $draft = ScoutingReport::create(['player_id' => $p->id, 'author_id' => $a2->id, 'level' => 'C', 'positives' => 'x', 'negatives' => 'y']);
        // a2 ainda não submeteu: não vê o relatório de a1
        $this->assertFalse(ScoutingReport::visibleTo($a2)->whereKey($r1->id)->exists());
        $this->assertTrue(ScoutingReport::visibleTo($coord)->whereKey($r1->id)->exists());
        $this->assertFalse(ScoutingReport::visibleTo($coord)->whereKey($draft->id)->exists()); // rascunho alheio
        $draft->submit();
        $this->assertTrue(ScoutingReport::visibleTo($a2)->whereKey($r1->id)->exists());
    }

    public function test_catalog_json_round_trip_upserts_and_versions(): void
    {
        $this->actingAs($this->user('coordenador'));
        $export = CatalogTransfer::export();
        $this->assertCount(40, $export['roles']);
        $export['roles'][0]['description'] = 'Descrição revisada via JSON';
        $export['roles'][] = ['id' => 'role_novo', 'position_group' => 'Atacante', 'name' => 'Atacante de canal', 'description' => 'Ataca o canal entre lateral e zagueiro.'];
        $stats = CatalogTransfer::import($export);
        $this->assertSame(1, $stats['roles_created']);
        $this->assertSame(1, $stats['roles_updated']);
        $this->assertSame(41, TacticalRole::count());
    }

    public function test_salary_change_creates_market_history(): void
    {
        $p = Player::create(['name' => 'Salário', 'salary_min' => 30000, 'salary_max' => 40000]);
        $p->update(['salary_max' => 45000]);
        $this->assertSame(2, $p->marketObservations()->where('kind', 'salario')->count());
        $this->assertStringContainsString('30.000 – 45.000', $p->fresh()->salaryRangeLabel());
        $this->assertSame('não informada', Player::create(['name' => 'Sem salário'])->salaryRangeLabel());
    }

    public function test_level_rank_and_not_observed(): void
    {
        $this->assertTrue(Level::A->rank() > Level::D->rank());
        $this->assertNull(Level::NaoObservado->rank());
    }
}
