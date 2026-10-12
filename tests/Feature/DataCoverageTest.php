<?php

namespace Tests\Feature;

use App\Console\Commands\PlayerPhotos;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
        $this->assertSame(0, PhotoBackfill::run()['count']);
    }

    public function test_new_link_only_touches_its_own_player(): void
    {
        $this->athlete('11');
        $this->athlete('12');
        $other = Player::create(['name' => 'Outro']);
        PlayerExternalId::withoutEvents(fn () => PlayerExternalId::create(['player_id' => $other->id, 'provider' => 'api_football', 'external_id' => '12', 'verified' => true, 'link_method' => 'id']));
        $p = Player::create(['name' => 'Novo vínculo']);
        PlayerExternalId::create(['player_id' => $p->id, 'provider' => 'api_football', 'external_id' => '11', 'verified' => true, 'link_method' => 'id']);
        $this->assertNotNull($p->fresh()->photo_url);
        $this->assertNull($other->fresh()->photo_url); // o restante só por backfill com prévia
        $this->assertSame(0, DB::table('data_fix_changes')->count());
    }

    public function test_backfill_preview_apply_and_revert(): void
    {
        $this->athlete('5');
        $p = Player::create(['name' => 'Lote']);
        PlayerExternalId::withoutEvents(fn () => PlayerExternalId::create(['player_id' => $p->id, 'provider' => 'api_football', 'external_id' => '5', 'verified' => true, 'link_method' => 'id']));
        $this->artisan('scout:photos --backfill')->expectsOutputToContain('PRÉVIA: 1')->assertSuccessful();
        $this->assertNull($p->fresh()->photo_url);
        $this->artisan('scout:photos --backfill --apply')->expectsOutputToContain('APLICADO: 1')->assertSuccessful();
        $this->assertStringEndsWith('/5.png', $p->fresh()->photo_url);
        $batch = DB::table('data_fix_changes')->where('kind', 'photo')->value('batch');
        $this->artisan("scout:photos --revert={$batch}")->assertSuccessful();
        $this->assertNull($p->fresh()->photo_url);
    }

    public function test_probe_states_and_hidden_urls_fall_back_to_initials(): void
    {
        $img = imagecreatetruecolor(120, 120);
        for ($i = 0; $i < 3000; $i++) {
            imagesetpixel($img, random_int(0, 119), random_int(0, 119), random_int(0, 0xFFFFFF)); // ruído: PNG acima do limiar de "genérica"
        }
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        Http::fake([
            'media.api-sports.io/ok.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'media.api-sports.io/404.png' => Http::response('', 404),
            'media.api-sports.io/403.png' => Http::response('', 403),
            'media.api-sports.io/html.png' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
            'media.api-sports.io/redir.png' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
            'media.api-sports.io/redir-ok.png' => Http::response('', 302, ['Location' => '/ok.png']),
        ]);
        $probe = fn ($u) => PlayerPhotos::probe($u);
        $this->assertSame('ok', $probe('https://media.api-sports.io/ok.png'));
        $this->assertSame('quebrada', $probe('https://media.api-sports.io/404.png'));
        $this->assertSame('bloqueada', $probe('https://media.api-sports.io/403.png'));
        $this->assertSame('nao_imagem', $probe('https://media.api-sports.io/html.png'));
        $this->assertSame('host_nao_permitido', $probe('https://exemplo.com/a.png'));
        $this->assertSame('host_nao_permitido', $probe('https://media.api-sports.io/redir.png')); // redirecionamento para fora da lista
        $this->assertSame('ok', $probe('https://media.api-sports.io/redir-ok.png'));
        $this->assertSame('host_nao_permitido', $probe('http://media.api-sports.io/ok.png'));
        $this->assertSame('generica', $probe('https://img.a.transfermarkt.technology/portrait/header/default.jpg'));

        $p = Player::create(['name' => 'José da Silva', 'photo_url' => 'https://media.api-sports.io/404.png', 'photo_status' => 'quebrada']);
        $this->assertNull($p->photoUrl());
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $p->avatarUrl());
        $this->assertStringContainsString('JS', base64_decode(substr($p->avatarUrl(), 26)));
        $p->update(['photo_status' => 'bloqueada']); // servidor recusado ≠ navegador recusado: ainda tenta, com fallback
        $this->assertSame('https://media.api-sports.io/404.png', $p->fresh()->photoUrl());
    }

    public function test_manual_upload_records_origin_and_is_not_replaced_by_source_photo(): void
    {
        $u = $this->user('analista');
        $this->actingAs($u);
        $p = Player::create(['name' => 'Com upload']);
        $p->update(['photo_path' => 'players/abc.jpg']);
        $this->assertSame('upload', $p->fresh()->photo_source);
        $this->assertSame($u->id, $p->fresh()->photo_uploaded_by);
        $this->athlete('8');
        PlayerExternalId::create(['player_id' => $p->id, 'provider' => 'api_football', 'external_id' => '8', 'verified' => true, 'link_method' => 'id']);
        $this->assertNull($p->fresh()->photo_url); // fonte não substitui foto manual
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

    public function test_photo_check_batch_can_be_reverted_despite_timestamp_format(): void
    {
        Http::fake(['media.api-sports.io/*' => Http::response('', 404)]);
        $p = Player::create(['name' => 'Checada', 'photo_url' => 'https://media.api-sports.io/x.png']);
        $this->artisan('scout:photos --check --apply')->assertSuccessful();
        $this->assertSame('quebrada', $p->fresh()->photo_status);
        $batch = DB::table('data_fix_changes')->where('kind', 'photo_check')->value('batch');
        $this->artisan("scout:photos --revert={$batch}")->expectsOutputToContain('Revertidas: 1')->assertSuccessful();
        $this->assertNull($p->fresh()->photo_status);
    }
}
