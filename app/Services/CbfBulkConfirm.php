<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CbfAthlete;
use App\Models\IdentityCandidate;
use App\Models\Player;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Confirmação em lote, decidida pelo Gustavo (12/10/2026): vínculos CBF prováveis da fila "Identidades a revisar"
 * com a constatação "mesmo clube de inscrição e nome compatível" passam a confirmados.
 * Fica de fora quem tem nascimento no cadastro diferente do da CBF (sinal de outro atleta) e ID CBF já usado por outro cadastro.
 * Lote reversível (data_fix_changes); depois disso o coletor busca foto, nascimento e totais na próxima rodada de detalhes.
 */
class CbfBulkConfirm
{
    /** @return Collection<int, array{candidate:IdentityCandidate, player:string, cbf:string, decision:string, reason:string}> */
    public static function evaluate(bool $includeDivergent = false): Collection
    {
        return IdentityCandidate::with('player')->where('provider', 'cbf')->where('kind', 'confirmar')->where('status', 'pendente')
            ->where('evidence', 'like', 'Mesmo clube%nome compatível%')->orderBy('label')->get()
            ->map(function (IdentityCandidate $c) use ($includeDivergent) {
                $p = $c->player;
                $a = CbfAthlete::where('cbf_id', $c->external_id)->first();
                $row = ['candidate' => $c, 'player' => $p?->name ?? '?', 'cbf' => $c->label];
                if (! $p || ! $a) {
                    return $row + ['decision' => 'fila', 'reason' => 'Cadastro ou registro CBF não existe mais.'];
                }
                if ($a->player_id && $a->player_id !== $p->id) {
                    return $row + ['decision' => 'fila', 'reason' => 'Registro CBF ligado a outro cadastro.'];
                }
                if (Player::where('cbf_id', $a->cbf_id)->where('id', '!=', $p->id)->exists()) {
                    return $row + ['decision' => 'fila', 'reason' => 'ID CBF já pertence a outro cadastro.'];
                }
                $cbfBirth = $a->birth_date?->toDateString() ?? ($c->details['cbf_birth_date'] ?? null);
                if ($p->birth_date && $cbfBirth && $p->birth_date->toDateString() !== $cbfBirth) {
                    $why = 'Nascimento diverge: CBF '.Carbon::parse($cbfBirth)->format('d/m/Y').' × cadastro '.$p->birth_date->format('d/m/Y').'.';

                    return $includeDivergent
                        ? $row + ['decision' => 'confirmar', 'reason' => $why.' Confirmado por decisão do Gustavo; cadastro passa a usar o nascimento da CBF.', 'birth' => $cbfBirth]
                        : $row + ['decision' => 'fila', 'reason' => $why];
                }

                return $row + ['decision' => 'confirmar', 'reason' => 'Mesmo clube de inscrição e nome compatível.'];
            });
    }

    /** @return array{batch:?string, confirmed:int} */
    public static function apply(Collection $rows, ?int $userId, string $actor): array
    {
        $todo = $rows->where('decision', 'confirmar');
        if ($todo->isEmpty()) {
            return ['batch' => null, 'confirmed' => 0];
        }
        $batch = DataFix::newBatch('identity_cbf');

        return DB::transaction(function () use ($todo, $batch, $userId, $actor) {
            $n = 0;
            foreach ($todo as $r) {
                $c = IdentityCandidate::whereKey($r['candidate']->id)->lockForUpdate()->first();
                if (! $c || $c->status !== 'pendente') {
                    continue;
                }
                $p = $c->player;
                $a = CbfAthlete::where('cbf_id', $c->external_id)->first();
                $now = now()->toDateTimeString();
                DataFix::apply($batch, 'identity_cbf', 'cbf_athletes', $a->id, ['player_id' => $p->id, 'link_status' => 'confirmado', 'detail_fetched_at' => null], $actor);
                $src = $p->field_sources ?? [];
                $change = [];
                if (! $p->cbf_id) {
                    $src['cbf_id'] = ['source' => 'cbf', 'at' => now()->toDateString(), 'reviewed_by' => $userId, 'method' => 'confirmacao_em_lote'];
                    $change['cbf_id'] = $a->cbf_id;
                }
                if (! empty($r['birth'])) { // divergente confirmado: vale o nascimento da CBF (o anterior fica no "antes" do lote)
                    $src['birth_date'] = ['source' => 'cbf', 'at' => now()->toDateString(), 'reviewed_by' => $userId, 'method' => 'confirmacao_em_lote',
                        'previous' => $p->birth_date?->toDateString()];
                    $change['birth_date'] = $r['birth'];
                }
                if ($change) {
                    DataFix::apply($batch, 'identity_cbf', 'players', $p->id, $change + ['field_sources' => json_encode($src)], $actor);
                }
                DataFix::apply($batch, 'identity_cbf', 'identity_candidates', $c->id, ['status' => 'vinculado', 'decided_by' => $userId, 'decided_at' => $now,
                    'evidence' => trim(($c->evidence ?? '').' Confirmado em lote (mesmo clube de inscrição e nome compatível).')], $actor);
                AuditEvent::record('identity_confirm', $p, ['provider' => 'cbf', 'external_id' => $c->external_id, 'kind' => 'confirmar', 'batch' => $batch]);
                $n++;
            }

            return ['batch' => $batch, 'confirmed' => $n];
        });
    }
}
