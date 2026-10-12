<?php

namespace Tests\Feature;

use App\Filament\Pages\CoberturaDados;
use App\Models\Club;
use App\Models\DataSource;
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

    public function test_states_for_stats_and_photo(): void
    {
        $covered = Club::create(['name' => 'Coberto']);
        $other = Club::create(['name' => 'Sem partidas']);
        $y = now(config('scout.display_timezone'))->format('Y');
        Fixture::create(['provider' => 'api_football', 'external_id' => 'f1', 'season' => $y, 'home_club_id' => $covered->id, 'data_quality' => 'completo']);
        $stat = fn (Player $p, array $a) => PlayerSeasonStat::create($a + ['player_id' => $p->id, 'provider' => 'cbf', 'competition_key' => uniqid(), 'competition_name' => 'X', 'fetched_at' => now()]);

        $stat(Player::create(['name' => 'Disponível']), ['season' => $y, 'appearances' => 3, 'minutes' => 270]);
        $stat(Player::create(['name' => 'Temporada 25/26']), ['season' => substr((string) ($y - 1), 2).'/'.substr($y, 2), 'appearances' => 3, 'minutes' => 270]);
        $stat(Player::create(['name' => 'Parcial']), ['season' => $y, 'appearances' => 3, 'minutes' => null]);
        $stat(Player::create(['name' => 'Antigo']), ['season' => '2019', 'appearances' => 30, 'minutes' => 2500]);
        $stat(Player::create(['name' => 'Coleta velha']), ['season' => $y, 'appearances' => 3, 'minutes' => 270, 'fetched_at' => now()->subDays(45)]);
        $review = Player::create(['name' => 'Em revisão', 'current_club_id' => $covered->id]);
        IdentityCandidate::create(['player_id' => $review->id, 'provider' => 'api_football', 'external_id' => '9', 'kind' => 'mesclar', 'status' => 'pendente', 'score' => 90, 'label' => 'x']);
        Player::create(['name' => 'Sem clube']);
        Player::create(['name' => 'Clube sem partidas', 'current_club_id' => $other->id]);
        Player::create(['name' => 'Aguardando', 'current_club_id' => $covered->id]);
        $linked = Player::create(['name' => 'Vinculado sem jogo', 'current_club_id' => $covered->id, 'birth_date' => '2000-01-01']);
        PlayerExternalId::create(['player_id' => $linked->id, 'provider' => 'api_football', 'external_id' => '77', 'verified' => true, 'link_method' => 'nome_nascimento']);

        $r = DataCoverage::rows()->keyBy('name');
        $this->assertSame('disponivel', $r['Disponível']->stats_state);
        $this->assertSame('disponivel', $r['Temporada 25/26']->stats_state); // rótulo com 2 dígitos não some
        $this->assertSame('parcial', $r['Parcial']->stats_state);
        $this->assertSame('desatualizado', $r['Antigo']->stats_state);
        $this->assertSame('desatualizado', $r['Coleta velha']->stats_state);
        $this->assertSame('identidade_pendente', $r['Em revisão']->stats_state);
        $this->assertSame('nao_coletado', $r['Sem clube']->stats_state);
        $this->assertSame('fora_cobertura', $r['Clube sem partidas']->stats_state);
        $this->assertSame('aguardando_vinculo', $r['Aguardando']->stats_state);
        $this->assertSame('sem_participacao', $r['Vinculado sem jogo']->stats_state);
        $this->assertSame('sem_vinculo', $r['Sem clube']->photo_reason);

        DataSource::updateOrCreate(['key' => 'api_football'], ['name' => 'API-Football', 'kind' => 'api_comercial', 'enabled' => true])
            ->forceFill(['circuit_open_until' => now()->addHour()])->save();
        $this->assertSame('falha_bloqueio', DataCoverage::rows()->keyBy('name')['Aguardando']->stats_state); // fonte pausada ≠ não jogou

        $this->actingAs($this->user('leitor'));
        $this->get(CoberturaDados::getUrl(['motivo' => 'fora_cobertura']))->assertOk()->assertSee('Clube sem partidas')->assertSee('Denominador: 10 atletas');
    }
}
