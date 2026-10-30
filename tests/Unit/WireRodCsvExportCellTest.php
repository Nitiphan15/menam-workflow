<?php

namespace Tests\Unit;

use App\Support\FormWR\CsvExportCell;
use PHPUnit\Framework\TestCase;

class WireRodCsvExportCellTest extends TestCase
{
    /** @dataProvider dangerousTextProvider */
    public function test_it_neutralizes_excel_formula_prefixes(string $input): void
    {
        $this->assertSame("'".$input, CsvExportCell::safe($input));
    }

    public function dangerousTextProvider(): array
    {
        return [['=1+1'], ['+SUM(A1:A2)'], ['-2+3'], ['@SUM(A1:A2)'], ['  =CMD()']];
    }

    public function test_it_preserves_numbers_and_safe_text(): void
    {
        $this->assertSame(123.45, CsvExportCell::safe(123.45));
        $this->assertSame('-123.4500', CsvExportCell::safe('-123.4500'));
        $this->assertSame('R400-01', CsvExportCell::safe('R400-01'));
    }
}
