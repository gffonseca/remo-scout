<?php

namespace App\Sources;

use App\Models\DataSource;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP comum às fontes (F09).
 * - Kill switch (enabled) e credencial exigida.
 * - Cota diária e mensal reservadas de forma atômica no banco ANTES de cada tentativa: dois workers nunca passam do limite.
 * - Intervalo mínimo entre chamadas: a trava só reserva o horário da próxima chamada (fração de segundo); a espera é fora dela.
 * - 429 respeita Retry-After (segundos ou data HTTP) com jitter; espera longa não prende o worker: pausa a fonte até lá.
 * - 5xx e falha de conexão/timeout: nova tentativa com backoff e jitter.
 * - Circuito: falhas seguidas pausam a fonte com retomada automática (5 min, dobrando até 6 h); um sucesso fecha o circuito.
 * - Cache opcional de resposta por fonte/endereço/parâmetros (não consome cota).
 * - URL absoluta só no mesmo host da fonte. Nunca registra credenciais em log.
 */
class SourceClient
{
    public const FAILURE_THRESHOLD = 3;

    public const MAX_INLINE_WAIT = 60;

    /** Marca no cache de uma resposta 404 entregue pelo coletor. */
    public const NOT_FOUND = "\0nao_encontrado";

    /** Substituível em teste para não dormir de verdade. @var (\Closure(float): void)|null */
    public static ?\Closure $sleeper = null;

    public function __construct(public DataSource $source) {}

    public static function for(string $key): self
    {
        return new self(DataSource::byKey($key));
    }

    /** @return array<mixed> */
    /**
     * @param  (\Closure(string): ?array{0:string,1:string,2:string})|null  $check  valida o corpo de um HTTP 200 antes de contar como
     *                                                                              sucesso; devolve [tipo, mensagem, saúde] quando o provedor sinaliza erro dentro da resposta (ex.: API-Football "errors").
     */
    public function getJson(string $path, array $query = [], array $headers = [], ?int $cacheSeconds = null, ?\Closure $check = null): array
    {
        $json = json_decode($this->request($path, $query, $headers, $cacheSeconds, $check), true);
        if (! is_array($json)) {
            throw new SourceException('Resposta não é JSON válido', 'formato');
        }

        return $json;
    }

    public function getRaw(string $path, array $query = [], array $headers = [], ?int $cacheSeconds = null): string
    {
        return $this->request($path, $query, $headers, $cacheSeconds);
    }

    public static function cacheKey(DataSource $s, string $url, array $query): string
    {
        ksort($query);

        return 'source-resp:'.$s->key.':'.sha1($url.'|'.json_encode($query));
    }

    private function request(string $path, array $query, array $headers, ?int $cacheSeconds, ?\Closure $check = null): string
    {
        $s = $this->source->fresh();
        if (! $s->enabled) {
            throw new SourceException("Fonte {$s->name} desativada", 'desativada');
        }
        if ($s->requires_credential && ! $s->credential()) {
            $s->update(['health' => 'sem_credencial']);
            throw new SourceException("Fonte {$s->name} aguardando credencial", 'credencial');
        }
        $url = $this->url($s, $path);
        if ($s->transport === 'coletor_local') {
            // Fonte buscada pelo Mac (IP brasileiro / fonte que recusa servidor): só lê o que o coletor entregou.
            $hit = Cache::get(static::cacheKey($s, $url, $query));
            if ($hit === self::NOT_FOUND) {
                throw new SourceException('Não encontrado na fonte', 'nao_encontrado', 404);
            }
            if (is_string($hit)) {
                return $hit;
            }
            throw new CollectorPending($url, $query);
        }
        $cacheKey = $cacheSeconds ? static::cacheKey($s, $url, $query) : null;
        if ($cacheKey && is_string($hit = Cache::get($cacheKey))) {
            return $hit;
        }
        if ($s->circuitOpen()) {
            throw new SourceException("{$s->name} pausada até ".$s->circuit_open_until->timezone(config('scout.display_timezone'))->format('d/m H:i')
                ." após falhas seguidas ({$s->consecutive_failures}). Retoma sozinha.", 'circuito');
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->reserveQuota($s);
            $this->waitForSlot($s);
            try {
                $res = Http::timeout(25)->connectTimeout(10)
                    ->withHeaders($headers + ['User-Agent' => $s->key === 'sofascore'
                        ? 'Mozilla/5.0'
                        : 'Mozilla/5.0 (compatible; CIFUT-Remo/1.0; uso interno)', 'Accept' => 'application/json, text/html;q=0.9, */*;q=0.8'])
                    ->get($url, $query ?: null);
            } catch (ConnectionException $e) {
                if ($attempt < 2) {
                    $this->pause(1 + $this->jitter());

                    continue;
                }
                $this->fail($s, 'Falha de conexão ou tempo esgotado: '.class_basename($e), 'degradado');
                throw new SourceException('Falha de conexão com '.$s->name, 'conexao');
            }
            if ($res->status() === 429) {
                $wait = static::retryAfter($res) ?? 5 * $attempt;
                if ($attempt < 3 && $wait <= self::MAX_INLINE_WAIT) {
                    $this->pause($wait + $this->jitter());

                    continue;
                }
                $until = $wait > self::MAX_INLINE_WAIT ? now()->addSeconds($wait) : null;
                $this->fail($s, 'HTTP 429: limite de chamadas da fonte'.($until ? ' (Retry-After '.$wait.' s)' : ''), 'degradado', $until);
                throw new SourceException("{$s->name} limitou as chamadas (HTTP 429)", 'limite', 429);
            }
            if ($res->serverError() && $attempt < 3) {
                $this->pause(0.5 * $attempt + $this->jitter());

                continue;
            }
            break;
        }

        if (in_array($res->status(), [401, 403], true)) {
            $msg = mb_substr(strip_tags((string) $res->body()), 0, 200);
            $this->fail($s, "HTTP {$res->status()}: {$msg}", $res->status() === 403 ? 'bloqueado' : 'sem_credencial');
            throw new SourceException("{$s->name} recusou o acesso (HTTP {$res->status()}): {$msg}", 'bloqueado', $res->status());
        }
        if ($res->status() === 404) {
            $this->reachable($s); // a fonte respondeu; só o recurso não existe

            throw new SourceException('Não encontrado na fonte', 'nao_encontrado', 404);
        }
        if (! $res->successful()) {
            $this->fail($s, "HTTP {$res->status()}", 'degradado');
            throw new SourceException("{$s->name} respondeu HTTP {$res->status()}", 'http', $res->status());
        }
        $body = (string) $res->body();
        if ($check && ($err = $check($body))) {
            [$kind, $msg, $health] = $err;
            $this->fail($s, $msg, $health); // erro lógico conta para o circuito e não vai para o cache
            throw new SourceException($msg, $kind);
        }
        $this->reachable($s, true);
        if ($cacheKey) {
            Cache::put($cacheKey, $body, $cacheSeconds);
        }

        return $body;
    }

