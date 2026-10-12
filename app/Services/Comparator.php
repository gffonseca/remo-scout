<?php

namespace App\Services;

use App\Enums\GamePhase;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\RecruitmentNeed;
use App\Models\User;
use App\Support\BudgetFit;
use App\Support\PlayerInsight;
use App\Support\PlayerStatsView;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comparador fixo (Bloco 3): 2–5 atletas lado a lado com contexto e amostra equivalentes.
 * - Nível só observado e vigente, por papel e fase, com data; potencial à parte.
 * - Estatística só da MESMA fonte e temporada; mostra competição e minutos e avisa contexto diferente/amostra pequena.
 * - Faixa salarial só para quem vê financeiro; relação com o teto da necessidade em 4 estados.
 */
class Comparator
{
    public const MAX = 5;

    /** @return array{ok:bool, message:string} */
    public static function toggle(User $u, Player $p): array
    {
        unset(static::$memo[$u->id]);
        $q = DB::table('compare_pins')->where('user_id', $u->id);
        if ((clone $q)->where('player_id', $p->id)->exists()) {
            (clone $q)->where('player_id', $p->id)->delete();

            return ['ok' => true, 'message' => "{$p->name} saiu do comparador."];
        }
        if ((clone $q)->count() >= self::MAX) {
            return ['ok' => false, 'message' => 'O comparador já tem '.self::MAX.' atletas. Retire um antes.'];
        }
        DB::table('compare_pins')->insert(['user_id' => $u->id, 'player_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['ok' => true, 'message' => "{$p->name} fixado no comparador."];
    }

    /** @var array<int, array<string, true>> fixados por usuário, memorizados na requisição (lista sem N+1) */
    private static array $memo = [];

    public static function isPinned(User $u, Player $p): bool
    {
        static::$memo[$u->id] ??= DB::table('compare_pins')->where('user_id', $u->id)->pluck('player_id')->flip()->map(fn () => true)->all();

        return isset(static::$memo[$u->id][$p->id]);
    }

    /** @return Collection<int, Player> */
    public static function players(User $u): Collection
    {
        $ids = DB::table('compare_pins')->where('user_id', $u->id)->orderBy('created_at')->pluck('player_id');

        return Player::with(['currentClub', 'latestContract', 'roleAssessments.tacticalRole', 'specialtyAssessments.specialty', 'seasonStats',
            'submittedReports' => fn ($q) => $q->visibleTo($u)])
            ->whereIn('id', $ids)->get()->sortBy(fn ($p) => $ids->search($p->id))->values();
    }

    /** Papéis/fases com avaliação observada em algum dos atletas → nível de cada um (ou n/o). */
    public static function roleGrid(Collection $players): array
    {
        $keys = [];
        foreach ($players as $p) {
            foreach ($p->roleAssessments->filter(fn ($a) => $a->kind?->value === 'observado') as $a) {
                $keys[$a->tactical_role_id.'|'.($a->phase?->value ?? '')] = $a->tacticalRole?->name.' · '.GamePhase::label($a->phase);
            }
        }
        asort($keys);
        $grid = [];
        foreach ($keys as $key => $label) {
            [$roleId, $phase] = explode('|', $key);
            foreach ($players as $p) {
                $a = $p->roleAssessments->filter(fn ($x) => $x->kind?->value === 'observado' && $x->tactical_role_id === $roleId && ($x->phase?->value ?? '') === $phase)
                    ->sortByDesc(fn ($x) => [($x->observed_on ?? $x->created_at)?->timestamp ?? 0, $x->created_at?->timestamp ?? 0])->first();
                $grid[$label][$p->id] = $a ? ['level' => $a->level, 'date' => ($a->observed_on ?? $a->created_at)?->format('d/m/Y'), 'expired' => $a->isExpired()] : null;
            }
        }

        return $grid;
    }

    /** Fontes/temporadas presentes, para escolher a base comum. @return array<string,string> */
    public static function statBases(Collection $players): array
    {
        return $players->flatMap(fn ($p) => $p->seasonStats->map(fn ($s) => $s->provider.'|'.$s->season))
            ->countBy()->sortDesc()
            ->mapWithKeys(fn ($n, $k) => [$k => PlayerStatsView::providerLabel(explode('|', $k)[0]).' '.explode('|', $k)[1]." ({$n} de {$players->count()})"])->all();
    }

    /**
     * Linha principal de cada atleta na base escolhida: a de mais minutos (sem somar competições nem fontes).
     *
     * @return array{rows: array<string, ?PlayerSeasonStat>, context_differs: bool}
     */
    public static function statRows(Collection $players, ?string $base): array
    {
        if (! $base) {
            return ['rows' => [], 'context_differs' => false];
        }
        [$prov, $season] = explode('|', $base);
        $rows = [];
        foreach ($players as $p) {
            $rows[$p->id] = $p->seasonStats->where('provider', $prov)->where('season', $season)
                ->sortByDesc(fn ($s) => [$s->minutes ?? -1, $s->appearances ?? -1])->first();
        }
        $comps = collect($rows)->filter()->map(fn ($s) => $s->competition_name)->unique();

        return ['rows' => $rows, 'context_differs' => $comps->count() > 1];
    }

    public static function budget(?RecruitmentNeed $need, Player $p): ?array
    {
        return $need ? BudgetFit::salary($need, $p) : null;
    }

    public static function levels(Player $p): array
    {
        return ['atual' => PlayerInsight::currentLevel($p), 'potencial' => PlayerInsight::potentialLevel($p),
            'parecer' => PlayerInsight::recommendation($p), 'conhecimento' => PlayerInsight::knowledge($p)];
    }
}
