<?php

namespace Tests\Feature;

use App\Console\Commands\IdentitiesConverge;
use App\Filament\Pages\CoberturaDados;
use App\Jobs\RunArtisanSync;
use App\Models\CbfAthlete;
use App\Models\Club;
use App\Models\ContractObservation;
use App\Models\Player;
use App\Models\WatchlistItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** Fusão de duplicados autorizada pelo Gustavo (12/10/2026): só evidência forte, lote reversível, nada apagado. */
class PlayersDedupTest extends ScoutTestCase
{
    public function test_merges_strong_pairs_reversibly_and_keeps_scout_record(): void
    {
        $club = Club::create(['name' => 'Goiás']);
        $scout = Player::create(['name' => 'Baldória', 'current_club_id' => $club->id, 'birth_date' => '2005-04-21', 'is_priority' => true]);
        $shadow = Player::create(['name' => 'Guilherme Baldória', 'full_name' => 'Guilherme Baldória Santos', 'current_club_id' => $club->id,
            'birth_date' => '2005-04-21', 'cbf_id' => '777', 'height_cm' => 180]);
        CbfAthlete::create(['cbf_id' => '777', 'name' => 'Guilherme Baldória Santos', 'club_id' => $club->id, 'player_id' => $shadow->id, 'link_status' => 'confirmado']);
        ContractObservation::create(['player_id' => $shadow->id, 'status' => 'vigente', 'observed_on' => now()]);
        WatchlistItem::create(['player_id' => $scout->id, 'status' => 'ativo', 'owner_id' => $this->user('analista')->id, 'reason' => 'teste']);
        $homonym = Player::create(['name' => 'Baldória', 'current_club_id' => Club::create(['name' => 'Outro'])->id, 'birth_date' => '2005-04-21']); // outro clube
        $different = Player::create(['name' => 'Fulano', 'current_club_id' => $club->id, 'birth_date' => '2005-04-21']); // nome incompatível

        $this->artisan('scout:players-dedup')->expectsOutputToContain('PRÉVIA: 1 par')->assertSuccessful();
        $this->assertNull($shadow->fresh()->deleted_at);

        $this->artisan('scout:players-dedup --apply --actor=user:1')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $this->assertSoftDeleted('players', ['id' => $shadow->id]); // arquivado, não apagado
        $kept = $scout->fresh();
        $this->assertSame('777', $kept->cbf_id);
        $this->assertSame(180, $kept->height_cm);
        $this->assertSame('Guilherme Baldória Santos', $kept->full_name);
        $this->assertSame($kept->id, CbfAthlete::where('cbf_id', '777')->value('player_id'));
        $this->assertSame(1, ContractObservation::where('player_id', $kept->id)->count());
        $this->assertNull($homonym->fresh()->deleted_at);
        $this->assertNull($different->fresh()->deleted_at);

        $batch = DB::table('data_fix_changes')->where('kind', 'dedup')->value('batch');
        $this->artisan("scout:players-dedup --revert={$batch}")->expectsOutputToContain('conflitos: 0')->assertSuccessful();
        $this->assertNull($shadow->fresh()->deleted_at);
        $this->assertSame('777', $shadow->fresh()->cbf_id);
        $this->assertNull($scout->fresh()->cbf_id);
        $this->assertNull($scout->fresh()->height_cm);
        $this->assertSame($shadow->id, CbfAthlete::where('cbf_id', '777')->value('player_id'));
        $this->assertSame(1, ContractObservation::where('player_id', $shadow->id)->count());
    }

    public function test_convergence_duplicate_without_birth_on_scout_record(): void
    {
        $club = Club::create(['name' => 'Cuiabá']);
        $scout = Player::create(['name' => 'Calebe Costa', 'current_club_id' => $club->id]);
        $other = Player::create(['name' => 'Calebe', 'current_club_id' => $club->id, 'birth_date' => '2003-06-07']);
        Cache::put(IdentitiesConverge::CACHE, ['at' => now()->toDateTimeString(), 'applied' => false, 'rows' => [
            ['player_id' => $scout->id, 'decision' => 'duplicado', 'duplicate_of' => $other->id, 'reason' => 'Cadastro duplicado (convergência)'],
        ]]);
        $this->artisan('scout:players-dedup --apply')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $this->assertSoftDeleted('players', ['id' => $other->id]);
        $this->assertSame('2003-06-07', $scout->fresh()->birth_date->toDateString());
    }

    public function test_admin_buttons(): void
    {
        $club = Club::create(['name' => 'Goiás']);
        Player::create(['name' => 'Baldória', 'current_club_id' => $club->id, 'birth_date' => '2005-04-21']);
        Player::create(['name' => 'Baldoria', 'current_club_id' => $club->id, 'birth_date' => '2005-04-21']);
        $this->artisan('scout:players-dedup')->assertSuccessful();
        Queue::fake();
        $admin = $this->user('admin', ['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin);
        Livewire::test(CoberturaDados::class)->assertSee('1 par(es)')
            ->callAction('fix', [], ['correcao' => 'duplicados'])->assertHasNoActionErrors();
        Queue::assertPushed(RunArtisanSync::class, fn ($j) => $j->command === 'scout:players-dedup' && $j->params['--apply'] === true);
        $this->actingAs($this->user('coordenador'));
        Livewire::test(CoberturaDados::class)->assertActionHidden('fix', ['correcao' => 'duplicados']);
    }
}
