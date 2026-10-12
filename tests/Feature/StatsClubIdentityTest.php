<?php

namespace Tests\Feature;

use App\Filament\Pages\CoberturaDados;
use App\Filament\Resources\Players\PlayerResource;
use App\Jobs\RunArtisanSync;
use App\Models\Appearance;
use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Services\DataCoverage;
use App\Services\SeasonStatsAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Prompt mestre 3.5 — clube da estatística: canônico × ID da fonte × rótulo; nunca código como nome. */
class StatsClubIdentityTest extends ScoutTestCase
{
    private function tmStat(Player $p, string $clubId, array $extra = []): PlayerSeasonStat
    {
        return PlayerSeasonStat::create($extra + ['player_id' => $p->id, 'provider' => 'transfermarkt', 'competition_key' => 'BRA2', 'competition_name' => 'Série B',
            'season' => '2025', 'club_external_id' => $clubId, 'club_key' => PlayerSeasonStat::clubKey($clubId), 'appearances' => 10]);
    }

    public function test_two_clubs_same_season_and_same_id_in_different_providers_stay_distinct(): void
    {
        $p = Player::create(['name' => 'Rodado']);
        $this->tmStat($p, '199');
        $this->tmStat($p, '537'); // emprestado no meio da temporada: outra linha
        PlayerSeasonStat::create(['player_id' => $p->id, 'provider' => 'cbf', 'competition_key' => 'BRA2', 'competition_name' => 'Série B', 'season' => '2025',
            'club_external_id' => '199', 'club_key' => PlayerSeasonStat::clubKey('199'), 'appearances' => 9]);
        $this->assertSame(3, $p->seasonStats()->count());

        $a = Club::create(['name' => 'Paysandu']);
        $b = Club::create(['name' => 'Nacional', 'country' => 'Brasil', 'state' => 'AM']);
        Club::create(['name' => 'Nacional', 'country' => 'Uruguai']); // homônimo: o vínculo é pelo ID, nunca pelo nome
        ClubExternalId::create(['provider' => 'transfermarkt', 'external_id' => '199', 'club_id' => $a->id, 'method' => 'manual']);
        ClubExternalId::create(['provider' => 'transfermarkt', 'external_id' => '537', 'club_id' => $b->id, 'method' => 'manual']);
        ClubExternalId::create(['provider' => 'cbf', 'external_id' => '199', 'club_id' => $b->id, 'method' => 'nome_exato']); // mesmo número, outra fonte

        $this->artisan('scout:stats-clubs --apply --actor=teste')->assertSuccessful();
        $rows = $p->seasonStats()->get();
        $this->assertSame($a->id, $rows->where('provider', 'transfermarkt')->firstWhere('club_external_id', '199')->club_id);
        $this->assertSame($b->id, $rows->where('provider', 'transfermarkt')->firstWhere('club_external_id', '537')->club_id);
        $this->assertSame($b->id, $rows->firstWhere('provider', 'cbf')->club_id);
    }

    public function test_preview_writes_nothing_apply_is_traced_and_revert_is_idempotent_with_conflicts(): void
    {
        $p = Player::create(['name' => 'Trilha']);
        $s1 = $this->tmStat($p, '199');
        $s2 = $this->tmStat($p, '537');
        $c = Club::create(['name' => 'Paysandu']);
        ClubExternalId::create(['provider' => 'transfermarkt', 'external_id' => '199', 'club_id' => $c->id, 'method' => 'manual', 'external_name' => 'Paysandu SC']);
        ClubExternalId::create(['provider' => 'transfermarkt', 'external_id' => '537', 'club_id' => $c->id, 'method' => 'subconjunto']); // semelhança: não corrige sozinho

        $this->artisan('scout:stats-clubs')->expectsOutputToContain('PRÉVIA')->assertSuccessful();
        $this->assertNull($s1->fresh()->club_id);
        $this->assertSame(0, DB::table('data_fix_changes')->count());

        $this->artisan('scout:stats-clubs --apply')->expectsOutputToContain('vínculo por semelhança a confirmar: 1')->assertSuccessful();
        $this->assertSame($c->id, $s1->fresh()->club_id);
        $this->assertSame('Paysandu SC', $s1->fresh()->club_source_name);
        $this->assertNull($s2->fresh()->club_id);
        $batch = DB::table('data_fix_changes')->value('batch');

        $s1->forceFill(['club_source_name' => 'editado à mão'])->save(); // edição posterior → conflito, sem sobrescrita cega
        $this->artisan("scout:stats-clubs --revert={$batch}")->expectsOutputToContain('conflitos (editadas depois): 1')->assertSuccessful();
        $this->assertSame($c->id, $s1->fresh()->club_id);
        $s1->forceFill(['club_source_name' => 'Paysandu SC'])->save();
        $this->artisan("scout:stats-clubs --revert={$batch}")->expectsOutputToContain('Revertidas: 1')->assertSuccessful();
        $this->assertNull($s1->fresh()->club_id);
        $this->artisan("scout:stats-clubs --revert={$batch}")->expectsOutputToContain('já revertidas: 1')->assertSuccessful();
    }

