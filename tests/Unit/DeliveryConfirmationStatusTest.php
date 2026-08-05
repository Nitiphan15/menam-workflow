<?php

namespace Tests\Unit;

use App\Models\FormDP\DeliveryConfirmation;
use PHPUnit\Framework\TestCase;

class DeliveryConfirmationStatusTest extends TestCase
{
    /** @dataProvider statusProvider */
    public function test_status_display_and_active_state(
        ?string $status,
        string $label,
        string $badge,
        bool $active
    ): void {
        $this->assertSame($label, DeliveryConfirmation::statusLabel($status));
        $this->assertSame($badge, DeliveryConfirmation::statusBadgeClass($status));
        $this->assertSame($active, DeliveryConfirmation::isActiveStatus($status));
    }

    public static function statusProvider(): array
    {
        return [
            'confirm' => ['CONFIRM', 'Confirm Delivery', 'success', true],
            'postpone' => ['POSTPONE', 'Request Postpone', 'warning', true],
            'cancelled' => ['CANCELLED', 'ยกเลิกการยืนยัน', 'danger', false],
            'empty' => [null, '-', 'light text-dark border', false],
        ];
    }
}
