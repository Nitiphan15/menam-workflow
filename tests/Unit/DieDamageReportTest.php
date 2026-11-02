<?php

namespace Tests\Unit;

use App\Exports\FormDIE\DieDamageExport;
use App\Services\FormDIE\DieDamageReportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class DieDamageReportTest extends TestCase
{
    public function test_rates_distinguish_missing_zero_and_signed_size_difference(): void
    {
        $s = new DieDamageReportService();
        $this->assertSame(9.45, $s->metrics(['production_kg' => 1000, 'price' => 9450])['thb_kg']);
        $this->assertNull($s->metrics(['production_kg' => 0, 'price' => 9450])['thb_kg']);
        $this->assertNull($s->metrics(['production_kg' => 1000])['thb_kg']);
        $this->assertSame(0.0, $s->metrics(['production_kg' => 1000, 'price' => 0])['thb_kg']);
        $this->assertSame(-1.0, $s->metrics(['initial_size' => 3, 'current_size' => 2])['size_diff']);
    }

    public function test_pivots_keep_repeat_events_years_and_missing_dimensions(): void
    {
        $records = [
            ['site' => 'W', 'die_code' => 'D1', 'damaged_at' => '2024-01-05', 'die_type' => 'Diamond', 'fracture_type' => 'แตกNib'],
            ['site' => 'W', 'die_code' => 'D1', 'damaged_at' => '2025-01-05', 'die_type' => 'TC', 'fracture_type' => null],
            ['site' => 'P', 'die_code' => 'D1', 'damaged_at' => '2024-01-06', 'die_type' => null, 'fracture_type' => null],
        ];
        $s = new DieDamageReportService();
        $p = $s->pivot($records, 'type');
        $this->assertSame(3, $p['total']);
        $this->assertSame(1, $p['rows']['2024-01']['ไม่ระบุ']);
        $this->assertSame(1, $p['rows']['2025-01']['TC']);
        $this->assertSame(1, $s->pivot($records, 'fracture-type')['total']);
        $this->assertCount(2, $s->pivot($records, 'weight')['rows']);
    }

    public function test_export_preserves_identifiers_and_prevents_formulas(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $export = new DieDamageExport([]);
        foreach (['=HYPERLINK("x")', '+W24001', '00001'] as $i => $value) {
            $cell = $sheet->getCell('A'.($i + 1));
            $export->bindValue($cell, $value);
            $this->assertSame('s', $cell->getDataType());
            $this->assertSame($value, $cell->getValue());
        }
    }
}
