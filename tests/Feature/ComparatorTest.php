<?php

namespace Tests\Feature;

use App\Filament\Pages\Comparador;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\PlayerSeasonStat;
use App\Models\RecruitmentNeed;
use App\Models\TacticalRole;
use App\Services\Comparator;

/** Comparador fixo: até 5, por usuário, mesma base de estatística, nível observado por papel/fase, financeiro só com permissão. */
class ComparatorTest extends ScoutTestCase
{
    public function test_pins_are_per_user_and_capped_at_five(): void
    {
        [$a, $b] = [$this->user('analista'), $this->user('analista')];
        $ps = collect(range(1, 6))->map(fn ($i) => Player::create(['name' => "P{$i}"]));
        foreach ($ps->take(5) as $p) {
            $this->assertTrue(Comparator::toggle($a, $p)['ok']);
        }
        $this->assertFalse(Comparator::toggle($a, $ps[5])['ok']);
        $this->assertCount(5, Comparator::players($a));
        $this->assertCount(0, Comparator::players($b));
        Comparator::toggle($a, $ps[0]); // retira
        $this->assertFalse(Comparator::isPinned($a, $ps[0]));
        $this->assertSame(['P2', 'P3', 'P4', 'P5'], Comparator::players($a)->pluck('name')->all());
    }

    public function test_stats_come_from_one_base_without_summing_and_flag_context(): void
    {
        $u = $this->user('analista');
        $a = Player::create(['name' => 'A']);
        $b = Player::create(['name' => 'B']);
        foreach ([[$a, 'Série B', 1800], [$a, 'Copa do Brasil', 300], [$b, 'Série C', 400]] as [$p, $comp, $min]) {
            PlayerSeasonStat::create(['player_id' => $p->id, 'provider' => 'api_football', 'competition_key' => $comp, 'competition_name' => $comp, 'season' => '2026', 'appearances' => 5, 'minutes' => $min, 'goals' => 1]);
        }
        PlayerSeasonStat::create(['player_id' => $b->id, 'provider' => 'cbf', 'competition_key' => 'cbf-total', 'competition_name' => 'CBF', 'season' => '2026', 'appearances' => 30]);
        Comparator::toggle($u, $a);
        Comparator::toggle($u, $b);
        $players = Comparator::players($u);
        $this->assertSame('api_football|2026', array_key_first(Comparator::statBases($players)));
        $r = Comparator::statRows($players, 'api_football|2026');
        $this->assertSame(1800, $r['rows'][$a->id]->minutes); // a linha de mais minutos, sem somar a Copa
        $this->assertTrue($r['context_differs']);
    }

    public function test_page_shows_observed_levels_by_role_and_hides_salary_without_permission(): void
    {
        $role = TacticalRole::first();
        $a = Player::create(['name' => 'Alfa', 'salary_min' => 10000, 'salary_max' => 50000, 'current_club_id' => Club::create(['name' => 'Remo'])->id]);
        $b = Player::create(['name' => 'Beta']);
        PlayerRoleAssessment::create(['player_id' => $a->id, 'tactical_role_id' => $role->id, 'kind' => 'observado', 'phase' => 'com_posse', 'level' => 'B', 'observed_on' => now()->subDays(3)]);
        PlayerRoleAssessment::create(['player_id' => $b->id, 'tactical_role_id' => $role->id, 'kind' => 'potencial', 'phase' => 'com_posse', 'level' => 'A']);
        $u = $this->user('analista');
        Comparator::toggle($u, $a);
        Comparator::toggle($u, $b);
        $grid = Comparator::roleGrid(Comparator::players($u));
        $cells = reset($grid);
        $this->assertSame('B', $cells[$a->id]['level']->value);
        $this->assertNull($cells[$b->id]); // potencial não entra como observado
        $this->actingAs($u);
        $this->get(Comparador::getUrl())->assertOk()->assertSee('Alfa')->assertSee('Beta')->assertSee('não avaliado')->assertDontSee('Faixa salarial');
    }

    public function test_coordinator_sees_budget_state_against_reference_need(): void
    {
        $need = RecruitmentNeed::create(['title' => 'Volante', 'position' => 'VOL', 'budget_salary_max' => 30000, 'status' => 'aberta']);
        $u = $this->user('coordenador');
        Comparator::toggle($u, Player::create(['name' => 'Caro', 'salary_min' => 40000, 'salary_max' => 50000]));
        Comparator::toggle($u, Player::create(['name' => 'Negociável', 'salary_min' => 20000, 'salary_max' => 40000]));
        $this->actingAs($u);
        $this->get(Comparador::getUrl(['need' => $need->id]))->assertOk()->assertSee('Faixa salarial')->assertSee('Acima do orçamento')->assertSee('Intersecta o orçamento');
    }
}
