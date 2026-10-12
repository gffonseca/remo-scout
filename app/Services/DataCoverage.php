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
            covered as (
                select f.home_club_id as club_id, f.provider, f.data_quality from fixtures f where f.season like ? || '%' and f.home_club_id is not null
                union all select f.away_club_id, f.provider, f.data_quality from fixtures f where f.season like ? || '%' and f.away_club_id is not null
            )
            select p.id, p.name, c.name as club, p.is_priority as priority, st.last_fetch,
              case
                when st.has_recent and st.recent_partial then 'parcial'
                when st.has_recent and st.recent_fresh then 'disponivel'
                when st.n > 0 then 'desatualizado'
                when exists (select 1 from identity_candidates ic where ic.player_id = p.id and ic.status = 'pendente') then 'identidade_pendente'
                when p.current_club_id is null then 'nao_coletado'
                when not exists (select 1 from covered v where v.club_id = p.current_club_id) then 'fora_cobertura'
                when not exists (select 1 from covered v where v.club_id = p.current_club_id and v.provider <> all(?::text[])) then 'falha_bloqueio'
                when exists (select 1 from player_external_ids e join covered v on v.provider = e.provider and v.club_id = p.current_club_id and v.data_quality = 'completo'
                             where e.player_id = p.id and e.verified) then 'sem_participacao'
                else 'aguardando_vinculo'
              end as stats_state,
              case
                when p.photo_url is not null or p.photo_path is not null then null
                when exists (select 1 from player_external_ids e where e.player_id = p.id and (e.verified or e.provider in ('transfermarkt','sofascore'))) or p.cbf_id is not null then 'fonte_sem_foto'
                else 'sem_vinculo'
              end as photo_reason
            from p left join clubs c on c.id = p.current_club_id left join st on st.player_id = p.id
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
}
