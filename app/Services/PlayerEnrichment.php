<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\ContractObservation;
use App\Models\EnrichmentReview;
use App\Models\IdentityCandidate;
use App\Models\MarketObservation;
use App\Models\Player;
use App\Models\PlayerExternalId;
use App\Models\PlayerSeasonStat;
use App\Sources\Sofascore;
use App\Sources\SourceException;
use App\Sources\Transfermarkt;
use App\Support\ClubResolver;
use App\Support\NameMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Vinculação e enriquecimento de atletas por fontes externas.
 * Regras: (1) vínculo automático só com correspondência forte e inequívoca; caso contrário candidatos para revisão;
 * (2) campos vazios são preenchidos e marcados com a fonte; campos divergentes viram revisão — nunca sobrescreve em silêncio;
 * (3) estatísticas ficam por provedor (não somar); (4) nada de saúde/lesões.
 */
class PlayerEnrichment
{
    /** @return array{status:string,message:string} */
    public static function linkTransfermarkt(Player $p, ?string $forceId = null): array
    {
        $tm = new Transfermarkt;
        if ($forceId) {
            return static::attach($p, Transfermarkt::PROVIDER, $forceId, 'Vinculado manualmente');
        }
        if ($id = $p->externalId(Transfermarkt::PROVIDER)) {
            return ['status' => 'ja_vinculado', 'message' => "Já vinculado ao Transfermarkt #{$id}"];
        }
        $results = $tm->search($p->name);
        if ($p->full_name && count($results) === 0) {
            $results = $tm->search($p->full_name);
        }
        $scored = collect($results)->map(function ($r) use ($p) {
            $score = NameMatcher::score($p->name, $r['name']);
            if ($p->full_name) {
                $score = max($score, NameMatcher::score($p->full_name, $r['name']));
            }
            $clubScore = $p->currentClub ? NameMatcher::clubScore($p->currentClub->name, $r['club']['name'] ?? '') : 0;
            $agePenalty = ($p->age !== null && isset($r['age'])) ? (abs($p->age - (int) $r['age']) <= 1 ? 10 : -40) : 0;
            $total = $score + ($clubScore >= 50 ? 15 : ($p->currentClub ? -10 : 0)) + $agePenalty;

            return ['r' => $r, 'score' => max(0, min(130, $total)), 'club_ok' => $clubScore >= 50];
        })->sortByDesc('score')->values();

        return static::decide($p, Transfermarkt::PROVIDER, $scored, fn ($r) => $r['name'].' — '.($r['club']['name'] ?? 'sem clube').' · '.($r['position'] ?? '?').' · '.($r['age'] ?? '?').' anos', fn ($r) => (string) $r['id'],
            fn ($r) => $tm->profile((string) $r['id'])['dateOfBirth'] ?? null);
    }