    public function test_profile_never_shows_a_code_and_aggregates_keep_historical_club(): void
    {
        $old = Club::create(['name' => 'Clube Antigo']);
        $now = Club::create(['name' => 'Clube Atual']);
        $p = Player::create(['name' => 'Histórico', 'current_club_id' => $now->id]);
        $this->tmStat($p, '987654', ['club_source_name' => 'Antigo FC']);
        $f = Fixture::create(['provider' => 'api_futebol', 'external_id' => 'x1', 'competition_name' => 'Série C', 'season' => '2026', 'home_club_id' => $old->id, 'data_quality' => 'completo']);
        Appearance::create(['fixture_id' => $f->id, 'player_id' => $p->id, 'club_id' => $old->id, 'provider' => 'api_futebol', 'used' => true, 'started' => true, 'minutes' => 90, 'goals' => 1]);
        SeasonStatsAggregator::rebuildProvider('api_futebol', [$p->id]);
        SeasonStatsAggregator::rebuildProvider('api_futebol', [$p->id]); // reconsolidar não duplica
        $agg = $p->seasonStats()->where('provider', 'api_futebol')->get();
        $this->assertCount(1, $agg);
        $this->assertSame($old->id, $agg[0]->club_id); // clube da partida, não o atual

        $this->actingAs($this->user('analista'));
        $html = $this->get(PlayerResource::getUrl('view', ['record' => $p]))->assertOk()->getContent();
        $this->assertStringContainsString('Clube não identificado', $html);
        $this->assertStringContainsString('Clube Antigo', $html);
        $this->assertDoesNotMatchRegularExpression('/<td>\s*987654\s*<\/td>/', $html); // ID só no detalhe de suporte
    }

    public function test_coordinator_links_pending_club_from_coverage_page(): void
    {
        $p = Player::create(['name' => 'Pendente']);
        $s = $this->tmStat($p, '4242', ['club_source_name' => 'Clube Distante']);
        $club = Club::create(['name' => 'Distante FC']);
        $this->actingAs($this->user('analista'));
        Livewire::test(CoberturaDados::class)->assertSee('Clube Distante')->assertActionHidden('linkClub', ['provider' => 'transfermarkt', 'external_id' => '4242']);

        $this->actingAs($this->user('coordenador'));
        Livewire::test(CoberturaDados::class)
            ->callAction('linkClub', ['club_id' => $club->id], ['provider' => 'transfermarkt', 'external_id' => '4242'])->assertHasNoActionErrors();
        $this->assertSame($club->id, $s->fresh()->club_id);
        $map = ClubExternalId::where('provider', 'transfermarkt')->where('external_id', '4242')->first();
        $this->assertSame('manual', $map->method);
        $this->assertNotNull($map->confirmed_at);
        $this->assertSame(1, DB::table('data_fix_changes')->where('row_id', $s->id)->count());
    }

    public function test_analyst_sees_pending_club_but_cannot_link(): void
    {
        $this->tmStat(Player::create(['name' => 'Pendente']), '4242', ['club_source_name' => 'Clube Distante']);
        $this->actingAs($this->user('analista'));
        Livewire::test(CoberturaDados::class)->assertSee('Clube Distante')
            ->assertActionHidden('linkClub', ['provider' => 'transfermarkt', 'external_id' => '4242']);
    }

