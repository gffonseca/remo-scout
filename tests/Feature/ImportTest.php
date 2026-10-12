<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Services\Import\PlayerImporter;

class ImportTest extends ScoutTestCase
{
    private function rows(): array
    {
        return [
            ['Nome' => 'João Exemplo', 'Nascimento' => '10/02/1999', 'Posição' => 'Lateral esquerdo', 'Pé' => 'canhoto', 'Clube' => 'Clube A', 'Salário' => '30 mil', 'CBF' => '123456'],
            ['Nome' => 'José Sem Data', 'Nascimento' => '', 'Posição' => 'VOL', 'Pé' => '', 'Clube' => 'Clube B', 'Salário' => '', 'CBF' => ''],
            ['Nome' => '', 'Nascimento' => '', 'Posição' => '', 'Pé' => '', 'Clube' => '', 'Salário' => '', 'CBF' => ''],
            ['Nome' => 'Duplicado CBF', 'Nascimento' => '', 'Posição' => '', 'Pé' => '', 'Clube' => '', 'Salário' => '', 'CBF' => '123456'],
        ];
    }

    private function mapping(): array
    {
        return ['name' => 'Nome', 'birth_date' => 'Nascimento', 'primary_position' => 'Posição', 'preferred_foot' => 'Pé', 'current_club' => 'Clube', 'salary_min' => 'Salário', 'cbf_id' => 'CBF'];
    }

    public function test_guess_mapping_recognizes_common_headers(): void
    {
        $m = PlayerImporter::guessMapping(['Atleta', 'Data de nascimento', 'Posição', 'Pé', 'Clube atual', 'Empresário']);
        $this->assertSame('Atleta', $m['name']);
        $this->assertSame('Data de nascimento', $m['birth_date']);
        $this->assertSame('Clube atual', $m['current_club']);
        $this->assertSame('Empresário', $m['agent_name']);
    }

    public function test_plan_commit_reimport_and_revert(): void
    {
        $plan = PlayerImporter::plan($this->rows(), $this->mapping(), true);
        $this->assertSame(['criar', 'criar', 'erro', 'erro'], array_column($plan, 'action'));
        $batch = PlayerImporter::commit($plan, 'teste.csv', $this->mapping(), null);
        $this->assertSame(2, $batch->rows_created);
        $joao = Player::where('cbf_id', '123456')->first();
        $this->assertSame('LE', $joao->primary_position->value);
        $this->assertSame('esquerdo', $joao->preferred_foot->value);
        $this->assertSame('30000.00', $joao->salary_min);
        $this->assertNull(Player::where('name', 'José Sem Data')->first()->birth_date); // ausente ≠ inventado

        // Reimportar não duplica: João casa por CBF; José (sem data) vira possível homônimo e é ignorado
        $plan2 = PlayerImporter::plan(array_slice($this->rows(), 0, 2), $this->mapping(), true);
        $this->assertSame(['atualizar', 'ignorar'], array_column($plan2, 'action'));
        PlayerImporter::commit($plan2, 'teste2.csv', $this->mapping(), null);
        $this->assertSame(2, Player::count());

        $r = PlayerImporter::revert($batch, null);
        $this->assertSame(2, $r['removed']);
        $this->assertSame(0, Player::count());
    }

    public function test_financial_columns_ignored_without_permission(): void
    {
        $plan = PlayerImporter::plan(array_slice($this->rows(), 0, 1), $this->mapping(), false);
        $this->assertArrayNotHasKey('salary_min', $plan[0]['data']);
    }
}
