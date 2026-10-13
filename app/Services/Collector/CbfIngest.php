<?php

namespace App\Services\Collector;

use App\Models\Agent;
use App\Models\CbfAthlete;
use App\Models\CbfIntermediary;
use App\Models\Club;
use App\Models\IdentityCandidate;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\SyncRun;
use App\Services\IdentityConvergence;
use App\Services\PlayerEnrichment;
use App\Support\ClubResolver;
use App\Support\NameMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Ingestão do que o coletor local traz da CBF.
 * Regra de ouro: só campos em lista branca são lidos; CPF/documentos são ignorados mesmo se vierem.
 * Não cria atletas no scout: mantém o registro CBF e vincula apenas quando a identidade é inequívoca.
 */
class CbfIngest
{
    public const PROVIDER = 'cbf';

    private const LIST_FIELDS = ['atleta_id', 'atleta_nome', 'atleta_apelido', 'clube_id', 'clube_nome_popular', 'clube_nome_completo', 'clube_uf', 'clube_escudo', 'exercicio'];

    private const PHOTO_DIR = 'players/cbf/';

    /** @param list<array<string,mixed>> $rows */
    public static function athleteList(array $rows, array $competition, SyncRun $run): array
    {
        $label = mb_substr((string) ($competition['label'] ?? $competition['slug'] ?? 'CBF'), 0, 80);
        $season = (string) ($competition['season'] ?? now()->year);
        $created = $updated = $linked = $discarded = 0;
        foreach ($rows as $raw) {
            $r = static::only($raw, self::LIST_FIELDS);
            $id = preg_replace('/\D/', '', (string) ($r['atleta_id'] ?? ''));
            if ($id === '' || blank($r['atleta_nome'] ?? null)) {
                $discarded++;

                continue;
            }
            $club = static::club($r);
            $a = CbfAthlete::firstOrNew(['cbf_id' => $id]);
            $comps = $a->competitions ?? [];
            $comps[$season] = array_values(array_unique(array_merge($comps[$season] ?? [], [$label])));
            $a->fill([
                'name' => static::clean($r['atleta_nome']),
                'nickname' => static::clean($r['atleta_apelido'] ?? null),
                'club_cbf_id' => (string) ($r['clube_id'] ?? '') ?: null,
                'club_name' => static::clean($r['clube_nome_popular'] ?? $r['clube_nome_completo'] ?? null),
                'club_state' => mb_substr((string) ($r['clube_uf'] ?? ''), 0, 2) ?: null,
                'club_id' => $club?->id,
                'competitions' => $comps,
                'last_seen_at' => now(),
            ]);
            $a->exists ? $updated++ : $created++;
            $a->save();
            if (! $a->player_id && static::link($a)) {
                $linked++;
            }
        }
        $run->received += count($rows);
        $run->created += $created;
        $run->updated += $updated;
        $run->discarded += $discarded;
        $run->save();

        return ['recebidos' => count($rows), 'novos' => $created, 'atualizados' => $updated, 'vinculados' => $linked, 'descartados' => $discarded];
    }

    /**
     * Ids CBF cujo detalhe (nascimento, foto, totais) está ausente ou velho. Primeiro os ligados ao scout (prioritários antes);
     * depois, registros ainda livres que podem confirmar identidade por convergência: mesmo clube de um atleta do scout sem
     * nascimento e nome compatível. Para esses só o nascimento é usado; nada vai ao cadastro sem a ligação.
     */
    public static function pendingDetails(int $limit = 150): array
    {
        $limit = max(1, min($limit, 500));
        $linked = CbfAthlete::query()
            ->join('players', 'players.id', '=', 'cbf_athletes.player_id')
            ->whereNull('players.deleted_at')
            ->where(fn ($q) => $q->whereNull('cbf_athletes.detail_fetched_at')->orWhere('cbf_athletes.detail_fetched_at', '<', now()->subDays(14)))
            ->orderByDesc('players.is_priority')->orderBy('cbf_athletes.detail_fetched_at')
            ->limit($limit)
            ->pluck('cbf_athletes.cbf_id')->all();
        if (count($linked) >= $limit) {
            return $linked;
        }

        return array_values(array_unique(array_merge($linked, static::convergenceCandidates($limit - count($linked)))));
    }

