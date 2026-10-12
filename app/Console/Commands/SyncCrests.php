<?php

namespace App\Console\Commands;

use App\Sources\ApiFutebol;
use Illuminate\Console\Command;

class SyncCrests extends Command
{
    protected $signature = 'scout:sync-crests {--competitions=10,14,16,17,2}';

    protected $description = 'Preenche escudos dos clubes a partir da API Futebol (não sobrescreve escudo enviado)';

    public function handle(): int
    {
        try {
            $n = (new ApiFutebol)->syncCrests(array_map('intval', explode(',', $this->option('competitions'))));
            $this->info("Escudos preenchidos: {$n}");
        } catch (\Throwable $e) {
            $this->warn('Escudos: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
