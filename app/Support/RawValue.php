<?php

namespace App\Support;

/** Comparação de valores brutos do banco ("25000.00" = 25000, JSON, booleanos, datas com hora). */
class RawValue
{
    public static function normalize(mixed $v): ?string
    {
        if ($v instanceof \BackedEnum) {
            $v = $v->value;
        }
        if ($v instanceof \DateTimeInterface) {
            $v = $v->format('Y-m-d');
        }
        if (is_string($v) && $v !== '' && in_array($v[0], ['[', '{'], true) && json_validate($v)) {
            $v = json_decode($v, true);
        }
        if (is_array($v)) {
            return json_encode($v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }

        return $v === null || $v === '' ? null : trim((string) $v);
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return static::normalize($a) === static::normalize($b);
    }
}
