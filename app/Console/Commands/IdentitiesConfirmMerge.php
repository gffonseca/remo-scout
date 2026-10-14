<?php

namespace App\Console\Commands;

use App\Services\DataFix;
use App\Services\MergeBulkConfirm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Funde em lote os provisórios "Mesmo clube e nome compatível" da fila (pedido do Gustavo, 13/10/2026). Sem --apply é prévia. */
class IdentitiesConfirmMerge extends Command
{
    protected $signature = 'scout:identities-confirm-merge {--apply} {--divergentes : funde também nascimento divergente (decisão do Gustavo, 14/10/2026); mantém o nascimento do cadastro do scout} {--actor=} {--revert=}';

    protected $description = 'Confirma em lote as mesclas de cadastros provisórios (mesmo clube + nome compatível), com prévia e reversão';

    public const CACHE = 'identity.merge_confirm.preview';

    public function handle(): int
    {
        if ($batch = $this->option('revert')) {
            $r = DataFix::revert($batch);
            MergeBulkConfirm::afterRevert($batch);
            $this->info("Revertidas: {$r['reverted']}; já revertidas: {$r['already']}; conflitos: ".count($r['conflicts']).'.');

            return self::SUCCESS;
        }
        $rows = MergeBulkConfirm::evaluate((bool) $this->option('divergentes'));
        $actor = $this->option('actor') ?: 'cli';
        $userId = str_starts_with($actor, 'user:') ? (int) substr($actor, 5) : null;
        $res = $this->option('apply') ? MergeBulkConfirm::apply($rows, $userId, $actor) : null;
        Cache::put(self::CACHE, ['at' => now()->toDateTimeString(), 'applied' => (bool) $res, 'batch' => $res['batch'] ?? null,
            'rows' => $rows->map(fn ($r) => ['label' => $r['label'], 'decision' => $r['decision'], 'reason' => $r['reason']])->values()->all()], now()->addDays(7));
        $ok = $rows->where('decision', 'confirmar')->count();
        $closing = $rows->where('decision', 'fechar')->count();
        $this->line($res ? "APLICADO: {$res['merged']} provisório(s) fundido(s); ".($res['closed'] ?? 0).' item(ns) já fundido(s) fechado(s).'.($res['batch'] ? " Lote {$res['batch']}." : '')
            : "PRÉVIA: {$ok} de {$rows->count()} seriam fundidos; {$closing} já fundido(s) seriam fechados. Nada foi gravado.");
        foreach ($rows->where('decision', 'fila') as $r) {
            $this->line("  fila: {$r['label']} — {$r['reason']}");
        }

        return self::SUCCESS;
    }
}
