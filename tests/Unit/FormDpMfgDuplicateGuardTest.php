<?php

namespace Tests\Unit;

use App\Services\FormDP\DeliveryConfirmationService;
use PHPUnit\Framework\TestCase;

class FormDpMfgDuplicateGuardTest extends TestCase
{
    public function test_mfg_spacing_is_canonicalized_for_storage_and_duplicate_checks(): void
    {
        $service = new DeliveryConfirmationService();

        $this->assertSame(
            '+W2601973, +W2602460',
            $service->canonicalMfgValue('+W2601973,+W2602460')
        );
        $this->assertSame(
            '+W2601973,+W2602460',
            $service->normalizedMfgKey(" +w2601973,  +w2602460 ")
        );
    }

    public function test_form_and_controller_guard_against_duplicate_submissions(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/FormDP/DeliveryPlanController.php');
        $blade = file_get_contents($root.'/resources/views/formdp/index.blade.php');

        $this->assertStringContainsString('->canonicalMfgValue(', $controller);
        $this->assertStringContainsString('->normalizedMfgKey(', $controller);
        $this->assertStringContainsString("REPLACE(UPPER(ISNULL(d.mfg_no, '')), ' ', '')", $controller);
        $this->assertStringContainsString('duplicateCheckInFlight', $blade);
        $this->assertStringContainsString('setDuplicateCheckBusy(true)', $blade);
        $this->assertStringContainsString('id="dpSubmitButton"', $blade);
    }
}