    /** Endereço final; URL absoluta só no host da própria fonte (evita usar o servidor para buscar qualquer endereço). */
    private function url(DataSource $s, string $path): string
    {
        if (! preg_match('#^https?://#i', $path)) {
            return rtrim((string) $s->base_url, '/').'/'.ltrim($path, '/');
        }
        $host = strtolower((string) parse_url($path, PHP_URL_HOST));
        $allowed = strtolower((string) parse_url((string) $s->base_url, PHP_URL_HOST));
        if ($host === '' || $host !== $allowed) {
            throw new SourceException("Endereço fora da fonte {$s->name} (esperado: {$allowed})", 'url');
        }

        return $path;
    }

    /** Reserva 1 chamada na cota diária e mensal numa única instrução; recusa se qualquer uma estiver esgotada. */
    private function reserveQuota(DataSource $s): void
    {
        $day = DataSource::quotaDay();
        $month = DataSource::quotaMonth();
        $ok = DB::update(<<<'SQL'
            update data_sources set
                used_today = case when quota_date = ?::date then used_today + 1 else 1 end,
                quota_date = ?::date,
                used_month = case when quota_month = ? then used_month + 1 else 1 end,
                quota_month = ?
            where id = ?
              and (daily_quota is null or (case when quota_date = ?::date then used_today else 0 end) < daily_quota)
              and (monthly_quota is null or (case when quota_month = ? then used_month else 0 end) < monthly_quota)
            SQL, [$day, $day, $month, $month, $s->id, $day, $month]);
        if ($ok === 0) {
            throw new SourceException("Cota de {$s->name} esgotada", 'cota');
        }
    }

    /** Reserva o horário da próxima chamada sob trava curta e espera fora dela. */
    private function waitForSlot(DataSource $s): void
    {
        $lock = Cache::lock('source-throttle:'.$s->key, 10);
        $lock->block(15);
        try {
            $now = microtime(true);
            $slot = max($now, (float) Cache::get('source-last:'.$s->key, 0) + $s->min_interval_ms / 1000);
            Cache::put('source-last:'.$s->key, $slot, 3600);
        } finally {
            $lock->release();
        }
        if ($slot > $now) {
            $this->pause($slot - $now);
        }
    }

    /** Retry-After em segundos (inteiro ou data HTTP); null se ausente/ilegível. */
    public static function retryAfter(Response $res): ?int
    {
        $h = trim((string) $res->header('Retry-After'));
        if ($h === '') {
            return null;
        }
        if (ctype_digit($h)) {
            return (int) $h;
        }
        $ts = strtotime($h);

        return $ts === false ? null : max(0, $ts - time());
    }

    private function fail(DataSource $s, string $msg, string $health, ?CarbonInterface $openUntil = null): void
    {
        Log::warning('[fonte] '.$s->key.' '.$msg);
        $n = (int) DB::selectOne('update data_sources set consecutive_failures = consecutive_failures + 1 where id = ? returning consecutive_failures', [$s->id])->consecutive_failures;
        if (! $openUntil && $n >= self::FAILURE_THRESHOLD) {
            $openUntil = now()->addMinutes(min(360, 5 * 2 ** ($n - self::FAILURE_THRESHOLD)));
        }
        $s->forceFill(['health' => $health, 'last_error_at' => now(), 'last_error' => mb_substr($msg, 0, 500)]
            + ($openUntil ? ['circuit_open_until' => $openUntil] : []))->save();
        $s->consecutive_failures = $n;
        $s->syncOriginalAttribute('consecutive_failures');
    }

    private function reachable(DataSource $s, bool $success = false): void
    {
        $s->forceFill(['consecutive_failures' => 0, 'circuit_open_until' => null]
            + ($success ? ['health' => 'ok', 'last_success_at' => now()] : []))->save();
    }

    private function jitter(): float
    {
        return random_int(0, 1000) / 1000;
    }

    private function pause(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        static::$sleeper ? (static::$sleeper)($seconds) : usleep((int) ($seconds * 1_000_000));
    }
}
