<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\ContractObservation;
use App\Models\ImportBatch;
use App\Models\MarketObservation;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerRoleAssessment;
use App\Models\TacticalRole;
use App\Services\Import\PlayerImporter;
use App\Support\ClubResolver;
use Database\Seeders\ClubsSeeder;

/** F05 — reversão campo a campo, conflitos explícitos, derivados e reversão única. F02 na importação. */
class ImportRevertTest extends ScoutTestCase
{
    private array $map = ['name' => 'Nome', 'cbf_id' => 'CBF', 'height_cm' => 'Altura', 'salary_min' => 'Salário',
        'current_club' => 'Clube', 'agent_name' => 'Empresário', 'preferred_foot' => 'Pé', 'positions' => 'Posições'];

    private function import(array $rows, bool $financial = true): ImportBatch
    {
        return PlayerImporter::commit(PlayerImporter::plan($rows, $this->map, $financial), 'lote.csv', $this->map, null);
    }

    public function test_existing_player_fields_and_derived_salary_observation_are_restored(): void
    {
        $p = Player::create(['name' => 'Atleta', 'cbf_id' => '111', 'height_cm' => null]);
        $b = $this->import([['Nome' => 'Atleta', 'CBF' => '111', 'Altura' => '1,88', 'Salário' => 'R$ 25.000', 'Pé' => 'canhoto', 'Posições' => 'LE, ALE']]);
        $p->refresh();
        $this->assertSame(188, $p->height_cm);
        $this->assertSame('25000.00', $p->salary_min);
        $this->assertSame(['LE', 'ALE'], $p->positions);
        $this->assertSame(1, MarketObservation::where('player_id', $p->id)->where('import_batch_id', $b->id)->count()); // derivado ligado ao lote

        $r = PlayerImporter::revert($b, null);
        $p->refresh();
        $this->assertNull($p->height_cm);
        $this->assertNull($p->salary_min);
        $this->assertNull($p->positions);
        $this->assertSame('nao_informado', $p->preferred_foot->value);
        $this->assertSame(0, MarketObservation::where('player_id', $p->id)->count());
        $this->assertGreaterThanOrEqual(5, $r['restored']);
        $this->assertSame([], $r['conflicts']);
    }

    public function test_later_edit_is_kept_and_reported_as_conflict(): void
    {
        $p = Player::create(['name' => 'Atleta', 'cbf_id' => '111']);
        $b = $this->import([['Nome' => 'Atleta', 'CBF' => '111', 'Altura' => '1,88', 'Pé' => 'direito']]);
        $p->refresh()->update(['height_cm' => 190]); // analista corrige depois

        $r = PlayerImporter::revert($b, null);
        $p->refresh();
        $this->assertSame(190, $p->height_cm); // trabalho posterior preservado
        $this->assertSame('nao_informado', $p->preferred_foot->value); // campo intocado foi restaurado
        $this->assertCount(1, $r['conflicts']);
        $this->assertSame('height_cm', $r['conflicts'][0]['field']);
        $this->assertEquals($r, $b->fresh()->revert_report); // relatório persistido no lote (jsonb reordena chaves)
    }

    public function test_created_player_edited_or_used_later_is_kept(): void
    {
        $b = $this->import([['Nome' => 'Novo Um', 'CBF' => '201'], ['Nome' => 'Novo Dois', 'CBF' => '202'], ['Nome' => 'Novo Três', 'CBF' => '203']]);
        Player::where('cbf_id', '201')->first()->update(['name' => 'Novo Um Corrigido']);
        PlayerRoleAssessment::create(['player_id' => Player::where('cbf_id', '202')->value('id'),
            'tactical_role_id' => TacticalRole::value('id'), 'kind' => 'observado', 'level' => 'B', 'evidence' => 'x']);

        $r = PlayerImporter::revert($b, null);
        $this->assertSame(1, $r['removed']);
        $this->assertSame(2, $r['kept']);
        $this->assertSame(['201', '202'], Player::orderBy('cbf_id')->pluck('cbf_id')->all());
        $this->assertStringContainsString('name', collect($r['conflicts'])->firstWhere('label', 'Novo Um Corrigido')['reason']);
    }

    public function test_batch_can_be_reverted_only_once(): void
    {
        $b = $this->import([['Nome' => 'Novo', 'CBF' => '301']]);
        PlayerImporter::revert($b, null);
        $this->expectException(\RuntimeException::class);
        PlayerImporter::revert($b->fresh(), null);
    }

