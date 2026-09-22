<?php

namespace Tests\Unit;

use App\Services\FormVC\VariableCostService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionClass;

class VariableCostPeriodFilterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/app/Services/FormVC/VariableCostService.php';
    }

    public function test_month_inputs_expand_to_complete_month_boundaries(): void
    {
        $filters = $this->invokePrivate('normalizeFilters', [[
            'date_from' => '2025-02',
            'date_to' => '2026-09',
            'site' => 'ALL',
        ]]);

        $this->assertSame('2025-02-01', $filters['date_from']);
        $this->assertSame('2026-09-30', $filters['date_to']);
    }

    public function test_reversed_month_inputs_keep_complete_month_boundaries(): void
    {
        $filters = $this->invokePrivate('normalizeFilters', [[
            'date_from' => '2026-09',
            'date_to' => '2025-02',
            'site' => 'ALL',
        ]]);

        $this->assertSame('2025-02-01', $filters['date_from']);
        $this->assertSame('2026-09-30', $filters['date_to']);
    }

    public function test_year_filter_accepts_multiple_unique_years_and_old_single_year_links(): void
    {
        $multi = $this->invokePrivate('normalizeYearlyFilters', [[
            'years' => ['2024', '2026', '2024', 'invalid'],
            'site' => 'ALL',
        ]]);
        $legacy = $this->invokePrivate('normalizeYearlyFilters', [[
            'year' => '2023',
            'site' => 'ALL',
        ]]);

        $this->assertSame([2026, 2024], $multi['years']);
        $this->assertSame([2023], $legacy['years']);
    }

    public function test_views_use_month_inputs_and_multi_year_selection(): void
    {
        $filtersView = file_get_contents(dirname(__DIR__, 2).'/resources/views/formvc/partials/filters.blade.php');
        $yearlyView = file_get_contents(dirname(__DIR__, 2).'/resources/views/formvc/yearly.blade.php');

        $this->assertSame(2, substr_count($filtersView, 'type="month"'));
        $this->assertStringNotContainsString('type="date"', $filtersView);
        $this->assertStringContainsString('name="years[]"', $yearlyView);
        $this->assertStringContainsString('vc-yearly-years', $yearlyView);
    }

    public function test_shared_year_comparison_loads_each_year_sequentially(): void
    {
        $base = dirname(__DIR__, 2);
        $partial = file_get_contents($base.'/resources/views/formvc/partials/year-comparison.blade.php');
        $routes = file_get_contents($base.'/routes/web.php');

        foreach (['summary', 'monthly', 'matrix', 'accounts'] as $page) {
            $view = file_get_contents($base."/resources/views/formvc/{$page}.blade.php");
            $this->assertStringContainsString("formvc.partials.year-comparison", $view);
        }

        $this->assertStringContainsString('for (let i = 0; i < years.length; i++)', $partial);
        $this->assertStringContainsString("request()->input('years', [])", $partial);
        $this->assertStringContainsString("new Chart(context", $partial);
        $this->assertStringContainsString("Route::get('/year-comparison'", $routes);
    }

    private function invokePrivate(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod(VariableCostService::class, $method);
        $reflection->setAccessible(true);
        $service = (new ReflectionClass(VariableCostService::class))->newInstanceWithoutConstructor();

        return $reflection->invokeArgs($service, $arguments);
    }
}
