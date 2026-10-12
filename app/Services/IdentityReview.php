<?php

namespace App\Services;

use App\Models\Appearance;
use App\Models\AuditEvent;
use App\Models\CbfAthlete;
use App\Models\EnrichmentReview;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerSeasonStat;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Decisões humanas da fila "Identidades a revisar" (F14).
 * - escolher: o candidato escolhido vira vínculo confirmado (manual); os demais são descartados.
 * - confirmar: vínculo provável passa a confirmado; na CBF, o detalhe é buscado de novo e aplicado.
 * - mesclar: cadastro provisório (de escalação) é mesclado no cadastro do scout.
 * Descartar um vínculo provável desfaz o que veio daquela fonte (campos, foto, estatísticas, revisões).
 */
class IdentityReview
{
    public static function confirm(IdentityCandidate $c, User $user): string
    {
        static::authorize($user);

        return DB::transaction(function () use ($c, $user) {
            $c = IdentityCandidate::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'pendente') {
                return 'já decidido ('.$c->status.')';
            }
            $p = $c->player;
            $msg = match ($c->kind) {
                'mesclar' => static::merge($c, $p, $user),
                default => static::confirmLink($c, $p, $user),
            };
            if ($c->kind === 'escolher') {
                IdentityCandidate::where('player_id', $p->id)->where('provider', $c->provider)->where('status', 'pendente')
                    ->where('id', '!=', $c->id)->update(['status' => 'descartado', 'decided_by' => $user->id, 'decided_at' => now()]);
            }
            $c->update(['status' => 'vinculado', 'decided_by' => $user->id, 'decided_at' => now()]);
            AuditEvent::record('identity_confirm', $p, ['provider' => $c->provider, 'external_id' => $c->external_id, 'kind' => $c->kind]);

            return $msg;
        });
    }

    public static function discard(IdentityCandidate $c, User $user): string
    {
        static::authorize($user);

        return DB::transaction(function () use ($c, $user) {
            $c = IdentityCandidate::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'pendente') {
                return 'já decidido ('.$c->status.')';
            }
            $msg = 'Descartado.';
            if ($c->kind === 'confirmar') {
                $msg = static::unlink($c->player, $c->provider, (string) $c->external_id);
            } elseif ($c->kind === 'mesclar' && $c->shadow) {
                $c->shadow->update(['notes' => trim(($c->shadow->notes ? $c->shadow->notes."\n" : '').'Revisão: NÃO é '.$c->player->name.' (decidido em '.now()->format('d/m/Y').').')]);
                $msg = 'Cadastros mantidos separados.';
            }
            $c->update(['status' => 'descartado', 'decided_by' => $user->id, 'decided_at' => now()]);
            AuditEvent::record('identity_discard', $c->player, ['provider' => $c->provider, 'external_id' => $c->external_id, 'kind' => $c->kind]);

            return $msg;
        });
    }

    private static function confirmLink(IdentityCandidate $c, Player $p, User $user): string
    {
        if ($c->provider === 'cbf') {
            $a = CbfAthlete::where('cbf_id', $c->external_id)->firstOrFail();
            if ($other = Player::where('cbf_id', $a->cbf_id)->where('id', '!=', $p->id)->first()) {
                throw new \RuntimeException("ID CBF {$a->cbf_id} já pertence a {$other->name}. Verifique duplicata antes de confirmar.");
            }
            $a->update(['player_id' => $p->id, 'link_status' => 'confirmado', 'detail_fetched_at' => null]); // detalhe volta à fila e é aplicado
            if (! $p->cbf_id) {
                $src = $p->field_sources ?? [];
                $src['cbf_id'] = ['source' => 'cbf', 'at' => now()->toDateString(), 'reviewed_by' => $user->id];
                $p->forceFill(['cbf_id' => $a->cbf_id, 'field_sources' => $src])->save();
            }

            return "Vínculo CBF #{$a->cbf_id} confirmado; foto, nascimento e totais chegam na próxima coleta.";
        }
        $x = PlayerExternalId::where('provider', $c->provider)->where('external_id', $c->external_id)->first();
        if ($x && $x->player_id !== $p->id) {
            throw new \RuntimeException("Este ID já está ligado a outro atleta ({$x->player?->name}).");
        }
        PlayerExternalId::updateOrCreate(['provider' => $c->provider, 'external_id' => $c->external_id],
            ['player_id' => $p->id, 'verified' => true, 'verified_by' => $user->id, 'link_method' => 'manual', 'evidence' => $c->evidence ?? $c->label]);

        return "Vínculo {$c->provider} #{$c->external_id} confirmado.";
    }

    private static function merge(IdentityCandidate $c, Player $into, User $user): string
    {
        $shadow = $c->shadow ?? throw new \RuntimeException('Cadastro provisório não existe mais.');
        $r = PlayerMerge::merge($shadow, $into, $user->id);
        PlayerExternalId::where('provider', $c->provider)->where('external_id', $c->external_id)
            ->update(['player_id' => $into->id, 'verified' => true, 'verified_by' => $user->id, 'link_method' => 'manual']);

        return "Mesclado: {$r['moved']} registro(s) movido(s)".($r['dropped'] ? ', '.count($r['dropped']).' duplicado(s) descartado(s)' : '').'.';
    }

    /** Desfaz um vínculo e tudo o que veio dele no cadastro do atleta. */
    public static function unlink(Player $p, string $provider, string $externalId): string
    {
        $undone = [];
        $src = $p->field_sources ?? [];
        foreach ($src as $field => $meta) {
            if (($meta['source'] ?? null) === $provider && empty($meta['reviewed_by'])) {
                $p->{$field} = null;
                unset($src[$field]);
                $undone[] = $field;
            }
        }
        $p->field_sources = $src;
        if ($p->photo_source === $provider) {
            $p->photo_url = null;
            $p->photo_source = null;
        }
        if ($provider === 'cbf' && $p->photo_path && str_starts_with($p->photo_path, 'players/cbf/')) {
            Storage::disk('media')->delete($p->photo_path);
            $p->photo_path = null;
            $undone[] = 'foto';
        }
        $p->citizenships = collect($p->citizenships ?? [])->reject(fn ($x) => ($x['source'] ?? null) === $provider)->values()->all() ?: null;
        $p->save();
        PlayerSeasonStat::where('player_id', $p->id)->where('provider', $provider)->delete();
        // atuações que vieram por esse vínculo não são do atleta: vão para um cadastro provisório do ID externo
        $moved = static::detachAppearances($p, $provider, $externalId);
        if ($moved) {
            $undone[] = "{$moved} atuação(ões) movida(s) para cadastro provisório";
        }
        EnrichmentReview::where('player_id', $p->id)->where('provider', $provider)->where('status', 'pendente')
            ->update(['status' => 'rejeitado', 'decision_note' => 'Vínculo de identidade descartado.']);
        if ($provider === 'cbf') {
            CbfAthlete::where('cbf_id', $externalId)->where('player_id', $p->id)->update(['player_id' => null, 'link_status' => null]);
        } else {
            PlayerExternalId::where('provider', $provider)->where('external_id', $externalId)->where('player_id', $p->id)->delete();
        }

        return 'Vínculo descartado'.($undone ? '; desfeito: '.implode(', ', $undone) : '').'.';
    }

    /** Move as atuações do provedor para um provisório ligado ao ID externo e refaz os agregados dos dois. */
    private static function detachAppearances(Player $p, string $provider, string $externalId): int
    {
        if ($provider === 'cbf' || ! Appearance::where('player_id', $p->id)->where('provider', $provider)->exists()) {
            return 0;
        }
        $shadow = Player::create(['name' => $p->name.' (outro atleta — '.$provider.' #'.$externalId.')', 'data_origin' => 'provedor',
            'notes' => "Atuações separadas de {$p->name} ao descartar o vínculo {$provider} #{$externalId} em ".now()->format('d/m/Y').'.']);
        $n = Appearance::where('player_id', $p->id)->where('provider', $provider)->update(['player_id' => $shadow->id]);
        PlayerExternalId::where('provider', $provider)->where('external_id', $externalId)->delete();
        PlayerExternalId::create(['player_id' => $shadow->id, 'provider' => $provider, 'external_id' => $externalId, 'verified' => false, 'link_method' => 'cadastro_provisorio']);
        SeasonStatsAggregator::rebuildProvider($provider, [$p->id, $shadow->id]);

        return $n;
    }

    private static function authorize(User $user): void
    {
        if (! $user->is_active || ! $user->canEdit()) {
            throw new AuthorizationException('Seu perfil não pode decidir identidades.');
        }
    }
}
