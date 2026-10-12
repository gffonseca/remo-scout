<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubExternalId;
use App\Models\DataSource;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Por que o atleta está sem estatística ou sem foto — causa verificável e o que fazer.
 * Só atletas do scout (cadastros provisórios de partidas ficam fora).
 */
class DataCoverage
{
    /**
     * Estado da estatística por atleta (prompt mestre 3.6). Ordem = precedência.
     * Ausência de estatística nunca é "não jogou": "sem participação confirmada" exige vínculo confirmado e escalações completas do clube.
     */
    public const STATS_STATES = [
        'disponivel' => ['Disponível', 'Há estatística desta temporada ou da anterior com jogos ou minutos, coletada há menos de 30 dias.'],
        'parcial' => ['Parcial', 'Há estatística recente, mas com minutos ou participação indeterminados (fonte incompleta). Mostrada com "≥"/"indet.".'],
        'desatualizado' => ['Desatualizado', 'Só há estatística de temporadas antigas, ou a recente foi coletada há mais de 30 dias. Reprocessar a fonte.'],
        'identidade_pendente' => ['Identidade pendente', 'Há candidato de identidade aguardando revisão. Aceito, partidas e estatística passam para o atleta.'],
        'falha_bloqueio' => ['Falha ou bloqueio da fonte', 'O clube é coberto, mas a fonte está pausada (circuito aberto) ou bloqueada agora. Aguarde a reabertura; não é ausência de jogo.'],
        'sem_participacao' => ['Sem participação confirmada', 'Vínculo confirmado com a fonte e escalações completas do clube na temporada, sem o atleta. É evidência de não ter jogado nessas partidas — não de desempenho.'],
        'aguardando_vinculo' => ['Aguardando vínculo', 'O clube tem partidas coletadas, mas o atleta ainda não foi ligado à fonte (sem nascimento para o vínculo automático, ou nome diferente).'],
        'fora_cobertura' => ['Fora de cobertura', 'Nenhuma partida da temporada do clube nas fontes (ex.: Série D, estaduais, exterior). Use Fontes externas no perfil.'],
        'nao_coletado' => ['Não coletado', 'Sem clube atual: não há como achar o atleta nas partidas. Informe o clube.'],
    ];

    /** Estados que contam como "sem estatística utilizável". */
    public const MISSING = ['identidade_pendente', 'falha_bloqueio', 'sem_participacao', 'aguardando_vinculo', 'fora_cobertura', 'nao_coletado'];

    public const PHOTO_REASONS = [
        'sem_vinculo' => ['Sem vínculo com fonte de foto', 'Foto vem da API-Football (vínculo confirmado), CBF, Transfermarkt ou Sofascore. Vincule o atleta ou envie a foto.'],
        'fonte_sem_foto' => ['Fonte vinculada sem foto', 'A fonte não tem foto deste atleta. Envie a foto pelo cadastro.'],
    ];

    /** @return array{players:int, states:array<string,int>, photo:array<string,int>, with_photo:int} */
    public static function summary(): array
    {
        $rows = static::rows();

        return [
            'players' => $rows->count(),
            'states' => $rows->countBy('stats_state')->all(),
            'photo' => $rows->whereNotNull('photo_reason')->countBy('photo_reason')->all(),
            'with_photo' => $rows->whereNull('photo_reason')->count(),
        ];
    }

    private static function base()
    {
        return Player::query()->whereNot(fn ($w) => $w->provisional());
    }

