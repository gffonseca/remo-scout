<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\SyncRun;
use App\Sources\ApiFootball;
use App\Support\SourceRunLock;
use Illuminate\Console\Command;

class SyncApiFootball extends Command
{
    protected $signature = 'scout:sync-api-football {--leagues=71,72,73,75,612} {--season=} {--max=400} {--user=}';

    protected $description = 'Sincroniza partidas finalizadas da API-Football (estatística individual Série A/B; escalações Série C/Nordeste)';

    public function handle(): int
    {
        $source = DataSource::byKey(ApiFootball::PROVIDER);
        if (! $source->enabled) {
            $this->warn('API-Football desativada.');

            return self::SUCCESS;
        }
        if (! SourceRunLock::run(ApiFootball::PROVIDER, fn () => $this->runSync($source))) {
            $this->warn('API-Football: já existe sincronização em andamento.');
        }

        return self::SUCCESS;
    }

    private function runSync(DataSource $source): void
    {
        SyncRun::closeStale($source->id, 40);
        $season = $this->option('season') ? (int) $this->option('season') : null;
        $leagues = array_map('intval', explode(',', $this->option('leagues')));
        $run = SyncRun::create(['data_source_id' => $source->id, 'job' => 'partidas', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['leagues' => $leagues, 'season' => $season, 'max' => (int) $this->option('max')], 'requested_by' => $this->option('user')]);
        try {
            (new ApiFootball)->sync($run, $leagues, (int) $this->option('max'), $season);
            $run->finish();
        } catch (\Throwable $e) {
            $run->errors++;
            $run->note('Falha: '.$e->getMessage());
            $run->finish('falhou');
        }
        $this->info("API-Football: {$run->status} · requisições {$run->requests_made} · partidas {$run->received} · provisórios {$run->created} · vinculados por nascimento {$run->updated} · erros {$run->errors}");
    }
}
