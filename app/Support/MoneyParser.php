<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Interpreta valores monetários de planilha no padrão pt-BR (decisão de 12/10/2026).
 *
 * - "R$ 25.000" = 25000 · "R$ 25.000,50" = 25000,50 · "25 mil" = 25000 · "1,2 mi" = 1200000
 * - Negativo ("-1000", "(500)") é recusado: status "negativo".
 * - Formato ambíguo ("25,000", "2.5", "25,000.50", "1.234,5.6") não é convertido: status "ambiguo",
 *   para revisão humana na prévia. Nunca adivinha separador.
 * - Moeda só quando explícita (R$/BRL, US$/USD, €/EUR); sem indicação, assume BRL e marca currency_assumed.
 * - Número vindo como int/float (Excel) é aceito como está, exceto negativo.
 *
 * @phpstan-type Result array{status:'ok'|'vazio'|'ambiguo'|'negativo'|'invalido', value:?float, currency:?string, currency_assumed:bool, original:?string, note:?string}
 */
class MoneyParser
{
    /** @return Result */
    public static function parse(mixed $v): array
    {
        $original = is_scalar($v) ? trim((string) $v) : null;
        $base = ['value' => null, 'currency' => null, 'currency_assumed' => false, 'original' => $original, 'note' => null];
        if ($v === null || $original === '') {
            return ['status' => 'vazio'] + $base;
        }
        if (is_int($v) || is_float($v)) {
            return $v < 0
                ? ['status' => 'negativo', 'note' => 'valor negativo recusado'] + $base
                : ['status' => 'ok', 'value' => round((float) $v, 2), 'currency' => 'BRL', 'currency_assumed' => true] + $base;
        }

        $s = Str::of($original)->ascii()->lower()->squish()->toString();
        [$currency, $assumed] = match (true) {
            (bool) preg_match('/\b(us\$|usd|dolar|dolares)|^\$/', $s) => ['USD', false],
            (bool) preg_match('/(eur|euro)|€/', $original.' '.$s) => ['EUR', false],
            (bool) preg_match('/r\$|\bbrl\b|\breais?\b/', $s) => ['BRL', false],
            default => ['BRL', true],
        };
        $base['currency'] = $currency;
        $base['currency_assumed'] = $assumed;

        if (preg_match('/^\(.*\)$/', $s) || preg_match('/(^|[\s$])-\s*\d/', $s) || preg_match('/\d\s*-$/', $s)) {
            return ['status' => 'negativo', 'note' => 'valor negativo recusado'] + $base;
        }

        $mult = 1;
        $suffix = '';
        if (preg_match('/\d\s*(mil|k)\b/', $s)) {
            $mult = 1_000;
            $suffix = ' mil';
        } elseif (preg_match('/\d\s*(mi|mm|milhao|milhoes)\b/', $s)) {
            $mult = 1_000_000;
            $suffix = ' mi';
        }

        if (! preg_match('/\d[\d.,]*/', $s, $m)) {
            return ['status' => 'invalido', 'note' => 'sem número'] + $base;
        }
        $num = rtrim($m[0], '.,');
        // Texto extra além de moeda, sufixo e espaços indica algo que não sabemos ler ("25 a 30 mil", "entre ...").
        $rest = trim(preg_replace('/r\$|us\$|\$|€|brl|usd|eur(os?)?|reais?|dolar(es)?|mil|milhao|milhoes|\bmi\b|\bmm\b|\bk\b|\s/u', '', str_replace($num, '', $s)), '.,');
        if ($rest !== '') {
            return ['status' => 'invalido', 'note' => "texto não reconhecido: \"{$rest}\""] + $base;
        }

        $value = match (true) {
            (bool) preg_match('/^\d+$/', $num) => (float) $num,
            (bool) preg_match('/^\d{1,3}(\.\d{3})+$/', $num) => (float) str_replace('.', '', $num),                 // 25.000 · 1.250.000
            (bool) preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $num) => (float) str_replace(['.', ','], ['', '.'], $num), // 25.000,50 · 1,2
            (bool) preg_match('/^\d+,\d{1,2}$/', $num) => (float) str_replace(',', '.', $num),                        // 2500,5
            default => null,
        };
        if ($value === null) {
            $why = preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $num) || preg_match('/^\d+\.\d{1,2}$/', $num)
                ? 'separador em formato estrangeiro ou ambíguo' : 'formato numérico não reconhecido';

            return ['status' => $why === 'formato numérico não reconhecido' ? 'invalido' : 'ambiguo', 'note' => "\"{$num}\": {$why}"] + $base;
        }

        return ['status' => 'ok', 'value' => round($value * $mult, 2), 'note' => $suffix !== '' ? "multiplicador{$suffix}" : null] + $base;
    }

    /** Rótulo legível para a prévia: original → interpretação. */
    public static function describe(array $r): string
    {
        if ($r['status'] !== 'ok') {
            return "\"{$r['original']}\" não importado ({$r['status']}".($r['note'] ? ': '.$r['note'] : '').')';
        }

        return "\"{$r['original']}\" → ".number_format($r['value'], 2, ',', '.').' '.$r['currency'].($r['currency_assumed'] ? ' (moeda presumida)' : '');
    }
}
