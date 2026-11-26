<?php

namespace Tests\Unit;

use App\Support\FormWOS\DeadstockReasonMap;
use PHPUnit\Framework\TestCase;

class DeadstockReasonMapTest extends TestCase
{
    public function test_reason_16_supports_sales_and_factory_delivery_codes(): void
    {
        $this->assertSame('รอคิวจัดรถส่ง/ขอน้ำหนักก่อนส่ง', DeadstockReasonMap::description('SS16'));
        $this->assertSame(
            'รอคิวจัดรถส่ง/ขอน้ำหนักก่อนส่ง (ส่งมอบภายใน 30 วัน)',
            DeadstockReasonMap::description('SS16-1')
        );
        $this->assertSame(
            'รอคิวจัดรถส่ง/ขอน้ำหนักก่อนส่ง (จะส่งมอบเกิน 30 วัน)',
            DeadstockReasonMap::description('SS16-2')
        );
        $this->assertSame(
            'รอคิวจัดรถส่ง/ขอน้ำหนักก่อนส่ง (ส่งมอบภายใน 30 วัน)',
            DeadstockReasonMap::description('FF16-1')
        );
        $this->assertSame(
            'รอคิวจัดรถส่ง/ขอน้ำหนักก่อนส่ง (จะส่งมอบเกิน 30 วัน)',
            DeadstockReasonMap::description('FF16-2')
        );
    }
}
