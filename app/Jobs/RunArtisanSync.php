<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

/**
 * Executa uma sincronização limitada no worker (disparada pela tela de Fontes). F06:
 * - comandos sempre com teto de chamadas (--max/--limit), então cabem no timeout;
 * - timeout (1500 s) < retry_after da fila (1800 s): outro worker nunca reserva o job ainda vivo;
 * - uma tentativa só (falha fica registrada no SyncRun; nova execução é decisão humana ou do agendador);
 * - sem sobreposição: o mesmo comando para a mesma fonte não roda duas vezes ao mesmo tempo
 *   (job descartado aqui; e os comandos ainda checam SourceRunLock, que também cobre o agendador).
 */
class RunArtisanSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $command, public array $params = []) {}

    public function middleware(): array
    {
        $key = $this->command.':'.($this->params['provider'] ?? 'default');

        return [(new WithoutOverlapping($key))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        abort_unless(str_starts_with($this->command, 'scout:'), 403);
        Artisan::call($this->command, $this->params);
    }
}
