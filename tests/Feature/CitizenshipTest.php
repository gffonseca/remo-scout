<?php

namespace Tests\Feature;

use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** F15 — cidadania declarada ≠ passaporte comunitário conferido. */
class CitizenshipTest extends ScoutTestCase
{
    public function test_passport_yes_requires_document_and_records_who_and_when(): void
    {
        $p = Player::create(['name' => 'X']);
        try {
            $p->update(['eu_passport' => true]);
            $this->fail('passaporte sem documento');
        } catch (ValidationException) {
        }
        $this->assertNull($p->fresh()->eu_passport);

        $u = $this->user('coordenador');
        $this->actingAs($u);
        $p->update(['eu_passport' => true, 'eu_passport_evidence' => 'Passaporte italiano conferido em 10/10/2026']);
        $p->refresh();
        $this->assertTrue($p->eu_passport);
        $this->assertSame($u->id, $p->eu_passport_confirmed_by);
        $this->assertNotNull($p->eu_passport_confirmed_at);

        $p->update(['eu_passport' => null]); // volta a desconhecido e limpa a confirmação
        $this->assertNull($p->fresh()->eu_passport_confirmed_by);
    }

    public function test_citizenship_from_sources_does_not_duplicate_country(): void
    {
        $p = Player::create(['name' => 'X']);
        $this->assertTrue($p->addCitizenship('Itália', 'transfermarkt'));
        $this->assertFalse($p->addCitizenship('itália', 'cbf'));
        $this->assertFalse($p->addCitizenship(null, 'cbf'));
        $p->save();
        $this->assertCount(1, $p->fresh()->citizenships);
        $this->assertNull($p->fresh()->eu_passport);
    }

    public function test_migration_moves_unproven_passports_to_unknown_and_keeps_citizenship(): void
    {
        $m = require database_path('migrations/2026_10_14_000200_citizenship_and_passport.php');
        $m->down();
        $id = (string) Str::uuid7();
        DB::table('players')->insert(['id' => $id, 'name' => 'Legado', 'nationality' => 'Brasil', 'second_nationality' => 'Itália', 'eu_passport' => true,
            'field_sources' => json_encode(['second_nationality' => ['source' => 'transfermarkt', 'at' => '2026-10-11']]), 'created_at' => now(), 'updated_at' => now()]);
        $m->up();
        $p = Player::find($id);
        $this->assertNull($p->eu_passport);
        $this->assertStringContainsString('confirmar com documento', $p->notes);
        $this->assertEquals([['country' => 'Brasil', 'source' => 'cadastro', 'at' => null], ['country' => 'Itália', 'source' => 'transfermarkt', 'at' => '2026-10-11']], $p->citizenships);
    }

    public function test_changing_passport_evidence_records_new_confirmer(): void
    {
        $a = $this->user('analista');
        $b = $this->user('coordenador');
        $this->actingAs($a);
        $p = Player::create(['name' => 'X', 'eu_passport' => true, 'eu_passport_evidence' => 'Passaporte PT conferido']);
        $this->assertSame($a->id, $p->eu_passport_confirmed_by);
        $this->actingAs($b);
        $this->travel(1)->day();
        $p->update(['eu_passport_evidence' => 'Passaporte IT conferido (renovado)']);
        $this->assertSame($b->id, $p->fresh()->eu_passport_confirmed_by);
        $this->assertTrue($p->fresh()->eu_passport_confirmed_at->isToday());
    }
}