    /** Registros CBF livres, sem detalhe, do clube de atletas do scout sem nascimento (com candidato do Transfermarkt na fila) e nome compatível. */
    public static function convergenceCandidates(int $limit): array
    {
        // só quem já tem candidato do Transfermarkt na fila: sem ele, o nascimento da CBF não teria com quem convergir
        $byClub = Player::query()->whereNull('birth_date')->whereNotNull('current_club_id')->whereNot(fn ($w) => $w->provisional())
            ->whereHas('identityCandidates', fn ($c) => $c->where('provider', 'transfermarkt')->where('status', 'pendente'))
            ->orderByDesc('is_priority')->get(['id', 'name', 'full_name', 'current_club_id', 'is_priority'])->groupBy('current_club_id');
        if ($byClub->isEmpty()) {
            return [];
        }
        $ids = [];
        CbfAthlete::query()->whereNull('player_id')->whereNull('detail_fetched_at')->whereIn('club_id', $byClub->keys())
            ->orderBy('cbf_id')->get(['cbf_id', 'name', 'nickname', 'club_id'])
            ->each(function (CbfAthlete $a) use ($byClub, &$ids) {
                foreach ($byClub[$a->club_id] as $p) {
                    $score = max(NameMatcher::score($p->name, $a->nickname), NameMatcher::score($p->name, $a->name), NameMatcher::score($p->full_name, $a->name));
                    if ($score >= IdentityConvergence::CBF_NAME_MIN) {
                        $ids[$a->cbf_id] = $p->is_priority ? 0 : 1;
                        break;
                    }
                }
            });
        asort($ids);

        return array_map('strval', array_slice(array_keys($ids), 0, $limit)); // chave numérica vira int no PHP
    }

    public static function athleteDetail(array $raw, SyncRun $run): array
    {
        $id = preg_replace('/\D/', '', (string) ($raw['atleta_id'] ?? ''));
        $a = $id !== '' ? CbfAthlete::where('cbf_id', $id)->first() : null;
        if (! $a) {
            $run->discarded++;
            $run->save();

            return ['status' => 'desconhecido'];
        }
        $birth = static::date($raw['atleta_data_nascimento'] ?? null);
        $stats = static::only((array) ($raw['atleta_estatisticas'] ?? []), ['qtd_partidas', 'qtd_gols', 'qtd_cartoes_amarelos', 'qtd_cartoes_vermelhos']);
        $seasons = array_values(array_filter((array) ($raw['atletas_anos'] ?? []), fn ($y) => preg_match('/^\d{4}$/', (string) $y)));
        $season = $seasons ? (string) max($seasons) : (string) now()->year;
        $current = static::only((array) ($raw['atleta_time_atual'] ?? []), ['time_id', 'time_nome', 'time_nome_popular', 'time_uf', 'time_escudo']);

        $a->fill(['birth_date' => $birth, 'stats' => array_merge($a->stats ?? [], [$season => $stats]), 'detail_fetched_at' => now()]);
        if (filled($current['time_id'] ?? null)) {
            $club = static::club(['clube_id' => $current['time_id'], 'clube_nome_popular' => $current['time_nome_popular'] ?? null, 'clube_nome_completo' => $current['time_nome'] ?? null, 'clube_uf' => $current['time_uf'] ?? null, 'clube_escudo' => $current['time_escudo'] ?? null]);
            $a->fill(['club_cbf_id' => (string) $current['time_id'], 'club_name' => static::clean($current['time_nome_popular'] ?? $current['time_nome'] ?? null), 'club_id' => $club?->id]);
        }
        $a->save();

        $p = $a->player;
        $changes = $reviews = 0;
        if ($p && ! static::confirmByBirth($a, $p, $birth)) {
            $run->received++;
            $run->save();

            return ['status' => 'provavel']; // nada copiado para o cadastro antes de confirmar a identidade
        }
        if ($p) {
            foreach (['birth_date' => $birth?->toDateString(), 'full_name' => static::titleCase($a->name), 'current_club_id' => $a->club_id] as $field => $value) {
                if (in_array($field, ['current_club_id', 'full_name'], true) && filled($p->getRawOriginal($field))) {
                    continue; // troca de clube/grafia: só informativa (perfil mostra o registro CBF)
                }
                [$c, $r] = PlayerEnrichment::propose($p, $field, $value, self::PROVIDER);
                $changes += $c;
                $reviews += $r;
            }
            if (static::storePhoto($p, (string) ($raw['atleta_foto'] ?? ''))) {
                $changes++;
            }
            $p->enriched_at = now();
            $p->save();
            if ($stats) {
                // total publicado = uma linha por temporada (clube de inscrição atual); acha a existente mesmo com chave antiga
                $row = PlayerSeasonStat::firstOrNew(['player_id' => $p->id, 'provider' => self::PROVIDER, 'competition_key' => 'cbf-total', 'season' => $season]);
                $row->fill(['club_key' => PlayerSeasonStat::clubKey($a->club_cbf_id, $a->club_id, $a->club_name),
                    'club_id' => $a->club_id, 'club_external_id' => $a->club_cbf_id ?: null, 'club_source_name' => $a->club_name,
                    'club_name' => $a->club_id ? Club::whereKey($a->club_id)->value('name') : null, 'competition_name' => 'Competições CBF (total publicado)', 'appearances' => static::int($stats['qtd_partidas'] ?? null), 'goals' => static::int($stats['qtd_gols'] ?? null),
                    'yellow_cards' => static::int($stats['qtd_cartoes_amarelos'] ?? null), 'red_cards' => static::int($stats['qtd_cartoes_vermelhos'] ?? null),
                    'source_url' => 'https://www.cbf.com.br/futebol-brasileiro/atletas', 'fetched_at' => now()])->save();
            }
        }
        $run->received++;
        $run->updated += $p ? 1 : 0;
        $run->save();

        return ['status' => 'ok', 'alteracoes' => $changes, 'revisoes' => $reviews];
    }

