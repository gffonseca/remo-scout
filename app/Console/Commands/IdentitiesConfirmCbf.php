<?php

namespace App\Console\Commands;

use App\Services\CbfBulkConfirm;
use App\Services\DataFix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Confirma em lote os vínculos CBF "mesmo clube de inscrição e nome compatível" (decisão do Gustavo). Sem --apply é prévia. */
class IdentitiesConfirmCbf extends Command
{
    protected $signature = 'scout:identities-confirm-cbf {--apply} {--actor=} {--revert=}';

    protected $description = 'Confirma em lote vínculos CBF prováveis (mesmo clube + nome compatível), com prévia e reversão';

    public const CACHE = 'identity.cbf_confirm.preview';

    public function handle(): int
    {
        if ($batch = $this->option('revert')) {
            $r = DataFix::revert($batch);
            $this->info("Revertidas: {$r['reverted']}; já revertidas: {$r['already']}; conflitos: ".count($r['conflicts']).'.');

            return self::SUCCESS;
        }
        $rows = CbfBulkConfirm::evaluate();
        $actor = $this->option('actor') ?: 'cli';
        $userId = str_starts_with($actor, 'user:') ? (int) substr($actor, 5) : null;
        $res = $this->option('apply') ? CbfBulkConfirm::apply($rows, $userId, $actor) : null;
        Cache::put(self::CACHE, ['at' => now()->toDateTimeString(), 'applied' => (bool) $res, 'batch' => $res['batch'] ?? null,
            'rows' => $rows->map(fn ($r) => ['player' => $r['player'], 'cbf' => $r['cbf'], 'decision' => $r['decision'], 'reason' => $r['reason']])->values()->all()], now()->addDays(7));
        $ok = $rows->where('decision', 'confirmar')->count();
        $this->line($res ? "APLICADO: {$res['confirmed']} vínculo(s) CBF confirmados.".($res['batch'] ? " Lote {$res['batch']}." : '')
            : "PRÉVIA: {$ok} de {$rows->count()} seriam confirmados. Nada foi gravado.");
        foreach ($rows->where('decision', 'fila') as $r) {
            $this->line("  fila: {$r['player']} — {$r['reason']}");
        }

        return self::SUCCESS;
    }
}
