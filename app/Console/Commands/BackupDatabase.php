<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Backup lógico do Postgres (pg_dump -Fc) para o bucket privado.
 * Roda no pre-deploy (antes das migrações — se falhar, o deploy é abortado) e diariamente no worker.
 * Confere a integridade com pg_restore --list antes de enviar. Retenção: últimas N cópias.
 * O plano Hobby do Railway não oferece backup de volume; este é o backup do sistema.
 */
class BackupDatabase extends Command
{
    protected $signature = 'scout:backup-db {--tag=manual : rótulo no nome do arquivo} {--keep=30 : cópias mantidas}';

    protected $description = 'Gera backup do banco (pg_dump) e envia ao bucket privado';

    public const DIR = 'backups/db';

    public function handle(): int
    {
        $disk = (string) config('scout.backup_disk', 'media');
        if (config("filesystems.disks.{$disk}.driver") !== 's3' && ! app()->environment('testing', 'local')) {
            $this->error("[backup] disco '{$disk}' não é o bucket (S3): backup não pode ficar no container.");

            return self::FAILURE;
        }
        $c = config('database.connections.'.config('database.default'));
        $url = $c['url'] ?? null;
        $parts = $url ? parse_url($url) : [];
        $env = [
            'PGHOST' => $parts['host'] ?? $c['host'],
            'PGPORT' => (string) ($parts['port'] ?? $c['port']),
            'PGDATABASE' => isset($parts['path']) ? ltrim($parts['path'], '/') : $c['database'],
            'PGUSER' => isset($parts['user']) ? urldecode($parts['user']) : $c['username'],
            'PGPASSWORD' => isset($parts['pass']) ? urldecode($parts['pass']) : (string) $c['password'],
        ];
        $tag = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->option('tag')) ?: 'manual';
        $name = now()->utc()->format('Ymd-His').'-'.$tag.'.dump';
        $tmp = sys_get_temp_dir().'/cifut-'.$name;

        try {
            $dump = Process::timeout(900)->env($env)->run(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$tmp]);
            if (! $dump->successful()) {
                $this->error('[backup] pg_dump falhou: '.mb_substr(trim($dump->errorOutput()), 0, 400));

                return self::FAILURE;
            }
            $size = is_file($tmp) ? filesize($tmp) : 0;
            $check = Process::timeout(120)->run(['pg_restore', '--list', $tmp]);
            if ($size < 1024 || ! $check->successful() || ! str_contains($check->output(), 'TABLE DATA')) {
                $this->error("[backup] arquivo inválido ({$size} bytes); nada enviado.");

                return self::FAILURE;
            }
            $tables = substr_count($check->output(), 'TABLE DATA');
            $sha = hash_file('sha256', $tmp);
            $stream = fopen($tmp, 'rb');
            $ok = Storage::disk($disk)->put(self::DIR.'/'.$name, $stream);
            is_resource($stream) && fclose($stream);
            if (! $ok || Storage::disk($disk)->size(self::DIR.'/'.$name) !== $size) {
                $this->error('[backup] envio ao bucket falhou ou ficou incompleto.');

                return self::FAILURE;
            }
            Storage::disk($disk)->put(self::DIR.'/'.$name.'.sha256', $sha.'  '.$name."\n");
        } finally {
            @unlink($tmp);
        }

        $all = collect(Storage::disk($disk)->files(self::DIR))->filter(fn ($f) => str_ends_with($f, '.dump'))->sort()->values();
        $keep = max(3, (int) $this->option('keep'));
        foreach ($all->slice(0, max(0, $all->count() - $keep)) as $old) {
            Storage::disk($disk)->delete([$old, $old.'.sha256']);
        }
        $this->info(sprintf('[backup] %s · %.1f MB · %d tabelas com dados · sha256 %s · %d cópias no bucket',
            $name, $size / 1048576, $tables, substr($sha, 0, 12), min($all->count(), $keep)));

        return self::SUCCESS;
    }
}
