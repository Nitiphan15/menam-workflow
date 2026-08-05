<?php

namespace Tests\Unit;

use App\Http\Controllers\FormWOS\DeadstockReportController;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DeadstockReviewImportDefaultTest extends TestCase
{
    public function test_waiting_status_defaults_follow_up_date_to_import_month_end(): void
    {
        $payload = $this->payload([
            'review_status' => $this->cell('กำลังติดตาม รอคำตอบจากลูกค้า'),
        ], Carbon::parse('2026-07-12 09:30:00', 'Asia/Bangkok'));

        $this->assertSame('waiting_follow_up', $payload['review_status']);
        $this->assertSame('2026-07-31', $payload['next_follow_up_date']);
    }

    public function test_imported_follow_up_date_is_preserved(): void
    {
        $payload = $this->payload([
            'review_status' => $this->cell('มีกำหนดส่งมอบแล้ว'),
            'next_follow_up_date' => $this->cell('2026-08-15'),
        ], Carbon::parse('2026-07-12 09:30:00', 'Asia/Bangkok'));

        $this->assertSame('2026-08-15', $payload['next_follow_up_date']);
    }

    public function test_untracked_status_does_not_receive_a_follow_up_date(): void
    {
        $payload = $this->payload([
            'review_status' => $this->cell('ยังไม่ได้ติดตาม'),
        ], Carbon::parse('2026-07-12 09:30:00', 'Asia/Bangkok'));

        $this->assertArrayNotHasKey('next_follow_up_date', $payload);
    }

    public function test_new_due_date_column_becomes_the_latest_saved_due_date(): void
    {
        $payload = $this->payload([
            'revised_due_date' => $this->cell('2026-09-30'),
        ], Carbon::parse('2026-07-12 09:30:00', 'Asia/Bangkok'));

        $this->assertSame('2026-09-30', $payload['revised_due_date']);
    }

    private function payload(array $row, Carbon $importedAt): array
    {
        $controller = new DeadstockReportController();

        return (new ReflectionMethod($controller, 'deadstockReviewPayloadFromImportRow'))
            ->invoke($controller, $row, $importedAt);
    }

    private function cell(string $value): array
    {
        return ['raw' => $value, 'text' => $value];
    }
}
