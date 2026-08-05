<?php

namespace Tests\Unit;

use App\Support\FormWOS\DeadstockReviewStatus;
use PHPUnit\Framework\TestCase;

class DeadstockReviewStatusTest extends TestCase
{
    public function test_it_exposes_only_the_three_current_statuses(): void
    {
        $this->assertSame([
            'open' => 'ยังไม่ได้ติดตาม',
            'waiting_follow_up' => 'กำลังติดตาม รอคำตอบจากลูกค้า',
            'in_progress' => 'มีกำหนดส่งมอบแล้ว',
        ], DeadstockReviewStatus::labels());
    }

    public function test_it_normalizes_legacy_statuses_into_the_current_groups(): void
    {
        $this->assertSame('open', DeadstockReviewStatus::normalize('ยังไม่เริ่ม'));
        $this->assertSame('waiting_follow_up', DeadstockReviewStatus::normalize('waiting_sales'));
        $this->assertSame('waiting_follow_up', DeadstockReviewStatus::normalize('ยังไม่ได้ส่งมอบเดือนปัจจุบัน'));
        $this->assertSame('in_progress', DeadstockReviewStatus::normalize('follow_up'));
        $this->assertSame('in_progress', DeadstockReviewStatus::normalize('ปิดแล้ว'));
        $this->assertSame('open', DeadstockReviewStatus::normalize('ยังไม่ได้ติดตาม'));
        $this->assertSame('waiting_follow_up', DeadstockReviewStatus::normalize('กำลังติดตาม รอคำตอบจากลูกค้า'));
        $this->assertSame('in_progress', DeadstockReviewStatus::normalize('มีกำหนดส่งมอบแล้ว'));
    }

    public function test_filter_groups_include_current_and_legacy_values(): void
    {
        $this->assertSame(['open'], DeadstockReviewStatus::valuesForFilter('open'));
        $this->assertSame([
            'waiting_follow_up',
            'waiting_sales',
            'waiting_customer',
            'waiting_delivery',
            'not_delivered_current_month',
        ], DeadstockReviewStatus::valuesForFilter('waiting_follow_up'));
        $this->assertSame([
            'in_progress',
            'follow_up',
            'closed',
        ], DeadstockReviewStatus::valuesForFilter('in_progress'));
    }
}
