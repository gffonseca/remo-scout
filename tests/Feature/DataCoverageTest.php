<?php

namespace Tests\Feature;

use App\Filament\Pages\CoberturaDados;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerSeasonStat;
use App\Models\ProviderAthlete;
use App\Services\DataCoverage;
use App\Services\PhotoBackfill;

/** Fotos da API-Football chegam ao cadastro com vínculo confiável; cobertura explica a falta de estatística e foto. */
class DataCoverageTest extends ScoutTestCase
{
    private function athlete(string $id, ?string $photo = 'https://media.api-sports.io/football/players/'): ProviderAthlete
    {
        return ProviderAthlete::create(['provider' => 'api_football', 'external_id' => $id, 'name' => 'A'.$id, 'photo_url' => $photo ? $photo.$id.'.png' : null, 'fetched_at' => now()]);
    }

    public function test_photo_comes_from_trusted_link_only_and_never_overwrites(): void
    {
        $this->athlete('1');
        $this->athlete('2');
        $this->athlete('3');
        $ok = Player::create(['name' => 'Vinculado']);
        $doubt = Player::create(['name' => 'Duvidoso']);
        $own = Player::create(['name' => 'Com foto', 'photo_url' => 'https://exemplo/foto.png', 'photo_source' => 'transfermarkt']);
        PlayerExternalId::create(['player_id' => $ok->id, 'provider' => 'api_football', 'external_id' => '1', 'verified' => true, 'link_method' => 'nome_nascimento']);
        PlayerExternalId::create(['player_id' => $doubt->id, 'provider' => 'api_football', 'external_id' => '2', 'verified' => false, 'link_method' => 'nome']);
        PlayerExternalId::create(['player_id' => $own->id, 'provider' => 'api_football', 'external_id' => '3', 'verified' => true, 'link_method' => 'id']);
        $this->assertSame('https://media.api-sports.io/football/players/1.png', $ok->fresh()->photo_url); // no ato do vínculo
        $this->assertSame('api_football', $ok->fresh()->photo_source);
        $this->assertNull($doubt->fresh()->photo_url);
        $this->assertSame('https://exemplo/foto.png', $own->fresh()->photo_url);
        $this->assertSame(0, PhotoBackfill::run());
    }

    public function test_reasons_for_missing_stats_and_photo(): void
    {
        $covered = Club::create(['name' => 'Coberto']);
        $other = Club::create(['name' => 'Sem partidas']);
        Fixture::create(['provider' => 'api_football', 'external_id' => 'f1', 'season' => now()->format('Y'), 'home_club_id' => $covered->id, 'away_club_id' => null]);
        $withStats = Player::create(['name' => 'Com estatística', 'current_club_id' => $covered->id]);
        PlayerSeasonStat::create(['player_id' => $withStats->id, 'provider' => 'cbf', 'competition_key' => 'x', 'competition_name' => 'X', 'season' => '2026', 'appearances' => 3]);
        $review = Player::create(['name' => 'Em revisão', 'current_club_id' => $covered->id]);
        IdentityCandidate::create(['player_id' => $review->id, 'provider' => 'api_football', 'external_id' => '9', 'kind' => 'mesclar', 'status' => 'pendente', 'score' => 90, 'label' => 'x']);
        Player::create(['name' => 'Sem clube']);
        Player::create(['name' => 'Clube sem partidas', 'current_club_id' => $other->id]);
        Player::create(['name' => 'Sem nascimento', 'current_club_id' => $covered->id]);
        Player::create(['name' => 'Não encontrado', 'current_club_id' => $covered->id, 'birth_date' => '2000-01-01']);

        $r = DataCoverage::rows()->keyBy('name');
        $this->assertNull($r['Com estatística']->stats_reason);
        $this->assertSame('revisao', $r['Em revisão']->stats_reason);
        $this->assertSame('sem_clube', $r['Sem clube']->stats_reason);
        $this->assertSame('clube_sem_partidas', $r['Clube sem partidas']->stats_reason);
        $this->assertSame('sem_nascimento', $r['Sem nascimento']->stats_reason);
        $this->assertSame('nao_encontrado', $r['Não encontrado']->stats_reason);
        $this->assertSame('sem_vinculo', $r['Sem clube']->photo_reason);

        $this->actingAs($this->user('leitor'));
        $this->get(CoberturaDados::getUrl(['motivo' => 'sem_nascimento']))->assertOk()->assertSee('Sem nascimento')->assertDontSee('Não encontrado</a>', false);
    }
}
