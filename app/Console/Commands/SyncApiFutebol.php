<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\SyncRun;
use App\Sources\ApiFutebol;
use Illuminate\Console\Command;

class SyncApiFutebol extends Command
{
    protected $signature = 'scout:sync-api-futebol {--competitions=10,14,16,17,2} {--max=300} {--user=}';

    protected $description = 'Sincroniza partidas finalizadas (escalações, minutos, gols, cartões) da API Futebol';

    public function handle(): int
    {
        $source = DataSource::byKey('api_futebol');
        $run = SyncRun::create(['data_source_id' => $source->id, 'job' => 'partidas', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['competitions' => $this->option('competitions'), 'max' => (int) $this->option('max')], 'requested_by' => $this->option('user')]);
        try {
            (new ApiFutebol)->sync($run, array_map('intval', explode(',', $this->option('competitions'))), (int) $this->option('max'));
            $run->finish();
        } catch (\Throwable $e) {
            $run->errors++;
            $run->note('Falha: '.$e->getMessage());
            $run->finish('falhou');
        }
        $this->info("API Futebol: {$run->status} · requisições {$run->requests_made} · partidas {$run->received} · atletas novos {$run->created} · vinculados {$run->updated} · erros {$run->errors}");

        return self::SUCCESS;
    }
}
