<?php

namespace App\Exports\FormWIP;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export WIP Monitoring — 4 sheet: สรุป / Work Center / WO / ยอดแต่ละขั้น
 * ใช้ข้อมูลชุดเดียวกับหน้าจอ (ตาม filter ปัจจุบัน ไม่แบ่งหน้า)
 */
class WipExport implements WithMultipleSheets
{
    public function __construct(private array $data)
    {
    }

    public function sheets(): array
    {
        return [
            $this->summarySheet(),
            $this->wcSheet(),
            $this->woSheet(),
            $this->stepSheet(),
        ];
    }

    private function summarySheet(): WipArraySheet
    {
        $d = $this->data;
        $f = $d['filters'];
        $rows = [
            ['WIP & Stock Pending Monitoring (Prototype)', '', '', ''],
            ['ข้อมูล ณ', $this->dt($d['fetchedAt']), 'Export เมื่อ', now()->format('d/m/Y H:i')],
            ['Site', $f['site'], 'WO เปิดย้อนหลัง (วัน)', $f['days']],
            ['Route', $f['scope'] === 'pk' ? 'จบด้วย PK' : 'ทุก Route', 'Work Center', $f['wc'] ?: 'ทั้งหมด'],
            ['Aging', $f['aging'] ?: 'ทั้งหมด', 'สถานะ', $f['status'] !== '' ? $d['statuses'][$f['status']]['label'] : 'ทั้งหมด'],
            ['ค้นหา', $f['q'] ?: '-', 'WIP คงค้างรวม (ตัน)', round($d['totalWipKg'] / 1000, 3)],
            ['ตัวกรองอื่น', $this->otherFilters($f), 'เรียงตาม', $f['sort'] ?? 'aging'],
            ['สถานะ', 'คำอธิบาย', 'น้ำหนัก (ตัน)', 'จำนวน WO'],
        ];
        foreach ($d['statusCards'] as $key => $c) {
            $rows[] = [
                $c['label'],
                $c['th'],
                $key === 'WAITING_QC' ? 'N/A' : round(($c['kg'] ?? 0) / 1000, 3),
                $key === 'WAITING_QC' ? 'N/A' : $c['wo_count'],
            ];
        }
        $rows[] = ['', '', '', ''];
        $rows[] = ['Aging', 'เกณฑ์', 'น้ำหนัก (ตัน)', 'จำนวน WO'];
        $criteria = ['NORMAL' => '0–7 วัน', 'MONITOR' => '8–15 วัน', 'WARNING' => '16–30 วัน', 'CRITICAL' => 'มากกว่า 30 วัน'];
        foreach ($d['agingSummary'] as $key => $a) {
            $rows[] = [$a['label'], $criteria[$key] ?? '', round($a['kg'] / 1000, 3), $a['wo_count']];
        }

        return new WipArraySheet('สรุป', $rows, headerRows: [1, 8, 9 + count($d['statusCards']) + 1], freeze: null);
    }

    private function wcSheet(): WipArraySheet
    {
        $rows = [['Work Center', 'ชื่อ', 'รอเริ่ม (kg)', 'กำลังผลิต (kg)', 'รอแพ็ค (kg)', 'รวม (kg)', 'จำนวน WO']];
        foreach ($this->data['wcSummary'] as $w) {
            $rows[] = [
                $w['wc_code'], $w['wc_name'],
                round($w['waiting_prod'], 2), round($w['in_process'], 2), round($w['waiting_pack'], 2),
                round($w['total'], 2), $w['wo_count'],
            ];
        }

        return new WipArraySheet('Work Center', $rows, numberCols: ['C', 'D', 'E', 'F']);
    }