    /** @return array{status:string,message:string} */
    public static function linkSofascore(Player $p, ?string $forceId = null): array
    {
        if ($forceId) {
            return static::attach($p, Sofascore::PROVIDER, $forceId, 'Vinculado manualmente');
        }
        if ($id = $p->externalId(Sofascore::PROVIDER)) {
            return ['status' => 'ja_vinculado', 'message' => "Já vinculado ao Sofascore #{$id}"];
        }
        $results = (new Sofascore)->search($p->name);
        $scored = collect($results)->map(function ($r) use ($p) {
            $score = NameMatcher::score($p->name, $r['name'] ?? '');
            if (! empty($r['shortName'])) {
                $score = max($score, NameMatcher::score($p->name, $r['shortName']));
            }
            $clubScore = $p->currentClub ? NameMatcher::clubScore($p->currentClub->name, $r['team']['name'] ?? '') : 0;
            $total = $score + ($clubScore >= 50 ? 20 : ($p->currentClub ? -10 : 0));

            return ['r' => $r, 'score' => max(0, min(130, $total)), 'club_ok' => $clubScore >= 50];
        })->sortByDesc('score')->values();

        return static::decide($p, Sofascore::PROVIDER, $scored, fn ($r) => ($r['name'] ?? '?').' — '.($r['team']['name'] ?? 'sem clube').' · '.($r['position'] ?? '?'), fn ($r) => (string) $r['id'],
            fn ($r) => isset($r['dateOfBirthTimestamp']) ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $r['dateOfBirthTimestamp'])->toDateString() : null);
    }

    /**
     * F14: vínculo automático só com nome compatível E data de nascimento igual à do cadastro.
     * Sem nascimento no cadastro (ou na fonte), ou com divergência, os candidatos vão para "Identidades a revisar".
     */
    private static function decide(Player $p, string $provider, $scored, callable $label, callable $idOf, ?callable $dobOf = null): array
    {
        if ($scored->isEmpty()) {
            return ['status' => 'sem_resultado', 'message' => 'Nenhum resultado na fonte para "'.$p->name.'".'];
        }
        $top = $scored->first();
        $second = $scored->get(1);
        $distinct = ! $second || $top['score'] - $second['score'] >= 15;
        $evidence = null;
        if ($top['score'] >= 88 && $distinct && $p->birth_date && $dobOf) {
            $dob = $dobOf($top['r']);
            $dob = $dob ? substr((string) $dob, 0, 10) : null;
            if ($dob === $p->birth_date->toDateString()) {
                return static::attach($p, $provider, $idOf($top['r']), 'Vínculo automático (nome + nascimento '.$p->birth_date->format('d/m/Y').')', 'nome_nascimento');
            }
            $evidence = $dob ? 'Nascimento na fonte '.\Illuminate\Support\Carbon::parse($dob)->format('d/m/Y').' ≠ cadastro '.$p->birth_date->format('d/m/Y') : 'Fonte não informa nascimento';
        } elseif (! $p->birth_date) {
            $evidence = 'Cadastro sem data de nascimento: vínculo só por revisão humana.';
        }
        foreach ($scored->take(6) as $c) {
            IdentityCandidate::updateOrCreate(
                ['player_id' => $p->id, 'provider' => $provider, 'external_id' => $idOf($c['r'])],
                ['label' => mb_substr($label($c['r']), 0, 250), 'details' => $c['r'], 'score' => $c['score'], 'status' => 'pendente', 'kind' => 'escolher',
                    'evidence' => $c === $top ? $evidence : null],
            );
        }

        return ['status' => 'revisar', 'message' => min(6, $scored->count()).' candidato(s) em "Identidades a revisar"'.($evidence ? " ({$evidence})" : '').'.'];
    }

    private static function attach(Player $p, string $provider, string $externalId, string $how, string $method = 'manual'): array
    {
        $existing = PlayerExternalId::where('provider', $provider)->where('external_id', $externalId)->first();
        if ($existing && $existing->player_id !== $p->id) {
            return ['status' => 'conflito', 'message' => "Este id já está vinculado a outro atleta ({$existing->player?->name}). Verifique possível duplicata."];
        }
        // Só chega aqui com escolha humana (manual) ou nome + nascimento: ambos são vínculos confirmados.
        PlayerExternalId::updateOrCreate(['provider' => $provider, 'external_id' => $externalId],
            ['player_id' => $p->id, 'verified' => true, 'verified_by' => $method === 'manual' ? auth()->id() : null, 'link_method' => $method, 'evidence' => $how]);
        IdentityCandidate::where('player_id', $p->id)->where('provider', $provider)->update(['status' => 'descartado']);
        IdentityCandidate::where('player_id', $p->id)->where('provider', $provider)->where('external_id', $externalId)->update(['status' => 'vinculado']);

        return ['status' => 'vinculado', 'message' => "{$how}: {$provider} #{$externalId}"];
    }

    /** Puxa perfil + estatísticas do Transfermarkt para atleta já vinculado. */
    public static function enrichTransfermarkt(Player $p): array
    {
        $id = $p->externalId(Transfermarkt::PROVIDER);
        if (! $id) {
            return ['status' => 'sem_vinculo', 'message' => 'Atleta sem vínculo com o Transfermarkt.'];
        }
        $tm = new Transfermarkt;
        $prof = $tm->profile($id);
        $stats = [];
        try {
            $stats = $tm->stats($id);
        } catch (SourceException $e) {
            // estatística é opcional
        }

        return DB::transaction(function () use ($p, $prof, $stats, $id) {
            $changes = 0;
            $reviews = 0;
            $cit = $prof['citizenship'] ?? [];
            $foot = ['left' => 'esquerdo', 'right' => 'direito', 'both' => 'ambidestro'][$prof['foot'] ?? ''] ?? null;
            $pos = Transfermarkt::position($prof['position']['main'] ?? null);
            $others = collect($prof['position']['other'] ?? [])->map(fn ($o) => Transfermarkt::position($o))->filter()->values()->all();
            $proposals = [
                'birth_date' => $prof['dateOfBirth'] ?? null,
                'height_cm' => isset($prof['height']) ? (int) $prof['height'] : null,
                'preferred_foot' => $foot,
                'primary_position' => $pos,
                'nationality' => Transfermarkt::country($cit[0] ?? null),
                'second_nationality' => Transfermarkt::country($cit[1] ?? null),
                'full_name' => $prof['fullName'] ?? null,
                'transfermarkt_url' => $prof['url'] ?? null,
            ];
            foreach ($proposals as $field => $value) {
                [$c, $r] = static::propose($p, $field, $value, Transfermarkt::PROVIDER);
                $changes += $c;
                $reviews += $r;
            }
            if ($others && empty($p->positions)) {
                $p->positions = array_values(array_unique(array_diff($others, [$pos])));
                $changes++;
            }
            if (! empty($prof['imageUrl']) && ! $p->photo_url && ! $p->photo_path && ! str_contains($prof['imageUrl'], 'default')) {
                $p->photo_url = $prof['imageUrl'];
                $p->photo_source = Transfermarkt::PROVIDER;
                $changes++;
            }
            // F15: cidadania informada pela fonte vira cidadania declarada — nunca passaporte confirmado.
            foreach ($cit as $c) {
                $changes += $p->addCitizenship(Transfermarkt::country($c), Transfermarkt::PROVIDER) ? 1 : 0;
            }
            if (! empty($prof['agent']['name']) && ! $p->agent_id) {
                $agent = Agent::query()->whereRaw('lower(name) = ?', [mb_strtolower($prof['agent']['name'])])->first()
                    ?? Agent::create(['name' => $prof['agent']['name'], 'notes' => 'Criado a partir do Transfermarkt (fonte não oficial).']);
                $p->agent_id = $agent->id;
                $changes++;
            }
            $sources = $p->field_sources ?? [];
            $p->field_sources = $sources;
            $p->enriched_at = now();
            $p->save();

            // Clube e contrato observados (datados, não verdade permanente)
            $club = isset($prof['club']['name']) ? ClubResolver::resolve(Transfermarkt::PROVIDER, $prof['club']['id'] ?? null, $prof['club']['name']) : null;
            if ($club && ! $club->crest_url && ! $club->crest_path && ! empty($prof['club']['id'])) {
                $club->update(['crest_url' => 'https://tmssl.akamaized.net/images/wappen/head/'.$prof['club']['id'].'.png', 'crest_source' => Transfermarkt::PROVIDER]);
            }
            if ($club && $p->current_club_id && $club->id !== $p->current_club_id) {
                $reviews += static::review($p, 'current_club_id', $p->current_club_id, $club->id, Transfermarkt::PROVIDER);
            } elseif ($club && ! $p->current_club_id) {
                $p->update(['current_club_id' => $club->id]);
                $changes++;
            }
            if (! empty($prof['club']['contractExpires'])) {
                ContractObservation::firstOrCreate(
                    ['player_id' => $p->id, 'contract_end' => $prof['club']['contractExpires'], 'source_label' => 'Transfermarkt (não oficial)'],
                    ['club_id' => $club?->id, 'status' => 'vigente', 'confirmation' => 'estimado', 'source_url' => $prof['url'] ?? null, 'observed_on' => now()->toDateString()],
                );
            }
            if (! empty($prof['marketValue'])) {
                MarketObservation::firstOrCreate(
                    ['player_id' => $p->id, 'kind' => 'valor_mercado', 'amount' => $prof['marketValue'], 'currency' => 'EUR', 'source_label' => 'Transfermarkt (não oficial)'],
                    ['confirmation' => 'estimado', 'source_url' => $prof['url'] ?? null, 'observed_on' => $prof['marketValueDetails']['lastUpdated'] ?? now()->toDateString(),
                        'notes' => 'Valor de mercado é referência externa: não é preço negociado nem salário.'],
                );
            }
            foreach ($stats as $s) {
                PlayerSeasonStat::updateOrCreate(
                    ['player_id' => $p->id, 'provider' => Transfermarkt::PROVIDER, 'competition_key' => $s['competitionId'] ?? $s['competitionName'], 'season' => (string) ($s['seasonId'] ?? '?'), 'club_name' => $s['clubId'] ?? null],
                    ['competition_name' => $s['competitionName'] ?? '?', 'appearances' => $s['appearances'] ?? null, 'minutes' => $s['minutesPlayed'] ?? null,
                        'goals' => $s['goals'] ?? null, 'assists' => $s['assists'] ?? null, 'yellow_cards' => $s['yellowCards'] ?? null,
                        'red_cards' => isset($s['redCards']) ? (int) $s['redCards'] + (int) ($s['secondYellowCards'] ?? 0) : null,
                        'clean_sheets' => $s['cleanSheets'] ?? null, 'goals_conceded' => $s['goalsConceded'] ?? null,
                        'source_url' => 'https://www.transfermarkt.com/-/leistungsdaten/spieler/'.$id, 'fetched_at' => now()],
                );
            }

            return ['status' => 'ok', 'message' => "Transfermarkt: {$changes} campo(s) preenchido(s), {$reviews} divergência(s) para revisar, ".count($stats).' linha(s) de estatística.'];
        });
    }

    /** Estatísticas Sofascore das temporadas recentes (atleta já vinculado). */
    public static function enrichSofascore(Player $p, int $maxSeasons = 4): array
    {
        $id = $p->externalId(Sofascore::PROVIDER);
        if (! $id) {
            return ['status' => 'sem_vinculo', 'message' => 'Atleta sem vínculo com o Sofascore.'];
        }
        $sf = new Sofascore;
        $seasons = $sf->seasons($id);
        $recentYears = [(string) now()->year, (string) (now()->year - 1), substr((string) (now()->year - 1), 2).'/'.substr((string) now()->year, 2), substr((string) now()->year, 2).'/'.substr((string) (now()->year + 1), 2)];
        $done = 0;
        foreach ($seasons as $ut) {
            foreach ($ut['seasons'] ?? [] as $season) {
                if (! in_array($season['year'] ?? '', $recentYears, true) || $done >= $maxSeasons) {
                    continue;
                }
                try {
                    $st = $sf->seasonStats($id, (int) $ut['uniqueTournament']['id'], (int) $season['id']);
                } catch (\App\Sources\CollectorPending $e) {
                    throw $e; // pedido ao coletor do Mac: a tarefa continua na próxima rodada
                } catch (SourceException $e) {
                    continue;
                }
                $extra = array_filter($st, fn ($v) => is_numeric($v));
                PlayerSeasonStat::updateOrCreate(
                    ['player_id' => $p->id, 'provider' => Sofascore::PROVIDER, 'competition_key' => (string) $ut['uniqueTournament']['id'], 'season' => (string) $season['year'], 'club_name' => null],
                    ['competition_name' => $ut['uniqueTournament']['name'], 'appearances' => $st['appearances'] ?? null, 'starts' => $st['matchesStarted'] ?? null,
                        'minutes' => $st['minutesPlayed'] ?? null, 'goals' => $st['goals'] ?? null, 'assists' => $st['assists'] ?? null,
                        'yellow_cards' => $st['yellowCards'] ?? null, 'red_cards' => $st['redCards'] ?? null,
                        'clean_sheets' => $st['cleanSheet'] ?? null, 'goals_conceded' => $st['goalsConceded'] ?? null,
                        'rating' => isset($st['rating']) ? round((float) $st['rating'], 2) : null, 'extra' => $extra,
                        'source_url' => 'https://www.sofascore.com/player/-/'.$id, 'fetched_at' => now()],
                );
                $done++;
            }
        }
        if (! $p->sofascore_url) {
            $p->update(['sofascore_url' => 'https://www.sofascore.com/player/-/'.$id]);
        }
        if (! $p->photo_url && ! $p->photo_path) {
            $p->update(['photo_url' => 'https://api.sofascore.app/api/v1/player/'.$id.'/image', 'photo_source' => Sofascore::PROVIDER]);
        }
        $p->update(['enriched_at' => now()]);

        return ['status' => 'ok', 'message' => "Sofascore: {$done} temporada(s) de estatística atualizada(s)."];
    }

    /** @return array{0:int,1:int} [preenchidos, revisões] */
    public static function propose(Player $p, string $field, $value, string $provider): array
    {
        if ($value === null || $value === '') {
            return [0, 0];
        }
        $raw = $p->getRawOriginal($field);
        $current = $raw instanceof \DateTimeInterface ? $raw->format('Y-m-d') : $raw;
        $empty = $current === null || $current === '' || ($field === 'preferred_foot' && $current === 'nao_informado');
        if ($empty) {
            $p->{$field} = $value;
            $src = $p->field_sources ?? [];
            $src[$field] = ['source' => $provider, 'at' => now()->toDateString()];
            $p->field_sources = $src;

            return [1, 0];
        }
        if (! \App\Support\RawValue::same($current, $value)
            && ! (is_string($current) && is_string($value) && mb_strtolower(trim($current)) === mb_strtolower(trim($value)))) {
            return [0, static::review($p, $field, $current, $value, $provider)];
        }

        return [0, 0];
    }

    /** Rótulo legível de um valor bruto (UUID de clube vira nome; data vira dd/mm/aaaa). */
    public static function label(string $field, mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return match (true) {
            in_array($field, ['current_club_id', 'rights_holder_club_id'], true) => (\App\Models\Club::find($raw)?->name ?? 'clube inexistente').' (#'.$raw.')',
            $field === 'agent_id' => \App\Models\Agent::find($raw)?->name ?? 'empresário inexistente',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $raw) => \Illuminate\Support\Carbon::parse($raw)->format('d/m/Y'),
            default => is_array($raw) ? implode(', ', $raw) : (string) $raw,
        };
    }

    private static function review(Player $p, string $field, mixed $currentRaw, mixed $proposedRaw, string $provider): int
    {
        $currentRaw = $currentRaw instanceof \DateTimeInterface ? $currentRaw->format('Y-m-d') : $currentRaw;
        $proposedRaw = $proposedRaw instanceof \DateTimeInterface ? $proposedRaw->format('Y-m-d') : $proposedRaw;
        $pending = EnrichmentReview::where('player_id', $p->id)->where('provider', $provider)->where('field', $field)->where('status', 'pendente')->get()
            ->first(fn ($r) => \App\Support\RawValue::same($r->proposed_raw, $proposedRaw));
        if ($pending) {
            return 0;
        }
        EnrichmentReview::create([
            'player_id' => $p->id, 'provider' => $provider, 'field' => $field, 'status' => 'pendente',
            'current_raw' => $currentRaw, 'proposed_raw' => $proposedRaw,
            'current_value' => static::label($field, $currentRaw), 'proposed_value' => static::label($field, $proposedRaw),
        ]);

        return 1;
    }

    /**
     * Aplica revisão (F16). Só marca "aceito" depois de confirmar que o valor gravado é o proposto.
     * Se o cadastro mudou desde que a revisão foi aberta, ou o valor proposto é inválido, vira "conflito".
     *
     * @return array{status:string, message:string}
     */
    public static function accept(EnrichmentReview $r, ?int $userId): array
    {
        return DB::transaction(function () use ($r, $userId) {
            $r = EnrichmentReview::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pendente') {
                return ['status' => $r->status, 'message' => 'Revisão já decidida ('.$r->status.').'];
            }
            $p = Player::whereKey($r->player_id)->lockForUpdate()->firstOrFail();
            $field = $r->field;
            $proposed = $r->proposed_raw;
            $conflict = function (string $why) use ($r, $userId) {
                $r->update(['status' => 'conflito', 'decision_note' => $why, 'reviewed_by' => $userId, 'reviewed_at' => now()]);

                return ['status' => 'conflito', 'message' => $why];
            };
            if (! \Illuminate\Support\Facades\Schema::hasColumn('players', $field)) {
                return $conflict("Campo {$field} não existe no cadastro.");
            }
            if (! \App\Support\RawValue::same($p->getRawOriginal($field), $r->current_raw)) {
                return $conflict('O cadastro mudou depois que a revisão foi aberta (agora: '.(static::label($field, $p->getRawOriginal($field)) ?? 'vazio').'). Reavalie.');
            }
            if (in_array($field, ['current_club_id', 'rights_holder_club_id'], true) && ! \App\Models\Club::whereKey($proposed)->exists()) {
                return $conflict('Clube proposto não existe mais.');
            }
            $p->{$field} = $proposed;
            $src = $p->field_sources ?? [];
            $src[$field] = ['source' => $r->provider, 'at' => now()->toDateString(), 'reviewed_by' => $userId];
            $p->field_sources = $src;
            $p->save();
            $applied = DB::table('players')->where('id', $p->id)->value($field);
            if (! \App\Support\RawValue::same($applied, $proposed)) {
                throw new \RuntimeException("Aplicação de {$field} não confirmada; nada foi alterado.");
            }
            $r->update(['status' => 'aceito', 'reviewed_by' => $userId, 'reviewed_at' => now(), 'decision_note' => null]);

            return ['status' => 'aceito', 'message' => 'Aplicado: '.static::label($field, $proposed)];
        });
    }
}
