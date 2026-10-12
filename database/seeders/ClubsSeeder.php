<?php

namespace Database\Seeders;

use App\Models\Club;
use Illuminate\Database\Seeder;

/**
 * Pré-cadastro dos clubes das Séries A, B, C e D de 2026 + Copa do Nordeste, Copa Verde e Parazão.
 * Fonte: data/clubs-2026.json (Wikipedia pt e imprensa, compilado em 11/10/2026 — revalidar).
 * Idempotente: atualiza cidade/UF/competições sem apagar dados editados.
 */
class ClubsSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/clubs-2026.json'), true, flags: JSON_THROW_ON_ERROR);
        $order = ['Série A', 'Série B', 'Série C', 'Série D'];
        foreach ($data['clubs'] as $c) {
            $division = collect($order)->first(fn ($s) => in_array($s, $c['competitions_2026'], true));
            $club = Club::query()->whereRaw('lower(name) = ?', [mb_strtolower($c['name'])])->first() ?? new Club(['name' => $c['name'], 'country' => 'Brasil']);
            $comps = $club->competitions ?? [];
            $comps['2026'] = $c['competitions_2026'];
            $club->fill([
                'state' => $club->state ?: $c['state'],
                'city' => $club->city ?: ($c['city'] ?? null),
                'competitions' => $comps,
                'current_division' => $club->current_division ?: ($division ?? 'Sem divisão'),
            ])->save();
        }
    }
}