    private function woSheet(): WipArraySheet
    {
        $d = $this->data;
        $rows = [[
            'Site', 'WO No.', 'สถานะ', 'Suspended', 'Product Code', 'Product Description', 'Customer', 'Size', 'Grade', 'SO',
            'Qty WO (kg)', 'รอเข้าผลิต', 'กำลังผลิต', 'รอแพ็ค', 'รอรับเข้า FG', 'Hold/Rework', 'WIP ค้างรวม',
            'Output ขั้นสุดท้าย', 'รับเข้า FG แล้ว (ERP)', 'พบใน ERP', 'ขั้นปัจจุบัน', 'แผนก', 'เครื่อง', 'Routing',
            'วันเปิด WO', 'Due Date', 'เริ่มผลิต', 'เคลื่อนไหวล่าสุด', 'รับเข้า FG ครั้งแรก', 'รับเข้า FG ล่าสุด',
            'Aging (วัน)', 'ระดับ Aging', 'Aging นับจาก', 'ฐาน Aging',
        ]];
        foreach ($d['rows'] as $r) {
            $b = $r['buckets'];
            $rows[] = [
                $r['site'], $r['wo_no'], $d['statuses'][$r['status']]['label'], $r['suspended'] ? 'Y' : '',
                $r['part_number'], $r['part_desc'], $r['customer'], $r['size'], $r['grade'], $r['ord_number'],
                $r['qty'], $b['WAITING_PRODUCTION'], $b['IN_PROCESS'], $b['WAITING_PACKAGING'], $b['WAITING_FG'], $b['HOLD'], $r['wip_total'],
                $r['final_output'], $r['erp_received'], $r['erp_found'] ? 'Y' : 'N', $r['current_wc'], (string) ($r['current_dept'] ?? '-'), $r['current_machine'], $r['route_text'],
                $this->d($r['dateopen']), $this->d($r['reqdate']), $this->dt($r['production_start']), $this->dt($r['last_tx']),
                $this->d($r['first_fg_date']), $this->d($r['last_fg_date']),
                $r['aging_days'], $d['agingBands'][$r['aging_band']]['label'], $this->dt($r['aging_ref']),
                ($r['aging_source'] ?? 'activity') === 'status' ? 'วันเข้าสถานะ' : 'ประมาณจาก transaction',
            ];
        }

        return new WipArraySheet('WO', $rows, numberCols: ['K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'], freeze: 'C2');
    }

    private function stepSheet(): WipArraySheet
    {
        $rows = [[
            'Site', 'WO No.', 'Seq', 'Work Center', 'ชื่อ WC', 'แผนก', 'Size in', 'Size out', 'เบิกเข้า (kg)', 'Output ของดี (kg)',
            'NCR (kg)', 'ค้างที่ขั้นนี้ (kg)', 'เครื่องล่าสุด', 'รับครั้งแรก', 'รับล่าสุด', 'ขั้นปัจจุบัน',
        ]];
        foreach ($this->data['rows'] as $r) {
            foreach ($r['steps'] as $s) {
                $rows[] = [
                    $r['site'], $r['wo_no'], $s['seq'], $s['wc_code'], $s['wc_name'], (string) ($s['dept'] ?? '-'),
                    $s['size_in'] !== null ? (string) $s['size_in'] : '', $s['size_out'] !== null ? (string) $s['size_out'] : '',
                    $s['input_qty'], $s['good_qty'], $s['ncr_qty'], $s['balance'],
                    (string) ($s['machine'] ?? ''), $this->dt($s['first_stamp']), $this->dt($s['last_stamp']),
                    $s['wc_code'] === $r['current_wc'] ? 'Y' : '',
                ];
            }
        }

        return new WipArraySheet('ยอดแต่ละขั้น', $rows, numberCols: ['I', 'J', 'K', 'L'], freeze: 'D2');
    }

    private function otherFilters(array $f): string
    {
        $labels = [
            'dept' => 'แผนก', 'machine' => 'เครื่อง', 'product' => 'Product', 'customer' => 'Customer',
            'grade' => 'Grade', 'size' => 'Size', 'date_from' => 'เปิดตั้งแต่', 'date_to' => 'เปิดถึง', 'due' => 'Due',
        ];
        $parts = [];
        foreach ($labels as $key => $label) {
            if (($f[$key] ?? '') !== '') {
                $parts[] = "{$label}: {$f[$key]}";
            }
        }
        if (empty($f['only_wip'])) {
            $parts[] = 'แสดง WO ที่เปิดทั้งหมด';
        }

        return $parts ? implode(' · ', $parts) : '-';
    }

    private function d($v): string
    {
        return $v ? Carbon::parse($v)->format('d/m/Y') : '';
    }

    private function dt($v): string
    {
        return $v ? Carbon::parse($v)->format('d/m/Y H:i') : '';
    }
}
