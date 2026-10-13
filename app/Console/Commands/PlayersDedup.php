<?php

namespace App\Console\Commands;

use App\Services\DataFix;
use App\Services\DuplicatePlayers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Fusão de cadastros duplicados (autorizada pelo Gustavo em 12/10/2026, em lote reversível). Sem --apply é prévia. */
class PlayersDedup extends Command
{
    protected $signature = 'scout:players-dedup {--apply} {--actor=} {--revert=}';

    protected $description = 'Encontra e funde cadastros duplicados do mesmo atleta (prévia, aplicação e reversão)';

    public const CACHE = 'players.dedup.preview';

    public function handle(): int
    {
        if ($batch = $this->option('revert')) {
            $r = DataFix::revert($batch);
            $this->info("Revertidas: {$r['reverted']}; já revertidas: {$r['already']}; conflitos: ".count($r['conflicts']).'.');

            return self::SUCCESS;
        }
        $conv = Cache::get(IdentitiesConverge::CACHE)['rows'] ?? [];
        $pairs = DuplicatePlayers::find($conv);
        $actor = $this->option('actor') ?: 'cli';
        $userId = str_starts_with($actor, 'user:') ? (int) substr($actor, 5) : null;
        $res = $this->option('apply') ? DuplicatePlayers::apply($pairs, $userId, $actor) : null;
        $rows = $pairs->map(fn ($p) => ['keep' => $p['keep']->name.' ('.substr($p['keep']->id, -6).')', 'drop' => $p['drop']->name.' ('.substr($p['drop']->id, -6).')', 'rule' => $p['rule']])->values()->all();
        Cache::put(self::CACHE, ['at' => now()->toDateTimeString(), 'applied' => (bool) $res, 'batch' => $res['batch'] ?? null, 'rows' => $rows], now()->addDays(7));
        foreach ($rows as $r) {
            $this->line("  {$r['drop']} → {$r['keep']} — {$r['rule']}");
        }
        $this->line($res
            ? "APLICADO: {$res['merged']} duplicado(s) fundido(s); {$res['kept_behind']} referência(s) iguais ficaram no arquivado.".($res['batch'] ? " Lote {$res['batch']}." : '')
            : 'PRÉVIA: '.count($rows).' par(es) de cadastros duplicados. Nada foi gravado.');

        return self::SUCCESS;
    }
}
