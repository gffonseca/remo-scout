<?php

namespace Tests\Feature;

/** Completa escalações de teste até 11 titulares identificados e dá ao lado sem substituição uma troca entre reservas fictícios. */
trait CompleteLineups
{
    protected function completeLineups(array $d): array
    {
        foreach (['mandante' => 9000, 'visitante' => 9500] as $side => $base) {
            if (! is_array($d['escalacoes'][$side] ?? null)) {
                continue;
            }
            $t = $d['escalacoes'][$side]['titulares'] ?? [];
            for ($i = count($t); $i < 11; $i++) {
                $t[] = ['atleta' => ['atleta_id' => $base + $i, 'nome_popular' => "Extra {$side} {$i}"]];
            }
            $d['escalacoes'][$side]['titulares'] = $t;
            if (($d['substituicoes'][$side] ?? []) === [] && ! empty($d['substituicoes']['_fill'])) {
                $d['escalacoes'][$side]['reservas'][] = ['atleta' => ['atleta_id' => $base + 50, 'nome_popular' => "Reserva {$side}"]];
                $d['substituicoes'][$side] = [['saiu' => ['atleta_id' => $base + 10], 'entrou' => ['atleta_id' => $base + 50], 'minuto' => '40:00', 'periodo_slug' => 'segundo-tempo']];
            }
        }
        unset($d['substituicoes']['_fill']);

        return $d;
    }
}
