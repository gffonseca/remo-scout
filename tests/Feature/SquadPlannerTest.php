<?php

namespace Tests\Feature;

use App\Filament\CommandPalette;
use App\Filament\Resources\Players\Pages\ViewPlayer;
use App\Filament\Resources\Players\PlayerResource;
use App\Filament\Resources\Players\RelationManagers\ContractObservationsRelationManager;
use App\Filament\Resources\Players\RelationManagers\VideoReferencesRelationManager;
use App\Filament\Resources\SquadPlans\Pages\PlanSquad;
use App\Filament\Resources\SquadPlans\SquadPlanResource;
use App\Models\Club;
use App\Models\ContractObservation;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\RecruitmentNeed;
use App\Models\SquadPlan;
use App\Models\SquadPlanPick;
use App\Models\SquadPlanSlot;
use App\Models\TacticalRole;
use App\Services\SquadPlanner;
use Livewire\Livewire;

/** Planejador de elenco (com/sem posse, titular + alternativas, carências, cenários) e paleta Ctrl+K. */
class SquadPlannerTest extends ScoutTestCase
{
    private function plan(array $extra = []): SquadPlan
    {
        $remo = Club::firstOrCreate(['name' => 'Clube do Remo', 'country' => 'Brasil']);
        $plan = SquadPlan::create($extra + ['name' => 'Base 2027', 'club_id' => $remo->id, 'shape_with_ball' => '4-3-3', 'shape_without_ball' => '4-4-2']);
        SquadPlanner::syncSlots($plan);

        return $plan;
    }

    private function slot(SquadPlan $p, string $code, string $phase = 'com_posse'): SquadPlanSlot
    {
        return $p->slots()->where('phase', $phase)->where('code', $code)->firstOrFail();
    }

    public function test_slots_follow_both_shapes_and_reshape_keeps_matching_codes(): void
    {
        $plan = $this->plan();
        $this->assertSame(11, $plan->slots()->where('phase', 'com_posse')->count());
        $this->assertSame(11, $plan->slots()->where('phase', 'sem_posse')->count());
        $le = Player::create(['name' => 'Lateral', 'primary_position' => 'LE']);
        $pd = Player::create(['name' => 'Ponta', 'primary_position' => 'PD']);
        SquadPlanner::addPick($this->slot($plan, 'LE'), $le->id, true);
        SquadPlanner::addPick($this->slot($plan, 'PD'), $pd->id, true);
        $plan->update(['shape_with_ball' => '3-5-2']);
        SquadPlanner::syncSlots($plan);
        $this->assertSame(0, $plan->slots()->where('phase', 'com_posse')->where('code', 'LE')->count()); // LE some no 3-5-2
        $this->assertSame(0, SquadPlanPick::where('player_id', $le->id)->count());
        $this->assertSame(11, $plan->slots()->where('phase', 'com_posse')->count());
    }

    public function test_depth_order_promote_remove_and_gaps(): void
    {
        $plan = $this->plan();
        $remo = $plan->club;
        $slot = $this->slot($plan, 'CA');
        $a = Player::create(['name' => 'Do elenco', 'primary_position' => 'CA', 'current_club_id' => $remo->id]);
        $b = Player::create(['name' => 'Alvo externo', 'primary_position' => 'CA']);
        $c = Player::create(['name' => 'Terceiro', 'primary_position' => 'CA', 'current_club_id' => $remo->id]);

        $row = fn () => collect(SquadPlanner::view($plan->fresh())['phases']['com_posse'])->first(fn ($r) => $r['slot']->code === 'CA');
        $this->assertSame(['sem_titular', 'sem_alternativa'], $row()['gaps']);

        SquadPlanner::addPick($slot, $a->id, true);
        SquadPlanner::addPick($slot, $c->id, false);
        $pb = SquadPlanner::addPick($slot, $b->id, true); // novo titular empurra os demais
        $this->assertSame(['Alvo externo', 'Do elenco', 'Terceiro'], $slot->picks()->with('player')->get()->pluck('player.name')->all());
        $this->assertSame([1, 2, 3], $slot->picks()->pluck('depth')->all());
        $this->assertSame(['titular_fora'], $row()['gaps']);

        SquadPlanner::remove($pb); // titular sai: posto fica sem titular (ninguém é promovido sozinho)
        $this->assertSame([2, 3], $slot->picks()->pluck('depth')->all());
        $this->assertContains('sem_titular', $row()['gaps']);
        SquadPlanner::promote($slot->picks()->where('player_id', $c->id)->first());
        $this->assertSame(['Terceiro', 'Do elenco'], $slot->picks()->with('player')->get()->pluck('player.name')->all());
        $this->assertSame([], $row()['gaps']);
    }

