<?php

namespace Tests\Feature;

use App\Filament\Pages\CoberturaDados;
use App\Jobs\RunArtisanSync;
use App\Models\CbfAthlete;
use App\Models\Club;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
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

    private function athlete(string $name, string $cbfId, ?string $birth, array $tm): Player
    {
        $p = Player::create(['name' => $name, 'current_club_id' => $this->club->id]);
        CbfAthlete::create(['cbf_id' => $cbfId, 'name' => $name.' da Silva', 'nickname' => $name, 'club_id' => $this->club->id, 'birth_date' => $birth, 'player_id' => $p->id, 'link_status' => 'provavel']);
        IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'cbf', 'external_id' => $cbfId, 'kind' => 'confirmar', 'status' => 'pendente', 'score' => 0, 'label' => 'CBF']);
        foreach ($tm as $id => $age) {
            IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'transfermarkt', 'external_id' => (string) $id, 'kind' => 'escolher', 'status' => 'pendente', 'score' => 90,
                'label' => "TM {$id}", 'details' => ['id' => (string) $id, 'age' => $age]]);
        }

        return $p;
    }

    private function fakeProfiles(): void
    {
        Http::fake([
            '*/players/101/profile' => Http::response(['id' => '101', 'dateOfBirth' => '2001-05-10', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/102/profile' => Http::response(['id' => '102', 'dateOfBirth' => '1999-01-01', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/201/profile' => Http::response(['id' => '201', 'dateOfBirth' => '2002-02-02', 'club' => ['id' => '609', 'name' => 'Cruzeiro Esporte Clube']]),
            '*/players/301/profile' => Http::response(['id' => '301', 'dateOfBirth' => '2000-03-03', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/players/302/profile' => Http::response(['id' => '302', 'dateOfBirth' => '2000-03-03', 'club' => ['id' => '5677', 'name' => 'Vila Nova Futebol Clube (GO)']]),
            '*/clubs/*' => Http::response(['name' => 'Clube']),
        ]);
    }

    public function test_preview_links_only_when_both_sources_agree_and_apply_is_reversible(): void
    {
        $this->fakeProfiles();
        $ok = $this->athlete('Japa', '9001', '2001-05-10', [101 => 25, 102 => 27]);
        $otherClub = $this->athlete('Tito', '9002', '2002-02-02', [201 => 24]);
        $twins = $this->athlete('Dodô', '9003', '2000-03-03', [301 => 26, 302 => 26]);
        $noBirth = $this->athlete('Sorriso', '9004', null, [401 => 24]);

        $this->artisan('scout:identities-converge')->expectsOutputToContain('PRÉVIA: 1 de 3')->assertSuccessful();
        $this->assertNull($ok->fresh()->birth_date); // prévia não grava
        $this->assertSame(0, PlayerExternalId::count());

        $this->artisan('scout:identities-converge --apply --actor=user:1')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $p = $ok->fresh();
        $this->assertSame('2001-05-10', $p->birth_date->toDateString());
        $this->assertSame('9001', $p->cbf_id);
        $this->assertSame('confirmado', CbfAthlete::where('cbf_id', '9001')->value('link_status'));
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
        $this->assertSame('provavel', CbfAthlete::where('cbf_id', '9001')->value('link_status'));
        $this->assertSame(0, PlayerExternalId::count());
        $this->assertSame(2, IdentityCandidate::where('player_id', $p->id)->where('provider', 'transfermarkt')->where('status', 'pendente')->count());
    }

    public function test_age_filter_skips_profile_calls_and_admin_buttons_dispatch(): void
    {
        $this->fakeProfiles();
        $this->athlete('Japa', '9001', '2001-05-10', [101 => 25, 102 => 40]);
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
}
