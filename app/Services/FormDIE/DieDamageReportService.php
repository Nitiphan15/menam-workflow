<?php

namespace App\Services\FormDIE;

class DieDamageReportService
{
    public const FIELDS = [
        'code' => 'CODE', 'supplier' => 'Supplier', 'initial_size' => 'ขนาดแรกเข้า',
        'current_size' => 'ขนาดปัจจุบัน', 'production_kg' => 'น้ำหนักผลิตรวม (Kg)',
        'usage_count' => 'เบิกใช้งาน (ครั้ง)', 'stock_size' => 'ขนาดที่เสียเข้า STOCK',
        'reason' => 'สาเหตุ', 'fracture_type' => 'ประเภทแตก', 'die_type' => 'ประเภทไดร์',
        'machine' => 'Line สุดท้าย', 'customer' => 'Customer', 'workorder' => 'MFG / WO',
        'wire_grade' => 'เกรดลวด', 'material_supplier' => 'ผู้ผลิตวัตถุดิบ', 'material_problem' => 'ปัญหาวัตถุดิบ',
        'tensile_min' => 'MIN Tensile (N/mm²)', 'tensile_max' => 'MAX Tensile (N/mm²)',
        'tensile_drawing' => 'Tensile รีด/ตัดเพลา (N/mm²)', 'tensile_annealing' => 'Tensile อันนีล (N/mm²)',
        'price' => 'Price (บาท)',
    ];

    public const NUMBERS = ['initial_size', 'current_size', 'stock_size', 'production_kg', 'price', 'tensile_min', 'tensile_max', 'tensile_drawing', 'tensile_annealing', 'usage_count'];

    public const REPORTS = [
        'month' => ['รายเดือน', 'month', null],
        'damage' => ['สาเหตุเสียรายเดือน', 'month', 'reason'],
        'fracture' => ['ประเภทแตกรายเดือน', 'month', 'fracture_type'],
        'fracture-type' => ['ประเภทแตก แยกชนิด DIE', 'month', 'die_type'],
        'type' => ['ประเภท DIE รายเดือน', 'month', 'die_type'],
        'brand' => ['Supplier รายเดือน', 'month', 'supplier'],
        'machine-month' => ['เครื่องจักรรายเดือน', 'month', 'machine'],
        'machine-type' => ['เครื่องจักร × ประเภท DIE', 'machine', 'die_type'],
        'machine' => ['DIE เสียจากเครื่องจักร', 'machine', null],
        'grade' => ['เกรดลวด × DIE', 'wire_grade', null],
        'customer' => ['ลูกค้า × DIE', 'customer', null],
        'machine-grade' => ['เครื่องจักร × เกรดลวด', 'machine', 'wire_grade'],
        'material' => ['ปัญหาวัตถุดิบ', 'material_supplier', 'material_problem'],
        'weight' => ['น้ำหนักผลิต / ต้นทุน', 'die_code', null],
    ];

    public function metrics(array $row): array
    {
        $kg = $row['production_kg'] ?? null;
        $price = $row['price'] ?? null;

        return [
            'thb_kg' => $kg !== null && (float) $kg > 0 && $price !== null ? (float) $price / (float) $kg : null,
            'size_diff' => isset($row['current_size'], $row['initial_size']) ? (float) $row['current_size'] - (float) $row['initial_size'] : null,
        ];
    }

    public function pivot(iterable $records, string $report): array
    {
        [$title, $group, $column] = self::REPORTS[$report];
        $rows = [];
        $columns = [];
        $total = 0;
        foreach ($records as $record) {
            $r = is_array($record) ? $record : $record->toArray();
            if (in_array($report, ['fracture', 'fracture-type'], true) && empty($r['fracture_type'])) {
                continue;
            }
            $key = $group === 'month' ? substr((string) $r['damaged_at'], 0, 7) : (trim((string) ($r[$group] ?? '')) ?: 'ไม่ระบุ');
            if ($group === 'die_code') {
                $key = $r['site'].' / '.$key;
            }
            $col = $column ? (trim((string) ($r[$column] ?? '')) ?: 'ไม่ระบุ') : 'จำนวนครั้ง';
            $columns[$col] = true;
            $rows[$key][$col] = ($rows[$key][$col] ?? 0) + 1;
            $total++;
        }
        ksort($rows, SORT_NATURAL);
        $columns = array_keys($columns);
        sort($columns, SORT_NATURAL);

        return compact('title', 'rows', 'columns', 'total');
    }
}
