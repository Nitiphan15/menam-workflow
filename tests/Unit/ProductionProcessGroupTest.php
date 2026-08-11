<?php

namespace Tests\Unit;

require_once dirname(__DIR__, 2) . '/app/Support/FormDP/ProductionProcessGroup.php';

use App\Support\FormDP\ProductionProcessGroup;
use PHPUnit\Framework\TestCase;

class ProductionProcessGroupTest extends TestCase
{
    /** @dataProvider processLabels */
    public function test_process_codes_are_grouped_for_tracking_filters(string $process, string $expected): void
    {
        $this->assertSame($expected, ProductionProcessGroup::label($process));
    }

    public static function processLabels(): array
    {
        return [
            'CB3' => ['CB3', 'Combine'],
            'CB3C' => ['CB3C', 'Combine'],
            'CB6SC' => ['cb6sc', 'Combine'],
            'CT' => ['CT', 'Cut'],
            'CU' => ['cu', 'Cut'],
            'D10' => ['D10', 'Cut'],
            'other station' => ['PK', 'PK'],
        ];
    }

    public function test_multiple_grouped_and_regular_stations_can_match_together(): void
    {
        $filters = ['Combine', 'Cut', 'PK'];

        $this->assertTrue(ProductionProcessGroup::matches('CB4C', $filters));
        $this->assertTrue(ProductionProcessGroup::matches('CU', $filters));
        $this->assertTrue(ProductionProcessGroup::matches('PK', $filters));
        $this->assertFalse(ProductionProcessGroup::matches('ST', $filters));
    }
}
