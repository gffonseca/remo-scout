<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\SyncRun;
use App\Sources\FootballData;
use App\Support\SourceRunLock;
use Illuminate\Console\Command;

class SyncFootballData extends Command
{
    protected $signature = 'scout:sync-football-data {--from=2022 : primeira temporada a importar} {--user=}';

    protected $description = 'Importa resultados e odds de fechamento da Série A (football-data.co.uk)';

    public function handle(): int
    {
        $source = DataSource::byKey(FootballData::PROVIDER);
        if (! $source->enabled) {
            $this->warn('football-data.co.uk desativada.');

            return self::SUCCESS;
        }
        SourceRunLock::run(FootballData::PROVIDER, function () use ($source) {
            $run = SyncRun::create(['data_source_id' => $source->id, 'job' => 'odds', 'status' => 'em_execucao', 'started_at' => now(),
                'scope' => ['from' => (int) $this->option('from')], 'requested_by' => $this->option('user')]);
            try {
                $fd = new FootballData;
                $fd->import($fd->fetch('BRA', $run), (int) $this->option('from'), $run);
                $run->finish();
            } catch (\Throwable $e) {
                $run->errors++;
                $run->note('Falha: '.$e->getMessage());
                $run->finish('falhou');
            }
            $this->info("football-data: {$run->status} · jogos {$run->updated} · descartados {$run->discarded}");
        });

        return self::SUCCESS;
    }
}
