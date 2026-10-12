<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\CollectorTask;
use App\Models\DataSource;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Services\Collector\SofascoreTasks;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Support\Facades\Http;

/** Sofascore sob demanda pelo coletor do Mac: servidor nunca acessa a rede; o Mac só busca o que foi pedido. */
class SofascoreTasksTest extends ScoutTestCase
{
    private const TOKEN = 'teste-token-coletor-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        config(['scout.collector_token' => self::TOKEN]);
        Http::fake(); // qualquer acesso direto do servidor seria um erro
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->withToken(self::TOKEN)->json($method, '/api/coletor/'.$path, $data);
    }

    /** O "Mac": responde conforme o endereço pedido. */
    private function sofascore(string $url, array $query): array
    {
        $year = (string) now()->year;
        $path = parse_url($url, PHP_URL_PATH);

        return match (true) {
            $path === '/api/v1/search/all' => [200, ['results' => [['type' => 'player', 'entity' => ['id' => 9, 'name' => 'João Silva', 'team' => ['name' => 'Remo'],
                'dateOfBirthTimestamp' => strtotime('2000-01-01 UTC')]]]]],
            $path === '/api/v1/player/9/statistics/seasons' => [200, ['uniqueTournamentSeasons' => [['uniqueTournament' => ['id' => 325, 'name' => 'Brasileirão Série A'],
                'seasons' => [['id' => 77, 'year' => $year], ['id' => 50, 'year' => '2019']]]]]],
            str_contains($path, '/unique-tournament/325/season/77/statistics/overall') => [200, ['statistics' => ['appearances' => 10, 'minutesPlayed' => 800, 'goals' => 2, 'rating' => 7.12]]],
            default => [404, null],
        };
    }

    private function runMac(int $maxRounds = 10): int
    {
        $calls = 0;
        foreach ($this->api('GET', 'tarefas')->assertOk()->json('tarefas') as $t) {
            $reqs = $t['requests'];
            for ($i = 0; $i < $maxRounds && $reqs; $i++) {
                $answers = [];
                foreach ($reqs as $r) {
                    [$status, $body] = $this->sofascore($r['url'], (array) $r['query']);
                    $calls++;
                    $answers[] = ['url' => $r['url'], 'query' => (array) $r['query'], 'status' => $status, 'body' => $body ? json_encode($body) : null];
                }
                $out = $this->api('POST', 'tarefas/'.$t['id'], ['responses' => $answers])->assertOk();
                $reqs = $out->json('status') === 'aguardando_coletor' ? $out->json('requests') : [];
            }
        }

        return $calls;
    }

    public function test_request_round_trips_through_mac_and_links_by_birth_then_enriches(): void
    {
        $p = Player::create(['name' => 'João Silva', 'birth_date' => '2000-01-01', 'current_club_id' => Club::create(['name' => 'Remo'])->id]);
        $task = SofascoreTasks::request($p, $this->user('analista')->id);
        $this->assertSame('aguardando_coletor', $task->status);
        $this->assertSame('https://api.sofascore.com/api/v1/search/all', $task->pending_paths[0]['url']);
        Http::assertNothingSent();

        $calls = $this->runMac();
        $task->refresh();
        $this->assertSame('concluida', $task->status, (string) $task->result);
        $this->assertSame('9', $p->fresh()->externalId('sofascore'));
        $s = PlayerSeasonStat::where('player_id', $p->id)->where('provider', 'sofascore')->sole(); // só a temporada recente
        $this->assertSame(800, $s->minutes);
        $this->assertSame(3, $calls); // busca, temporadas, estatística — nada além do pedido
        Http::assertNothingSent();
    }

    public function test_mac_cannot_inject_unrequested_or_foreign_urls(): void
    {
        $p = Player::create(['name' => 'João Silva']);
        $task = SofascoreTasks::request($p);
        $this->api('POST', 'tarefas/'.$task->id, ['responses' => [
            ['url' => 'https://evil.test/api/v1/search/all', 'query' => ['q' => 'João Silva'], 'status' => 200, 'body' => '{}'],
            ['url' => 'https://api.sofascore.com/api/v1/player/1', 'query' => [], 'status' => 200, 'body' => '{"player":{}}'],
        ]])->assertOk()->assertJsonPath('status', 'aguardando_coletor');
        $this->assertSame(1, $task->fresh()->rounds); // nada aceito: continua esperando a mesma busca
    }

    public function test_block_on_mac_closes_task_and_marks_source(): void
    {
        $task = SofascoreTasks::request(Player::create(['name' => 'X']));
        $r = $task->pending_paths[0];
        $this->api('POST', 'tarefas/'.$task->id, ['responses' => [['url' => $r['url'], 'query' => (array) $r['query'], 'status' => 403, 'body' => null]]])
            ->assertJsonPath('status', 'falhou');
        $this->assertSame('bloqueado', DataSource::byKey('sofascore')->health);
    }

    public function test_kill_switch_hides_tasks_and_refuses_results(): void
    {
        $task = SofascoreTasks::request(Player::create(['name' => 'X']));
        DataSource::byKey('sofascore')->update(['enabled' => false]);
        $this->api('GET', 'tarefas')->assertOk()->assertJsonPath('tarefas', []);
        $this->api('POST', 'tarefas/'.$task->id, ['responses' => [['url' => 'https://api.sofascore.com/api/v1/search/all', 'status' => 200, 'body' => '{}']]])->assertStatus(423);
    }

    public function test_repeated_request_reuses_open_task_and_enrich_command_queues(): void
    {
        $p = Player::create(['name' => 'João Silva', 'is_priority' => true]);
        $a = SofascoreTasks::request($p);
        $this->assertSame($a->id, SofascoreTasks::request($p)->id);
        $this->artisan('scout:enrich', ['provider' => 'sofascore', '--scope' => 'priority'])->assertSuccessful();
        $this->assertSame(1, CollectorTask::count());
    }
}
