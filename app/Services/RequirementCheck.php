<?php

namespace App\Services;

use App\Enums\Level;
use App\Models\Player;
use App\Models\RecruitmentNeed;

/**
 * Verificação de requisitos obrigatórios estruturados de uma necessidade.
 * Resultado por item: atende | nao_atende | pendente (falta informação). Ausência de dado
 * nunca reprova: vira "pendente". Requisitos em texto livre exigem verificação manual.
 */
class RequirementCheck
{
    /** @return list<array{item:string,status:string,detail:string}> */
    public static function evaluate(RecruitmentNeed $need, Player $player): array
    {
        $out = [];

        $codes = array_filter(array_merge([$player->primary_position?->value], $player->positions ?? []));
        if ($codes === []) {
            $out[] = ['item' => 'Posição', 'status' => 'pendente', 'detail' => 'posição não informada'];
        } else {
            $ok = in_array($need->position->value, $codes, true);
            $out[] = ['item' => 'Posição', 'status' => $ok ? 'atende' : 'nao_atende', 'detail' => 'atleta: '.implode(', ', array_unique($codes))];
        }

        if ($need->age_min || $need->age_max) {
            $age = $player->age;
            if ($age === null) {
                $out[] = ['item' => 'Idade', 'status' => 'pendente', 'detail' => 'nascimento não informado'];
            } else {
                $ok = (! $need->age_min || $age >= $need->age_min) && (! $need->age_max || $age <= $need->age_max);
                $out[] = ['item' => 'Idade', 'status' => $ok ? 'atende' : 'nao_atende', 'detail' => "{$age} anos"];
            }
        }

        if ($need->preferred_foot) {
            $foot = $player->preferred_foot?->value;
            if (! $foot || $foot === 'nao_informado') {
                $out[] = ['item' => 'Pé', 'status' => 'pendente', 'detail' => 'pé não informado'];
            } else {
                $ok = $foot === $need->preferred_foot->value || $foot === 'ambidestro';
                $out[] = ['item' => 'Pé', 'status' => $ok ? 'atende' : 'nao_atende', 'detail' => $player->preferred_foot->getLabel()];
            }
        }

        if ($need->target_level && $need->tactical_role_id) {
            $a = $player->roleAssessments()->where('tactical_role_id', $need->tactical_role_id)->latest('updated_at')->first();
            if (! $a || $a->level === Level::NaoObservado) {
                $out[] = ['item' => 'Nível no papel', 'status' => 'pendente', 'detail' => 'papel não avaliado'];
            } else {
                $ok = $a->level->rank() >= $need->target_level->rank();
                $out[] = ['item' => 'Nível no papel', 'status' => $ok ? 'atende' : 'nao_atende', 'detail' => $a->level->getLabel()];
            }
        }

        foreach ($need->specialties()->wherePivot('is_mandatory', true)->get() as $sp) {
            $a = $player->specialtyAssessments()->where('specialty_id', $sp->id)->latest('updated_at')->first();
            $out[] = ! $a || $a->level === Level::NaoObservado
                ? ['item' => $sp->name, 'status' => 'pendente', 'detail' => 'especialidade não avaliada']
                : ['item' => $sp->name, 'status' => 'atende', 'detail' => $a->level->getLabel()];
        }

        foreach ($need->mandatory_requirements ?? [] as $text) {
            $out[] = ['item' => $text, 'status' => 'manual', 'detail' => 'verificação manual'];
        }

        return $out;
    }

    public static function summary(RecruitmentNeed $need, Player $player): string
    {
        $r = collect(static::evaluate($need, $player));
        $fail = $r->where('status', 'nao_atende')->pluck('item');
        if ($fail->isNotEmpty()) {
            return 'Reprova: '.$fail->implode(', ');
        }
        $pend = $r->where('status', 'pendente')->pluck('item');
        if ($pend->isNotEmpty()) {
            return 'Pendente: '.$pend->implode(', ');
        }

        return $r->where('status', 'manual')->isNotEmpty() ? 'Atende (+ itens manuais)' : 'Atende';
    }

    public static function details(RecruitmentNeed $need, Player $player): string
    {
        $labels = ['atende' => '✔', 'nao_atende' => '✘', 'pendente' => '?', 'manual' => '☐'];

        return collect(static::evaluate($need, $player))
            ->map(fn ($i) => $labels[$i['status']].' '.$i['item'].' — '.$i['detail'])->implode("\n");
    }
}