    /** @return Collection<int, object{id:string,name:string,club:?string,priority:bool,stats_state:string,last_fetch:?string,photo_reason:?string}> */
    public static function rows(): Collection
    {
        $tz = config('scout.display_timezone');
        $year = (int) now($tz)->format('Y');
        $ids = static::base()->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $blocked = DataSource::all()->filter(fn ($d) => $d->circuitOpen() || $d->health === 'bloqueado')->pluck('key')->all();

        return collect(DB::select(<<<'SQL'
            with p as (select * from players where id = any(?::uuid[])),
            st as (
                select s.player_id,
                  bool_or(s.recent and (s.appearances is not null or s.minutes is not null)) as has_recent,
                  bool_or(s.recent and (s.minutes is null and coalesce(s.appearances, 0) > 0
                      or coalesce((s.extra->>'participacao_indeterminada')::int, 0) > 0)) as recent_partial,
                  bool_or(s.recent and s.fetched_at >= now() - interval '30 days') as recent_fresh,
                  count(*) as n, max(s.fetched_at) as last_fetch
                from (select *, (case when substring(season from '(\d{2,4})\D*$') ~ '^\d{2}$' then 2000 + substring(season from '(\d{2,4})\D*$')::int
                        else substring(season from '(\d{2,4})\D*$')::int end) >= ? as recent from player_season_stats) s
                group by s.player_id
            ),
            covered as materialized (
                select club_id, provider, bool_or(data_quality = 'completo') as complete
                from (select f.home_club_id as club_id, f.provider, f.data_quality from fixtures f where f.season like ? || '%' and f.home_club_id is not null
                      union all select f.away_club_id, f.provider, f.data_quality from fixtures f where f.season like ? || '%' and f.away_club_id is not null) x
                group by club_id, provider
            ),
            cov as materialized (
                select club_id, array_agg(provider) as providers, array_agg(provider) filter (where complete) as complete_providers
                from covered group by club_id
            ),
            pend as materialized (select distinct player_id from identity_candidates where status = 'pendente'),
            ext as materialized (select player_id, array_agg(provider) filter (where verified) as verified_providers,
                                        bool_or(verified or provider in ('transfermarkt','sofascore')) as photo_link
                                 from player_external_ids group by player_id)
            select p.id, p.name, c.name as club, p.is_priority as priority, st.last_fetch,
              case
                when st.has_recent and st.recent_partial then 'parcial'
                when st.has_recent and st.recent_fresh then 'disponivel'
                when st.n > 0 then 'desatualizado'
                when pend.player_id is not null then 'identidade_pendente'
                when p.current_club_id is null then 'nao_coletado'
                when cov.club_id is null then 'fora_cobertura'
                when not (cov.providers && array(select unnest(cov.providers) except select unnest(?::text[]))) then 'falha_bloqueio'
                when ext.verified_providers && cov.complete_providers then 'sem_participacao'
                else 'aguardando_vinculo'
              end as stats_state,
              case
                when p.photo_url is not null or p.photo_path is not null then null
                when coalesce(ext.photo_link, false) or p.cbf_id is not null then 'fonte_sem_foto'
                else 'sem_vinculo'
              end as photo_reason
            from p left join clubs c on c.id = p.current_club_id left join st on st.player_id = p.id
              left join cov on cov.club_id = p.current_club_id left join pend on pend.player_id = p.id left join ext on ext.player_id = p.id
            order by p.is_priority desc, p.name
            SQL, ['{'.$ids->implode(',').'}', $year - 1, (string) $year, (string) $year, '{'.implode(',', $blocked).'}']));
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
            $batch = DataFix::newBatch('stats_club');
            $actor = 'user:'.$by->id;
            // o vínculo também entra na trilha: reverter o lote desfaz o vínculo e não deixa a fonte religar sozinha
            $map = ClubExternalId::where('provider', $mapProvider)->where('external_id', $externalId)->first();
            $mapData = ['club_id' => $club->id, 'method' => 'manual', 'confirmed_by' => $by->id, 'confirmed_at' => now()->toDateTimeString(), 'external_name' => $label ?? $map?->external_name];
            if ($map) {
                DataFix::apply($batch, 'club_map', 'club_external_ids', $map->id, $mapData, $actor);
            } else {
                $map = ClubExternalId::create(['provider' => $mapProvider, 'external_id' => $externalId] + $mapData);
                DataFix::recordCreated($batch, 'club_map', 'club_external_ids', $map->id, $actor);
            }
            $n = 0;
            $skipped = PlayerSeasonStat::where('provider', $provider)->where('club_external_id', $externalId)->whereNotNull('club_id')->where('club_id', '!=', $club->id)->count();
            foreach (PlayerSeasonStat::where('provider', $provider)->where('club_external_id', $externalId)->whereNull('club_id')->pluck('id') as $id) {
                $n += DataFix::apply($batch, 'stats_club', 'player_season_stats', $id, ['club_id' => $club->id, 'club_name' => $club->name], $actor) ? 1 : 0;
            }
            Cache::forget('coverage.rows');

            return ['rows' => $n, 'batch' => $batch, 'club' => $club->name, 'skipped' => $skipped];
        });
    }

    /** Última execução e última execução concluída por fonte (para "última tentativa bem-sucedida"). */
    public static function sourceRuns(): Collection
    {
        return DataSource::orderBy('name')->get()->map(function ($d) {
            $last = SyncRun::where('data_source_id', $d->id)->latest('started_at')->first();
            $ok = SyncRun::where('data_source_id', $d->id)->whereIn('status', ['concluido', 'parcial'])->latest('finished_at')->first();

            return (object) ['key' => $d->key, 'name' => $d->name, 'enabled' => (bool) $d->enabled, 'running' => $last?->status === 'em_execucao',
                'last_status' => $last?->status, 'last_at' => $last?->started_at, 'ok_at' => $ok?->finished_at, 'blocked' => $d->circuitOpen() || $d->health === 'bloqueado'];
        })->filter(fn ($r) => $r->enabled || $r->last_at)->values();
    }

    /** Prévia (sem gravar) do backfill de clube das estatísticas. @return array{vincular:int, a_confirmar:int, sem_vinculo:int, sem_rotulo:int} */
    public static function clubBackfillPreview(): array
    {
        $trusted = "'".implode("','", ClubExternalId::TRUSTED)."'";
        $r = DB::selectOne(<<<SQL
            select
              count(*) filter (where m.id is not null and (m.confirmed_at is not null or m.method in ({$trusted}))) as vincular,
              count(*) filter (where m.id is not null and not (m.confirmed_at is not null or m.method in ({$trusted}))) as a_confirmar,
              count(*) filter (where m.id is null) as sem_vinculo,
              count(*) filter (where s.club_source_name is null) as sem_rotulo
            from player_season_stats s
            left join club_external_ids m on m.provider = s.provider and m.external_id = s.club_external_id
            where s.club_id is null and s.club_external_id is not null
            SQL);

        return array_map('intval', (array) $r);
    }

    /** Lotes de correção (trilha), mais recentes primeiro. */
    public static function batches(int $limit = 20): Collection
    {
        return collect(DB::select(<<<'SQL'
            select batch, min(kind) as kind, count(*) as rows, count(reverted_at) as reverted, min(actor) as actor, min(created_at) as at
            from data_fix_changes group by batch order by min(created_at) desc limit ?
            SQL, [$limit]));
    }
}
