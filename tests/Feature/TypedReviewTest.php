<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\EnrichmentReview;
use App\Models\Player;
use App\Services\PlayerEnrichment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** F16 — revisão com valor tipado; aceite só com aplicação confirmada; conflito explícito. */
class TypedReviewTest extends ScoutTestCase
{
    public function test_uuid_is_stored_raw_and_label_is_only_display(): void
    {
        [$a, $b] = [Club::create(['name' => 'Alfa']), Club::create(['name' => 'Beta'])];
        $p = Player::create(['name' => 'X', 'current_club_id' => $a->id]);
        PlayerEnrichment::propose($p, 'current_club_id', $b->id, 'cbf');
        $r = EnrichmentReview::firstOrFail();
        $this->assertSame($b->id, $r->proposed_raw);
        $this->assertSame('Beta (#'.$b->id.')', $r->proposed_value);
        $r->update(['proposed_value' => 'Rótulo adulterado']); // rótulo não controla o que é gravado
        $this->assertSame('aceito', PlayerEnrichment::accept($r, null)['status']);
        $this->assertSame($b->id, $p->fresh()->current_club_id);
    }

    public function test_changed_record_since_review_becomes_conflict_and_changes_nothing(): void
    {
        [$a, $b, $c] = [Club::create(['name' => 'Alfa']), Club::create(['name' => 'Beta']), Club::create(['name' => 'Gama'])];
        $p = Player::create(['name' => 'X', 'current_club_id' => $a->id]);
        PlayerEnrichment::propose($p, 'current_club_id', $b->id, 'transfermarkt');
        $p->update(['current_club_id' => $c->id]); // analista atualizou à mão depois
        $res = PlayerEnrichment::accept(EnrichmentReview::firstOrFail(), null);
        $this->assertSame('conflito', $res['status']);
        $this->assertSame($c->id, $p->fresh()->current_club_id);
        $this->assertStringContainsString('Gama', EnrichmentReview::first()->decision_note);
    }

    public function test_deleted_target_club_becomes_conflict(): void
    {
        [$a, $b] = [Club::create(['name' => 'Alfa']), Club::create(['name' => 'Beta'])];
        $p = Player::create(['name' => 'X', 'current_club_id' => $a->id]);
        PlayerEnrichment::propose($p, 'current_club_id', $b->id, 'cbf');
        $b->delete();
        $this->assertSame('conflito', PlayerEnrichment::accept(EnrichmentReview::firstOrFail(), null)['status']);
        $this->assertSame($a->id, $p->fresh()->current_club_id);
    }

    public function test_typed_date_and_decided_review_is_not_reapplied(): void
    {
        $p = Player::create(['name' => 'X', 'birth_date' => '2000-01-01']);
        PlayerEnrichment::propose($p, 'birth_date', '2000-01-02', 'transfermarkt');
        PlayerEnrichment::propose($p, 'birth_date', '2000-01-02', 'transfermarkt'); // não duplica revisão pendente
        $this->assertSame(1, EnrichmentReview::count());
        $r = EnrichmentReview::first();
        $this->assertSame('01/01/2000', $r->current_value);
        PlayerEnrichment::accept($r, null);
        $this->assertSame('2000-01-02', $p->fresh()->birth_date->toDateString());
        $this->assertSame('aceito', PlayerEnrichment::accept($r->fresh(), null)['status']);
    }

    public function test_legacy_label_review_is_converted_by_migration(): void
    {
        [$a, $b] = [Club::create(['name' => 'Alfa']), Club::create(['name' => 'Beta'])];
        $p = Player::create(['name' => 'X', 'current_club_id' => $a->id]);
        $m = require database_path('migrations/2026_10_14_000100_typed_enrichment_reviews.php');
        $m->down();
        DB::table('enrichment_reviews')->insert(['id' => (string) Str::uuid7(), 'player_id' => $p->id, 'provider' => 'transfermarkt',
            'field' => 'current_club_id', 'current_value' => 'Alfa', 'proposed_value' => 'Beta (#'.$b->id.')', 'status' => 'pendente', 'created_at' => now(), 'updated_at' => now()]);
        $m->up();
        $r = EnrichmentReview::firstOrFail();
        $this->assertSame($b->id, $r->proposed_raw);
        $this->assertSame($a->id, $r->current_raw);
        $this->assertSame('aceito', PlayerEnrichment::accept($r, null)['status']);
        $this->assertSame($b->id, $p->fresh()->current_club_id);
    }
}
