<?php

namespace App\Console\Commands;

use App\Services\DataFix;
use App\Services\IdentityConvergence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Ligação por convergência CBF × Transfermarkt (nascimento igual nas duas fontes + mesmo clube). Sem --apply é prévia. */
class IdentitiesConverge extends Command
{
    protected $signature = 'scout:identities-converge {--apply} {--search : busca no Transfermarkt quem ainda não tem candidatos} {--limit=60} {--actor=} {--revert=}';

    protected $description = 'Liga atletas sem nascimento quando CBF e Transfermarkt concordam (prévia, aplicação e reversão)';

    public const CACHE = 'identity.convergence.preview';

    public function handle(): int
    {
        if ($batch = $this->option('revert')) {
            $r = DataFix::revert($batch);
            $this->info("Revertidas: {$r['reverted']}; já revertidas: {$r['already']}; conflitos: ".count($r['conflicts']).'.');

            return self::SUCCESS;
        }
        $rows = IdentityConvergence::evaluate((bool) $this->option('search'), (int) $this->option('limit'));
        $link = collect($rows)->where('decision', 'ligar');
        Cache::put(self::CACHE, ['at' => now()->toDateTimeString(), 'applied' => (bool) $this->option('apply'), 'rows' => $rows], now()->addDays(7));
        $this->table(['Atleta', 'Clube', 'Nascimento', 'Decisão', 'Motivo'], collect($rows)->map(fn ($r) => [$r['player'], $r['club'] ?? '—', $r['birth'] ?? '—', $r['decision'], $r['reason']])->all());
        if (! $this->option('apply')) {
            $this->line("PRÉVIA: {$link->count()} de ".count($rows).' atleta(s) seriam ligados. Nada foi gravado.');

            return self::SUCCESS;
        }
        $r = IdentityConvergence::apply($rows, $this->option('actor') ?: 'cli');
        $this->line("APLICADO: {$r['linked']} atleta(s) ligados.".($r['batch'] ? " Lote {$r['batch']} — desfazer: php artisan scout:identities-converge --revert={$r['batch']}" : ''));

        return self::SUCCESS;
    }
}
