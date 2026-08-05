<?php

namespace Tests\Unit;

use App\Http\Controllers\FormFC\ForecastRmDivisionController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FormFcPlanningDivisionSelectionTest extends TestCase
{
    public function test_planning_can_select_multiple_valid_divisions(): void
    {
        $request = Request::create('/fc/division', 'GET', [
            'divisions' => ['d1', 'D8', 'D8', 'INVALID'],
        ]);

        $this->assertSame(['D1', 'D8'], $this->selectedDivisions($request));
    }

    public function test_planning_defaults_to_every_division_when_selection_is_empty(): void
    {
        $request = Request::create('/fc/division', 'GET');

        $this->assertSame(
            ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9'],
            $this->selectedDivisions($request)
        );
    }

    private function selectedDivisions(Request $request): array
    {
        $controller = new ForecastRmDivisionController();
        $method = new ReflectionMethod($controller, 'requestedPlanningDivisions');
        $method->setAccessible(true);

        return $method->invoke($controller, $request);
    }
}
