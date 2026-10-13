<?php

namespace Tests\Feature;

use App\Models\Appearance;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerSeasonStat;
use App\Services\SeasonStatsAggregator;
use Illuminate\Support\Facades\DB;

/** Pedido do Gustavo (13/10/2026): fundir em lote os provisórios "Mesmo clube e nome compatível" — reversível. */
class MergeBulkConfirmTest extends ScoutTestCase
{
    public function test_merges_provisional_with_appearances_reversibly_and_keeps_birth_conflicts_in_queue(): void
    {
        $club = Club::create(['name' => 'Vila Nova']);
        $scout = Player::create(['name' => 'Dudu', 'current_club_id' => $club->id]);
        $shadow = Player::create(['name' => 'Dudu', 'data_origin' => 'provedor', 'current_club_id' => $club->id, 'birth_date' => '2001-12-03']);
        PlayerExternalId::create(['player_id' => $shadow->id, 'provider' => 'api_football', 'external_id' => '555', 'verified' => false, 'link_method' => 'cadastro_provisorio']);
        $f = Fixture::create(['provider' => 'api_football', 'external_id' => '1', 'competition_name' => 'Série B', 'season' => '2026', 'data_quality' => 'completo', 'field_quality' => ['gols' => 'conferem']]);
        Appearance::create(['fixture_id' => $f->id, 'player_id' => $shadow->id, 'provider' => 'api_football', 'started' => true, 'used' => true, 'minutes' => 90]);
        SeasonStatsAggregator::rebuildProvider('api_football');
        IdentityCandidate::create(['player_id' => $scout->id, 'shadow_player_id' => $shadow->id, 'provider' => 'api_football', 'external_id' => '555', 'kind' => 'mesclar',
            'status' => 'pendente', 'score' => 100, 'label' => 'Dudu — Vila Nova (partida API-Football)', 'evidence' => 'Mesmo clube e nome compatível; sem nascimento no provedor para confirmar.']);

        $other = Player::create(['name' => 'Igor', 'current_club_id' => $club->id, 'birth_date' => '2007-03-07']);
        $otherShadow = Player::create(['name' => 'Igor', 'data_origin' => 'provedor', 'current_club_id' => $club->id, 'birth_date' => '1995-01-01']);
        IdentityCandidate::create(['player_id' => $other->id, 'shadow_player_id' => $otherShadow->id, 'provider' => 'api_football', 'external_id' => '556', 'kind' => 'mesclar',
            'status' => 'pendente', 'score' => 100, 'label' => 'Igor', 'evidence' => 'Mesmo clube e nome compatível; sem nascimento no provedor para confirmar.']);

        $this->artisan('scout:identities-confirm-merge')->expectsOutputToContain('PRÉVIA: 1 de 2')->expectsOutputToContain('Nascimento diverge')->assertSuccessful();
        $admin = $this->user('admin');
        $this->artisan('scout:identities-confirm-merge --apply --actor=user:'.$admin->id)->expectsOutputToContain('APLICADO: 1')->assertSuccessful();

        $this->assertSoftDeleted('players', ['id' => $shadow->id]);
        $this->assertSame(1, $scout->appearances()->count());
        $this->assertSame(90, PlayerSeasonStat::where('player_id', $scout->id)->where('provider', 'api_football')->sole()->minutes);
        $x = PlayerExternalId::where('external_id', '555')->first();
        $this->assertSame($scout->id, $x->player_id);
        $this->assertTrue((bool) $x->verified);
        $this->assertSame('2001-12-03', $scout->fresh()->birth_date->toDateString());
        $this->assertSame('vinculado', IdentityCandidate::where('external_id', '555')->value('status'));
        $this->assertSame('pendente', IdentityCandidate::where('external_id', '556')->value('status'));
        $this->assertNull($otherShadow->fresh()->deleted_at);

        $batch = DB::table('data_fix_changes')->where('kind', 'dedup')->value('batch');
        $this->artisan("scout:identities-confirm-merge --revert={$batch}")->assertSuccessful();
        $this->assertNull($shadow->fresh()->deleted_at);
        $this->assertSame(1, $shadow->fresh()->appearances()->count());
        $this->assertSame(0, $scout->appearances()->count());
        $this->assertNull($scout->fresh()->birth_date);
        $this->assertSame(90, PlayerSeasonStat::where('player_id', $shadow->id)->where('provider', 'api_football')->sole()->minutes);
        $this->assertSame(0, PlayerSeasonStat::where('player_id', $scout->id)->where('provider', 'api_football')->count());
        $this->assertSame('pendente', IdentityCandidate::where('external_id', '555')->value('status'));
    }
}
