<?php

namespace App\Services;

use App\Enums\AssessmentKind;
use App\Enums\GamePhase;
use App\Enums\Level;
use App\Enums\RequirementStatus;
use App\Models\NeedRequirementCheck;
use App\Models\Player;
use App\Models\PlayerRoleAssessment;
use App\Models\RecruitmentNeed;
use Illuminate\Support\Collection;

/**
 * Verificação dos requisitos obrigatórios de uma necessidade para um candidato (F03/F04).
 *
 * Regras:
 * - Cada item tem estado atende | nao_atende | pendente | nao_aplicavel. Desconhecido = pendente, nunca atende.
 * - Nível no papel usa SOMENTE avaliação observada, do mesmo papel e da mesma fase (geral ≠ fase específica).
 *   Só potencial → pendente. Avaliação vencida (config scout.assessment_valid_months) → pendente.
 * - Requisitos textuais obrigatórios ficam pendentes até coordenador/admin confirmar com evidência.
 * - Resultado geral: qualquer obrigatório "não atende" → Não atende; senão qualquer pendente → Pendente; senão Atende.
 */
class RequirementCheck
{
    /** @return list<array{item:string,status:string,detail:string,kind:string,key:?string}> */
    public static function evaluate(RecruitmentNeed $need, Player $player, ?bool $withBudget = null): array
    {
        // orçamento só para quem vê dados financeiros (null = usuário logado; sem usuário, ex.: jobs, inclui)
        $withBudget ??= auth()->user()?->canViewFinancial() ?? true;
        $out = [];
        $add = function (string $item, RequirementStatus $s, string $detail, string $kind = 'auto', ?string $key = null) use (&$out) {
            $out[] = ['item' => $item, 'status' => $s->value, 'detail' => $detail, 'kind' => $kind, 'key' => $key];
        };

        $codes = array_values(array_unique(array_filter(array_merge([$player->primary_position?->value], $player->positions ?? []))));
        $codes === []
            ? $add('Posição', RequirementStatus::Pendente, 'posição não informada')
            : $add('Posição', in_array($need->position->value, $codes, true) ? RequirementStatus::Atende : RequirementStatus::NaoAtende, 'atleta: '.implode(', ', $codes));

        if ($need->age_min || $need->age_max) {
            $age = $player->age;
            $age === null
                ? $add('Idade', RequirementStatus::Pendente, 'nascimento não informado')
                : $add('Idade', (! $need->age_min || $age >= $need->age_min) && (! $need->age_max || $age <= $need->age_max)
                    ? RequirementStatus::Atende : RequirementStatus::NaoAtende, "{$age} anos");
        }

        if ($need->preferred_foot) {
            $foot = $player->preferred_foot?->value;
            if (! $foot || $foot === 'nao_informado') {
                $add('Pé', RequirementStatus::Pendente, 'pé não informado');
            } else {
                $ok = $foot === $need->preferred_foot->value || $foot === 'ambidestro';
                $add('Pé', $ok ? RequirementStatus::Atende : RequirementStatus::NaoAtende, $player->preferred_foot->getLabel());
            }
        }

        foreach (static::roleRequirements($need)->where('is_mandatory', true) as $req) {
            [$status, $detail] = static::roleStatus($player, $req['tactical_role_id'], $req['phase'], $req['min_level']);
            $add('Nível '.$req['role_name'].' · '.GamePhase::label($req['phase']).' ≥ '.$req['min_level']->value, $status, $detail);
        }

        foreach ($need->specialties()->wherePivot('is_mandatory', true)->get() as $sp) {
            $a = $player->specialtyAssessments()->where('specialty_id', $sp->id)->latest('updated_at')->first();
            ! $a || $a->level === Level::NaoObservado
                ? $add($sp->name, RequirementStatus::Pendente, 'especialidade não avaliada')
                : $add($sp->name, RequirementStatus::Atende, $a->level->getLabel());
        }

        // orçamento (4 estados): dentro = atende; intersecta/desconhecido = pendente (negociar/levantar); acima = não atende
        foreach ($withBudget ? ['Orçamento salarial' => \App\Support\BudgetFit::salary($need, $player), 'Orçamento de transferência' => \App\Support\BudgetFit::transfer($need, $player)] : [] as $label => $fit) {
            if ($fit) {
                $add($label, match ($fit['state']) {
                    'dentro' => RequirementStatus::Atende,
                    'acima' => RequirementStatus::NaoAtende,
                    default => RequirementStatus::Pendente,
                }, \App\Support\BudgetFit::LABELS[$fit['state']].' — '.$fit['detail'], 'budget', $fit['state']);
            }
        }

        $checks = NeedRequirementCheck::where('recruitment_need_id', $need->id)->where('player_id', $player->id)->get()->keyBy('requirement_key');
        foreach ($need->mandatory_requirements ?? [] as $text) {
            if (blank($text)) {
                continue;
            }
            $key = NeedRequirementCheck::keyFor($text);
            $c = $checks->get($key);
            $status = $c?->status ?? RequirementStatus::Pendente;
            $detail = match (true) {
                ! $c => 'sem evidência registrada',
                $status === RequirementStatus::Pendente => 'evidência registrada por '.($c->evidenceAuthor?->name ?? '—').' em '.$c->evidence_at?->format('d/m/Y').', aguardando confirmação',
                default => $status->getLabel().' — confirmado por '.($c->confirmer?->name ?? '—').' em '.$c->confirmed_at?->format('d/m/Y'),
            };
            $add($text, $status, $detail, 'manual', $key);
        }

        return $out;
    }

