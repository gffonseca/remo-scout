<?php

namespace Tests\Feature;

use App\Filament\Resources\Players\Pages\ViewPlayer;
use App\Filament\Resources\Players\RelationManagers\ContractObservationsRelationManager;
use App\Models\Club;
use App\Models\DataSource;
use App\Models\Player;
use App\Support\Bid;
use Database\Seeders\DataSourcesSeeder;
use Livewire\Livewire;

/** BID CBF: consulta assistida (CAPTCHA no navegador), registrada como observação contratual datada. */
class BidTest extends ScoutTestCase
{
    public function test_bid_link_uses_cbf_id_and_source_is_manual_only(): void
    {
        $this->seed(DataSourcesSeeder::class);
        $this->assertSame('consulta_manual', DataSource::byKey('bid_cbf')->transport);
        $this->assertSame('https://bid.cbf.com.br/atleta-competicoes/828757', Bid::url(Player::create(['name' => 'A', 'cbf_id' => '828757'])));
        $this->assertSame('https://bid.cbf.com.br/home', Bid::url(Player::create(['name' => 'B'])));
    }

    public function test_recording_a_bid_lookup_creates_confirmed_dated_observation_with_author(): void
    {
        $club = Club::create(['name' => 'Remo']);
        $p = Player::create(['name' => 'Atleta', 'cbf_id' => '77']);
        $user = $this->user('analista');
        $o = Bid::record($p, ['tipo' => 'emprestimo', 'numero' => '123/2026', 'inicio' => '2026-07-01', 'publicacao' => '2026-07-03',
            'fim' => '2026-12-31', 'club_id' => $club->id], $user);
        $this->assertSame('emprestado', $o->status->value);
        $this->assertSame('confirmado', $o->confirmation->value);
        $this->assertSame('BID CBF', $o->source_label);
        $this->assertSame('2026-07-03', $o->observed_on->toDateString());
        $this->assertSame($user->id, $o->author_id);
        $this->assertStringContainsString('nº 123/2026', $o->notes);
        $this->assertStringContainsString('Consulta manual por '.$user->name, $o->notes);
    }

    public function test_bid_action_in_contract_tab(): void
    {
        $p = Player::create(['name' => 'Atleta', 'cbf_id' => '77']);
        $this->actingAs($this->user('analista'));
        Livewire::test(ContractObservationsRelationManager::class, ['ownerRecord' => $p, 'pageClass' => ViewPlayer::class])
            ->callTableAction('bid', data: ['tipo' => 'profissional', 'publicacao' => now()->subDay()->toDateString()])
            ->assertHasNoTableActionErrors();
        $this->assertSame('vigente', $p->contractObservations()->sole()->status->value);
    }
}
