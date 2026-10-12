<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Exclusão mútua por fonte para execuções de sincronização (F06): agendador, botão da tela e retry da fila
 * nunca processam a mesma fonte ao mesmo tempo. O TTL é maior que o timeout do job, para o lock não expirar
 * com a execução ainda viva.
 */
class SourceRunLock
{
    public const TTL = 1700; // > RunArtisanSync::$timeout (1500) e < retry_after (1800)

    /** Executa $fn com o lock; devolve false se outra execução da mesma fonte estiver em andamento. */
    public static function run(string $sourceKey, callable $fn): bool
    {
        $lock = Cache::lock('source-run:'.$sourceKey, self::TTL);
        if (! $lock->get()) {
            return false;
        }
        try {
            $fn();
        } finally {
            $lock->release();
        }

        return true;
    }
}
