<?php

namespace App\Support;

use App\Models\Player;
use App\Models\RecruitmentNeed;

/**
 * Faixa do atleta × teto da necessidade (decisão do Q&A, F11):
 * dentro (faixa inteira ≤ teto) · intersecta (parte acima: negociar) · acima · desconhecido (sem faixa, moeda ou periodicidade incompatível).
 * O menor valor da faixa nunca vira orçamento confirmado. Os textos não trazem valores (perfis sem acesso financeiro veem só o estado).
 */
class BudgetFit
{
    public const LABELS = ['dentro' => 'Dentro do orçamento', 'intersecta' => 'Intersecta o orçamento', 'acima' => 'Acima do orçamento', 'desconhecido' => 'Orçamento desconhecido'];

    /** @return array{state:string, detail:string}|null null quando a necessidade não tem teto salarial */
    public static function salary(RecruitmentNeed $need, Player $p): ?array
    {
        if ($need->budget_salary_max === null) {
            return null;
        }
        if ($p->salary_min === null && $p->salary_max === null) {
            return ['state' => 'desconhecido', 'detail' => 'faixa salarial não informada'];
        }
        if (($p->salary_currency ?? 'BRL') !== ($need->budget_currency ?? 'BRL')) {
            return ['state' => 'desconhecido', 'detail' => 'faixa em '.$p->salary_currency.', teto em '.($need->budget_currency ?? 'BRL').': sem conversão automática'];
        }
        $div = match ($p->salary_periodicity ?? 'mensal') {
            'mensal' => 1,
            'anual' => 12, // teto da necessidade é mensal
            default => null,
        };
        if (! $div) {
            return ['state' => 'desconhecido', 'detail' => 'periodicidade da faixa não comparável'];
        }
        $cap = (float) $need->budget_salary_max;
        $lo = $p->salary_min !== null ? (float) $p->salary_min / $div : null;
        $hi = $p->salary_max !== null ? (float) $p->salary_max / $div : null;
        $conf = mb_strtolower($p->salary_confirmation?->getLabel() ?? 'não verificado');

        return match (true) {
            $hi !== null && $hi <= $cap => ['state' => 'dentro', 'detail' => "faixa inteira dentro do teto ({$conf})"],
            $lo !== null && $lo > $cap => ['state' => 'acima', 'detail' => "faixa inteira acima do teto ({$conf})"],
            $hi === null => ['state' => 'intersecta', 'detail' => "só o piso é conhecido e está no teto: o máximo pode passar ({$conf})"],
            default => ['state' => 'intersecta', 'detail' => "parte da faixa acima do teto: negociar ({$conf})"],
        };
    }

    /** Pedida de transferência × teto de transferência (valor único). */
    public static function transfer(RecruitmentNeed $need, Player $p): ?array
    {
        if ($need->budget_transfer_max === null) {
            return null;
        }
        if ($p->asking_price === null) {
            return ['state' => 'desconhecido', 'detail' => 'pedida de transferência não informada'];
        }
        if (($p->asking_price_currency ?? 'BRL') !== ($need->budget_currency ?? 'BRL')) {
            return ['state' => 'desconhecido', 'detail' => 'pedida em '.$p->asking_price_currency.', teto em '.($need->budget_currency ?? 'BRL')];
        }

        return (float) $p->asking_price <= (float) $need->budget_transfer_max
            ? ['state' => 'dentro', 'detail' => 'pedida dentro do teto']
            : ['state' => 'acima', 'detail' => 'pedida acima do teto'];
    }

    public static function color(string $state): string
    {
        return ['dentro' => 'success', 'intersecta' => 'warning', 'acima' => 'danger'][$state] ?? 'gray';
    }
}
