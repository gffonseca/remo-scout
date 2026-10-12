<?php

namespace Tests\Feature;

use App\Filament\Resources\RecruitmentNeeds\Pages\SearchCandidates;
use App\Filament\Resources\RecruitmentNeeds\RecruitmentNeedResource;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\ProspectCase;
use App\Models\RecruitmentNeed;
use App\Models\TacticalRole;
use App\Services\NeedSearch;
use App\Support\BudgetFit;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** F11 — pesquisa da necessidade com os mesmos critérios; orçamento em 4 estados; desconhecido = pendência. */
class NeedSearchTest extends ScoutTestCase
{
    private function need(array $extra = []): RecruitmentNeed
    {
        return RecruitmentNeed::create($extra + ['title' => 'Lateral esquerdo', 'position' => 'LE']);
    }

    private function player(array $a = []): Player
    {
        return Player::create($a + ['name' => 'Atleta '.uniqid(), 'primary_position' => 'LE']);
    }

    public function test_salary_budget_has_four_states_and_floor_is_never_confirmation(): void
    {
        $n = $this->need(['budget_salary_max' => 30000]);
        $s = fn (array $a) => BudgetFit::salary($n, $this->player($a))['state'];
        $this->assertSame('dentro', $s(['salary_min' => 20000, 'salary_max' => 30000]));
        $this->assertSame('intersecta', $s(['salary_min' => 25000, 'salary_max' => 40000])); // menor valor cabe, mas não confirma
        $this->assertSame('acima', $s(['salary_min' => 31000, 'salary_max' => 40000]));
        $this->assertSame('desconhecido', $s([]));
        $this->assertSame('intersecta', $s(['salary_min' => 10000])); // só o piso: o máximo pode passar
        $this->assertSame('dentro', $s(['salary_max' => 28000]));
        $this->assertSame('desconhecido', $s(['salary_min' => 1000, 'salary_max' => 2000, 'salary_currency' => 'USD']));
        $this->assertSame('dentro', $s(['salary_min' => 240000, 'salary_max' => 360000, 'salary_periodicity' => 'anual'])); // 20–30 mil/mês
        $this->assertNull(BudgetFit::salary($this->need(), $this->player()));
        $this->assertSame('acima', BudgetFit::transfer($this->need(['budget_transfer_max' => 100000]), $this->player(['asking_price' => 150000]))['state']);
    }

    public function test_search_classifies_with_same_criteria_and_unknown_is_pending(): void
    {
        $n = $this->need(['age_max' => 28, 'preferred_foot' => 'esquerdo', 'budget_salary_max' => 30000]);
        $ok = $this->player(['name' => 'Confirmado', 'birth_date' => now()->subYears(24), 'preferred_foot' => 'esquerdo', 'salary_min' => 20000, 'salary_max' => 25000]);
        $amb = $this->player(['name' => 'Ambidestro', 'birth_date' => now()->subYears(24), 'preferred_foot' => 'ambidestro', 'salary_max' => 25000]);
        $semIdade = $this->player(['name' => 'Sem idade', 'preferred_foot' => 'esquerdo', 'salary_max' => 25000]);
        $negociar = $this->player(['name' => 'Negociar', 'birth_date' => now()->subYears(24), 'preferred_foot' => 'esquerdo', 'salary_min' => 25000, 'salary_max' => 45000]);
        $caro = $this->player(['name' => 'Caro', 'birth_date' => now()->subYears(24), 'preferred_foot' => 'esquerdo', 'salary_min' => 50000, 'salary_max' => 60000]);
        $semPosicao = Player::create(['name' => 'Sem posição']);
        $outra = $this->player(['name' => 'Zagueiro', 'primary_position' => 'ZAG']);
        $prov = $this->player(['name' => 'Provisório', 'data_origin' => 'provedor']);
        PlayerExternalId::create(['player_id' => $prov->id, 'provider' => 'api_futebol', 'external_id' => '1', 'link_method' => 'cadastro_provisorio']);
        $secundaria = $this->player(['name' => 'Secundária', 'primary_position' => 'ZAG', 'positions' => ['LE'], 'birth_date' => now()->subYears(24), 'preferred_foot' => 'esquerdo', 'salary_max' => 1]);

        $this->actingAs($this->user('coordenador'));
        $rows = NeedSearch::run($n)->keyBy(fn ($r) => $r['player']->name);
        $this->assertSame('atende', $rows['Confirmado']['class']);
        $this->assertSame('atende', $rows['Ambidestro']['class']);
        $this->assertSame('atende', $rows['Secundária']['class']);
        $this->assertSame(['Idade'], $rows['Sem idade']['pending']);
        $this->assertSame('pendente', $rows['Negociar']['class']);
        $this->assertSame(['Orçamento salarial'], $rows['Negociar']['pending']);
        $this->assertSame('nao_atende', $rows['Caro']['class']);
        $this->assertSame('pendente', $rows['Sem posição']['class']);
        $this->assertFalse($rows->has('Zagueiro'));
        $this->assertFalse($rows->has('Provisório'));

        $viaveis = NeedSearch::filter(NeedSearch::run($n), 'viaveis')->map(fn ($r) => $r['player']->name);
        $this->assertNotContains('Caro', $viaveis);
        $this->assertSame(['Sem idade', 'Sem posição'], NeedSearch::filter(NeedSearch::run($n), 'pendente', 'Idade')->map(fn ($r) => $r['player']->name)->sort()->values()->all());
    }

