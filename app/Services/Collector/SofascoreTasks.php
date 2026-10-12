<?php

namespace App\Services\Collector;

use App\Models\CollectorTask;
use App\Models\DataSource;
use App\Models\Player;
use App\Services\PlayerEnrichment;
use App\Sources\CollectorPending;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use Illuminate\Support\Facades\Cache;

/**
 * Sofascore sob demanda pelo coletor do Mac (decisão do Gustavo: o Sofascore recusa IP de servidor).
 * O servidor roda o enriquecimento normal; quando falta uma resposta, a tarefa pede o endereço ao coletor,
 * que busca, devolve, e o servidor continua. Toda regra (vínculo, revisão, lista branca) segue no servidor.
 * Termos do Sofascore restringem coleta automatizada: uso só sob demanda, com intervalo, e com kill switch.
 */
class SofascoreTasks
{
    public const SOURCE = 'sofascore';

    public const MAX_ROUNDS = 12;

    public const CACHE_SECONDS = 6 * 3600;

    public static function request(Player $p, ?int $userId = null): CollectorTask
    {
        $task = CollectorTask::where('source', self::SOURCE)->where('player_id', $p->id)->whereIn('status', ['pendente', 'aguardando_coletor'])->first()
            ?? CollectorTask::create(['source' => self::SOURCE, 'kind' => 'enriquecer', 'player_id' => $p->id, 'status' => 'pendente', 'requested_by' => $userId]);

        return static::advance($task);
    }

    /** Tenta concluir com o que já está em cache; se faltar algo, registra o endereço pedido ao coletor. */
    public static function advance(CollectorTask $task): CollectorTask
    {
        $p = $task->player?->fresh(['currentClub', 'externalIds']);
        if (! $p) {
            return static::close($task, 'falhou', 'Atleta não existe mais.');
        }
        try {
            $link = PlayerEnrichment::linkSofascore($p);
            if (! in_array($link['status'], ['vinculado', 'ja_vinculado'], true)) {
                return static::close($task, 'concluida', $link['message']);
            }
            $res = PlayerEnrichment::enrichSofascore($p->fresh());

            return static::close($task, 'concluida', $res['message']);
        } catch (CollectorPending $e) {
            if ($task->rounds >= self::MAX_ROUNDS) {
                return static::close($task, 'falhou', 'Limite de rodadas com o coletor atingido.');
            }
            $task->update(['status' => 'aguardando_coletor', 'pending_paths' => [$e->request()], 'rounds' => $task->rounds + 1]);

            return $task;
        } catch (SourceException $e) {
            return static::close($task, 'falhou', $e->getMessage());
        }
    }

    /**
     * Respostas do coletor: só para endereços que esta tarefa pediu. 200 vira cache; 404 vira "não encontrado";
     * outros códigos (403/429) encerram a tarefa — o Sofascore também recusou o Mac.
     *
     * @param  list<array{url:string, query?:array, status:int, body?:?string}>  $responses
     */
    public static function receive(CollectorTask $task, array $responses): CollectorTask
    {
        if ($task->status !== 'aguardando_coletor') {
            return $task;
        }
        $source = DataSource::byKey(self::SOURCE);
        $asked = collect($task->pending_paths ?? [])->map(fn ($r) => static::sig($r['url'], (array) ($r['query'] ?? [])));
        $accepted = 0;
        foreach ($responses as $r) {
            $query = (array) ($r['query'] ?? []);
            if (! $asked->contains(static::sig($r['url'], $query))) {
                continue; // endereço não pedido: ignorado
            }
            $accepted++;
            $status = (int) $r['status'];
            if ($status === 200 && is_string($r['body'] ?? null)) {
                Cache::put(SourceClient::cacheKey($source, $r['url'], $query), $r['body'], self::CACHE_SECONDS);
            } elseif ($status === 404) {
                Cache::put(SourceClient::cacheKey($source, $r['url'], $query), SourceClient::NOT_FOUND, self::CACHE_SECONDS);
            } else {
                $source->update(['health' => $status === 403 ? 'bloqueado' : 'degradado', 'last_error_at' => now(), 'last_error' => "Sofascore respondeu HTTP {$status} ao coletor do Mac"]);

                return static::close($task, 'falhou', "Sofascore respondeu HTTP {$status} ao coletor do Mac.");
            }
        }
        if ($accepted === 0) {
            return $task; // nada do que foi pedido: a tarefa continua esperando, sem gastar rodada
        }
        $source->update(['health' => 'ok', 'last_success_at' => now()]);

        return static::advance($task->fresh());
    }

    /** Endereço permitido ao coletor: só a API v1 do Sofascore. */
    public static function allowed(string $url): bool
    {
        return (bool) preg_match('#^https://api\.sofascore\.com/api/v1/[A-Za-z0-9/_\-]+$#', $url);
    }

    private static function sig(string $url, array $query): string
    {
        ksort($query);

        return $url.'?'.http_build_query($query);
    }

    private static function close(CollectorTask $task, string $status, string $result): CollectorTask
    {
        $task->update(['status' => $status, 'result' => mb_substr($result, 0, 1000), 'pending_paths' => null, 'finished_at' => now()]);

        return $task;
    }
}