    public function test_migration_on_legacy_rows_is_lossless_and_keeps_cbf_ingest_on_the_same_row(): void
    {
        $m = require database_path('migrations/2026_10_16_000100_season_stats_club_identity.php');
        $m->down(); // volta ao formato antigo para simular os dados de produção
        $club = Club::create(['name' => 'Paysandu']);
        $p = Player::create(['name' => 'Legado', 'cbf_id' => '555']);
        DB::table('cbf_athletes')->insert(['id' => (string) Str::uuid(), 'cbf_id' => '555', 'name' => 'Legado', 'club_cbf_id' => '90', 'club_name' => 'Paysandu', 'club_id' => $club->id, 'created_at' => now(), 'updated_at' => now()]);
        $row = fn (array $a) => DB::table('player_season_stats')->insert($a + ['id' => (string) Str::uuid(), 'player_id' => $p->id, 'competition_name' => 'X', 'season' => '2026', 'created_at' => now(), 'updated_at' => now()]);
        $row(['provider' => 'transfermarkt', 'competition_key' => 'BRA2', 'club_name' => '199']);
        $row(['provider' => 'transfermarkt', 'competition_key' => 'BRA2', 'club_name' => '537']); // duas passagens com código (caso real de produção)
        $row(['provider' => 'transfermarkt', 'competition_key' => 'BRA3', 'club_name' => 'Remo']);
        $row(['provider' => 'transfermarkt', 'competition_key' => 'BRA3', 'club_name' => 'REMO']); // só a caixa difere: era permitido
        $row(['provider' => 'cbf', 'competition_key' => 'cbf-total', 'club_name' => 'Paysandu', 'appearances' => 5]);
        $m->up();

        $this->assertSame(['199', '537'], PlayerSeasonStat::where('competition_key', 'BRA2')->orderBy('club_external_id')->pluck('club_external_id')->all());
        $tm = PlayerSeasonStat::where('provider', 'transfermarkt')->where('competition_key', 'BRA2')->first();
        $this->assertNull($tm->club_name);
        $this->assertSame(2, PlayerSeasonStat::where('competition_key', 'BRA3')->count());
        $cbf = PlayerSeasonStat::where('provider', 'cbf')->first();
        $this->assertSame('ext:90', $cbf->club_key);
        $this->assertSame($club->id, $cbf->club_id);

        // a ingestão seguinte da CBF atualiza a mesma linha (não duplica)
        $cbf->update(['appearances' => 6]);
        $this->assertSame(1, PlayerSeasonStat::where('provider', 'cbf')->where('player_id', $p->id)->where('season', '2026')->count());
    }

    public function test_reverting_a_link_batch_removes_the_mapping_too(): void
    {
        $p = Player::create(['name' => 'Reverte']);
        $s = $this->tmStat($p, '7777', ['club_source_name' => 'Longe FC']);
        $club = Club::create(['name' => 'Longe']);
        $r = DataCoverage::linkClub('transfermarkt', '7777', $club->id, $this->user('coordenador'));
        $this->assertSame($club->id, $s->fresh()->club_id);
        $this->artisan("scout:stats-clubs --revert={$r['batch']}")->expectsOutputToContain('Revertidas: 2')->assertSuccessful();
        $this->assertNull($s->fresh()->club_id);
        $this->assertFalse(ClubExternalId::where('provider', 'transfermarkt')->where('external_id', '7777')->exists());
        $this->artisan('scout:stats-clubs --apply')->assertSuccessful(); // nada religa sozinho
        $this->assertNull($s->fresh()->club_id);
    }

    public function test_coverage_page_lists_partial_and_outdated(): void
    {
        $p = Player::create(['name' => 'Parcial na lista']);
        PlayerSeasonStat::create(['player_id' => $p->id, 'provider' => 'cbf', 'competition_key' => 'k', 'competition_name' => 'X', 'season' => now()->format('Y'), 'appearances' => 3, 'fetched_at' => now()]);
        $this->actingAs($this->user('leitor'));
        $this->get(CoberturaDados::getUrl(['motivo' => 'parcial']))->assertOk()->assertSee('Parcial na lista');
    }

    public function test_admin_runs_fix_from_coverage_page_and_reverts_batch(): void
    {
        Queue::fake();
        $this->actingAs($this->user('admin', ['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        Livewire::test(CoberturaDados::class)->callAction('fix', [], ['correcao' => 'clubes'])->assertHasNoActionErrors();
        Queue::assertPushed(RunArtisanSync::class, fn ($j) => $j->command === 'scout:stats-clubs' && $j->params['--apply'] === true);

        $p = Player::create(['name' => 'Lote tela']);
        $s = $this->tmStat($p, '4040');
        $c = Club::create(['name' => 'Tela FC']);
        ClubExternalId::create(['provider' => 'transfermarkt', 'external_id' => '4040', 'club_id' => $c->id, 'method' => 'manual']);
        $this->artisan('scout:stats-clubs --apply')->assertSuccessful();
        $batch = DB::table('data_fix_changes')->value('batch');
        Livewire::test(CoberturaDados::class)->assertSee($batch)->call('revertBatch', $batch);
        $this->assertNull($s->fresh()->club_id);
    }

    public function test_coordinator_cannot_run_batch_fixes(): void
    {
        $this->actingAs($this->user('coordenador'));
        Livewire::test(CoberturaDados::class)->assertActionHidden('fix', ['correcao' => 'clubes'])->call('revertBatch', 'x')->assertForbidden();
    }
}
