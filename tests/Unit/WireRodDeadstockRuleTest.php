<?php

namespace Tests\Unit;

use App\Support\FormWR\WireRodDeadstockRule;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class WireRodDeadstockRuleTest extends TestCase
{
    public function test_day_180_is_incoming_and_day_181_is_dead(): void
    {
        $asOf = Carbon::parse('2026-10-26', 'Asia/Bangkok');

        $this->assertSame('INCOMING', WireRodDeadstockRule::stockStatus($asOf->copy()->subDays(180), $asOf));
        $this->assertSame('DEAD', WireRodDeadstockRule::stockStatus($asOf->copy()->subDays(181), $asOf));
        $this->assertSame('UNKNOWN', WireRodDeadstockRule::stockStatus(null, $asOf));
    }

    public function test_slit_coil_precedes_part_type_group(): void
    {
        $this->assertSame('Slit Coil', WireRodDeadstockRule::materialGroup('Slit Coil 3.0 mm', 70));
        $this->assertSame('Stainless Steel', WireRodDeadstockRule::materialGroup('Rod', 70));
        $this->assertSame('Steel', WireRodDeadstockRule::materialGroup('Rod', 71));
        $this->assertSame('Grating', WireRodDeadstockRule::materialGroup('Rod', 95));
        $this->assertSame('Other', WireRodDeadstockRule::materialGroup('Rod', 99));
    }

    public function test_d8_is_pcs_and_other_sales_are_kg(): void
    {
        $this->assertSame('PCS', WireRodDeadstockRule::displayUnit('D8', '01'));
        $this->assertSame('KG', WireRodDeadstockRule::displayUnit('D1', '03'));
        $this->assertSame('KG', WireRodDeadstockRule::displayUnit(null, '01'));
        $this->assertSame('PCS', WireRodDeadstockRule::displayUnit(null, '03'));
    }
}
