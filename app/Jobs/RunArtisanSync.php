<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/** Executa uma sincronização longa no worker (disparada pela tela de Fontes). */
class RunArtisanSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3500;

    public int $tries = 1;

    public function __construct(public string $command, public array $params = []) {}

    public function handle(): void
    {
        abort_unless(str_starts_with($this->command, 'scout:'), 403);
        Artisan::call($this->command, $this->params);
    }
}
