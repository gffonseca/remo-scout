<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Foto do atleta a partir da API-Football: o provedor envia a foto de cada atleta (guardada em provider_athletes),
 * mas ela só vai para o cadastro quando o vínculo é confiável (ID conferido, ou o cadastro provisório criado do próprio atleta).
 * Nunca sobrescreve foto enviada nem foto de outra fonte.
 */
class PhotoBackfill
{
    public static function run(?string $playerId = null): int
    {
        return DB::update(<<<'SQL'
            update players p set photo_url = pa.photo_url, photo_source = 'api_football', updated_at = now()
            from player_external_ids pe
            join provider_athletes pa on pa.provider = pe.provider and pa.external_id = pe.external_id
            where pe.player_id = p.id and pe.provider = 'api_football'
              and (pe.verified = true or pe.link_method = 'cadastro_provisorio')
              and pa.photo_url is not null and p.photo_url is null and p.photo_path is null
              and (?::uuid is null or p.id = ?::uuid)
            SQL, [$playerId, $playerId]);
    }
}
