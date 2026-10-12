<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\SyncRun;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** F09 — cota atômica, Retry-After, circuito com retomada, timeout, cache e diagnóstico de atualização. */
class SourceClientTest extends ScoutTestCase
{
    /** @var list<float> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
        DataSource::query()->update(['min_interval_ms' => 0, 'daily_quota' => 100, 'used_today' => 0, 'monthly_quota' => null]);
        SourceClient::$sleeper = function (float $s) {
            $this->slept[] = $s;
        };
    }

    protected function tearDown(): void
    {
        SourceClient::$sleeper = null;
        parent::tearDown();
    }

    private function tm(): SourceClient
    {
        return SourceClient::for('transfermarkt');
    }

    private function kind(callable $fn): ?string
    {
        try {
            $fn();
        } catch (SourceException $e) {
            return $e->kind;
        }

        return null;
    }

    public function test_quota_is_reserved_atomically_and_never_exceeded(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        DataSource::byKey('transfermarkt')->update(['daily_quota' => 2]);
        $a = $this->tm();
        $b = $this->tm(); // "segundo worker" com instância própria e modelo carregado antes das chamadas
        $a->getJson('/x');
        $b->getJson('/y');
        $this->assertSame('cota', $this->kind(fn () => $a->getJson('/z')));
        $this->assertSame('cota', $this->kind(fn () => $b->getJson('/z')));
        Http::assertSentCount(2);
        $this->assertSame(2, DataSource::byKey('transfermarkt')->used_today);
    }

    public function test_monthly_quota_also_limits(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        DataSource::byKey('transfermarkt')->update(['monthly_quota' => 5, 'used_month' => 5, 'quota_month' => DataSource::quotaMonth()]);
        $this->assertSame('cota', $this->kind(fn () => $this->tm()->getJson('/x')));
        Http::assertNothingSent();
        $this->assertSame(0, DataSource::byKey('transfermarkt')->remainingQuota());
    }

    public function test_quota_day_follows_brasilia_midnight_not_utc(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-13 01:00:00', 'UTC')); // 22:00 de 12/10 em Belém
        DataSource::byKey('transfermarkt')->update(['daily_quota' => 3, 'used_today' => 3, 'quota_date' => '2026-10-12']);
        $this->assertSame('cota', $this->kind(fn () => $this->tm()->getJson('/x')));
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-13 03:30:00', 'UTC')); // 00:30 de 13/10 em Belém
        $this->tm()->getJson('/x');
        $this->assertSame(1, DataSource::byKey('transfermarkt')->used_today);
    }

    public function test_429_short_retry_after_is_respected_then_succeeds(): void
    {
        Http::fake(['*' => Http::sequence()->push('slow down', 429, ['Retry-After' => '7'])->push(['ok' => true])]);
        $this->assertSame(['ok' => true], $this->tm()->getJson('/x'));
        $this->assertGreaterThanOrEqual(7, $this->slept[0]);
        $this->assertLessThanOrEqual(8, $this->slept[0]); // jitter até 1 s
        $this->assertSame(2, DataSource::byKey('transfermarkt')->used_today); // cada tentativa consome cota
    }

    public function test_429_long_retry_after_pauses_source_instead_of_holding_worker(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '900'])]);
        $this->assertSame('limite', $this->kind(fn () => $this->tm()->getJson('/x')));
        $this->assertSame([], $this->slept);
        $s = DataSource::byKey('transfermarkt');
        $this->assertTrue($s->circuitOpen());
        $this->assertEqualsWithDelta(now()->addSeconds(900)->timestamp, $s->circuit_open_until->timestamp, 2);
        $this->assertSame('circuito', $this->kind(fn () => $this->tm()->getJson('/x')));
        Http::assertSentCount(1); // pausada: nem chega a chamar
    }

    public function test_403_opens_circuit_after_repeated_failures_and_resumes_on_success(): void
    {
        Http::fake(['*' => Http::sequence()->push('Forbidden', 403)->push('Forbidden', 403)->push('Forbidden', 403)->push(['ok' => 1])]);
        foreach ([1, 2] as $i) {
            $this->assertSame('bloqueado', $this->kind(fn () => $this->tm()->getJson('/x')));
            $this->assertFalse(DataSource::byKey('transfermarkt')->circuitOpen(), "falha {$i} ainda não abre");
        }
        $this->assertSame('bloqueado', $this->kind(fn () => $this->tm()->getJson('/x')));
        $s = DataSource::byKey('transfermarkt');
        $this->assertTrue($s->circuitOpen());
        $this->assertSame('bloqueado', $s->health);
        $this->assertSame('circuito', $this->kind(fn () => $this->tm()->getJson('/x')));

        $this->travel(6)->minutes(); // retomada: uma tentativa passa
        $this->assertSame(['ok' => 1], $this->tm()->getJson('/x'));
        $s->refresh();
        $this->assertSame(0, $s->consecutive_failures);
        $this->assertNull($s->circuit_open_until);
        $this->assertSame('ok', $s->health);
    }

    public function test_circuit_backoff_doubles_while_failures_continue(): void
    {
        Http::fake(['*' => Http::response('erro', 500)]);
        DataSource::byKey('transfermarkt')->update(['consecutive_failures' => 3]);
        $this->assertSame('http', $this->kind(fn () => $this->tm()->getJson('/x')));
        $s = DataSource::byKey('transfermarkt');
        $this->assertSame(4, $s->consecutive_failures);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $s->circuit_open_until->timestamp, 2);
        Http::assertSentCount(3); // 5xx: 3 tentativas com backoff
        $this->assertCount(2, $this->slept);
    }

    public function test_timeout_retries_once_then_reports_connection_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->assertSame('conexao', $this->kind(fn () => $this->tm()->getJson('/x')));
        $s = DataSource::byKey('transfermarkt');
        $this->assertSame(2, $s->used_today);
        $this->assertSame(1, $s->consecutive_failures);
        $this->assertSame('degradado', $s->health);
        $this->assertStringContainsString('tempo esgotado', $s->last_error);
    }

    public function test_404_is_not_a_source_failure(): void
    {
        Http::fake(['*' => Http::response('nada', 404)]);
        DataSource::byKey('transfermarkt')->update(['consecutive_failures' => 2]);
        $this->assertSame('nao_encontrado', $this->kind(fn () => $this->tm()->getJson('/x')));
        $this->assertSame(0, DataSource::byKey('transfermarkt')->consecutive_failures);
    }

    public function test_valid_response_is_cached_per_resource_and_params_without_spending_quota(): void
    {
        Http::fake(['*' => Http::response(['n' => 1])]);
        $this->tm()->getJson('/players/1', ['a' => 1, 'b' => 2], [], 600);
        $this->tm()->getJson('/players/1', ['b' => 2, 'a' => 1], [], 600); // mesma consulta, ordem diferente
        $this->tm()->getJson('/players/1', ['a' => 9], [], 600); // parâmetro diferente
        $this->tm()->getJson('/players/1', ['a' => 1, 'b' => 2]); // sem cache pedido
        Http::assertSentCount(3);
        $this->assertSame(3, DataSource::byKey('transfermarkt')->used_today);
    }

    public function test_absolute_url_must_stay_on_the_source_host(): void
    {
        Http::fake(['*' => Http::response('%PDF')]);
        $this->assertSame('url', $this->kind(fn () => SourceClient::for('sumula_fpf')->getRaw('http://169.254.169.254/latest/meta-data')));
        $this->assertSame('url', $this->kind(fn () => SourceClient::for('sumula_fpf')->getRaw('https://www.fpfpara.com.br.evil.test/x.pdf')));
        $this->assertSame('%PDF', SourceClient::for('sumula_fpf')->getRaw('https://www.fpfpara.com.br/files/sumulas/a.pdf'));
        Http::assertSentCount(1);
    }

    public function test_freshness_uses_last_completed_ingestion_not_health_probe(): void
    {
        $s = DataSource::byKey('cbf_atletas');
        $this->assertSame(30, $s->expected_refresh_hours);
        $s->update(['last_success_at' => now()]); // teste de saúde não conta
        $this->assertSame('nunca', $s->freshness());
        SyncRun::create(['data_source_id' => $s->id, 'job' => 'x', 'status' => 'concluido', 'finished_at' => now()->subHours(40)]);
        SyncRun::create(['data_source_id' => $s->id, 'job' => 'x', 'status' => 'falhou', 'finished_at' => now()]);
        $this->assertSame('atrasado', $s->freshness());
        SyncRun::create(['data_source_id' => $s->id, 'job' => 'x', 'status' => 'concluido', 'finished_at' => now()->subHours(2)]);
        $this->assertSame('em_dia', $s->freshness());
        $this->assertSame('sem_expectativa', DataSource::byKey('transfermarkt')->freshness());
    }

    public function test_dados_futebol_is_registered_with_contract_limits_and_bearer_auth(): void
    {
        config(['scout.credentials.DADOS_FUTEBOL_KEY' => 'k-test']);
        $this->seed(DataSourcesSeeder::class); // setUp zerou os limites; o seeder só preenche o que está vazio
        $s = DataSource::byKey('dados_futebol');
        $this->assertSame(30000, $s->monthly_quota);
        $this->assertTrue($s->requires_credential);
        $this->assertSame(['Authorization' => 'Bearer k-test'], $s->authHeaders());
        $this->assertFalse(DataSource::byKey('sportmonks')->enabled); // plano Free sem ligas brasileiras
    }
}
