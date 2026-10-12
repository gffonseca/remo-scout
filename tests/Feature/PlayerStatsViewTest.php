<?php

namespace Tests\Feature;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Support\PlayerStatsView as SV;

/** F12 — estatística no perfil com temporada dinâmica, fonte/data, minutos desconhecidos ≠ 0 e por 90 com denominador válido. */
class PlayerStatsViewTest extends ScoutTestCase
{
    private function stat(Player $p, array $a): PlayerSeasonStat
    {
        return PlayerSeasonStat::create($a + ['player_id' => $p->id, 'provider' => 'api_futebol', 'competition_key' => uniqid(), 'competition_name' => 'Série C', 'season' => '2026', 'fetched_at' => now()]);
    }

    public function test_recent_seasons_follow_the_calendar_not_a_fixed_list(): void
    {
        $this->assertSame([2025, 2026], SV::years('25/26'));
        $this->assertSame([2026, 2027], SV::years('2026/2027'));
        $this->assertTrue(SV::isRecent('2026', 2026));
        $this->assertTrue(SV::isRecent('2025', 2026));
        $this->assertFalse(SV::isRecent('2024', 2026));
        $this->assertTrue(SV::isRecent('2027', 2027));
        $this->assertFalse(SV::isRecent('2025', 2027)); // na virada do ano, 2025 sai das recentes sem mudar código
        $this->assertTrue(SV::isRecent('26/27', 2027));
    }

    public function test_unknown_minutes_are_never_zero_and_per90_needs_valid_denominator(): void
    {
        $p = Player::create(['name' => 'X']);
        $unknown = $this->stat($p, ['appearances' => 3, 'goals' => 2, 'minutes' => null, 'extra' => ['minutos_conhecidos' => 150, 'jogos_minutos_indeterminados' => 1]]);
        $this->assertSame('≥150', SV::minutes($unknown));
        $this->assertNull(SV::goalsPer90($unknown));
        $this->assertStringContainsString('Não são zero', SV::minutesTitle($unknown));

        $cbf = $this->stat($p, ['provider' => 'cbf', 'appearances' => 9, 'goals' => 1, 'minutes' => null]);
        $this->assertSame('n/p', SV::minutes($cbf));
        $this->assertSame('A CBF não publica minutos.', SV::minutesTitle($cbf));

        $zero = $this->stat($p, ['appearances' => 1, 'goals' => 0, 'minutes' => 0]);
        $this->assertNull(SV::goalsPer90($zero));

        $small = $this->stat($p, ['appearances' => 4, 'goals' => 2, 'minutes' => 300]);
        $this->assertSame(0.6, SV::goalsPer90($small));
        $this->assertTrue(SV::smallSample($small));

        $unverified = $this->stat($p, ['appearances' => 20, 'goals' => 5, 'minutes' => 1800, 'extra' => ['jogos_gols_nao_conferidos' => 2]]);
        $this->assertSame('≥5', SV::goals($unverified));
        $this->assertNull(SV::goalsPer90($unverified));
    }

    public function test_profile_shows_all_rows_with_source_date_and_older_seasons_collapsed(): void
    {
        $p = Player::create(['name' => 'Atleta Muitas Linhas']);
        foreach (range(1, 8) as $i) {
            $this->stat($p, ['competition_name' => "Competição {$i}", 'appearances' => $i, 'minutes' => $i * 90]);
        }
        $this->stat($p, ['provider' => 'cbf', 'competition_name' => 'Competições CBF (total publicado)', 'appearances' => 9, 'minutes' => null]);
        $this->stat($p, ['competition_name' => 'Série Antiga', 'season' => '2019', 'appearances' => 30, 'minutes' => 2500]);
        $this->stat($p, ['competition_name' => 'Só inscrito', 'appearances' => null, 'minutes' => null, 'goals' => null]);

        $this->actingAs($this->user('analista'));
        $html = $this->get(PlayerResource::getUrl('view', ['record' => $p]))->assertOk()->getContent();
        foreach (range(1, 8) as $i) {
            $this->assertStringContainsString("Competição {$i}", $html); // sem truncar em 6
        }
        $this->assertStringContainsString('API Futebol', $html);
        $this->assertStringContainsString('coletado em '.now(config('scout.display_timezone'))->format('d/m/Y'), $html);
        $this->assertStringContainsString('a CBF não publica minutos', $html);
        $this->assertStringContainsString('Temporadas anteriores (1 linhas)', $html);
        $this->assertStringContainsString('Série Antiga', $html);
        $this->assertStringContainsString('Só inscrito', $html); // prompt mestre 3.6: linha não some por campo ausente (aparece com "—")
    }
}
