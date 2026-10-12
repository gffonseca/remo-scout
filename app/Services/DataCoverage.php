<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Por que o atleta está sem estatística ou sem foto — causa verificável e o que fazer.
 * Só atletas do scout (cadastros provisórios de partidas ficam fora).
 */
class DataCoverage
{
    public const STATS_REASONS = [
        'revisao' => ['Vínculo aguardando revisão', 'Há candidato de identidade pendente: aceite ou descarte em Revisões de identidade. Aceito, as partidas e a estatística passam para o atleta.'],
        'sem_clube' => ['Sem clube atual', 'Sem clube não há como achar o atleta nas partidas. Informe o clube.'],
        'clube_sem_partidas' => ['Clube fora das partidas coletadas', 'Nenhuma partida da temporada do clube nas fontes (ex.: Série D, estaduais, exterior). Use Fontes externas no perfil (Transfermarkt/Sofascore).'],
        'sem_nascimento' => ['Clube coberto, atleta sem nascimento', 'O clube tem partidas, mas o vínculo automático exige nome + nascimento. Informe o nascimento; o vínculo sai na próxima sincronização.'],
        'nao_encontrado' => ['Clube coberto, atleta não encontrado', 'O clube tem partidas e o atleta tem nascimento, mas nenhum atleta do provedor bate (não jogou, nome diferente ou outro clube). Confira o clube e o nome.'],
    ];

    public const PHOTO_REASONS = [
        'sem_vinculo' => ['Sem vínculo com fonte de foto', 'Foto vem da API-Football (vínculo confirmado), Transfermarkt ou Sofascore. Vincule o atleta ou envie a foto.'],
        'fonte_sem_foto' => ['Fonte vinculada sem foto', 'A fonte não tem foto deste atleta. Envie a foto pelo cadastro.'],
    ];

    /** @return array{players:int, no_stats:int, no_photo:int, stats:array<string,int>, photo:array<string,int>} */
    public static function summary(): array
    {
        $rows = static::rows();

        return [
            'players' => static::base()->count(),
            'no_stats' => $rows->whereNotNull('stats_reason')->count(),
            'no_photo' => $rows->whereNotNull('photo_reason')->count(),
            'stats' => $rows->whereNotNull('stats_reason')->countBy('stats_reason')->all(),
            'photo' => $rows->whereNotNull('photo_reason')->countBy('photo_reason')->all(),
        ];
    }

    private static function base()
    {
        return Player::query()->whereNot(fn ($w) => $w->provisional());
    }

    /** @return Collection<int, object{id:string,name:string,club:?string,priority:bool,stats_reason:?string,photo_reason:?string}> */
    public static function rows(): Collection
    {
        $season = now(config('scout.display_timezone'))->format('Y');
        $ids = static::base()->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return collect(DB::select(<<<'SQL'
            with p as (select * from players where id = any(?::uuid[])),
            covered as (
                select home_club_id as club_id from fixtures where season like ? || '%' and home_club_id is not null
                union select away_club_id from fixtures where season like ? || '%' and away_club_id is not null
            )
            select p.id, p.name, c.name as club, p.is_priority as priority,
              case
                when exists (select 1 from player_season_stats s where s.player_id = p.id) then null
                when exists (select 1 from identity_candidates ic where ic.player_id = p.id and ic.status = 'pendente') then 'revisao'
                when p.current_club_id is null then 'sem_clube'
                when p.current_club_id not in (select club_id from covered) then 'clube_sem_partidas'
                when p.birth_date is null then 'sem_nascimento'
                else 'nao_encontrado'
              end as stats_reason,
              case
                when p.photo_url is not null or p.photo_path is not null then null
                when exists (select 1 from player_external_ids e where e.player_id = p.id and (e.verified or e.provider in ('transfermarkt','sofascore'))) then 'fonte_sem_foto'
                else 'sem_vinculo'
              end as photo_reason
            from p left join clubs c on c.id = p.current_club_id
            order by p.is_priority desc, p.name
            SQL, ['{'.$ids->implode(',').'}', $season, $season]));
    }

    /** provedor da estatística → provedor do mapeamento de clubes */
    public const CLUB_MAP_PROVIDER = ['transfermarkt' => 'transfermarkt', 'cbf' => 'cbf'];

    /**
     * IDs de clube das fontes ainda sem clube canônico nas estatísticas (pendência de vínculo).
     *
     * @return Collection<int, object{provider:string, external_id:string, label:?string, rows:int, players:int, map_club:?string, map_method:?string}>
     */
    public static function unresolvedClubs(): Collection
    {
        return collect(DB::select(<<<'SQL'
            select s.provider, s.club_external_id as external_id, max(s.club_source_name) as label, count(*) as rows, count(distinct s.player_id) as players,
                   max(c.name) as map_club, max(m.method) as map_method
            from player_season_stats s
            left join club_external_ids m on m.provider = s.provider and m.external_id = s.club_external_id
            left join clubs c on c.id = m.club_id
            where s.club_id is null and s.club_external_id is not null
            group by s.provider, s.club_external_id
            order by count(distinct s.player_id) desc, max(s.club_source_name)
            SQL));
    }

    /** Vínculo confirmado por pessoa: grava o mapeamento e corrige as estatísticas com trilha (lote reversível). */
    public static function linkClub(string $provider, string $externalId, string $clubId, User $by): array
    {
        $mapProvider = self::CLUB_MAP_PROVIDER[$provider] ?? $provider;
        $club = Club::findOrFail($clubId);

        return DB::transaction(function () use ($provider, $mapProvider, $externalId, $club, $by) {
            $label = PlayerSeasonStat::where('provider', $provider)->where('club_external_id', $externalId)->whereNotNull('club_source_name')->value('club_source_name');
            ClubExternalId::updateOrCreate(['provider' => $mapProvider, 'external_id' => $externalId],
                ['club_id' => $club->id, 'method' => 'manual', 'confirmed_by' => $by->id, 'confirmed_at' => now(), 'external_name' => $label]);
            $batch = DataFix::newBatch('stats_club');
            $n = 0;
            foreach (PlayerSeasonStat::where('provider', $provider)->where('club_external_id', $externalId)->pluck('id') as $id) {
                $n += DataFix::apply($batch, 'stats_club', 'player_season_stats', $id, ['club_id' => $club->id, 'club_name' => $club->name], 'user:'.$by->id) ? 1 : 0;
            }

            return ['rows' => $n, 'batch' => $batch, 'club' => $club->name];
        });
    }
}
