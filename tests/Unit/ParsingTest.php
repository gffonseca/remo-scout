<?php

namespace Tests\Unit;

use App\Enums\Position;
use App\Http\Controllers\ExportController;
use App\Services\Import\PlayerImporter;
use PHPUnit\Framework\TestCase;

class ParsingTest extends TestCase
{
    public function test_position_guess_accepts_codes_and_free_text(): void
    {
        $this->assertSame(Position::LE, Position::guess('Lateral esquerdo'));
        $this->assertSame(Position::LE, Position::guess('le'));
        $this->assertSame(Position::VOL, Position::guess('Volante'));
        $this->assertSame(Position::CA, Position::guess('Centroavante'));
        $this->assertNull(Position::guess('posição inventada'));
        $this->assertNull(Position::guess(''));
    }

    public function test_dates_are_parsed_from_common_formats(): void
    {
        $this->assertSame('2001-03-15', PlayerImporter::parseDate('15/03/2001')->toDateString());
        $this->assertSame('2001-03-05', PlayerImporter::parseDate('5/3/2001')->toDateString());
        $this->assertSame('2001-03-15', PlayerImporter::parseDate('2001-03-15')->toDateString());
        $this->assertSame('2026-12-31', PlayerImporter::parseDate('12/2026')->toDateString());
        $this->assertSame('2000-01-01', PlayerImporter::parseDate(36526)->toDateString()); // serial Excel
        $this->assertNull(PlayerImporter::parseDate('31/02/2001'));
        $this->assertNull(PlayerImporter::parseDate('ontem'));
    }

    public function test_money_parsing_handles_brazilian_formats(): void
    {
        $this->assertSame(1250000.0, PlayerImporter::parseMoney('R$ 1.250.000,00'));
        $this->assertSame(1200000.0, PlayerImporter::parseMoney('1,2 mi'));
        $this->assertSame(80000.0, PlayerImporter::parseMoney('80 mil'));
        $this->assertSame(35000.0, PlayerImporter::parseMoney(35000));
        $this->assertNull(PlayerImporter::parseMoney(''));
        $this->assertNull(PlayerImporter::parseMoney('a combinar'));
    }

    public function test_height_and_foot(): void
    {
        $this->assertSame(178, PlayerImporter::parseHeight('1,78'));
        $this->assertSame(186, PlayerImporter::parseHeight('186 cm'));
        $this->assertNull(PlayerImporter::parseHeight('3'));
        $this->assertSame('esquerdo', PlayerImporter::parseFoot('Canhoto'));
        $this->assertSame('direito', PlayerImporter::parseFoot('D'));
        $this->assertNull(PlayerImporter::parseFoot('?'));
    }

    public function test_formula_injection_is_neutralized_in_exports(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", ExportController::safeCell('=HYPERLINK("x")'));
        $this->assertSame("'+1", ExportController::safeCell('+1'));
        $this->assertSame("'@SUM(A1)", ExportController::safeCell('@SUM(A1)'));
        $this->assertSame('Normal', ExportController::safeCell('Normal'));
        $this->assertSame(42, ExportController::safeCell(42));
    }
}
