<?php

namespace Tests\Feature;

use App\Filament\Pages\CoberturaDados;
use App\Jobs\RunArtisanSync;
use App\Models\CbfAthlete;
use App\Models\Club;
use App\Models\IdentityCandidate;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** Confirmação em lote dos vínculos CBF "mesmo clube de inscrição e nome compatível" (decisão do Gustavo, 12/10/2026). */
class CbfBulkConfirmTest extends ScoutTestCase
{
    private function probable(string $name, string $cbfId, ?string $playerBirth = null, ?string $cbfBirth = null, string $evidence = 'Mesmo clube de inscrição e nome compatível. Nascimento ainda não conferido.'): Player
    {
        $club = Club::firstOrCreate(['name' => 'Goiás']);
        $p = Player::create(['name' => $name, 'current_club_id' => $club->id, 'birth_date' => $playerBirth]);
        CbfAthlete::create(['cbf_id' => $cbfId, 'name' => $name.' Silva', 'nickname' => $name, 'club_id' => $club->id, 'player_id' => $p->id, 'link_status' => 'provavel', 'birth_date' => $cbfBirth]);
        IdentityCandidate::create(['player_id' => $p->id, 'provider' => 'cbf', 'external_id' => $cbfId, 'kind' => 'confirmar', 'status' => 'pendente', 'score' => 0, 'label' => "{$name} · CBF #{$cbfId}", 'evidence' => $evidence]);

        return $p;
    }

    public function test_confirms_probable_links_except_birth_conflict_and_is_reversible(): void
    {
        $a = $this->probable('Baldória', '501');
        $b = $this->probable('Calebe', '502', '2003-06-07', '2003-06-07', 'Mesmo clube e nome compatível. Nascimento CBF: 07/06/2003 · cadastro: sem data.');
        $conflict = $this->probable('Camilo', '503', '1999-02-23', '2001-01-01', 'Mesmo clube e nome compatível. Nascimento CBF: 01/01/2001 · cadastro: 23/02/1999.');
        $other = Player::create(['name' => 'Outro']);
        IdentityCandidate::create(['player_id' => $other->id, 'provider' => 'transfermarkt', 'external_id' => '9', 'kind' => 'escolher', 'status' => 'pendente', 'score' => 90, 'label' => 'x']);

        $this->artisan('scout:identities-confirm-cbf')->expectsOutputToContain('PRÉVIA: 2 de 3')->expectsOutputToContain('Nascimento diverge')->assertSuccessful();
        $this->assertNull($a->fresh()->cbf_id);

        $admin = $this->user('admin');
        $this->artisan('scout:identities-confirm-cbf --apply --actor=user:'.$admin->id)->expectsOutputToContain('APLICADO: 2')->assertSuccessful();
        $this->assertSame('501', $a->fresh()->cbf_id);
        $this->assertSame('confirmado', CbfAthlete::where('cbf_id', '501')->value('link_status'));
        $this->assertNull(CbfAthlete::where('cbf_id', '501')->value('detail_fetched_at')); // detalhe volta à fila do coletor
        $this->assertSame('vinculado', IdentityCandidate::where('external_id', '502')->value('status'));
        $this->assertSame($admin->id, IdentityCandidate::where('external_id', '502')->value('decided_by'));
        $this->assertSame('pendente', IdentityCandidate::where('external_id', '503')->value('status'));
        $this->assertNull($conflict->fresh()->cbf_id);
        $this->assertSame('pendente', IdentityCandidate::where('provider', 'transfermarkt')->value('status')); // outras fontes não mudam

        $batch = DB::table('data_fix_changes')->where('kind', 'identity_cbf')->value('batch');
        $this->artisan("scout:identities-confirm-cbf --revert={$batch}")->assertSuccessful();
        $this->assertNull($a->fresh()->cbf_id);
        $this->assertSame('provavel', CbfAthlete::where('cbf_id', '501')->value('link_status'));
        $this->assertSame(2, IdentityCandidate::where('provider', 'cbf')->where('status', 'pendente')->whereIn('external_id', ['501', '502'])->count());
    }

    public function test_admin_button_dispatches_and_coordinator_does_not_see_it(): void
    {
        $this->probable('Baldória', '501');
        Queue::fake();
        $admin = $this->user('admin', ['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin);
        Livewire::test(CoberturaDados::class)->assertSee('nome compatível": 1', false)
            ->callAction('fix', [], ['correcao' => 'confirmar_cbf'])->assertHasNoActionErrors();
        Queue::assertPushed(RunArtisanSync::class, fn ($j) => $j->command === 'scout:identities-confirm-cbf' && $j->params['--actor'] === 'user:'.$admin->id);
        $this->actingAs($this->user('coordenador'));
        Livewire::test(CoberturaDados::class)->assertActionHidden('fix', ['correcao' => 'confirmar_cbf']);
    }

    public function test_divergent_birth_confirmed_on_request_adopts_cbf_date_and_reverts(): void
    {
        $p = $this->probable('Huguinho', '601', '2007-04-06', '2007-06-04', 'Mesmo clube e nome compatível. Nascimento CBF: 04/06/2007 · cadastro: 06/04/2007.');
        $this->artisan('scout:identities-confirm-cbf --apply')->expectsOutputToContain('APLICADO: 0')->assertSuccessful();
        $this->artisan('scout:identities-confirm-cbf --apply --divergentes')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $this->assertSame('2007-06-04', $p->fresh()->birth_date->toDateString());
        $this->assertSame('601', $p->fresh()->cbf_id);
        $this->assertSame('2007-04-06', $p->fresh()->field_sources['birth_date']['previous']);
        $batch = DB::table('data_fix_changes')->where('kind', 'identity_cbf')->latest('id')->value('batch');
        $this->artisan("scout:identities-confirm-cbf --revert={$batch}")->assertSuccessful();
        $this->assertSame('2007-04-06', $p->fresh()->birth_date->toDateString());
        $this->assertNull($p->fresh()->cbf_id);
    }
}
