<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CbfAthlete;
use App\Models\CbfIntermediary;
use App\Models\Club;
use App\Models\DataSource;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CollectorTest extends ScoutTestCase
{
    private const TOKEN = 'teste-token-coletor-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        config(['scout.collector_token' => self::TOKEN]);
        Storage::fake('media');
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->withToken(self::TOKEN)->json($method, '/api/coletor/'.$path, $data);
    }

    public function test_rejects_missing_or_wrong_token(): void
    {
        $this->getJson('/api/coletor/plano')->assertStatus(401);
        $this->withToken('errado')->getJson('/api/coletor/plano')->assertStatus(401);
        config(['scout.collector_token' => 'curto']);
        $this->withToken('curto')->getJson('/api/coletor/plano')->assertStatus(401); // token fraco nunca vale
    }

    public function test_plan_respects_kill_switch(): void
    {
        $this->api('GET', 'plano')->assertOk()->assertJsonPath('cbf_atletas.competitions.3.label', 'Série D');
        DataSource::byKey('cbf_atletas')->update(['enabled' => false]);
        $this->api('GET', 'plano')->assertOk()->assertJsonPath('cbf_atletas', null);
        $this->api('POST', 'cbf_atletas/inicio')->assertStatus(423);
    }

    public function test_athlete_list_drops_cpf_links_and_details_fill_profile(): void
    {
        $club = Club::create(['name' => 'Centro Sportivo Alagoano', 'country' => 'Brasil', 'state' => 'AL', 'aliases' => ['CSA']]);
        $p = Player::create(['name' => 'Abraão', 'current_club_id' => $club->id, 'birth_date' => '2004-07-30']);
        $sem = Player::create(['name' => 'Gabriel', 'current_club_id' => $club->id]); // sem nascimento no cadastro
        $run = $this->api('POST', 'cbf_atletas/inicio', ['host' => 'mini'])->assertOk()->json('run_id');

        $this->api('POST', 'cbf_atletas/lote', ['run_id' => $run, 'kind' => 'lista', 'competition' => ['slug' => 'campeonato-brasileiro/serie-d', 'label' => 'Série D', 'season' => '2026'],
            'items' => [
                ['atleta_id' => '828757', 'atleta_nome' => 'Abraao Marques de Lucena', 'atleta_apelido' => 'ABRAAO', 'atleta_cpf' => '12345678909',
                    'clube_id' => '20045', 'clube_nome_popular' => 'Csa', 'clube_nome_completo' => 'Centro Sportivo Alagoano', 'clube_uf' => 'AL',
                    'clube_escudo' => 'https://conteudo.cbf.com.br/clubes/20045/escudo.jpg'],
                ['atleta_id' => '', 'atleta_nome' => 'sem id'],
            ]])->assertOk()->assertJsonPath('vinculados', 1)->assertJsonPath('descartados', 1);

        $a = CbfAthlete::where('cbf_id', '828757')->firstOrFail();
        $this->assertSame($p->id, $a->player_id);
        $this->assertSame('provavel', $a->link_status); // F14: nome + clube = provável
        $this->assertNull($p->fresh()->cbf_id);        // nada copiado antes de confirmar
        $this->assertTrue(IdentityCandidate::where('player_id', $p->id)->where('kind', 'confirmar')->exists());
        $this->assertSame($club->id, $a->club_id);
        $this->assertSame('https://conteudo.cbf.com.br/clubes/20045/escudo.jpg', $club->fresh()->crest_url);
        $this->assertStringNotContainsString('12345678909', json_encode(DB::table('cbf_athletes')->get()));

        $this->api('GET', 'cbf_atletas/pendentes')->assertOk()->assertJsonPath('ids', ['828757']);

        $img = imagecreatetruecolor(120, 160);
        imagefilledrectangle($img, 0, 0, 119, 159, imagecolorallocate($img, 30, 60, 120));
        for ($i = 0; $i < 400; $i++) {
            imagesetpixel($img, random_int(0, 119), random_int(0, 159), imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = base64_encode(ob_get_clean());

        $this->api('POST', 'cbf_atletas/lote', ['run_id' => $run, 'kind' => 'detalhe', 'items' => [[
            'atleta_id' => '828757', 'atleta_nome' => 'Abraao Marques de Lucena', 'atleta_data_nascimento' => '30/07/2004', 'atleta_cpf' => '12345678909',
            'atleta_foto' => 'data:image/jpeg;base64,'.$jpeg, 'atletas_anos' => ['2026'],
            'atleta_time_atual' => ['time_id' => '20045', 'time_nome' => 'Centro Sportivo Alagoano', 'time_nome_popular' => 'Csa', 'time_uf' => 'AL'],
            'atleta_estatisticas' => ['qtd_partidas' => '11', 'qtd_gols' => '2', 'qtd_cartoes_amarelos' => '3', 'qtd_cartoes_vermelhos' => '0'],
        ]]])->assertOk()->assertJsonPath('ok', 1);

        $p->refresh();
        $this->assertSame('828757', $p->cbf_id); // nascimento CBF = cadastro → confirmado e aplicado
        $this->assertSame('confirmado', $a->fresh()->link_status);
        $this->assertSame('2004-07-30', $p->birth_date->toDateString());
        $this->assertSame('Abraao Marques De Lucena', $p->full_name);
        $this->assertStringStartsWith('players/cbf/828757-', $p->photo_path);
        Storage::disk('media')->assertExists($p->photo_path);
        $stat = PlayerSeasonStat::where('player_id', $p->id)->where('provider', 'cbf')->firstOrFail();
        $this->assertSame([11, 2, 3, 0], [$stat->appearances, $stat->goals, $stat->yellow_cards, $stat->red_cards]);
        $this->assertNull($stat->minutes); // CBF não publica minutos: ausente, não zero

        $this->api('POST', 'cbf_atletas/fim', ['run_id' => $run, 'requests_made' => 30, 'errors' => 0])->assertOk()->assertJsonPath('status', 'concluido');
        $this->assertSame('ok', DataSource::byKey('cbf_atletas')->health);
        $this->api('POST', 'cbf_atletas/lote', ['run_id' => $run, 'kind' => 'lista', 'items' => [['atleta_id' => '1']]])->assertStatus(409); // execução encerrada não aceita lote
    }

    public function test_official_photo_never_overwrites_department_upload(): void
    {
        $p = Player::create(['name' => 'Atleta Upload', 'cbf_id' => '111', 'photo_path' => 'players/meu-upload.jpg']);
        CbfAthlete::create(['cbf_id' => '111', 'name' => 'Atleta Upload', 'player_id' => $p->id]);
        $run = $this->api('POST', 'cbf_atletas/inicio')->json('run_id');
        $this->api('POST', 'cbf_atletas/lote', ['run_id' => $run, 'kind' => 'detalhe', 'items' => [['atleta_id' => '111', 'atleta_foto' => 'data:image/jpeg;base64,'.base64_encode(str_repeat('x', 3000))]]])->assertOk();
        $this->assertSame('players/meu-upload.jpg', $p->fresh()->photo_path);
    }

    public function test_intermediaries_registry_and_agent_link(): void
    {
        $ag = Agent::create(['name' => 'Fulano', 'agency' => '360 Football Business Ltda']);
        $run = $this->api('POST', 'cbf_intermediarios/inicio')->json('run_id');
        $this->api('POST', 'cbf_intermediarios/lote', ['run_id' => $run, 'kind' => 'intermediarios', 'items' => [
            ['nome' => '360 Football Business Ltda', 'tipo' => 'Jurídica', 'publicacao' => '03/02/2025'],
            ['nome' => '123.456.789-09', 'tipo' => 'Física', 'publicacao' => '01/01/2020'],
        ]])->assertOk()->assertJsonPath('novos', 1)->assertJsonPath('descartados', 1);
        $this->api('POST', 'cbf_intermediarios/fim', ['run_id' => $run])->assertOk();

        $i = CbfIntermediary::firstOrFail();
        $this->assertSame('2025-02-03', $i->published_on->toDateString());
        $this->assertSame($i->id, $ag->fresh()->cbf_intermediary_id);
    }
}