    /** @param list<array<string,mixed>> $rows linhas da tabela pública (nome, tipo, publicação) */
    public static function intermediaries(array $rows, SyncRun $run): array
    {
        $created = $updated = $discarded = 0;
        foreach ($rows as $raw) {
            $r = static::only($raw, ['nome', 'tipo', 'publicacao']);
            $name = static::clean($r['nome'] ?? null);
            if (! $name || preg_match('/\d{3}\.?\d{3}\.?\d{3}-?\d{2}/', $name)) { // nunca gravar algo que pareça CPF
                $discarded++;

                continue;
            }
            $type = in_array($r['tipo'] ?? null, ['Física', 'Jurídica'], true) ? $r['tipo'] : null;
            $i = CbfIntermediary::firstOrNew(['normalized_name' => NameMatcher::normalize($name), 'person_type' => $type]);
            $i->fill(['name' => $name, 'published_on' => static::date($r['publicacao'] ?? null), 'last_seen_at' => now()]);
            $i->exists ? $updated++ : $created++;
            $i->save();
        }
        $run->received += count($rows);
        $run->created += $created;
        $run->updated += $updated;
        $run->discarded += $discarded;
        $run->save();

        return ['recebidos' => count($rows), 'novos' => $created, 'atualizados' => $updated, 'descartados' => $discarded];
    }

    /** Vincula empresários do cadastro ao registro CBF por nome exato (pessoa ou agência). */
    public static function linkAgents(): int
    {
        $n = 0;
        foreach (Agent::whereNull('cbf_intermediary_id')->get() as $ag) {
            foreach (array_filter([$ag->name, $ag->agency]) as $candidate) {
                $hits = CbfIntermediary::where('normalized_name', NameMatcher::normalize($candidate))->get();
                if ($hits->count() === 1) {
                    $ag->update(['cbf_intermediary_id' => $hits->first()->id]);
                    $n++;

                    break;
                }
            }
        }

        return $n;
    }

    /**
     * F14. Mesmo ID CBF no cadastro = vínculo confirmado (ID oficial).
     * Único atleta do scout no mesmo clube com nome compatível = vínculo PROVÁVEL: nada é copiado para o
     * cadastro até a data de nascimento conferir (no detalhe) ou alguém confirmar em "Identidades a revisar".
     */
    public static function link(CbfAthlete $a): bool
    {
        $p = Player::where('cbf_id', $a->cbf_id)->first();
        if ($p) {
            if (CbfAthlete::where('player_id', $p->id)->where('id', '!=', $a->id)->exists()) {
                return false;
            }
            $a->update(['player_id' => $p->id, 'link_status' => 'confirmado']);

            return true;
        }
        if (! $a->club_id) {
            return false;
        }
        $hits = Player::whereNull('cbf_id')->where('current_club_id', $a->club_id)->get()
            ->filter(fn (Player $x) => max(NameMatcher::score($x->name, $a->nickname), NameMatcher::score($x->name, $a->name), NameMatcher::score($x->full_name, $a->name)) >= 92);
        if ($hits->count() !== 1 || CbfAthlete::where('player_id', $hits->first()->id)->where('id', '!=', $a->id)->exists()) {
            return false;
        }
        $p = $hits->first();
        $a->update(['player_id' => $p->id, 'link_status' => 'provavel']);
        IdentityCandidate::updateOrCreate(['player_id' => $p->id, 'provider' => self::PROVIDER, 'external_id' => $a->cbf_id], [
            'kind' => 'confirmar', 'status' => 'pendente', 'score' => 0,
            'label' => trim(($a->nickname ? $a->nickname.' — ' : '').$a->name.' · '.($a->club_name ?? 'sem clube').' · CBF #'.$a->cbf_id),
            'evidence' => 'Mesmo clube de inscrição e nome compatível. Nascimento ainda não conferido.',
        ]);

        return true;
    }