    public function test_club_alias_is_reused_and_created_club_and_agent_leave_with_the_batch(): void
    {
        $this->seed(ClubsSeeder::class);
        ClubResolver::flush();
        $clubs = Club::count();
        $b = $this->import([
            ['Nome' => 'A', 'CBF' => '401', 'Clube' => 'Bragantino', 'Empresário' => 'Agência Nova'],
            ['Nome' => 'B', 'CBF' => '402', 'Clube' => 'Clube Inédito FC'],
        ]);
        $this->assertSame('Red Bull Bragantino', Player::where('cbf_id', '401')->first()->currentClub->name);
        $this->assertSame($clubs + 1, Club::count());
        $this->assertSame(1, Agent::where('name', 'Agência Nova')->count());

        $r = PlayerImporter::revert($b, null);
        $this->assertSame(2, $r['removed']);
        $this->assertSame(2, $r['removed_other']);
        $this->assertSame($clubs, Club::count());
        $this->assertSame(0, Agent::where('name', 'Agência Nova')->count());
        $this->assertNotNull(Club::where('name', 'Red Bull Bragantino')->first()); // clube preexistente nunca sai
    }

    public function test_club_created_by_batch_but_used_later_is_kept(): void
    {
        $b = $this->import([['Nome' => 'A', 'CBF' => '501', 'Clube' => 'Clube Inédito FC']]);
        $club = Club::where('name', 'Clube Inédito FC')->first();
        Player::create(['name' => 'Outro', 'current_club_id' => $club->id]); // cadastro manual posterior
        $r = PlayerImporter::revert($b, null);
        $this->assertNotNull($club->fresh());
        $this->assertSame('club', collect($r['conflicts'])->firstWhere('label', 'Clube Inédito FC')['entity']);
    }

    public function test_ambiguous_or_negative_money_is_not_imported_and_explained(): void
    {
        $plan = PlayerImporter::plan([
            ['Nome' => 'A', 'CBF' => '601', 'Salário' => '25,000'],
            ['Nome' => 'B', 'CBF' => '602', 'Salário' => '-1000'],
            ['Nome' => 'C', 'CBF' => '603', 'Salário' => 'US$ 3.000'],
        ], $this->map, true);
        $this->assertArrayNotHasKey('salary_min', $plan[0]['data']);
        $this->assertStringContainsString('"25,000" não importado (ambiguo', $plan[0]['message']);
        $this->assertArrayNotHasKey('salary_min', $plan[1]['data']);
        $this->assertStringContainsString('negativo', $plan[1]['message']);
        $this->assertSame(3000.0, $plan[2]['data']['salary_min']);
        $this->assertSame('USD', $plan[2]['data']['salary_currency']);
    }

    public function test_legacy_batch_without_change_log_still_reverts_what_it_created(): void
    {
        $b = $this->import([['Nome' => 'Antigo', 'CBF' => '701']]);
        $b->changes()->delete();
        $b->update(['has_change_log' => false]);
        $r = PlayerImporter::revert($b->fresh(), null);
        $this->assertSame(1, $r['removed']);
        $this->assertSame(0, Player::where('cbf_id', '701')->count());
    }

    public function test_created_player_with_any_later_reference_or_field_outside_snapshot_is_kept(): void
    {
        $b = $this->import([['Nome' => 'Um', 'CBF' => '801'], ['Nome' => 'Dois', 'CBF' => '802'], ['Nome' => 'Três', 'CBF' => '803']]);
        $this->travel(5)->seconds();
        Player::where('cbf_id', '801')->first()->update(['birth_date' => '2001-01-01']); // campo fora do snapshot
        PlayerExternalId::create(['player_id' => Player::where('cbf_id', '802')->value('id'), 'provider' => 'transfermarkt', 'external_id' => '999']);
        $r = PlayerImporter::revert($b, null);
        $this->assertSame(1, $r['removed']);
        $this->assertEqualsCanonicalizing(['801', '802'], Player::pluck('cbf_id')->all());
        $this->assertSame(1, PlayerExternalId::count()); // vínculo externo não sumiu em cascata
        $this->assertStringContainsString('player_external_ids', collect($r['conflicts'])->firstWhere('label', 'Dois')['reason']);
    }

    public function test_batch_observation_edited_later_is_kept(): void
    {
        $map = $this->map + ['contract_end' => 'Fim'];
        $b = PlayerImporter::commit(PlayerImporter::plan([['Nome' => 'X', 'CBF' => '901', 'Fim' => '31/12/2026']], $map, true), 'l.csv', $map, null);
        $obs = ContractObservation::first();
        $this->travel(10)->seconds();
        $obs->update(['confirmation' => 'confirmado']);
        $r = PlayerImporter::revert($b, null);
        $this->assertNotNull($obs->fresh());
        $this->assertSame('contract_observation', $r['conflicts'][0]['entity']);
    }

    public function test_club_created_by_batch_with_external_id_later_is_kept(): void
    {
        $b = $this->import([['Nome' => 'A', 'CBF' => '1001', 'Clube' => 'Clube Novíssimo']]);
        $club = Club::where('name', 'Clube Novíssimo')->first();
        ClubExternalId::create(['club_id' => $club->id, 'provider' => 'api_futebol', 'external_id' => '555']);
        Player::where('cbf_id', '1001')->first()->forceDelete(); // atleta já não segura o clube
        PlayerImporter::revert($b, null);
        $this->assertNotNull($club->fresh());
        $this->assertSame(1, ClubExternalId::count());
    }
}
