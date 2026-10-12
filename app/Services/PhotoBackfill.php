<?php

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Foto do atleta a partir da API-Football: o provedor envia a foto de cada atleta (guardada em provider_athletes),
 * mas ela só vai para o cadastro quando o vínculo é confiável (ID conferido, ou o cadastro provisório criado do próprio atleta).
 * Nunca sobrescreve foto enviada, foto da CBF nem foto de outra fonte.
 */
class PhotoBackfill
{
    /** @return Collection<int, object{id:string, name:string, photo_url:string}> */
    public static function candidates(?string $playerId = null): Collection
    {
        return collect(DB::select(<<<'SQL'
            select distinct on (p.id) p.id, p.name, pa.photo_url
            from players p
            join player_external_ids pe on pe.player_id = p.id and pe.provider = 'api_football'
                 and (pe.verified = true or pe.link_method = 'cadastro_provisorio')
            join provider_athletes pa on pa.provider = pe.provider and pa.external_id = pe.external_id
            where pa.photo_url is not null and p.photo_url is null and p.photo_path is null
              and (?::uuid is null or p.id = ?::uuid)
            order by p.id, pe.verified desc
            SQL, [$playerId, $playerId]));
    }

    /** Vínculo confiável novo: aplica direto no modelo (fica na auditoria do atleta). */
    public static function forPlayer(string $playerId): bool
    {
        $c = static::candidates($playerId)->first();
        if (! $c) {
            return false;
        }

        return Player::whereKey($playerId)->first()?->update(['photo_url' => $c->photo_url, 'photo_source' => 'api_football', 'photo_status' => null]) ?? false;
    }

    /** Lote: prévia (null) ou aplicação com trilha reversível. @return array{count:int, batch:?string} */
    public static function run(bool $apply = false, ?string $actor = null): array
    {
        $rows = static::candidates();
        if (! $apply) {
            return ['count' => $rows->count(), 'batch' => null, 'rows' => $rows];
        }
        $batch = DataFix::newBatch('photo');
        $n = 0;
        foreach ($rows as $r) {
            $n += DataFix::apply($batch, 'photo', 'players', $r->id, ['photo_url' => $r->photo_url, 'photo_source' => 'api_football'], $actor) ? 1 : 0;
        }

        return ['count' => $n, 'batch' => $batch, 'rows' => $rows];
    }
}
