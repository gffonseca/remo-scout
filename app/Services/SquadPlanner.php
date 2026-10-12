<?php

namespace App\Services;

use App\Enums\GamePhase;
use App\Enums\NeedStatus;
use App\Models\ClubSquadEntry;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\RecruitmentNeed;
use App\Models\SquadPlan;
use App\Models\SquadPlanPick;
use App\Models\SquadPlanSlot;
use App\Support\Formations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planejador de elenco: postos por estrutura (com/sem posse), titular + alternativas, carências e cenários.
 * Carência é fato verificável (sem titular, sem alternativa, titular fora do elenco, sem avaliação no papel/fase);
 * "sem avaliação" ≠ "nível baixo". Nada aqui decide contratação.
 */
class SquadPlanner
{
    public const GAP_LABELS = [
        'sem_titular' => 'Sem titular',
        'sem_alternativa' => 'Sem alternativa',
        'titular_fora' => 'Titular fora do elenco (depende de contratação)',
        'sem_avaliacao' => 'Titular sem avaliação vigente no papel/fase',
    ];

    /** Cria ou reajusta os postos da estrutura. Postos com o mesmo código ficam (com os atletas); os que saem perdem as escolhas. */
    public static function syncSlots(SquadPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            foreach (array_keys(SquadPlan::PHASES) as $phase) {
                $shape = Formations::get($plan->shapeFor($phase));
                $plan->slots()->where('phase', $phase)->whereNotIn('code', array_keys($shape))->delete();
                $sort = 0;
                foreach ($shape as $code => [$pos, $x, $y]) {
                    $slot = SquadPlanSlot::firstOrNew(['squad_plan_id' => $plan->id, 'phase' => $phase, 'code' => $code]);
                    if ($slot->exists && $slot->position->value !== $pos) {
                        $slot->tactical_role_id = null; // papel era de outra posição
                    }
                    $slot->fill(['position' => $pos, 'x' => $x, 'y' => $y, 'sort' => $sort++])->save();
                }
            }
        });
    }

    /** Cenário: cópia integral do plano (postos, papéis e escolhas) para simular contratações sem mexer no original. */
    public static function duplicate(SquadPlan $plan, string $name, ?int $userId): SquadPlan
    {
        return DB::transaction(function () use ($plan, $name, $userId) {
            $copy = $plan->replicate(['created_by'])->fill(['name' => $name, 'based_on_id' => $plan->id, 'created_by' => $userId]);
            $copy->save();
            foreach ($plan->slots()->with('picks')->get() as $slot) {
                $s = $slot->replicate()->fill(['squad_plan_id' => $copy->id]);
                $s->save();
                foreach ($slot->picks as $pick) {
                    $pick->replicate()->fill(['squad_plan_slot_id' => $s->id])->save();
                }
            }

            return $copy;
        });
    }

    /** Copia as escolhas de uma estrutura para a outra (mesmo código; senão, mesma posição ainda livre). Não apaga o que já existe. */
    public static function copyPhase(SquadPlan $plan, string $from, string $to): int
    {
        $src = $plan->slots()->where('phase', $from)->with('picks')->get();
        $dst = $plan->slots()->where('phase', $to)->get();
        $used = [];
        $n = 0;
        foreach ($dst as $slot) {
            $match = $src->firstWhere('code', $slot->code)
                ?? $src->first(fn ($s) => $s->position === $slot->position && ! in_array($s->id, $used, true));
            if (! $match) {
                continue;
            }
            $used[] = $match->id;
            foreach ($match->picks as $pick) {
                if (! $slot->picks()->where('player_id', $pick->player_id)->exists()) {
                    self::addPick($slot, $pick->player_id, $pick->depth === 1 && ! $slot->picks()->where('depth', 1)->exists());
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Titular empurra o anterior para alternativa 1; alternativa entra no fim da fila. */
    public static function addPick(SquadPlanSlot $slot, string $playerId, bool $starter): SquadPlanPick
    {
        return DB::transaction(function () use ($slot, $playerId, $starter) {
            $existing = $slot->picks()->where('player_id', $playerId)->first();
            if ($existing) {
                return $starter ? self::promote($existing) : $existing;
            }
            if ($starter) {
                $slot->picks()->increment('depth');
                $depth = 1;
            } else {
                $depth = max(2, (int) $slot->picks()->max('depth') + 1);
            }

            $pick = SquadPlanPick::create(['squad_plan_slot_id' => $slot->id, 'player_id' => $playerId, 'depth' => $depth]);
            self::compact($slot); // posto sem titular: as alternativas não podem ficar com buraco na numeração

            return $pick->fresh();
        });
    }

    public static function promote(SquadPlanPick $pick): SquadPlanPick
    {
        return DB::transaction(function () use ($pick) {
            $slot = $pick->slot;
            $slot->picks()->where('id', '!=', $pick->id)->where('depth', '<', $pick->depth)->increment('depth');
            $pick->update(['depth' => 1]);
            self::compact($slot);

            return $pick->fresh();
        });
    }

    public static function remove(SquadPlanPick $pick): void
    {
        $slot = $pick->slot;
        $pick->delete();
        self::compact($slot);
    }

    /** Profundidade sem buracos: 1, 2, 3… Um posto sem titular continua sem titular (não promove ninguém sozinho). */
    private static function compact(SquadPlanSlot $slot): void
    {
        $picks = $slot->picks()->get();
        $hasStarter = $picks->contains('depth', 1);
        $i = $hasStarter ? 1 : 2;
        foreach ($picks as $p) {
            if ($p->depth !== $i) {
                $p->update(['depth' => $i]);
            }
            $i++;
        }
    }

    /** Elenco de referência: atletas com o clube atual = clube do plano, mais o retrato de elenco vigente (Dados Futebol) já ligado. */
    public static function squadPlayerIds(SquadPlan $plan): Collection
    {
        if (! $plan->club_id) {
            return collect();
        }

        return Player::where('current_club_id', $plan->club_id)->pluck('id')
            ->merge(ClubSquadEntry::where('club_id', $plan->club_id)->whereNull('left_at')->whereNotNull('player_id')->pluck('player_id'))
            ->unique()->values();
    }

    /**
     * Visão do plano: postos por estrutura com escolhas, nível no papel/fase do posto e carências; necessidades abertas por posição.
     *
     * @return array{phases: array<string, Collection>, gaps: array<string,int>, needs: Collection, uncoveredNeeds: Collection, depth: array}
     */
    public static function view(SquadPlan $plan): array
    {
        $slots = $plan->slots()->with(['tacticalRole', 'picks.player.currentClub'])->get();
        $squad = self::squadPlayerIds($plan)->flip();
        $playerIds = $slots->flatMap(fn ($s) => $s->picks->pluck('player_id'))->unique();
        $assessments = PlayerRoleAssessment::whereIn('player_id', $playerIds)->where('kind', 'observado')->get()->groupBy('player_id');
        $needs = RecruitmentNeed::where('status', NeedStatus::Aberta->value)->get();

        $phases = [];
        $gaps = array_fill_keys(array_keys(self::GAP_LABELS), 0);
        foreach (SquadPlan::PHASES as $phase => $label) {
            $phases[$phase] = $slots->where('phase', $phase)->values()->map(function (SquadPlanSlot $slot) use ($squad, $assessments, $needs, $phase, &$gaps) {
                $picks = $slot->picks->map(fn ($pick) => [
                    'pick' => $pick, 'player' => $pick->player, 'in_squad' => $squad->has($pick->player_id),
                    'level' => $slot->tactical_role_id ? self::roleLevel($assessments->get($pick->player_id, collect()), $slot->tactical_role_id, $phase) : null,
                ]);
                $starter = $picks->first(fn ($p) => $p['pick']->depth === 1);
                $g = [];
                if (! $starter) {
                    $g[] = 'sem_titular';
                }
                if ($picks->count() < 2) {
                    $g[] = 'sem_alternativa';
                }
                if ($starter && ! $starter['in_squad']) {
                    $g[] = 'titular_fora';
                }
                if ($starter && $slot->tactical_role_id && ! $starter['level']) {
                    $g[] = 'sem_avaliacao';
                }
                foreach ($g as $k) {
                    $gaps[$k]++;
                }

                return ['slot' => $slot, 'picks' => $picks, 'starter' => $starter, 'gaps' => $g,
                    'needs' => $needs->filter(fn ($n) => $n->position === $slot->position)->values()];
            });
        }
        $positions = $slots->pluck('position')->map->value->unique();

        return [
            'phases' => $phases,
            'gaps' => $gaps,
            'needs' => $needs,
            'uncoveredNeeds' => $needs->reject(fn ($n) => $positions->contains($n->position->value))->values(),
            'depth' => self::depthByPosition($slots, $squad),
        ];
    }

    /** Avaliação observada vigente mais recente do papel na fase do posto. Avaliação "geral" não substitui fase específica. */
    public static function roleLevel(Collection $assessments, string $roleId, string $phase): ?array
    {
        $a = $assessments->filter(fn ($x) => $x->tactical_role_id === $roleId && $x->phase?->value === $phase)
            ->sortByDesc(fn ($x) => [($x->observed_on ?? $x->created_at)?->timestamp ?? 0, $x->created_at?->timestamp ?? 0])->first();
        if (! $a || ! $a->level?->rank() || $a->isExpired()) {
            return null;
        }

        return ['level' => $a->level, 'date' => ($a->observed_on ?? $a->created_at)?->format('d/m/Y'), 'phase' => GamePhase::label($phase)];
    }

    /** Profundidade por posição (as duas estruturas juntas, sem repetir atleta). */
    private static function depthByPosition(Collection $slots, Collection $squad): array
    {
        return $slots->groupBy(fn ($s) => $s->position->value)->map(function ($group, $pos) use ($squad) {
            $players = $group->flatMap(fn ($s) => $s->picks)->unique('player_id');

            return ['position' => $pos, 'label' => $group->first()->position->getLabel(), 'slots' => $group->pluck('code')->unique()->count(),
                'options' => $players->count(), 'in_squad' => $players->filter(fn ($p) => $squad->has($p->player_id))->count()];
        })->sortBy('position')->values()->all();
    }
}