    public function test_budget_is_hidden_from_users_without_financial_access(): void
    {
        $n = $this->need(['budget_salary_max' => 30000]);
        $p = $this->player(['salary_min' => 50000, 'salary_max' => 60000, 'birth_date' => now()->subYears(25)]);
        $this->actingAs($this->user('analista')); // sem acesso financeiro
        $row = NeedSearch::run($n)->first();
        $this->assertSame('atende', $row['class']); // orçamento não entra para quem não pode vê-lo
        $this->get(RecruitmentNeedResource::getUrl('search', ['record' => $n]))->assertOk()->assertDontSee('Orçamento salarial')->assertDontSee('Teto salarial');
        $csv = $this->get(route('export.need-search', ['need' => $n]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Orçamento', $csv);
    }

    public function test_coordinator_sees_budget_state_and_export_explains_it(): void
    {
        $n = $this->need(['budget_salary_max' => 30000]);
        $p = $this->player(['salary_min' => 50000, 'salary_max' => 60000, 'birth_date' => now()->subYears(25)]);
        $this->actingAs($this->user('coordenador'));
        $this->get(RecruitmentNeedResource::getUrl('search', ['record' => $n, 'show' => 'todos']))->assertOk()->assertSee('Orçamento salarial')->assertSee($p->name);
        $csv = $this->get(route('export.need-search', ['need' => $n, 'show' => 'todos']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Não atende: Orçamento salarial (Acima do orçamento', $csv);
    }

    public function test_add_to_board_from_search_and_reader_cannot(): void
    {
        $n = $this->need();
        $p = $this->player(['name' => 'Para o quadro']);
        $this->actingAs($this->user('analista'));
        Livewire::test(SearchCandidates::class, ['record' => $n->id])
            ->call('addToBoard', $p->id)->assertHasNoErrors();
        $this->assertTrue(ProspectCase::where('recruitment_need_id', $n->id)->where('player_id', $p->id)->exists());

    }

    public function test_reader_cannot_add_to_board(): void
    {
        $n = $this->need();
        $this->actingAs($this->user('leitor'));
        Livewire::test(SearchCandidates::class, ['record' => $n->id])
            ->call('addToBoard', Player::create(['name' => 'Outro', 'primary_position' => 'LE'])->id)->assertForbidden();
    }

    public function test_query_count_does_not_grow_with_players(): void
    {
        $role = TacticalRole::first();
        $n = $this->need(['tactical_role_id' => $role->id, 'target_level' => 'B', 'mandatory_requirements' => ['Aceita morar em Belém']]);
        $count = function (int $players) use ($n) {
            for ($i = 0; $i < $players; $i++) {
                $this->player(['birth_date' => now()->subYears(24)]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            NeedSearch::run($n);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };
        $this->actingAs($this->user('coordenador'));
        $few = $count(5);
        $many = $count(45); // 50 no total
        $this->assertLessThanOrEqual($few + 2, $many, "consultas: {$few} com 5 atletas, {$many} com 50");
    }
}
