<?php

namespace App\Console\Commands;

use App\Services\WatchlistAlerts;
use Illuminate\Console\Command;

class WatchlistAlertsCommand extends Command
{
    protected $signature = 'scout:watchlist-alerts';

    protected $description = 'Gera alertas da watchlist (sino), deduplicados';

    public function handle(): int
    {
        $this->info('[watchlist] '.WatchlistAlerts::run().' alerta(s) novo(s)');

        return self::SUCCESS;
    }
}
