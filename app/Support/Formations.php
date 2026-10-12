<?php

namespace App\Support;

/**
 * Estruturas do planejador. Coordenadas no mesmo campo do perfil (100 × 150, gol em baixo).
 * Código do posto = único na estrutura; posição = enum Position (casa com as necessidades).
 */
class Formations
{
    private const BACK4 = ['GOL' => ['GOL', 50, 143], 'LD' => ['LD', 86, 104], 'ZAGD' => ['ZAGD', 64, 122], 'ZAGE' => ['ZAGE', 36, 122], 'LE' => ['LE', 14, 104]];

    private const BACK3 = ['GOL' => ['GOL', 50, 143], 'ZAGD' => ['ZAGD', 74, 114], 'ZAG' => ['ZAG', 50, 128], 'ZAGE' => ['ZAGE', 26, 114]];

    /** @return array<string, array<string, array{0:string,1:int,2:int}>> */
    public static function all(): array
    {
        return [
            '4-3-3' => self::BACK4 + ['VOL' => ['VOL', 50, 96], 'MC-D' => ['MC', 68, 76], 'MC-E' => ['MC', 32, 76], 'PD' => ['PD', 84, 40], 'CA' => ['CA', 50, 22], 'PE' => ['PE', 16, 40]],
            '4-4-2' => self::BACK4 + ['MD' => ['MD', 85, 70], 'VOL-D' => ['VOL', 65, 88], 'VOL-E' => ['VOL', 35, 88], 'ME' => ['ME', 15, 70], 'CA-D' => ['CA', 66, 26], 'CA-E' => ['CA', 34, 26]],
            '4-2-3-1' => self::BACK4 + ['VOL-D' => ['VOL', 65, 90], 'VOL-E' => ['VOL', 35, 90], 'PD' => ['PD', 84, 52], 'MEI' => ['MEI', 50, 58], 'PE' => ['PE', 16, 52], 'CA' => ['CA', 50, 22]],
            '4-1-4-1' => self::BACK4 + ['VOL' => ['VOL', 50, 100], 'MD' => ['MD', 85, 66], 'MC-D' => ['MC', 64, 72], 'MC-E' => ['MC', 36, 72], 'ME' => ['ME', 15, 66], 'CA' => ['CA', 50, 22]],
            '3-5-2' => self::BACK3 + ['ALD' => ['ALD', 88, 80], 'VOL' => ['VOL', 50, 96], 'MC-D' => ['MC', 66, 72], 'MC-E' => ['MC', 34, 72], 'ALE' => ['ALE', 12, 80], 'CA-D' => ['CA', 66, 26], 'CA-E' => ['CA', 34, 26]],
            '5-3-2' => ['GOL' => ['GOL', 50, 143], 'ALD' => ['ALD', 86, 100], 'ZAGD' => ['ZAGD', 72, 116], 'ZAG' => ['ZAG', 50, 129], 'ZAGE' => ['ZAGE', 28, 116], 'ALE' => ['ALE', 14, 100],
                'VOL' => ['VOL', 50, 94], 'MC-D' => ['MC', 68, 76], 'MC-E' => ['MC', 32, 76], 'CA-D' => ['CA', 66, 26], 'CA-E' => ['CA', 34, 26]],
        ];
    }

    public static function options(): array
    {
        return array_combine(array_keys(self::all()), array_keys(self::all()));
    }

    public static function get(string $shape): array
    {
        return self::all()[$shape] ?? throw new \InvalidArgumentException("Estrutura desconhecida: {$shape}");
    }
}
