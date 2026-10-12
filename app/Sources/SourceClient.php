<?php

namespace App\Sources;

use App\Models\DataSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP comum às fontes: respeita kill switch (enabled), intervalo mínimo entre chamadas
 * (compartilhado entre workers via cache lock), cota diária, Retry-After/429, timeouts e registra saúde.
 * Nunca registra credenciais em log.
 */
class SourceClient
{
    public function __construct(public DataSource $source) {}

    public static function for(string $key): self
    {
        return new self(DataSource::byKey($key));
    }

    /** @return array<mixed> */
    public function getJson(string $path, array $query = [], array $headers = []): array
    {
        $res = $this->request($path, $query, $headers);
        $json = json_decode($res, true);
        if (! is_array($json)) {
            throw new SourceException('Resposta não é JSON válido', 'formato');
        }

        return $json;
    }

    public function getRaw(string $path, array $query = [], array $headers = []): string
    {
        return $this->request($path, $query, $headers);
    }

    private function request(string $path, array $query, array $headers): string
    {
        $s = $this->source->fresh();
        if (! $s->enabled) {
            throw new SourceException("Fonte {$s->name} desativada", 'desativada');
        }
        if ($s->requires_credential && ! $s->credential()) {
            $s->update(['health' => 'sem_credencial']);
            throw new SourceException("Fonte {$s->name} aguardando credencial", 'credencial');
        }
        if (($s->remainingQuota() ?? 1) <= 0) {
            throw new SourceException("Cota diária de {$s->name} esgotada", 'cota');
        }

        $url = str_starts_with($path, 'http') ? $path : rtrim((string) $s->base_url, '/').'/'.ltrim($path, '/');
        $lock = Cache::lock('source-throttle:'.$s->key, 30);
        $lock->block(30);
        try {
            $last = (float) Cache::get('source-last:'.$s->key, 0);
            $wait = ($last + $s->min_interval_ms / 1000) - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $attempt = 0;
            while (true) {
                $attempt++;
                try {
                    $res = Http::timeout(25)->connectTimeout(10)
                        ->withHeaders($headers + ['User-Agent' => $s->key === 'sofascore'
                            ? 'Mozilla/5.0'
                            : 'Mozilla/5.0 (compatible; REMO-SCOUT/1.0; uso interno)', 'Accept' => 'application/json, text/html;q=0.9, */*;q=0.8'])
                        ->get($url, $query ?: null);
                } catch (ConnectionException $e) {
                    $this->markError('Falha de conexão: '.class_basename($e), 'degradado');
                    throw new SourceException('Falha de conexão com '.$s->name, 'conexao');
                } finally {
                    Cache::put('source-last:'.$s->key, microtime(true), 3600);
                    $this->countRequest();
                }
                if ($res->status() === 429 && $attempt < 3) {
                    $retry = min(60, (int) ($res->header('Retry-After') ?: 5 * $attempt));
                    sleep($retry);

                    continue;
                }
                if ($res->serverError() && $attempt < 3) {
                    usleep(random_int(500, 1500) * 1000 * $attempt);

                    continue;
                }
                break;
            }
        } finally {
            $lock->release();
        }

        if (in_array($res->status(), [401, 403], true)) {
            $msg = mb_substr(strip_tags((string) $res->body()), 0, 200);
            $this->markError("HTTP {$res->status()}: {$msg}", $res->status() === 403 ? 'bloqueado' : 'sem_credencial');
            throw new SourceException("{$s->name} recusou o acesso (HTTP {$res->status()}): {$msg}", 'bloqueado', $res->status());
        }
        if ($res->status() === 404) {
            throw new SourceException('Não encontrado na fonte', 'nao_encontrado', 404);
        }
        if (! $res->successful()) {
            $this->markError("HTTP {$res->status()}", 'degradado');
            throw new SourceException("{$s->name} respondeu HTTP {$res->status()}", 'http', $res->status());
        }
        $s->update(['health' => 'ok', 'last_success_at' => now()]);

        return (string) $res->body();
    }

    private function countRequest(): void
    {
        $s = $this->source;
        DataSource::whereKey($s->id)->update([
            'used_today' => \DB::raw("case when quota_date = current_date then used_today + 1 else 1 end"),
            'quota_date' => now()->toDateString(),
        ]);
    }

    private function markError(string $msg, string $health): void
    {
        Log::warning('[fonte] '.$this->source->key.' '.$msg);
        $this->source->update(['health' => $health, 'last_error_at' => now(), 'last_error' => mb_substr($msg, 0, 500)]);
    }
}
