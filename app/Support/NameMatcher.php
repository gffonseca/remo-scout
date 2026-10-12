<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Normalização e similaridade de nomes (sem acento, sem pontuação). Pontuação 0–100. */
class NameMatcher
{
    private const STOP = ['de', 'da', 'do', 'dos', 'das', 'e', 'fc', 'ec', 'sc', 'ac', 'futebol', 'clube', 'esporte', 'sport', 'club', 'football', 'associacao', 'esportiva', 'regatas', 'atletico'];

    public static function normalize(?string $s): string
    {
        return Str::of((string) $s)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->toString();
    }

    public static function score(?string $a, ?string $b): int
    {
        $a = static::normalize($a);
        $b = static::normalize($b);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        $ta = array_values(array_diff(explode(' ', $a), ['de', 'da', 'do', 'dos', 'das']));
        $tb = array_values(array_diff(explode(' ', $b), ['de', 'da', 'do', 'dos', 'das']));
        $common = count(array_intersect($ta, $tb));
        if ($common > 0 && ($common === count($ta) || $common === count($tb))) {
            return 88; // um contém o outro (ex.: "Ziyech" x "Hakim Ziyech")
        }
        similar_text($a, $b, $pct);

        return (int) round(max($pct, $common ? 60 + 10 * $common : 0));
    }

    /** Nome de clube "core" para comparar ("Botafogo de Futebol e Regatas" → "botafogo"). */
    public static function clubCore(?string $s): string
    {
        $tokens = array_diff(explode(' ', static::normalize($s)), self::STOP);

        return implode(' ', $tokens);
    }

    public static function clubScore(?string $a, ?string $b): int
    {
        $ca = static::clubCore($a);
        $cb = static::clubCore($b);
        if ($ca === '' || $cb === '') {
            return 0;
        }
        if ($ca === $cb) {
            return 100;
        }
        $ta = explode(' ', $ca);
        $tb = explode(' ', $cb);
        $common = array_intersect($ta, $tb);

        return $common ? (int) round(100 * count($common) / max(count($ta), count($tb))) : 0;
    }
}
