<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\DataFix;
use App\Services\PhotoBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fotos dos atletas (prompt mestre 3.7). Sem --apply tudo é prévia.
 *   --backfill  foto da API-Football para quem tem vínculo confiável e está sem foto
 *   --check     verifica as URLs externas: host permitido, resposta, tipo e tamanho; grava o estado (não apaga a URL)
 *   --revert=   desfaz um lote (backfill ou verificação)
 *   --report    números atuais (com denominador)
 */
class PlayerPhotos extends Command
{
    protected $signature = 'scout:photos {--backfill} {--check} {--report} {--apply} {--revert=} {--limit=200} {--actor=}';

    protected $description = 'Fotos dos atletas: backfill com prévia, verificação das URLs e relatório';

    /** Hosts de onde o sistema aceita exibir foto externa. */
    public const ALLOWED_HOSTS = ['media.api-sports.io', 'img.a.transfermarkt.technology', 'tmssl.akamaized.net', 'img.sofascore.com', 'api.sofascore.app'];

    public const MAX_BYTES = 3_000_000;

    public function handle(): int
    {
        if ($batch = $this->option('revert')) {
            $r = DataFix::revert($batch);
            $this->info("Revertidas: {$r['reverted']}; já revertidas: {$r['already']}; conflitos: ".count($r['conflicts']).'.');

            return self::SUCCESS;
        }
        $apply = (bool) $this->option('apply');
        $actor = $this->option('actor') ?: 'cli';
        if ($this->option('backfill')) {
            $r = PhotoBackfill::run($apply, $actor);
            $this->line(($apply ? 'APLICADO' : 'PRÉVIA').": {$r['count']} atleta(s) recebem a foto da API-Football (vínculo confiável, cadastro sem foto).");
            $this->table(['Atleta'], $r['rows']->take(15)->map(fn ($x) => [$x->name])->all());
            if ($r['batch']) {
                $this->line("Lote {$r['batch']} — desfazer: php artisan scout:photos --revert={$r['batch']}");
            }
        }
        if ($this->option('check')) {
            $this->check($apply, $actor);
        }
        if ($this->option('report') || ! ($this->option('backfill') || $this->option('check'))) {
            $this->report();
        }

        return self::SUCCESS;
    }

    /** Estado de uma URL externa: ok | quebrada | bloqueada | generica | nao_imagem | host_nao_permitido. */
    public static function probe(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            return 'host_nao_permitido';
        }
        if (str_contains($url, 'default')) {
            return 'generica'; // Transfermarkt usa "default.jpg" para quem não tem foto
        }
        // redirecionamento só para host permitido e https (sem SSRF); corpo lido até o limite
        for ($hop = 0; $hop <= 2; $hop++) {
            try {
                $res = Http::timeout(15)->withOptions(['allow_redirects' => false, 'stream' => true, 'protocols' => ['https']])->get($url);
            } catch (\Throwable) {
                return 'quebrada';
            }
            if (! $res->redirect()) {
                break;
            }
            $next = $res->header('Location');
            $url = $next ? (str_starts_with($next, '/') ? 'https://'.$host.$next : $next) : '';
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host = parse_url($url, PHP_URL_HOST), self::ALLOWED_HOSTS, true)) {
                return 'host_nao_permitido';
            }
            if ($hop === 2) {
                return 'quebrada';
            }
        }
        if (in_array($res->status(), [401, 403, 429], true)) {
            return 'bloqueada'; // a fonte recusa o servidor; o navegador pode conseguir (fallback nas iniciais)
        }
        if (! $res->successful()) {
            return 'quebrada';
        }
        $type = strtolower((string) $res->header('Content-Type'));
        $stream = $res->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $body .= $stream->read(65536);
        }
        if (strlen($body) > self::MAX_BYTES) {
            return 'nao_imagem'; // grande demais para foto de perfil
        }
        if (! str_starts_with($type, 'image/') || @getimagesizefromstring($body) === false) {
            return 'nao_imagem';
        }

        return strlen($body) < 1500 ? 'generica' : 'ok';
    }

    private function check(bool $apply, string $actor): void
    {
        $rows = Player::whereNotNull('photo_url')->whereNull('photo_path')
            ->where(fn ($q) => $q->whereNull('photo_checked_at')->orWhere('photo_checked_at', '<', now()->subDays(7)))
            ->orderByDesc('is_priority')->limit((int) $this->option('limit'))->get(['id', 'name', 'photo_url', 'photo_status']);
        $batch = $apply ? DataFix::newBatch('photo_check') : null;
        $count = [];
        foreach ($rows as $p) {
            $state = self::probe($p->photo_url);
            $count[$state] = ($count[$state] ?? 0) + 1;
            if ($apply) {
                DataFix::apply($batch, 'photo_check', 'players', $p->id, ['photo_status' => $state, 'photo_checked_at' => now()->toDateTimeString()], $actor);
            }
            usleep(300_000); // baixa concorrência, sem repetição agressiva
        }
        $this->line(($apply ? 'VERIFICADO E GRAVADO' : 'PRÉVIA da verificação').": {$rows->count()} URL(s) — ".collect($count)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ').'.');
        if ($batch) {
            $this->line("Lote {$batch} — desfazer: php artisan scout:photos --revert={$batch}");
        }
    }

    private function report(): void
    {
        $base = Player::query()->whereNot(fn ($w) => $w->provisional());
        $total = (clone $base)->count();
        $stored = (clone $base)->whereNotNull('photo_path')->count();
        $external = (clone $base)->whereNull('photo_path')->whereNotNull('photo_url');
        $byStatus = (clone $external)->selectRaw("coalesce(photo_status, 'nao_verificada') as s, count(*) as n")->groupBy('s')->pluck('n', 's')->all();
        $none = (clone $base)->whereNull('photo_path')->whereNull('photo_url')->count();
        $pending = (clone $base)->whereNull('photo_path')->whereNull('photo_url')->whereHas('identityCandidates', fn ($q) => $q->where('status', 'pendente'))->count();
        $this->table(['Situação', 'Atletas', '% de '.$total], collect([
            'Foto armazenada (upload ou CBF)' => $stored,
            ...collect($byStatus)->mapWithKeys(fn ($n, $s) => ['URL externa · '.str_replace('_', ' ', $s) => $n])->all(),
            'Sem foto' => $none,
            '  dos quais com identidade pendente' => $pending,
        ])->map(fn ($n, $k) => [$k, $n, $total ? round(100 * $n / $total, 1).'%' : '—'])->values()->all());
        $this->line('Data: '.now(config('scout.display_timezone'))->format('d/m/Y H:i').'. URL preenchida não comprova imagem disponível: use --check.');
    }
}
