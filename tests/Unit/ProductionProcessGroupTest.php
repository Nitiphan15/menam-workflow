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

    public function test_filter_matches_any_station_in_the_full_routing(): void
    {
        $routing = ['SH1', 'CB3C', 'CT', 'PK'];

        $this->assertTrue(ProductionProcessGroup::matchesAny($routing, ['Combine']));
        $this->assertTrue(ProductionProcessGroup::matchesAny($routing, ['Cut']));
        $this->assertTrue(ProductionProcessGroup::matchesAny($routing, ['SH1', 'PK']));
        $this->assertFalse(ProductionProcessGroup::matchesAny($routing, ['ST']));
    }

    public function test_complete_status_is_not_a_selectable_station(): void
    {
        $this->assertSame(
            ['Combine', 'Cut', 'PK'],
            ProductionProcessGroup::selectableLabels(['Completed', 'CB3', 'Complete', 'CU', 'PK'])
        );
    }
}