    public function test_role_level_uses_slot_phase_and_general_does_not_substitute(): void
    {
        $plan = $this->plan();
        $role = TacticalRole::create(['code' => 'ca-ref', 'position_group' => 'Atacante', 'name' => 'Centroavante de referência', 'description' => 'x', 'compatible_positions' => ['CA'], 'criteria' => ['x'], 'status' => 'ativo']);
        $p = Player::create(['name' => 'Camisa 9', 'primary_position' => 'CA', 'current_club_id' => $plan->club_id]);
        foreach (['com_posse', 'sem_posse'] as $ph) {
            $this->slot($plan, $ph === 'com_posse' ? 'CA' : 'CA-D', $ph)->update(['tactical_role_id' => $role->id]);
            SquadPlanner::addPick($this->slot($plan, $ph === 'com_posse' ? 'CA' : 'CA-D', $ph), $p->id, true);
        }
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $role->id, 'kind' => 'observado', 'level' => 'B', 'phase' => 'com_posse']);
        PlayerRoleAssessment::create(['player_id' => $p->id, 'tactical_role_id' => $role->id, 'kind' => 'observado', 'level' => 'A', 'phase' => null]);
        $v = SquadPlanner::view($plan);
        $with = collect($v['phases']['com_posse'])->first(fn ($r) => $r['slot']->code === 'CA');
        $without = collect($v['phases']['sem_posse'])->first(fn ($r) => $r['slot']->code === 'CA-D');
        $this->assertSame('B', $with['starter']['level']['level']->value);
        $this->assertNull($without['starter']['level']); // só "geral": não vale para sem posse
        $this->assertContains('sem_avaliacao', $without['gaps']);
    }

    public function test_needs_cross_reference_copy_phase_and_scenario_is_independent(): void
    {
        $plan = $this->plan();
        $le = RecruitmentNeed::create(['title' => 'Lateral esquerdo', 'position' => 'LE']);
        $ala = RecruitmentNeed::create(['title' => 'Ala direito', 'position' => 'ALD']);
        $v = SquadPlanner::view($plan);
        $this->assertTrue(collect($v['phases']['com_posse'])->first(fn ($r) => $r['slot']->code === 'LE')['needs']->contains('id', $le->id));
        $this->assertSame([$ala->id], $v['uncoveredNeeds']->pluck('id')->all());

        $gk = Player::create(['name' => 'Goleiro', 'primary_position' => 'GOL']);
        $mc = Player::create(['name' => 'Meia', 'primary_position' => 'MC']);
        SquadPlanner::addPick($this->slot($plan, 'GOL'), $gk->id, true);
        SquadPlanner::addPick($this->slot($plan, 'MC-D'), $mc->id, true);
        $this->assertSame(1, SquadPlanner::copyPhase($plan, 'com_posse', 'sem_posse')); // GOL por código; MC não existe no 4-4-2
        $this->assertSame(1, $this->slot($plan, 'GOL', 'sem_posse')->picks()->count());
        $this->assertSame(0, SquadPlanner::copyPhase($plan, 'com_posse', 'sem_posse')); // idempotente

        $copy = SquadPlanner::duplicate($plan, 'Cenário: sem o goleiro', null);
        SquadPlanner::remove($this->slot($copy, 'GOL')->picks()->first());
        $this->assertSame(1, $this->slot($plan, 'GOL')->picks()->count()); // original intacto
        $this->assertSame($plan->id, $copy->based_on_id);
    }

    public function test_page_permissions_and_actions(): void
    {
        $plan = $this->plan();
        $p = Player::create(['name' => 'Novo zagueiro', 'primary_position' => 'ZAGD']);
        $slot = $this->slot($plan, 'ZAGD');

        $this->actingAs($this->user('analista'));
        $this->get(SquadPlanResource::getUrl('plan', ['record' => $plan]))->assertOk()->assertSee('Com posse')->assertSee('Sem posse')->assertSee('Profundidade por posição');
        Livewire::test(PlanSquad::class, ['record' => $plan->id])
            ->call('selectSlot', $slot->id)
            ->callAction('addPlayer', ['player_id' => $p->id, 'as' => 'titular'], ['slot' => $slot->id])->assertHasNoActionErrors()
            ->assertSee('Novo zagueiro');
        $this->assertSame(1, $slot->picks()->where('depth', 1)->count());
    }

    public function test_reader_sees_plan_but_cannot_change_it(): void
    {
        $plan = $this->plan();
        $p = Player::create(['name' => 'Fixo', 'primary_position' => 'GOL']);
        $pick = SquadPlanner::addPick($this->slot($plan, 'GOL'), $p->id, true);
        $this->actingAs($this->user('leitor'));
        $this->get(SquadPlanResource::getUrl('plan', ['record' => $plan]))->assertOk()->assertSee('Fixo');
        Livewire::test(PlanSquad::class, ['record' => $plan->id])->call('removePick', $pick->id)->assertForbidden();
        $this->assertTrue($pick->fresh() !== null);
    }

    public function test_command_palette_lists_actions_by_permission(): void
    {
        $need = RecruitmentNeed::create(['title' => 'Volante marcador', 'position' => 'VOL']);
        $this->actingAs($this->user('analista'));
        $titles = fn (string $q) => collect((new CommandPalette)->commands($q))->map(fn ($r) => (string) $r->title)->all();
        $this->assertContains('Planejador de elenco', $titles('elenco'));
        $this->assertContains('Pesquisar candidatos: Volante marcador', $titles('volante'));
        $this->assertContains('Comparador', $titles('comparar'));
        $this->assertTrue(collect($titles('novo atleta'))->contains(fn ($t) => str_starts_with($t, 'Novo(a)')));

        $this->actingAs($this->user('leitor'));
        $this->assertEmpty(array_filter($titles('novo'), fn ($t) => str_starts_with($t, 'Novo(a)'))); // leitor não cria
        $this->assertNotEmpty($titles('elenco'));
        $results = (new CommandPalette)->getResults('>planejador')->getCategories();
        $this->assertSame(['Atalhos'], $results->keys()->all());
    }

    public function test_profile_tabs_show_counts(): void
    {
        $p = Player::create(['name' => 'Com abas']);
        ContractObservation::create(['player_id' => $p->id, 'status' => 'vigente', 'observed_on' => now()]);
        $this->actingAs($this->user('analista'));
        $this->get(PlayerResource::getUrl('view', ['record' => $p]))->assertOk()->assertSee('Perfil')->assertSee('Dados cadastrais')->assertSee('Contrato (observações)');
        $this->assertSame('1', ContractObservationsRelationManager::getBadge($p, ViewPlayer::class));
        $this->assertNull(VideoReferencesRelationManager::getBadge($p, ViewPlayer::class));
    }
}
