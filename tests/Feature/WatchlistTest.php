<?php

namespace Tests\Feature;

use App\Filament\Resources\Watchlist\WatchlistResource;
use App\Models\Club;
use App\Models\ClubSquadEntry;
use App\Models\ContractObservation;
use App\Models\Player;
use App\Models\ScoutingReport;
use App\Models\WatchlistAlert;
use App\Models\WatchlistItem;
use App\Services\WatchlistAlerts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Watchlist: responsável, motivo, próxima revisão; alertas só no sistema e deduplicados. */
class WatchlistTest extends ScoutTestCase
{
    private function item(array $over = []): WatchlistItem
    {
        $owner = $over['owner'] ?? $this->user('analista');
        unset($over['owner']);
        $p = $over['player'] ?? Player::create(['name' => 'Monitorado', 'current_club_id' => Club::create(['name' => 'Clube A'])->id]);
        unset($over['player']);

        return WatchlistItem::create($over + ['player_id' => $p->id, 'owner_id' => $owner->id, 'reason' => 'Lateral que pode subir de nível', 'next_review_on' => now()->addDays(10)]);
    }

    private function notifications(int $userId): int
    {
        return DB::table('notifications')->where('notifiable_id', $userId)->count();
    }

    public function test_overdue_review_alerts_once_per_date(): void
    {
        $i = $this->item(['next_review_on' => now()->subDays(2)]);
        $this->assertSame(1, WatchlistAlerts::run());
        $this->assertSame(0, WatchlistAlerts::run()); // dedupe
        $this->assertSame(1, $this->notifications($i->owner_id));
        $i->update(['next_review_on' => now()->subDay()]); // remarcada e vencida de novo = novo fato
        $this->assertSame(1, WatchlistAlerts::run());
        $this->assertTrue($i->fresh()->isOverdue());
    }

    public function test_club_change_and_squad_conflict_alert_once(): void
    {
        $i = $this->item();
        WatchlistAlerts::run(); // registra o clube atual sem alertar
        $this->assertSame(0, WatchlistAlert::count());
        $b = Club::create(['name' => 'Clube B']);
        $i->player->update(['current_club_id' => $b->id]);
        $this->assertSame(1, WatchlistAlerts::run());
        $this->assertSame(0, WatchlistAlerts::run());
        $c = Club::create(['name' => 'Clube C']);
        ClubSquadEntry::create(['club_id' => $c->id, 'provider' => 'dados_futebol', 'external_player_id' => '9', 'name' => 'X', 'player_id' => $i->player_id,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->assertSame(1, WatchlistAlerts::run());
        $this->assertStringContainsString('Clube C', WatchlistAlert::latest('id')->first()->message);
    }

    public function test_new_report_by_someone_else_and_contract_end_alert(): void
    {
        $i = $this->item();
        $other = $this->user('analista');
        ScoutingReport::create(['player_id' => $i->player_id, 'author_id' => $other->id, 'status' => 'submetido', 'submitted_at' => now()]);
        ScoutingReport::create(['player_id' => $i->player_id, 'author_id' => $i->owner_id, 'status' => 'submetido', 'submitted_at' => now()]); // do próprio responsável: não alerta
        ContractObservation::create(['player_id' => $i->player_id, 'status' => 'vigente', 'contract_end' => now()->addDays(40), 'observed_on' => now()]);
        $this->assertSame(2, WatchlistAlerts::run());
        $this->assertEqualsCanonicalizing(['relatorio', 'contrato'], WatchlistAlert::pluck('kind')->all());
    }

    public function test_one_active_item_per_player_and_closed_items_do_not_alert(): void
    {
        $i = $this->item(['next_review_on' => now()->subDay()]);
        try {
            DB::transaction(fn () => WatchlistItem::create(['player_id' => $i->player_id, 'owner_id' => $i->owner_id, 'reason' => 'duplicado de propósito']));
            $this->fail('deveria impedir dois itens ativos');
        } catch (QueryException) {
        }
        $i->update(['status' => 'encerrado', 'closed_reason' => 'contratado por outro clube']);
        $this->assertSame(0, WatchlistAlerts::run());
        $this->assertTrue(WatchlistItem::create(['player_id' => $i->player_id, 'owner_id' => $i->owner_id, 'reason' => 'voltou ao radar depois'])->exists);
    }

    public function test_watchlist_page_and_reader_permissions(): void
    {
        $i = $this->item(['next_review_on' => now()->subDay()]);
        $this->actingAs($this->user('leitor'));
        $this->get(WatchlistResource::getUrl())->assertOk()->assertSee('Monitorado')->assertSee('vencida');
        $this->assertFalse(auth()->user()->can('create', WatchlistItem::class));
    }
}
