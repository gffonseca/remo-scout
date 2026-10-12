<?php

namespace Tests\Feature;

use App\Filament\Pages\ForcaDosClubes;
use App\Models\Club;
use App\Models\OddsMatch;
use App\Sources\FootballData;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\Http;

/** football-data.co.uk: odds de fechamento → probabilidade sem margem → força dos clubes. Não cria clubes. */
class FootballDataTest extends ScoutTestCase
{
    private const CSV = "\xEF\xBB\xBFCountry,League,Season,Date,Time,Home,Away,HG,AG,Res,PSCH,PSCD,PSCA,MaxCH,MaxCD,MaxCA,AvgCH,AvgCD,AvgCA,BFECH,BFECD,BFECA,B365CH,B365CD,B365CA\n"
        ."Brazil,Serie A,2026,09/10/2026,01:30,Palmeiras,Bahia,1,0,H,,,,1.6,4.4,5.75,1.54,4.26,5.33,1.6,4.6,6,1.53,4.2,5.75\n"
        ."Brazil,Serie A,2026,10/10/2026,21:00,Flamengo RJ,Remo,2,2,D,1.30,5.50,11.0,,,,,,,,,,,,\n"
        ."Brazil,Serie A,2026,11/10/2026,16:00,Clube Inexistente,Bahia,,,,,,,,,,,,,,,,,,\n"
        ."Brazil,Serie A,2021,01/05/2021,16:00,Palmeiras,Bahia,3,0,H,,,,,,,1.5,4,6,,,,,,\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        foreach (['Palmeiras', 'Bahia', 'Flamengo', 'Remo'] as $n) {
            Club::create(['name' => $n]);
        }
    }

    public function test_import_resolves_clubs_removes_margin_and_never_creates_clubs(): void
    {
        $r = (new FootballData)->import(self::CSV, 2022);
        $this->assertSame(3, $r['gravadas']); // 2021 fora do recorte
        $this->assertSame(['Clube Inexistente'], $r['sem_clube']);
        $this->assertSame(4, Club::count());

        $m = OddsMatch::where('home_name', 'Palmeiras')->sole();
        $this->assertEqualsWithDelta(1.0, $m->implied['h'] + $m->implied['d'] + $m->implied['a'], 0.0002);
        $this->assertEqualsWithDelta(1 / 1.54 + 1 / 4.26 + 1 / 5.33 - 1, $m->implied['margem'], 0.0001);
        $this->assertEquals(['h' => 1.53, 'd' => 4.2, 'a' => 5.75], $m->odds['bet365']); // jsonb não preserva a ordem das chaves

        $fla = OddsMatch::where('home_name', 'Flamengo RJ')->sole(); // só Pinnacle: usa Pinnacle
        $this->assertSame(Club::where('name', 'Flamengo')->value('id'), $fla->home_club_id);
        $this->assertNotNull($fla->implied);

        $none = OddsMatch::where('home_name', 'Clube Inexistente')->sole();
        $this->assertNull($none->home_goals); // placar ausente ≠ 0
        $this->assertNull($none->implied);

        (new FootballData)->import(self::CSV, 2022); // reimportar não duplica
        $this->assertSame(3, OddsMatch::count());
    }

    public function test_club_strength_uses_only_played_matches_with_odds(): void
    {
        (new FootballData)->import(self::CSV, 2022);
        $s = FootballData::clubStrength('2026')->keyBy(fn ($r) => Club::find($r->club_id)->name);
        $this->assertSame(1, (int) $s['Bahia']->jogos); // jogo sem placar/odds não conta
        $this->assertEquals(3.00, (float) $s['Palmeiras']->pontos_por_jogo);
        $m = OddsMatch::where('home_name', 'Palmeiras')->sole();
        $this->assertEqualsWithDelta(3 * $m->implied['h'] + $m->implied['d'], (float) $s['Palmeiras']->pontos_esperados, 0.01);
        $this->assertEquals(1.00, (float) $s['Remo']->pontos_por_jogo);
    }

    public function test_changed_csv_format_is_refused(): void
    {
        $this->expectException(SourceException::class);
        (new FootballData)->import("Country,League,Data,Mandante\nBrazil,Serie A,01/01/2026,X\n");
    }

    public function test_fetch_and_page(): void
    {
        SourceClient::$sleeper = fn () => null;
        Http::fake(['football-data.co.uk/*' => Http::response(self::CSV)]);
        $this->artisan('scout:sync-football-data')->assertSuccessful();
        $this->assertSame(3, OddsMatch::count());
        $this->actingAs($this->user('analista'));
        $this->get(ForcaDosClubes::getUrl())->assertOk()->assertSee('Palmeiras')->assertSee('Pontos esperados');
    }
}
