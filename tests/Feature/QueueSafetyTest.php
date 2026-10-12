<?php

namespace Tests\Feature;

use App\Jobs\RunArtisanSync;
use App\Models\SyncRun;
use App\Support\SourceRunLock;
use Database\Seeders\DataSourcesSeeder;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/** F06 — jobs limitados, sem reserva dupla e sem duas execuções da mesma fonte. */
class QueueSafetyTest extends ScoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
    }

    public function test_job_timeout_is_below_effective_retry_after_and_lock_ttl_in_between(): void
    {
        $job = new RunArtisanSync('scout:sync-api-futebol', ['--max' => 300]);
        $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', config('queue.connections.database.retry_after'));
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertGreaterThan($job->timeout, SourceRunLock::TTL);
        $this->assertLessThan(config('queue.connections.database.retry_after'), SourceRunLock::TTL);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan(0, $retryAfter);
    }

    public function test_same_command_and_source_do_not_overlap_on_the_queue(): void
    {
        $a = (new RunArtisanSync('scout:enrich', ['provider' => 'transfermarkt']))->middleware()[0];
        $b = (new RunArtisanSync('scout:enrich', ['provider' => 'transfermarkt']))->middleware()[0];
        $c = (new RunArtisanSync('scout:enrich', ['provider' => 'sofascore']))->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $a);
        $this->assertSame($a->key, $b->key);
        $this->assertNotSame($a->key, $c->key);
        $this->assertNull($a->releaseAfter); // descarta duplicado em vez de reenfileirar
        $this->assertSame(1560, $a->expiresAfter); // lock não fica preso se o worker morrer
    }

    public function test_second_worker_or_scheduler_skips_source_already_running(): void
    {
        $lock = Cache::lock('source-run:api_futebol', SourceRunLock::TTL);
        $this->assertTrue($lock->get()); // "worker 1" em execução
        try {
            Artisan::call('scout:sync-api-futebol', ['--max' => 1]);
            $this->assertStringContainsString('já existe sincronização em andamento', Artisan::output());
            $this->assertSame(0, SyncRun::count()); // nada processado em duplicidade
        } finally {
            $lock->release();
        }
        Cache::lock('source-run:transfermarkt', SourceRunLock::TTL)->get();
        Artisan::call('scout:enrich', ['provider' => 'transfermarkt', '--limit' => 1]);
        $this->assertStringContainsString('já existe enriquecimento em andamento', Artisan::output());
        $this->assertSame(0, SyncRun::count());
    }

    public function test_lock_is_released_even_when_the_run_fails(): void
    {
        try {
            SourceRunLock::run('teste', fn () => throw new \RuntimeException('falha'));
        } catch (\RuntimeException) {
        }
        $this->assertTrue(SourceRunLock::run('teste', fn () => null));
    }
}