    /** Vínculo provável vira confirmado quando o nascimento da CBF bate com o do cadastro. */
    private static function confirmByBirth(CbfAthlete $a, Player $p, ?Carbon $birth): bool
    {
        if ($a->link_status !== 'provavel') {
            return true;
        }
        $cand = IdentityCandidate::where('player_id', $p->id)->where('provider', self::PROVIDER)->where('external_id', $a->cbf_id)->first();
        if ($birth && $p->birth_date && $p->birth_date->toDateString() === $birth->toDateString()) {
            $a->update(['link_status' => 'confirmado']);
            $src = $p->field_sources ?? [];
            $src['cbf_id'] = ['source' => self::PROVIDER, 'at' => now()->toDateString(), 'method' => 'nome_clube_nascimento'];
            $p->forceFill(['cbf_id' => $a->cbf_id, 'field_sources' => $src])->save();
            $cand?->update(['status' => 'vinculado', 'decided_at' => now(), 'evidence' => 'Confirmado automaticamente: nascimento CBF = cadastro ('.$birth->format('d/m/Y').').']);

            return true;
        }
        $cand?->update(['evidence' => 'Mesmo clube e nome compatível. Nascimento CBF: '.($birth?->format('d/m/Y') ?? 'não informado')
            .' · cadastro: '.($p->birth_date?->format('d/m/Y') ?? 'sem data').'. Confirme olhando a foto/clube.',
            'details' => ['cbf_birth_date' => $birth?->toDateString(), 'club' => $a->club_name]]);

        return false;
    }

    private static function club(array $r): ?Club
    {
        $ext = (string) ($r['clube_id'] ?? '');
        if ($ext === '') {
            return null;
        }
        $state = mb_substr((string) ($r['clube_uf'] ?? ''), 0, 2) ?: null;
        $club = ClubResolver::resolve(self::PROVIDER, $ext, $r['clube_nome_popular'] ?? null, false, $state)
            ?? ClubResolver::resolve(self::PROVIDER, $ext, $r['clube_nome_completo'] ?? null, false, $state);
        $crest = (string) ($r['clube_escudo'] ?? '');
        if ($club && ! $club->crest_url && ! $club->crest_path && str_starts_with($crest, 'https://') && str_contains($crest, '.cbf.com.br/')) {
            $club->update(['crest_url' => $crest, 'crest_source' => self::PROVIDER]);
        }

        return $club;
    }

    /** Foto oficial: grava no bucket, sem sobrescrever upload feito pelo departamento. */
    private static function storePhoto(Player $p, string $dataUri): bool
    {
        if ($p->photo_path && ! str_starts_with($p->photo_path, self::PHOTO_DIR)) {
            return false;
        }
        if (! preg_match('#^data:image/(jpeg|jpg|png);base64,([A-Za-z0-9+/=\s]+)$#', $dataUri, $m)) {
            return false;
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bin === false || strlen($bin) < 1500 || strlen($bin) > 1_500_000 || ! @getimagesizefromstring($bin)) {
            return false; // vazio, placeholder minúsculo ou não é imagem
        }
        $path = self::PHOTO_DIR.$p->cbf_id.'-'.substr(sha1($bin), 0, 10).'.'.($m[1] === 'png' ? 'png' : 'jpg');
        if ($p->photo_path === $path) {
            return false;
        }
        Storage::disk('media')->put($path, $bin);
        if ($p->photo_path && $p->photo_path !== $path) {
            Storage::disk('media')->delete($p->photo_path);
        }
        $p->photo_path = $path;

        return true;
    }

    private static function only(array $raw, array $keys): array
    {
        return array_intersect_key($raw, array_flip($keys));
    }

    private static function clean(mixed $s): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags((string) $s)));

        return $s === '' ? null : mb_substr($s, 0, 200);
    }

    private static function titleCase(?string $s): ?string
    {
        return $s ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : null;
    }

    private static function date(mixed $s): ?Carbon
    {
        $s = trim((string) $s);
        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
