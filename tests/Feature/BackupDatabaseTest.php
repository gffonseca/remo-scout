<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/** Backup do banco: só envia arquivo íntegro (pg_restore --list), mantém N cópias e falha alto (aborta o pre-deploy). */
class BackupDatabaseTest extends ScoutTestCase
{
    public function test_real_dump_is_verified_uploaded_and_rotated(): void
    {
        Storage::fake('media');
        foreach (range(1, 4) as $i) {
            Storage::disk('media')->put("backups/db/2026010{$i}-000000-antigo.dump", 'x');
        }
        // pg_dump real contra o banco de teste (mesma versão do cliente/servidor locais)
        $this->artisan('scout:backup-db', ['--tag' => 'teste', '--keep' => 3])->expectsOutputToContain('tabelas com dados')->assertSuccessful();
        $files = collect(Storage::disk('media')->files('backups/db'))->filter(fn ($f) => str_ends_with($f, '.dump'))->values();
        $this->assertCount(3, $files);
        $this->assertTrue($files->contains(fn ($f) => str_ends_with($f, '-teste.dump')));
        $this->assertFalse($files->contains('backups/db/20260101-000000-antigo.dump'));
        $dump = $files->first(fn ($f) => str_ends_with($f, '-teste.dump'));
        $this->assertStringStartsWith('PGDMP', Storage::disk('media')->get($dump));
        $this->assertTrue(Storage::disk('media')->exists($dump.'.sha256'));
    }

    public function test_failed_dump_fails_the_command_and_uploads_nothing(): void
    {
        Storage::fake('media');
        Process::fake(['*pg_dump*' => Process::result(errorOutput: 'connection refused', exitCode: 1)]);
        $this->artisan('scout:backup-db')->expectsOutputToContain('pg_dump falhou')->assertFailed();
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_invalid_archive_is_not_uploaded(): void
    {
        Storage::fake('media');
        Process::fake(['*pg_dump*' => Process::result(), '*pg_restore*' => Process::result(output: '', exitCode: 1)]);
        $this->artisan('scout:backup-db')->expectsOutputToContain('arquivo inválido')->assertFailed();
        $this->assertSame([], Storage::disk('media')->allFiles());
    }
}
