<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class WirerodExport implements WithMultipleSheets
{
    protected array $data;   // รับข้อมูลที่ Controller คำนวณมาแล้ว
    protected array $meta;   // เก็บเดือน/label/scale/ปี ฯลฯ

    public function __construct(array $data, array $meta = [])
    {
        $this->data = $data;
        $this->meta = $meta;
    }

    public function sheets(): array
    {
        return [
            // ชีตซ้าย: สรุปตามเดือน
            new WirerodOpenSummarySheet(
                $this->data['poByItemMonth'],      // rows: [{item, description, by_month[<m>], total}]
                $this->meta['monthOrder'] ?? [],
                $this->meta['monthLabels'] ?? []
            ),
            // ชีตขวา: Balance + Overdue
            new WirerodBalanceOverdueSheet(
                $this->data['balanceWithOpen'],     // rows: [{item, company, balance, open}]
                $this->meta['SCALE'] ?? 1
            ),
            // ชีตรายละเอียดล่าง: PO not-due
            new WirerodDetailSheet(
                $this->data['poLines'],             // collection ที่คูณ scale แล้วสำหรับ qty/received/open
                $this->data['rawTotals']            // summary (คูณแล้วตามที่หน้าเว็บใช้)
            ),
        ];
    }
}