    /**
     * Requisitos de papel/fase da necessidade. Necessidades antigas (papel + nível-alvo, sem linha própria)
     * contam como requisito obrigatório "geral" do papel principal.
     *
     * @return Collection<int, array{tactical_role_id:string, role_name:string, phase:?GamePhase, min_level:Level, is_mandatory:bool}>
     */
    public static function roleRequirements(RecruitmentNeed $need): Collection
    {
        $rows = $need->roleRequirements()->with('tacticalRole')->get()->map(fn ($r) => [
            'tactical_role_id' => $r->tactical_role_id, 'role_name' => $r->tacticalRole?->name ?? 'Papel',
            'phase' => $r->phase, 'min_level' => $r->min_level, 'is_mandatory' => $r->is_mandatory,
        ]);
        $legacyLevel = $need->target_level;
        if ($need->tactical_role_id && $legacyLevel && $legacyLevel->rank()
            && ! $rows->contains(fn ($r) => $r['tactical_role_id'] === $need->tactical_role_id && $r['phase'] === null)) {
            $rows->push(['tactical_role_id' => $need->tactical_role_id, 'role_name' => $need->tacticalRole?->name ?? 'Papel',
                'phase' => null, 'min_level' => $legacyLevel, 'is_mandatory' => true]);
        }

        return $rows->values();
    }

    /** @return array{0:RequirementStatus,1:string} */
    public static function roleStatus(Player $player, string $roleId, ?GamePhase $phase, Level $min): array
    {
        $scope = fn ($q) => $q->where('tactical_role_id', $roleId)->when($phase, fn ($w) => $w->where('phase', $phase->value), fn ($w) => $w->whereNull('phase'));
        $observed = PlayerRoleAssessment::where('player_id', $player->id)->where('kind', AssessmentKind::Observado->value)->where($scope)
            ->orderByDesc('observed_on')->orderByDesc('created_at')->orderByDesc('id')->with('author')->first();

        if (! $observed || ! $observed->level->rank()) {
            $potential = PlayerRoleAssessment::where('player_id', $player->id)->where('kind', AssessmentKind::Potencial->value)->where($scope)->exists();
            $other = PlayerRoleAssessment::where('player_id', $player->id)->where('kind', AssessmentKind::Observado->value)
                ->where('tactical_role_id', $roleId)->when($phase, fn ($w) => $w->where(fn ($x) => $x->whereNull('phase')->orWhere('phase', '!=', $phase->value)), fn ($w) => $w->whereNotNull('phase'))->exists();

            return [RequirementStatus::Pendente, match (true) {
                $potential => 'só há avaliação de potencial (projeção não confirma nível atual)',
                $other => 'avaliado em outra fase; esta fase não foi observada',
                default => 'papel não avaliado nesta fase',
            }];
        }
        $who = ($observed->author?->name ?? 'sem autor').', '.$observed->referenceDate()->format('d/m/Y');
        if ($observed->isExpired()) {
            return [RequirementStatus::Pendente, "avaliação vencida ({$observed->level->value} em {$who}); reavaliar"];
        }

        return [$observed->level->rank() >= $min->rank() ? RequirementStatus::Atende : RequirementStatus::NaoAtende,
            "observado {$observed->level->value} ({$who})"];
    }

    /** Rótulo do nível observado mais recente no papel (fase geral), ou o motivo de não haver. */
    public static function observedLevelLabel(Player $player, string $roleId): string
    {
        $a = PlayerRoleAssessment::where('player_id', $player->id)->where('tactical_role_id', $roleId)
            ->where('kind', AssessmentKind::Observado->value)->whereNull('phase')
            ->orderByDesc('observed_on')->orderByDesc('created_at')->orderByDesc('id')->first();

        return match (true) {
            ! $a || ! $a->level->rank() => 'Não observado',
            $a->isExpired() => $a->level->value.' (vencida)',
            default => $a->level->getLabel(),
        };
    }

    public static function summary(RecruitmentNeed $need, Player $player): string
    {
        $r = collect(static::evaluate($need, $player));
        $fail = $r->where('status', RequirementStatus::NaoAtende->value)->pluck('item');
        if ($fail->isNotEmpty()) {
            return 'Não atende: '.$fail->implode(', ');
        }
        $pend = $r->where('status', RequirementStatus::Pendente->value)->pluck('item');
        if ($pend->isNotEmpty()) {
            return 'Pendente: '.$pend->implode(', ');
        }

        return 'Atende';
    }

    public static function color(string $summary): string
    {
        return match (true) {
            str_starts_with($summary, 'Não atende') => 'danger',
            str_starts_with($summary, 'Pendente') => 'warning',
            default => 'success',
        };
    }

    public static function details(RecruitmentNeed $need, Player $player): string
    {
        return collect(static::evaluate($need, $player))
            ->map(fn ($i) => RequirementStatus::from($i['status'])->symbol().' '.$i['item'].' — '.$i['detail'])->implode("\n");
    }
}
