<?php

namespace Tests\Feature;

use App\Filament\Pages\CoberturaDados;
use App\Jobs\RunArtisanSync;
use App\Models\CbfAthlete;
use App\Models\Club;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Services\Collector\CbfIngest;
use App\Sources\SourceClient;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** Opção B (12/10/2026): sem nascimento no cadastro, liga só quando CBF e Transfermarkt concordam no nascimento e no clube. */
class IdentityConvergenceTest extends ScoutTestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        SourceClient::$sleeper = fn () => null;
        $this->club = Club::create(['name' => 'Vila Nova', 'state' => 'GO']);
    }

    /** @param array<int, string> $tm id => nome do clube atual no Transfermarkt */
    private function athlete(string $name, string $cbfId, ?string $birth, array $tm, bool $cbfLinked = false): Player
    {
        $p = Player::create(['name' => $name, 'current_club_id' => $this->club->id]);
        CbfAthlete::create(['cbf_id' => $cbfId, 'name' => $name.' da Silva', 'nickname' => $name, 'club_id' => $this->club->id, 'birth_date' => $birth,
            'player_id' => $cbfLinked ? $p->id : null, 'link_status' => $cbfLinked ? 'provavel' : null]);
        foreach ($tm as $id => $club) {
            IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'transfermarkt', 'external_id' => (string) $id, 'kind' => 'escolher', 'status' => 'pendente', 'score' => 90,
                'label' => "TM {$id}", 'details' => ['id' => (string) $id, 'club' => ['id' => '0', 'name' => $club]]]);
        }

        return $p;
    }

    private function fakeProfiles(): void
    {
        Http::fake([
            '*/players/101/profile' => Http::response(['id' => '101', 'dateOfBirth' => '2001-05-10', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/102/profile' => Http::response(['id' => '102', 'dateOfBirth' => '1999-01-01', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/201/profile' => Http::response(['id' => '201', 'dateOfBirth' => '2002-02-02', 'club' => ['id' => '609', 'name' => 'Cruzeiro Esporte Clube']]),
            '*/players/401/profile' => Http::response(['id' => '401', 'dateOfBirth' => '1999-09-09', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/301/profile' => Http::response(['id' => '301', 'dateOfBirth' => '2000-03-03', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/302/profile' => Http::response(['id' => '302', 'dateOfBirth' => '2000-03-03', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/clubs/*' => Http::response(['name' => 'Clube']),
        ]);
    }

    public function test_preview_links_only_when_both_sources_agree_and_apply_is_reversible(): void
    {
        $this->fakeProfiles();
        $vila = 'Vila Nova Futebol Clube (GO)';
        $ok = $this->athlete('Japa', '9001', '2001-05-10', [101 => $vila, 102 => 'Sport Recife']);
        $otherClub = $this->athlete('Tito', '9002', '2002-02-02', [201 => 'Cruzeiro Esporte Clube']);
        $twins = $this->athlete('Dodô', '9003', '2000-03-03', [301 => $vila, 302 => $vila]);
        $noBirth = $this->athlete('Sorriso', '9004', null, [401 => $vila]); // CBF sem nascimento: nada confirma o Transfermarkt

        $this->artisan('scout:identities-converge')->expectsOutputToContain('PRÉVIA: 1 de 4')->assertSuccessful();
        $this->assertNull($ok->fresh()->birth_date); // prévia não grava
        $this->assertSame(0, PlayerExternalId::count());

        $this->artisan('scout:identities-converge --apply --actor=user:1')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $p = $ok->fresh();
        $this->assertSame('2001-05-10', $p->birth_date->toDateString());
        $this->assertSame('9001', $p->cbf_id);
        $this->assertSame('confirmado', CbfAthlete::where('cbf_id', '9001')->value('link_status'));
        $this->assertSame($p->id, CbfAthlete::where('cbf_id', '9001')->value('player_id'));
        $this->assertSame('convergencia_cbf_tm', PlayerExternalId::where('player_id', $p->id)->value('link_method'));
        $this->assertSame('vinculado', IdentityCandidate::where('external_id', '101')->value('status'));
        $this->assertSame('descartado', IdentityCandidate::where('external_id', '102')->value('status'));
        foreach ([$otherClub, $twins, $noBirth] as $x) { // clube diferente, dois candidatos iguais, CBF sem nascimento: seguem na fila
            $this->assertNull($x->fresh()->birth_date);
            $this->assertSame(0, PlayerExternalId::where('player_id', $x->id)->count());
        }

        $batch = DB::table('data_fix_changes')->where('kind', 'identity')->value('batch');
        $this->artisan("scout:identities-converge --revert={$batch}")->assertSuccessful();
        $p = $ok->fresh();
        $this->assertNull($p->birth_date);
        $this->assertNull($p->cbf_id);
        $this->assertNull(CbfAthlete::where('cbf_id', '9001')->value('player_id'));
        $this->assertSame(0, PlayerExternalId::count());
        $this->assertSame(2, IdentityCandidate::where('player_id', $p->id)->where('provider', 'transfermarkt')->where('status', 'pendente')->count());
    }

    public function test_previously_linked_probable_cbf_also_counts_and_homonym_in_cbf_blocks(): void
    {
        $this->fakeProfiles();
        $vila = 'Vila Nova Futebol Clube (GO)';
        $linked = $this->athlete('Japa', '9001', '2001-05-10', [101 => $vila], cbfLinked: true);
        $this->artisan('scout:identities-converge --apply')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $this->assertSame('9001', $linked->fresh()->cbf_id);

        $twin = $this->athlete('Dodô', '9003', '2000-03-03', [301 => $vila]);
        CbfAthlete::create(['cbf_id' => '9005', 'name' => 'Dodô Santos', 'nickname' => 'Dodô', 'club_id' => $this->club->id, 'birth_date' => '2000-03-03']);
        $this->artisan('scout:identities-converge --apply')->expectsOutputToContain('APLICADO: 0')->assertSuccessful();
        $this->assertNull($twin->fresh()->birth_date);
    }

    public function test_other_club_candidates_skip_profile_calls_and_admin_buttons_dispatch(): void
    {
        $this->fakeProfiles();
        $this->athlete('Japa', '9001', '2001-05-10', [101 => 'Vila Nova Futebol Clube (GO)', 102 => 'Sport Recife']);
        $this->artisan('scout:identities-converge')->assertSuccessful();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/players/102/'));

        Queue::fake();
        $admin = $this->user('admin', ['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin);
        Livewire::test(CoberturaDados::class)->assertSee('1 ligar')
            ->callAction('fix', [], ['correcao' => 'convergir'])->assertHasNoActionErrors();
        Queue::assertPushed(RunArtisanSync::class, fn ($j) => $j->command === 'scout:identities-converge' && $j->params['--apply'] === true);

        $this->actingAs($this->user('coordenador'));
        Livewire::test(CoberturaDados::class)->assertActionHidden('fix', ['correcao' => 'convergir']);
    }

    public function test_free_cbf_records_of_the_same_club_enter_the_detail_queue(): void
    {
        $p = Player::create(['name' => 'Japa', 'current_club_id' => $this->club->id, 'is_priority' => true]);
        $other = Club::create(['name' => 'Outro']);
        CbfAthlete::create(['cbf_id' => '7001', 'name' => 'Japa da Silva', 'nickname' => 'Japa', 'club_id' => $this->club->id]);
        CbfAthlete::create(['cbf_id' => '7002', 'name' => 'Fulano de Tal', 'nickname' => 'Fulano', 'club_id' => $this->club->id]); // nome incompatível
        CbfAthlete::create(['cbf_id' => '7003', 'name' => 'Japa Souza', 'nickname' => 'Japa', 'club_id' => $other->id]); // outro clube
        CbfAthlete::create(['cbf_id' => '7004', 'name' => 'Japa Lima', 'nickname' => 'Japa', 'club_id' => $this->club->id, 'detail_fetched_at' => now()]); // já tem detalhe

        $this->assertSame(['7001'], CbfIngest::pendingDetails(50));
        $p->update(['birth_date' => '2000-01-01']);
        $this->assertSame([], CbfIngest::pendingDetails(50)); // com nascimento, não precisa
    }
}
