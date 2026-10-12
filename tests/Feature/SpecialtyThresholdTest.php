<?php

namespace Tests\Feature;

use App\Filament\Resources\RecruitmentNeeds\Pages\EditRecruitmentNeed;
use App\Models\Player;
use App\Models\PlayerSpecialtyAssessment;
use App\Models\RecruitmentNeed;
use App\Models\Specialty;
use App\Services\RequirementCheck;
use Livewire\Livewire;

/** Prompt mestre 3.3 — especialidade obrigatória precisa de limiar; existir avaliação não basta. */
class SpecialtyThresholdTest extends ScoutTestCase
{
    private function item(RecruitmentNeed $n, Player $p, string $name): array
    {
        return collect(RequirementCheck::evaluate($n->fresh(), $p->fresh(), false))->firstWhere('item', $name);
    }

    public function test_threshold_level_and_sample(): void
    {
        $sp = Specialty::first();
        $n = RecruitmentNeed::create(['title' => 'Goleiro', 'position' => 'GOL']);
        $n->specialties()->attach($sp->id, ['is_mandatory' => true]);
        $p = Player::create(['name' => 'Pegador', 'primary_position' => 'GOL']);

        $this->assertSame('pendente', $this->item($n, $p, $sp->name)['status']); // sem avaliação
        PlayerSpecialtyAssessment::create(['player_id' => $p->id, 'specialty_id' => $sp->id, 'level' => 'B', 'sample_attempts' => 4, 'sample_successes' => 2, 'evidence' => 'x']);
        $i = $this->item($n, $p, $sp->name);
        $this->assertSame('pendente', $i['status']); // avaliada, mas sem limiar na necessidade
        $this->assertStringContainsString('não define o nível mínimo', $i['detail']);

        $n->specialties()->updateExistingPivot($sp->id, ['min_level' => 'A']);
        $this->assertSame('nao_atende', $this->item($n, $p, $sp->name)['status']);
        $n->specialties()->updateExistingPivot($sp->id, ['min_level' => 'B', 'min_attempts' => 10]);
        $i = $this->item($n, $p, $sp->name);
        $this->assertSame('pendente', $i['status']);
        $this->assertStringContainsString('4 de 10', $i['detail']);
        $n->specialties()->updateExistingPivot($sp->id, ['min_attempts' => 3]);
        $this->assertSame('atende', $this->item($n, $p, $sp->name)['status']);
    }

    public function test_form_saves_mandatory_with_threshold_and_desirable(): void
    {
        [$a, $b] = Specialty::take(2)->get()->all();
        $n = RecruitmentNeed::create(['title' => 'Lateral', 'position' => 'LE']);
        $this->actingAs($this->user('coordenador'));
        Livewire::test(EditRecruitmentNeed::class, ['record' => $n->id])
            ->set('data.specialty_rows', [
                'r1' => ['specialty_id' => $a->id, 'is_mandatory' => true, 'min_level' => 'B', 'min_attempts' => '5'],
                'r2' => ['specialty_id' => $b->id, 'is_mandatory' => false, 'min_level' => null, 'min_attempts' => null],
            ])->call('save')->assertHasNoFormErrors();
        $rows = $n->fresh()->specialties->keyBy('id');
        $this->assertTrue((bool) $rows[$a->id]->pivot->is_mandatory);
        $this->assertSame('B', $rows[$a->id]->pivot->min_level);
        $this->assertSame(5, (int) $rows[$a->id]->pivot->min_attempts);
        $this->assertFalse((bool) $rows[$b->id]->pivot->is_mandatory);

        Livewire::test(EditRecruitmentNeed::class, ['record' => $n->id])
            ->set('data.specialty_rows', ['r1' => ['specialty_id' => $a->id, 'is_mandatory' => true, 'min_level' => null, 'min_attempts' => null]])
            ->call('save')->assertHasFormErrors(['specialty_rows.r1.min_level' => 'required']);
    }
}
