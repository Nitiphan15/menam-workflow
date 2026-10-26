<?php

namespace Tests\Unit;

use App\Exports\FormOTD\InquiryShipDateSheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

class InquiryShipDateStockFallbackTest extends TestCase
{
    public function test_stock_is_blank_when_required_stock_connection_is_unavailable(): void
    {
        $sheet = new InquiryShipDateSheet(
            InquiryShipDateSheet::CONFIRM_BY_PLANNER_SHEET
        );
        DB::shouldReceive('connection')
            ->once()
            ->with('pgsqlw')
            ->andThrow(new \RuntimeException('stock database unavailable'));
        Log::shouldReceive('warning')
            ->once()
            ->with('FormDP inquiry export Stock FG lookup failed', \Mockery::on(
                fn(array $context) => $context['connection'] === 'pgsqlw'
                    && $context['message'] === 'stock database unavailable'
            ));

        $mapMethod = new ReflectionMethod($sheet, 'trackingStockFgMapForRows');
        $mapMethod->setAccessible(true);
        $stockMap = $mapMethod->invoke($sheet, collect([
            (object) ['part_number' => 'FG-001', 'mfg_no' => 'W2600001'],
        ]));

        $stockMethod = new ReflectionMethod($sheet, 'trackingStockFgForRow');
        $stockMethod->setAccessible(true);
        $stock = $stockMethod->invoke(
            $sheet,
            (object) ['part_number' => 'FG-001', 'mfg_no' => 'W2600001'],
            $stockMap
        );

        $this->assertNull($stock);
    }

    public function test_stock_remains_numeric_when_stock_connection_is_available(): void
    {
        $sheet = new InquiryShipDateSheet(
            InquiryShipDateSheet::CONFIRM_BY_PLANNER_SHEET
        );
        $method = new ReflectionMethod($sheet, 'trackingStockFgForRow');
        $method->setAccessible(true);

        $stock = $method->invoke(
            $sheet,
            (object) ['part_number' => 'FG-001', 'mfg_no' => '+P2600001'],
            [
                'pgsqlw' => ['__available' => true],
                'pgsqlp' => ['__available' => true, 'FG-001' => 12.5],
            ]
        );

        $this->assertSame(12.5, $stock);
    }
}
