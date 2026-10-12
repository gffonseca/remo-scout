<?php

namespace Tests\Unit;

use App\Support\MoneyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** F02 — dinheiro pt-BR: inequívoco entra; ambíguo/negativo/inválido nunca vira número. */
class MoneyParserTest extends TestCase
{
    public static function accepted(): array
    {
        return [
            'milhar pt-BR com R$' => ['R$ 25.000', 25000.0, 'BRL', false],
            'milhar e centavos' => ['R$ 25.000,50', 25000.5, 'BRL', false],
            'milhões com centavos' => ['R$ 1.250.000,00', 1250000.0, 'BRL', false],
            'sem moeda presume BRL' => ['25.000', 25000.0, 'BRL', true],
            'inteiro puro' => ['35000', 35000.0, 'BRL', true],
            'sufixo mil' => ['25 mil', 25000.0, 'BRL', true],
            'sufixo k' => ['80k', 80000.0, 'BRL', true],
            'mi com vírgula decimal' => ['1,2 mi', 1200000.0, 'BRL', true],
            'reais por extenso' => ['30 mil reais', 30000.0, 'BRL', false],
            'dólar explícito' => ['US$ 3.000', 3000.0, 'USD', false],
            'euro explícito' => ['€ 2.500', 2500.0, 'EUR', false],
            'decimal pt-BR curto' => ['2500,5', 2500.5, 'BRL', true],
        ];
    }

    #[DataProvider('accepted')]
    public function test_unambiguous_values_are_read(string $in, float $value, string $currency, bool $assumed): void
    {
        $r = MoneyParser::parse($in);
        $this->assertSame('ok', $r['status'], $in.' → '.json_encode($r));
        $this->assertSame($value, $r['value']);
        $this->assertSame($currency, $r['currency']);
        $this->assertSame($assumed, $r['currency_assumed']);
        $this->assertSame($in, $r['original']);
    }

    public static function rejected(): array
    {
        return [
            'negativo' => ['-1000', 'negativo'],
            'negativo com moeda' => ['R$ -1.000', 'negativo'],
            'negativo contábil' => ['(500)', 'negativo'],
            'vírgula de milhar estrangeira' => ['25,000', 'ambiguo'],
            'formato americano completo' => ['25,000.50', 'ambiguo'],
            'ponto decimal' => ['2.5', 'ambiguo'],
            'ponto decimal com mi' => ['2.5 mi', 'ambiguo'],
            'separadores misturados' => ['1.234,5.6', 'invalido'],
            'faixa em texto' => ['25 a 30 mil', 'invalido'],
            'sem número' => ['a combinar', 'invalido'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_doubtful_values_never_become_numbers(string $in, string $status): void
    {
        $r = MoneyParser::parse($in);
        $this->assertSame($status, $r['status'], $in.' → '.json_encode($r));
        $this->assertNull($r['value']);
        $this->assertStringContainsString('não importado', MoneyParser::describe($r));
    }

    public function test_spreadsheet_numbers_are_kept_but_negative_refused(): void
    {
        $this->assertSame(35000.0, MoneyParser::parse(35000)['value']);
        $this->assertSame(1234.56, MoneyParser::parse(1234.56)['value']);
        $this->assertSame('negativo', MoneyParser::parse(-10)['status']);
        $this->assertSame('vazio', MoneyParser::parse('')['status']);
        $this->assertSame('vazio', MoneyParser::parse(null)['status']);
    }

    public function test_round_trip_of_values_written_by_the_system(): void
    {
        foreach ([0.0, 0.5, 999.99, 25000.0, 25000.5, 1250000.0, 987654321.12] as $v) {
            $written = 'R$ '.number_format($v, 2, ',', '.');
            $this->assertSame($v, MoneyParser::parse($written)['value'], $written);
            $this->assertSame($v, MoneyParser::parse(number_format($v, 2, ',', '.'))['value'], 'sem símbolo de moeda');
        }
    }

    public function test_preview_shows_original_and_interpretation(): void
    {
        $this->assertSame('"R$ 25.000" → 25.000,00 BRL', MoneyParser::describe(MoneyParser::parse('R$ 25.000')));
        $this->assertSame('"25 mil" → 25.000,00 BRL (moeda presumida)', MoneyParser::describe(MoneyParser::parse('25 mil')));
    }
}
