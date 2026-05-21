<?php

namespace App\Services\FormAccounting;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LossProvisionService
{
    private const SITES = [
        'WIRE' => 'pgsqlw',
        'PLUS' => 'pgsqlp',
    ];

    public function getData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->fetchRows($filters);
        $periods = $this->buildPeriods($rows, $filters);
        $invoiceMap = $this->buildInvoiceMap($rows, $periods);
        $invoiceMap = $this->filterInvoiceMapByStatus($invoiceMap, $filters['status']);
        $invoiceMap = $this->filterInvoiceMapByAging($invoiceMap, $filters['aging']);

        $totalAmount = (float) $invoiceMap->sum('ar_amount');
        $totalCash = (float) $invoiceMap->sum('total_cash');
        $totalRemaining = (float) $invoiceMap->sum('remaining');

        $unpaidMap = $invoiceMap->filter(fn($r) => $r->status !== 'PAID');
        $over90 = (float) $unpaidMap->filter(fn($r) => $r->days_outstanding > 90)->sum('remaining');
        $over180 = (float) $unpaidMap->filter(fn($r) => $r->days_outstanding > 180)->sum('remaining');

        $agingSummary = collect(self::agingBuckets())->map(function ($bucket, $key) use ($unpaidMap) {
            $bucketRows = $unpaidMap->where('aging_key', $key);
            return (object) [
                'key' => $key,
                'label' => $bucket['label'],
                'color' => $bucket['color'],
                'remaining' => (float) $bucketRows->sum('remaining'),
                'count' => $bucketRows->count(),
            ];
        })->values();

        $customerOptions = $invoiceMap->pluck('customer_name')
            ->filter(fn($n) => trim((string) $n) !== '')
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'filters' => $filters,
            'rows' => $rows->take(1000)->values(),
            'invoiceMap' => $invoiceMap,
            'periods' => $periods,
            'allRowCount' => $rows->count(),
            'agingSummary' => $agingSummary,
            'customerOptions' => $customerOptions,
            'agingBuckets' => self::agingBuckets(),
            'kpis' => [
                'invoice_count' => $invoiceMap->count(),
                'customer_count' => $invoiceMap->pluck('customer_name')->filter()->unique()->count(),
                'row_count' => $rows->count(),
                'total_amount' => $totalAmount,
                'total_paid' => $totalCash,
                'total_cash' => $totalCash,
                'remaining' => $totalRemaining,
                'over_90' => $over90,
                'over_180' => $over180,
            ],
        ];
    }

    public function export(array $filters)
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->fetchRows($filters);
        $periods = $this->buildPeriods($rows, $filters);
        $invoiceMap = $this->buildInvoiceMap($rows, $periods);
        $invoiceMap = $this->filterInvoiceMapByStatus($invoiceMap, $filters['status']);
        $invoiceMap = $this->filterInvoiceMapByAging($invoiceMap, $filters['aging']);
        $fileName = 'loss_provision_' . str_replace('-', '', $filters['date_from']) . '_' . str_replace('-', '', $filters['date_to']) . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Map');

        $sheet->fromArray(['รายการเผื่อผลขาดทุน'], null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->fromArray([
            ['Date From', $filters['date_from']],
            ['Date To', $filters['date_to']],
            ['Site', $filters['site']],
            ['Customer', $filters['customer']],
            ['Invoice', $filters['invoice']],
        ], null, 'A3');
        $sheet->getStyle('A3:A7')->getFont()->setBold(true);

        $headers = $this->mapExportHeaders($periods);
        $sheet->fromArray($headers, null, 'A9');
        $lastHeaderColumn = Coordinate::stringFromColumnIndex(count($headers[0]));
        $this->styleHeader($sheet, "A9:{$lastHeaderColumn}9");

        // Freeze header rows (1-9) when scrolling
        $sheet->freezePane('A10');

        $invoiceRows = $invoiceMap->values();
        $data = $invoiceRows->map(fn($row) => $this->mapRowToArray($row, $periods))->all();
        if (!empty($data)) {
            $sheet->fromArray($data, null, 'A10');
            $lastRow = 9 + count($data);

            // Apply auto-filter to header + data ONLY (exclude totals + verify rows below)
            $sheet->setAutoFilter("A9:{$lastHeaderColumn}{$lastRow}");
            $amountStartColumn = Coordinate::stringFromColumnIndex(7);   // G = netamount
            $amountEndColumn = Coordinate::stringFromColumnIndex(9 + $periods->count() + 2); // last = remaining
            $sheet->getStyle("{$amountStartColumn}10:{$amountEndColumn}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');

            // Color RETURN documents (CN/EC) red on amount columns + จำนวนเงิน + เดือน + รวมรับชำระ + คงเหลือ
            foreach ($invoiceRows as $i => $row) {
                if ((string) ($row->document_type ?? '') === 'RETURN') {
                    $excelRow = 10 + $i;
                    $sheet->getStyle("{$amountStartColumn}{$excelRow}:{$amountEndColumn}{$excelRow}")
                        ->getFont()->getColor()->setRGB('CC0000');
                    // also color the document number column (C) so the row is identifiable
                    $sheet->getStyle("C{$excelRow}")->getFont()->getColor()->setRGB('CC0000');
                }
            }

            // ===== Fill remaining (Q) every row =====
            // I = จำนวนเงิน
            // P = รวมรับชำระ
            // Q = คงเหลือ
            $totalColCount = count($headers[0]);

            $colI = 'I';
            $colP = Coordinate::stringFromColumnIndex($totalColCount - 2); // P = รวมรับชำระ
            $colQ = Coordinate::stringFromColumnIndex($totalColCount - 1); // Q = คงเหลือ

            foreach ($data as $i => $dataRow) {
                $excelRow = 10 + $i;

                // ให้คงเหลือคิดเหมือนต้นฉบับทุกแถว
                // Q = I - P
                $sheet->setCellValue("{$colQ}{$excelRow}", "={$colI}{$excelRow}-{$colP}{$excelRow}");
            }

            // ===== Totals row + verification row =====
            // Column layout (per user):
            //   I = จำนวนเงิน (ar_amount, fixed at column 9)
            //   P = รวมรับชำระ (total_cash, 3rd-to-last)
            //   Q = คงเหลือ (remaining, 2nd-to-last)
            //   last = จำนวนเอกสารรับชำระ (voucher_count)

            $totalsRow = $lastRow + 1;
            $verifyRow = $totalsRow + 1;
            $colLast = Coordinate::stringFromColumnIndex($totalColCount);

            // ===== Totals row — เขียนเป็นค่าตัวเลขตรงๆ (ไม่ใช้ formula) =====
            // Recompute Q (คงเหลือ) per row server-side: Q = I - P
            // เพราะเราเขียน formula Q ใน loop ข้างต้น ค่าจะถูกอัปเดตเมื่อเปิด Excel
            // แต่เพื่อให้ totals ถูกต้องตอน export เลย เราคำนวณเองด้วย
            $colSums = [];
            foreach ($data as $i => $dataRow) {
                foreach ($dataRow as $idx => $val) {
                    if (is_numeric($val)) {
                        $colSums[$idx] = ($colSums[$idx] ?? 0) + (float) $val;
                    }
                }
                // Adjust Q column (index totalColCount-2 since 0-based; Q = totalColCount-1 in 1-based)
                $qIdx = $totalColCount - 2; // 0-based index of Q
                $iIdx = 8; // 0-based index of I (col 9)
                $pIdx = $totalColCount - 3; // 0-based index of P
                if (isset($dataRow[$iIdx]) && isset($dataRow[$pIdx])) {
                    $qVal = (float) $dataRow[$iIdx] - (float) $dataRow[$pIdx];
                    // Overwrite Q sum: remove old Q value contribution and add computed
                    if (isset($dataRow[$qIdx]) && is_numeric($dataRow[$qIdx])) {
                        $colSums[$qIdx] = ($colSums[$qIdx] ?? 0) - (float) $dataRow[$qIdx] + $qVal;
                    } else {
                        $colSums[$qIdx] = ($colSums[$qIdx] ?? 0) + $qVal;
                    }
                }
            }

            // Label + write sums
            $sheet->setCellValue("E{$totalsRow}", 'รวมทั้งหมด');
            foreach ($colSums as $idx => $sum) {
                $col = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$col}{$totalsRow}", $sum);
            }

            // Style totals row
            $sheet->getStyle("E{$totalsRow}:{$colLast}{$totalsRow}")
                ->getFont()->setBold(true);
            $sheet->getStyle("E{$totalsRow}:{$colLast}{$totalsRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EDF8');
            $sheet->getStyle("{$amountStartColumn}{$totalsRow}:{$colLast}{$totalsRow}")
                ->getNumberFormat()->setFormatCode('#,##0.00');

            // ===== Verification row =====
            //   P = I - P  (สรุปรวม = จำนวนเงิน - รวมรับชำระ)
            //   Q = Q - P  (สรุปคงเหลือ = คงเหลือ - รวมรับชำระ)
            $colO = Coordinate::stringFromColumnIndex($totalColCount - 3); // ช่องก่อนรวม

            $sheet->setCellValue("{$colO}{$verifyRow}", 'ตรวจสอบ →');
            $sheet->setCellValue("{$colP}{$verifyRow}", "={$colI}{$totalsRow}-{$colP}{$totalsRow}");
            $sheet->setCellValue("{$colQ}{$verifyRow}", "={$colQ}{$totalsRow}-{$colP}{$totalsRow}");

            $sheet->getStyle("{$colO}{$verifyRow}:{$colQ}{$verifyRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');

            $sheet->getStyle("{$colP}{$verifyRow}:{$colQ}{$verifyRow}")
                ->getNumberFormat()->setFormatCode('#,##0.00');

            $sheet->getStyle("{$colP}{$verifyRow}:{$colQ}{$verifyRow}")
                ->getFont()->setBold(true)->setItalic(true);

            $sheet->getStyle("{$colO}{$verifyRow}")
                ->getFont()->setItalic(true)->getColor()->setRGB('666666');

            $sheet->getStyle("{$colO}{$verifyRow}")
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }

        $this->autoSize($sheet, count($headers[0]));
        $this->buildPaymentPlanAgingSheet($spreadsheet, $invoiceRows);
        $this->buildDetailSheet($spreadsheet, $rows);
        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function normalizeFilters(array $filters): array
    {
        $today = Carbon::today('Asia/Bangkok');
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));
        $site = strtoupper(trim((string) ($filters['site'] ?? 'ALL')));

        if ($to === '') {
            $to = $today->toDateString();
        }

        if ($from === '') {
            $from = Carbon::parse($to)->copy()->subMonthsNoOverflow(3)->toDateString();
        }

        if (!isset(self::SITES[$site]) && $site !== 'ALL') {
            $site = 'ALL';
        }

        $aging = strtoupper(trim((string) ($filters['aging'] ?? 'ALL')));
        if (!in_array($aging, ['ALL', 'B0_30', 'B31_60', 'B61_90', 'B91_180', 'B180_PLUS'], true)) {
            $aging = 'ALL';
        }

        $groupCustomer = (string) ($filters['group_customer'] ?? '') === '1' ? '1' : '0';

        return [
            'date_from' => Carbon::parse($from)->toDateString(),
            'date_to' => Carbon::parse($to)->toDateString(),
            'site' => $site,
            'customer' => trim((string) ($filters['customer'] ?? '')),
            'invoice' => trim((string) ($filters['invoice'] ?? '')),
            'status' => in_array(($status = strtoupper(trim((string) ($filters['status'] ?? 'ALL')))), ['ALL', 'PAID', 'PARTIAL', 'UNPAID'], true) ? $status : 'ALL',
            'aging' => $aging,
            'group_customer' => $groupCustomer,
        ];
    }

    public function exportYearly(array $filters)
    {
        $data = $this->getYearlyData($filters);
        $year = $data['year'];
        $site = $data['site'];
        $monthlyTable = $data['monthlyTable'];
        $customerSummary = $data['customerSummary'];
        $agingSummary = $data['agingSummary'];
        $totals = $data['totals'];

        $fileName = 'loss_provision_yearly_' . $year . '_' . $site . '.xlsx';

        $spreadsheet = new Spreadsheet();

        // Sheet 1: Monthly summary
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('สรุปรายเดือน');
        $sheet->fromArray(['สรุปรายการเผื่อผลขาดทุน — ทั้งปี'], null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->fromArray([
            ['Year', $year],
            ['Site', $site],
            ['Date From', $data['dateFrom']],
            ['Date To', $data['dateTo']],
        ], null, 'A3');
        $sheet->getStyle('A3:A6')->getFont()->setBold(true);

        $sheet->fromArray([['เดือน', 'ยอดออกบิล', 'รับชำระ', 'คงเหลือ', '% รับชำระ', 'จำนวนเอกสาร']], null, 'A8');
        $this->styleHeader($sheet, 'A8:F8');

        $row = 9;
        foreach ($monthlyTable as $m) {
            $pct = $m->gross > 0 ? $m->paid / $m->gross * 100 : 0;
            $sheet->fromArray([[
                $m->label . ' ' . $year,
                (float) $m->gross,
                (float) $m->paid,
                (float) $m->remaining,
                $m->gross > 0 ? round($pct, 2) / 100 : 0,
                (int) $m->doc_count,
            ]], null, "A{$row}");
            $row++;
        }
        // Grand total
        $sheet->fromArray([[
            'รวมทั้งปี',
            (float) $totals->gross,
            (float) $totals->paid,
            (float) $totals->remaining,
            $totals->gross > 0 ? round($totals->paid / $totals->gross, 4) : 0,
            (int) $totals->docs,
        ]], null, "A{$row}");
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9EDF8');

        $sheet->getStyle("B9:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("E9:E{$row}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("F9:F{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $this->autoSize($sheet, 6);

        // Sheet 2: Customer top 30
        $custSheet = $spreadsheet->createSheet();
        $custSheet->setTitle('Top 30 ลูกค้า');
        $custSheet->fromArray(['ลูกค้า Top 30 — ตามยอดคงเหลือ'], null, 'A1');
        $custSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $custSheet->fromArray([['#', 'ลูกค้า', 'ยอดออกบิล', 'รับชำระ', 'คงเหลือ', '% ค้าง', 'จำนวนเอกสาร']], null, 'A3');
        $this->styleHeader($custSheet, 'A3:G3');

        $r = 4;
        foreach ($customerSummary as $i => $cust) {
            $pct = $cust->gross > 0 ? $cust->remaining / $cust->gross : 0;
            $custSheet->fromArray([[
                $i + 1,
                $cust->customer_name,
                (float) $cust->gross,
                (float) $cust->paid,
                (float) $cust->remaining,
                round($pct, 4),
                (int) $cust->doc_count,
            ]], null, "A{$r}");
            $r++;
        }
        if ($r > 4) {
            $custSheet->getStyle("C4:E" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $custSheet->getStyle("F4:F" . ($r - 1))->getNumberFormat()->setFormatCode('0.0%');
            $custSheet->getStyle("G4:G" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0');
        }
        $this->autoSize($custSheet, 7);

        // Sheet 3: Aging snapshot
        $ageSheet = $spreadsheet->createSheet();
        $ageSheet->setTitle('Aging snapshot');
        $ageSheet->fromArray(['Aging snapshot ณ ' . now('Asia/Bangkok')->toDateString()], null, 'A1');
        $ageSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ageSheet->fromArray([['ช่วงอายุ', 'จำนวนเอกสาร', 'ยอดคงเหลือ', '% ของรวม']], null, 'A3');
        $this->styleHeader($ageSheet, 'A3:D3');

        $totalAging = max((float) $agingSummary->sum('remaining'), 0.0001);
        $r = 4;
        foreach ($agingSummary as $b) {
            $pct = $b->remaining > 0 ? $b->remaining / $totalAging : 0;
            $ageSheet->fromArray([[
                $b->label,
                (int) $b->count,
                (float) $b->remaining,
                round($pct, 4),
            ]], null, "A{$r}");
            $r++;
        }
        if ($r > 4) {
            $ageSheet->getStyle("B4:B" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0');
            $ageSheet->getStyle("C4:C" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $ageSheet->getStyle("D4:D" . ($r - 1))->getNumberFormat()->setFormatCode('0.0%');
        }
        $this->autoSize($ageSheet, 4);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function getYearlyData(array $filters): array
    {
        $year = (int) ($filters['year'] ?? Carbon::today('Asia/Bangkok')->year);
        $site = strtoupper(trim((string) ($filters['site'] ?? 'ALL')));
        if (!isset(self::SITES[$site]) && $site !== 'ALL') {
            $site = 'ALL';
        }

        $sites = $site === 'ALL' ? self::SITES : [$site => self::SITES[$site]];
        $dateFrom = "{$year}-01-01";
        $dateTo = ($year + 1) . '-01-01';

        $monthlyAll = collect();
        $customerAll = collect();
        $agingAll = collect();
        $totalsAll = (object) ['gross' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'docs' => 0];

        // FX conversion: non-THB amounts × exchangerate.sell (default 1 if not found)
        $monthlySql = <<<'SQL'
SELECT
    EXTRACT(MONTH FROM ar.transdate)::int AS month,
    SUM(ar.amount * COALESCE(er.sell, 1))::numeric AS gross,
    SUM(COALESCE(ar.paid, 0) * COALESCE(er.sell, 1))::numeric AS paid,
    SUM(GREATEST((ar.amount - COALESCE(ar.paid, 0)) * COALESCE(er.sell, 1), 0))::numeric AS remaining,
    COUNT(*)::int AS doc_count
FROM ar
LEFT JOIN exchangerate er ON er.id = ar.exchangerate_id
WHERE ar.transdate >= ? AND ar.transdate < ?
GROUP BY EXTRACT(MONTH FROM ar.transdate)
ORDER BY 1
SQL;

        $customerSql = <<<'SQL'
SELECT
    c.name AS customer_name,
    SUM(ar.amount * COALESCE(er.sell, 1))::numeric AS gross,
    SUM(COALESCE(ar.paid, 0) * COALESCE(er.sell, 1))::numeric AS paid,
    SUM(GREATEST((ar.amount - COALESCE(ar.paid, 0)) * COALESCE(er.sell, 1), 0))::numeric AS remaining,
    COUNT(*)::int AS doc_count
FROM ar
JOIN customer c ON c.id = ar.customer_id
LEFT JOIN exchangerate er ON er.id = ar.exchangerate_id
WHERE ar.transdate >= ? AND ar.transdate < ?
GROUP BY c.name
ORDER BY remaining DESC NULLS LAST
LIMIT 30
SQL;

        $agingSql = <<<'SQL'
SELECT
    CASE
        WHEN (CURRENT_DATE - (ar.transdate + (COALESCE(ar.terms, 0) || ' days')::interval)::date) <= 30 THEN 'B0_30'
        WHEN (CURRENT_DATE - (ar.transdate + (COALESCE(ar.terms, 0) || ' days')::interval)::date) <= 60 THEN 'B31_60'
        WHEN (CURRENT_DATE - (ar.transdate + (COALESCE(ar.terms, 0) || ' days')::interval)::date) <= 90 THEN 'B61_90'
        WHEN (CURRENT_DATE - (ar.transdate + (COALESCE(ar.terms, 0) || ' days')::interval)::date) <= 180 THEN 'B91_180'
        ELSE 'B180_PLUS'
    END AS bucket,
    COUNT(*)::int AS doc_count,
    SUM((ar.amount - COALESCE(ar.paid, 0)) * COALESCE(er.sell, 1))::numeric AS remaining
FROM ar
LEFT JOIN exchangerate er ON er.id = ar.exchangerate_id
WHERE ar.transdate >= ? AND ar.transdate < ?
  AND (ar.amount - COALESCE(ar.paid, 0)) > 0.01
GROUP BY 1
SQL;

        foreach ($sites as $siteName => $connection) {
            $bindings = [$dateFrom, $dateTo];
            $monthlyAll = $monthlyAll->concat(
                collect(DB::connection($connection)->select($monthlySql, $bindings))
                    ->map(function ($r) use ($siteName) {
                        $r->site = $siteName;
                        $r->month = (int) $r->month;
                        $r->gross = (float) $r->gross;
                        $r->paid = (float) $r->paid;
                        $r->remaining = (float) $r->remaining;
                        $r->doc_count = (int) $r->doc_count;
                        return $r;
                    })
            );

            $customerAll = $customerAll->concat(
                collect(DB::connection($connection)->select($customerSql, $bindings))
                    ->map(function ($r) use ($siteName) {
                        $r->site = $siteName;
                        $r->gross = (float) $r->gross;
                        $r->paid = (float) $r->paid;
                        $r->remaining = (float) $r->remaining;
                        $r->doc_count = (int) $r->doc_count;
                        return $r;
                    })
            );

            $agingAll = $agingAll->concat(
                collect(DB::connection($connection)->select($agingSql, $bindings))
                    ->map(function ($r) use ($siteName) {
                        $r->site = $siteName;
                        $r->doc_count = (int) $r->doc_count;
                        $r->remaining = (float) $r->remaining;
                        return $r;
                    })
            );
        }

        // monthly aggregated across sites for chart
        $monthLabels = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $monthlyTable = [];
        for ($m = 1; $m <= 12; $m++) {
            $rowsForMonth = $monthlyAll->where('month', $m);
            $gross = (float) $rowsForMonth->sum('gross');
            $paid = (float) $rowsForMonth->sum('paid');
            $remaining = (float) $rowsForMonth->sum('remaining');
            $docs = (int) $rowsForMonth->sum('doc_count');
            $monthlyTable[] = (object) [
                'month' => $m,
                'label' => $monthLabels[$m - 1],
                'gross' => $gross,
                'paid' => $paid,
                'remaining' => $remaining,
                'doc_count' => $docs,
            ];
            $totalsAll->gross += $gross;
            $totalsAll->paid += $paid;
            $totalsAll->remaining += $remaining;
            $totalsAll->docs += $docs;
        }

        // customer top 30 (sum across sites)
        $customerSummary = $customerAll
            ->groupBy('customer_name')
            ->map(function ($g, $name) {
                return (object) [
                    'customer_name' => $name,
                    'gross' => (float) $g->sum('gross'),
                    'paid' => (float) $g->sum('paid'),
                    'remaining' => (float) $g->sum('remaining'),
                    'doc_count' => (int) $g->sum('doc_count'),
                ];
            })
            ->sortByDesc('remaining')
            ->take(30)
            ->values();

        // aging summary
        $bucketDef = self::agingBuckets();
        $agingSummary = collect($bucketDef)->map(function ($cfg, $key) use ($agingAll) {
            $rows = $agingAll->where('bucket', $key);
            return (object) [
                'key' => $key,
                'label' => $cfg['label'],
                'color' => $cfg['color'],
                'remaining' => (float) $rows->sum('remaining'),
                'count' => (int) $rows->sum('doc_count'),
            ];
        })->values();

        return [
            'year' => $year,
            'site' => $site,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'monthlyTable' => collect($monthlyTable),
            'customerSummary' => $customerSummary,
            'agingSummary' => $agingSummary,
            'totals' => $totalsAll,
        ];
    }

    private function validateDateRange(array $filters): ?string
    {
        try {
            $from = Carbon::parse($filters['date_from'])->startOfDay();
            $to = Carbon::parse($filters['date_to'])->startOfDay();
        } catch (\Throwable $e) {
            return 'รูปแบบวันที่ไม่ถูกต้อง';
        }

        if ($to->lessThan($from)) {
            return 'วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น';
        }

        $maxTo = $from->copy()->addMonthsNoOverflow(3);
        if ($to->greaterThan($maxTo)) {
            return 'ช่วงค้นหาต้องไม่เกิน 3 เดือน (ปัจจุบันเลือก ' . $from->toDateString() . ' ถึง ' . $to->toDateString() . ')';
        }

        return null;
    }

    public static function agingBuckets(): array
    {
        return [
            'B0_30'     => ['label' => '0–30 วัน',      'min' => 0,   'max' => 30,  'color' => '#10b981'],
            'B31_60'    => ['label' => '31–60 วัน',     'min' => 31,  'max' => 60,  'color' => '#0ea5e9'],
            'B61_90'    => ['label' => '61–90 วัน',     'min' => 61,  'max' => 90,  'color' => '#f59e0b'],
            'B91_180'   => ['label' => '91–180 วัน',    'min' => 91,  'max' => 180, 'color' => '#f97316'],
            'B180_PLUS' => ['label' => 'เกิน 180 วัน',  'min' => 181, 'max' => null, 'color' => '#dc2626'],
        ];
    }

    public static function agingKeyForDays(int $days): string
    {
        if ($days <= 30)  return 'B0_30';
        if ($days <= 60)  return 'B31_60';
        if ($days <= 90)  return 'B61_90';
        if ($days <= 180) return 'B91_180';
        return 'B180_PLUS';
    }

    private function fetchRows(array $filters): Collection
    {
        $sites = $filters['site'] === 'ALL'
            ? self::SITES
            : [$filters['site'] => self::SITES[$filters['site']]];

        $rows = collect();
        foreach ($sites as $site => $connection) {
            $rows = $rows->concat($this->fetchRowsForConnection($connection, $site, $filters));
            $rows = $rows->concat($this->fetchReturnRowsForConnection($connection, $site, $filters));
        }

        return $rows
            ->sortBy([
                ['transdate', 'asc'],
                ['invnumber', 'asc'],
                ['cashin_transdate', 'asc'],
                ['site', 'asc'],
            ])
            ->values();
    }

    private function fetchRowsForConnection(string $connection, string $site, array $filters): Collection
    {
        $bindings = [
            $filters['date_from'],
            $filters['date_to'],
            $filters['customer'],
            $filters['customer'],
            $filters['customer'],
            $filters['invoice'],
            $filters['invoice'],
            $filters['invoice'],
            $filters['invoice'],
        ];

        return collect(DB::connection($connection)->select($this->sql(), $bindings))
            ->map(fn($row) => $this->normalizeRow($row, $site));
    }

    private function fetchReturnRowsForConnection(string $connection, string $site, array $filters): Collection
    {
        $bindings = [
            $filters['date_from'],
            $filters['date_to'],
            $filters['customer'],
            $filters['customer'],
            $filters['customer'],
            $filters['invoice'],
            $filters['invoice'],
            $filters['invoice'],
            $filters['invoice'],
        ];

        return collect(DB::connection($connection)->select($this->returnSql(), $bindings))
            ->map(fn($row) => $this->normalizeRow($row, $site));
    }

    private function normalizeRow($row, string $site)
    {
        $row->site = $site;
        $row->site_ar_id = $site . ':' . $row->ar_id;
        $row->ar_amount = (float) ($row->ar_amount ?? 0);
        $row->netamount = (float) ($row->netamount ?? 0);
        $row->taxable = (float) ($row->taxable ?? 0);
        $row->paid = (float) ($row->paid ?? 0);
        $row->cash_amount = (float) ($row->cash_amount ?? 0);
        $row->payment_source = (string) ($row->payment_source ?? '');
        $row->document_type = (string) ($row->document_type ?? 'AR');

        return $row;
    }

    private function buildPeriods(Collection $rows, array $filters): Collection
    {
        $dates = collect([
            Carbon::parse($filters['date_from'])->startOfQuarter(),
            Carbon::parse($filters['date_to'])->startOfQuarter(),
        ]);

        $rows->pluck('cashin_transdate')
            ->filter()
            ->each(fn($date) => $dates->push(Carbon::parse($date)->startOfQuarter()));

        $start = $dates->min()->copy()->startOfQuarter();
        $end = $dates->max()->copy()->startOfQuarter();
        $periods = collect();

        while ($start->lte($end)) {
            $periods->push((object) [
                'key' => $this->periodKey($start),
                'label' => $this->quarterLabel($start),
                'start' => $start->copy()->toDateString(),
                'end' => $start->copy()->endOfQuarter()->toDateString(),
            ]);
            $start->addQuarter();
        }

        return $periods;
    }

    private function buildInvoiceMap(Collection $rows, Collection $periods): Collection
    {
        $paymentTerms = $this->loadCustomerPaymentTerms($rows);

        return $rows
            ->groupBy('site_ar_id')
            ->map(function (Collection $group) use ($periods, $paymentTerms) {
                $first = $group->first();
                $periodAmounts = $periods
                    ->mapWithKeys(fn($period) => [$period->key => 0.0])
                    ->all();

                foreach ($group as $row) {
                    if (empty($row->cashin_transdate)) {
                        continue;
                    }

                    $key = $this->periodKey(Carbon::parse($row->cashin_transdate));
                    $periodAmounts[$key] = ($periodAmounts[$key] ?? 0.0) + (float) $row->cash_amount;
                }

                $isReturn = (string) ($first->document_type ?? '') === 'RETURN';
                $totalCash = (float) $group->sum('cash_amount');
                $status = $this->paymentStatus((float) $first->ar_amount, $totalCash);

                $today = Carbon::today('Asia/Bangkok');
                $terms = (int) ($first->terms ?? 0);
                $customerTerm = $this->resolveCustomerPaymentTerm($paymentTerms, $first);
                $dueInfo = $this->calculatePaymentDueDate($first, $customerTerm, $terms);
                $agingBaseDate = $dueInfo['due_date'];
                $agingBaseSource = $dueInfo['source'];
                $daysOutstanding = 0;
                $signedDaysOverdue = 0;
                if ($agingBaseDate !== null) {
                    try {
                        $signedDaysOverdue = (int) Carbon::parse($agingBaseDate)->startOfDay()->diffInDays($today, false);
                        $daysOutstanding = max(0, $signedDaysOverdue);
                    } catch (\Throwable $e) {
                        $daysOutstanding = 0;
                        $signedDaysOverdue = 0;
                    }
                }
                $agingKey = self::agingKeyForDays($daysOutstanding);

                return (object) [
                    'site_ar_id' => $first->site_ar_id,
                    'site' => $first->site,
                    'ar_id' => $first->ar_id,
                    'document_type' => $first->document_type ?? 'AR',
                    'transdate' => $first->transdate,
                    'invnumber' => $first->invnumber,
                    'ordnumber' => $first->ordnumber,
                    'refnumber' => $first->refnumber,
                    'customer_code' => $first->customer_code ?? null,
                    'customer_name' => $first->customer_name,
                    'ar_amount' => (float) $first->ar_amount,
                    'taxable' => (float) $first->taxable,
                    'netamount' => (float) $first->netamount,
                    'paid' => (float) $first->paid,
                    'curr' => $first->curr,
                    'datepaid' => $first->datepaid,
                    'duedate' => $first->duedate,
                    'terms' => $terms,
                    'total_cash' => $totalCash,
                    'remaining' => (float) $first->ar_amount - $totalCash,
                    'status' => $status,
                    'days_outstanding' => $daysOutstanding,
                    'payment_days_overdue' => $signedDaysOverdue,
                    'aging_key' => $agingKey,
                    'aging_base_date' => $agingBaseDate,
                    'aging_base_source' => $agingBaseSource,
                    'payment_term' => $customerTerm,
                    'payment_term_label' => $this->paymentTermLabel($customerTerm, $terms),
                    'billing_plan_label' => $customerTerm?->billing_plan_name,
                    'payment_schedule_label' => $customerTerm?->payment_schedule_name,
                    'period_amounts' => $periodAmounts,
                    'voucher_count' => $isReturn ? 0 : $group->pluck('vouchernumber')->filter()->unique()->count(),
                    'vouchers' => $isReturn ? collect() : $group
                        ->filter(fn($row) => !empty($row->vouchernumber) || !empty($row->cashin_transdate))
                        ->sortBy([
                            ['cashin_transdate', 'asc'],
                            ['vouchernumber', 'asc'],
                        ])
                        ->values(),
                ];
            })
            ->sortBy([
                ['transdate', 'asc'],
                ['invnumber', 'asc'],
                ['site', 'asc'],
            ])
            ->values();
    }

    private function loadCustomerPaymentTerms(Collection $rows): Collection
    {
        $keys = $rows
            ->map(function ($row) {
                $source = $this->erpSourceForSite((string) ($row->site ?? ''));
                $code = strtoupper(trim((string) ($row->customer_code ?? '')));
                return $source && $code !== '' ? $source . '|' . $code : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        return DB::table('customer_payment_terms as cpt')
            ->leftJoin('mst_billing_plans as bp', 'bp.id', '=', 'cpt.billing_plan_id')
            ->leftJoin('mst_payment_schedules as ps', 'ps.id', '=', 'cpt.payment_schedule_id')
            ->where('cpt.is_active', true)
            ->select([
                'cpt.*',
                'bp.name_th as billing_plan_name',
                'ps.name_th as payment_schedule_name',
                'ps.schedule_type',
                'ps.payment_day',
            ])
            ->get()
            ->filter(function ($row) use ($keys) {
                return $keys->contains($row->erp_source . '|' . strtoupper(trim((string) $row->customer_code)));
            })
            ->keyBy(fn($row) => $row->erp_source . '|' . strtoupper(trim((string) $row->customer_code)));
    }

    private function resolveCustomerPaymentTerm(Collection $paymentTerms, $row)
    {
        $source = $this->erpSourceForSite((string) ($row->site ?? ''));
        $code = strtoupper(trim((string) ($row->customer_code ?? '')));

        if (!$source || $code === '') {
            return null;
        }

        return $paymentTerms->get($source . '|' . $code);
    }

    private function calculatePaymentDueDate($row, $customerTerm, int $fallbackTerms): array
    {
        if (empty($row->transdate)) {
            return ['due_date' => null, 'source' => 'missing-transdate'];
        }

        try {
            $baseDate = Carbon::parse($row->transdate)->startOfDay();
        } catch (\Throwable $e) {
            return ['due_date' => null, 'source' => 'invalid-transdate'];
        }

        if ($customerTerm) {
            $creditDays = (int) ($customerTerm->credit_days ?? 0);
            $due = $baseDate->copy()->addDays($creditDays);
            $scheduleType = (string) ($customerTerm->schedule_type ?? '');
            $paymentDay = $customerTerm->override_payment_day ?: $customerTerm->payment_day;

            if ($scheduleType === 'end_of_month') {
                $due = $due->endOfMonth();
            } elseif ($scheduleType === 'fixed_day' && $paymentDay) {
                $day = max(1, min(31, (int) $paymentDay));
                $candidate = $due->copy()->day(min($day, $due->daysInMonth));
                if ($candidate->lt($due)) {
                    $candidate = $due->copy()->addMonthNoOverflow();
                    $candidate->day(min($day, $candidate->daysInMonth));
                }
                $due = $candidate;
            }

            return [
                'due_date' => $due->toDateString(),
                'source' => 'customer_payment_terms',
            ];
        }

        return [
            'due_date' => $baseDate->addDays($fallbackTerms)->toDateString(),
            'source' => 'erp_terms_fallback',
        ];
    }

    private function paymentTermLabel($customerTerm, int $fallbackTerms): string
    {
        if ($customerTerm) {
            return trim((string) ($customerTerm->credit_term_detail ?: $customerTerm->credit_term_code ?: ($customerTerm->credit_days . ' วัน')));
        }

        return 'ERP terms ' . $fallbackTerms . ' วัน';
    }

    private function erpSourceForSite(string $site): ?string
    {
        return match (strtoupper($site)) {
            'WIRE' => 'pgsqlw',
            'PLUS' => 'pgsqlp',
            default => null,
        };
    }

    private function filterInvoiceMapByAging(Collection $invoiceMap, string $aging): Collection
    {
        if ($aging === 'ALL') {
            return $invoiceMap;
        }

        return $invoiceMap
            ->filter(fn($row) => ($row->aging_key ?? '') === $aging && ($row->status ?? '') !== 'PAID')
            ->values();
    }

    private function filterInvoiceMapByStatus(Collection $invoiceMap, string $status): Collection
    {
        if ($status === 'ALL') {
            return $invoiceMap;
        }

        return $invoiceMap
            ->filter(fn($row) => $row->status === $status)
            ->values();
    }

    private function paymentStatus(float $amount, float $paid): string
    {
        if (abs($paid) < 0.00001) {
            return 'UNPAID';
        }

        if (abs($amount - $paid) < 0.01) {
            return 'PAID';
        }

        return 'PARTIAL';
    }

    private function periodKey(Carbon $date): string
    {
        return $date->format('Y') . '-Q' . $date->quarter;
    }

    private function quarterLabel(Carbon $date): string
    {
        $labels = [
            1 => 'ม.ค.-มี.ค.',
            2 => 'เม.ย.-มิ.ย.',
            3 => 'ก.ค.-ก.ย.',
            4 => 'ต.ค.-ธ.ค.',
        ];

        return $labels[$date->quarter] . ' ' . ((int) $date->year + 543);
    }

    private function sql(): string
    {
        return <<<'SQL'
SELECT
    ar.id AS ar_id,
    ar.transdate,
    ar.invnumber,
    ar.ordnumber,
    ar.refnumber,
    ar.amount AS ar_amount,
    ar.netamount,
    (ar.amount - ar.netamount) AS taxable,
    ar.paid,
    ar.datepaid,
    ar.invoice,
    ar.cash,
    ar.hasitems,
    ar.hasfa,
    ar.duedate,
    ar.terms,
    ar.curr,
    ar.exchangerate_id,
    ar.notes AS ar_notes,
    ar.f1,
    ar.f2,
    ci.vouchernumber,
    ci.description AS cashin_description,
    ci.transdate AS cashin_transdate,
    ct.amount AS cash_amount,
    ci.notes AS cashin_notes,
    CASE WHEN ci.id IS NOT NULL THEN 'CASHIN' ELSE '' END AS payment_source,
    'AR'::text AS document_type,
    c.customernumber AS customer_code,
    c.name AS customer_name
FROM ar
LEFT JOIN cashtrans ct ON ar.id = ct.ap_ar_id
LEFT JOIN cashin ci ON ct.trans_id = ci.id
JOIN customer c ON c.id = ar.customer_id
WHERE ar.transdate BETWEEN ? AND ?
  AND (? = '' OR c.name ILIKE '%' || ? || '%' OR c.customernumber ILIKE '%' || ? || '%')
  AND (? = '' OR ar.invnumber ILIKE '%' || ? || '%' OR ar.ordnumber ILIKE '%' || ? || '%' OR ci.vouchernumber ILIKE '%' || ? || '%')
ORDER BY ar.transdate, ar.invnumber, ci.transdate, ci.vouchernumber
SQL;
    }

    private function returnSql(): string
    {
        return <<<'SQL'
SELECT
    ('RETURN:' || r.id)::text AS ar_id,
    COALESCE(r.transdate, ar.transdate) AS transdate,
    r.returnnumber AS invnumber,
    ar.ordnumber,
    r.invnumber AS refnumber,
    -ABS(COALESCE(r.amount, 0)) AS ar_amount,
    -ABS(COALESCE(r.netamount, r.amount, 0)) AS netamount,
    0::numeric AS taxable,
    -ABS(COALESCE(r.returnpaid, 0)) AS paid,
    r.returnpaiddate AS datepaid,
    false AS invoice,
    false AS cash,
    r.hasitems,
    false AS hasfa,
    ar.duedate,
    ar.terms,
    r.curr,
    r.exchangerate_id,
    r.notes AS ar_notes,
    r.f1,
    r.f2,
    NULL::text AS vouchernumber,
    r.returnnumber AS cashin_description,
    r.transdate AS cashin_transdate,
    -ABS(COALESCE(r.amount, 0)) AS cash_amount,
    r.notes AS cashin_notes,
    'RETURN'::text AS payment_source,
    'RETURN'::text AS document_type,
    c.customernumber AS customer_code,
    COALESCE(NULLIF(TRIM(r.name), ''), c.name) AS customer_name
FROM "return" r
LEFT JOIN ar ON ar.invnumber = r.invnumber
LEFT JOIN customer c ON c.id = COALESCE(r.customer_id, ar.customer_id)
WHERE COALESCE(r.transdate, ar.transdate) BETWEEN ? AND ?
  AND (? = '' OR COALESCE(NULLIF(TRIM(r.name), ''), c.name, '') ILIKE '%' || ? || '%' OR c.customernumber ILIKE '%' || ? || '%')
  AND (? = '' OR r.invnumber ILIKE '%' || ? || '%' OR r.returnnumber ILIKE '%' || ? || '%' OR r.notes ILIKE '%' || ? || '%')
ORDER BY COALESCE(r.transdate, ar.transdate), r.invnumber, r.returnnumber
SQL;
    }

    private function mapExportHeaders(Collection $periods): array
    {
        return [[
            'Site',
            'วันที่',
            'เอกสารเลขที่',
            'SO',
            'Customer',
            'Status',
            'มูลค่าสินค้า / บริการ',
            'ภาษี',
            'จำนวนเงิน',
            ...$periods->pluck('label')->all(),
            'รวมรับชำระ',
            'คงเหลือ',
            'จำนวนเอกสารรับชำระ',
        ]];
    }

    private function mapRowToArray($row, Collection $periods): array
    {
        $periodValues = [];
        foreach ($periods as $period) {
            $periodValues[] = (float) ($row->period_amounts[$period->key] ?? 0);
        }

        return array_merge([
            $row->site,
            $row->transdate,
            $row->invnumber,
            $row->ordnumber,
            $row->customer_name,
            $row->status,
            (float) $row->netamount,
            (float) $row->taxable,
            (float) $row->ar_amount,
        ], $periodValues, [
            (float) $row->total_cash,
            (float) $row->remaining,
            (int) $row->voucher_count,
        ]);
    }

    private function buildDetailSheet(Spreadsheet $spreadsheet, Collection $rows): void
    {
        $sheet = new Worksheet($spreadsheet, 'Voucher Detail');
        $spreadsheet->addSheet($sheet);
        $headers = $this->exportHeaders();
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:AA1');
        $sheet->freezePane('A2');

        $groupedRows = $rows
            ->groupBy(fn($row) => $this->detailCustomerGroupKey($row))
            ->sortKeys();

        $currentRow = 2;
        foreach ($groupedRows as $group) {
            $first = $group->first();
            $detailStartRow = $currentRow + 1;
            $detailData = $group
                ->sortBy([
                    ['customer_name', 'asc'],
                    ['transdate', 'asc'],
                    ['invnumber', 'asc'],
                    ['cashin_transdate', 'asc'],
                    ['vouchernumber', 'asc'],
                ])
                ->map(fn($row) => $this->rowToArray($row))
                ->values()
                ->all();
            $detailEndRow = $detailStartRow + count($detailData) - 1;
            $subtotalRow = $detailEndRow + 1;

            $sheet->mergeCells("A{$currentRow}:AA{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", $this->detailCustomerGroupLabel($first, $group->count()));
            $sheet->getStyle("A{$currentRow}:AA{$currentRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$currentRow}:AA{$currentRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');

            if ($detailData !== []) {
                $sheet->fromArray($detailData, null, "A{$detailStartRow}");
                $sheet->getStyle("F{$detailStartRow}:I{$detailEndRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle("Y{$detailStartRow}:Y{$detailEndRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getRowDimension($currentRow)->setOutlineLevel(0);
                for ($row = $detailStartRow; $row <= $detailEndRow; $row++) {
                    $sheet->getRowDimension($row)->setOutlineLevel(1);
                }
            }

            $sheet->setCellValue("A{$subtotalRow}", 'Subtotal');
            $sheet->setCellValue("B{$subtotalRow}", $first->customer_name ?: '(No customer)');
            foreach (['F', 'G', 'H', 'I', 'Y'] as $column) {
                $sheet->setCellValue("{$column}{$subtotalRow}", "=SUM({$column}{$detailStartRow}:{$column}{$detailEndRow})");
            }
            $sheet->getStyle("A{$subtotalRow}:AA{$subtotalRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$subtotalRow}:AA{$subtotalRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EDF8');
            $sheet->getStyle("F{$subtotalRow}:I{$subtotalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("Y{$subtotalRow}:Y{$subtotalRow}")->getNumberFormat()->setFormatCode('#,##0.00');

            $currentRow = $subtotalRow + 1;
        }

        if ($currentRow > 2) {
            $lastRow = $currentRow - 1;
            $sheet->setAutoFilter("A1:AA{$lastRow}");
        }

        $this->autoSize($sheet, count($headers[0]));
    }

    private function detailCustomerGroupKey($row): string
    {
        $site = strtoupper(trim((string) ($row->site ?? '')));
        $code = strtoupper(trim((string) ($row->customer_code ?? '')));
        $name = strtoupper(trim((string) ($row->customer_name ?? '')));

        return implode('|', [$site, $code, $name]);
    }

    private function detailCustomerGroupLabel($row, int $count): string
    {
        $customerCode = trim((string) ($row->customer_code ?? ''));
        $customerName = trim((string) ($row->customer_name ?? ''));
        $site = trim((string) ($row->site ?? ''));
        $customer = trim($customerCode . ($customerCode !== '' && $customerName !== '' ? ' - ' : '') . $customerName);

        return 'Customer: ' . ($customer !== '' ? $customer : '(No customer)') . ' | Site: ' . ($site !== '' ? $site : '-') . ' | Detail rows: ' . $count;
    }

    private function buildPaymentPlanAgingSheet(Spreadsheet $spreadsheet, Collection $invoiceRows): void
    {
        $sheet = new Worksheet($spreadsheet, 'Aging Payment Plan');
        $spreadsheet->addSheet($sheet);

        $headers = [[
            'Site',
            'วันที่เอกสาร',
            'เลขที่เอกสาร',
            'วันครบกำหนด',
            'หนี้ไม่เกินกำหนด',
            '1 - 30 วัน',
            '31 - 60 วัน',
            '61 - 90 วัน',
            '91 - 180 วัน',
            'มากกว่า 180 วัน',
            'ยอดเกินกำหนด',
            'ยอดลูกหนี้คงเหลือ',
            'จำนวนวันเกินกำหนด',
            'เงื่อนไข(วัน)',
            '(รายละเอียด)',
            'วันครบชำระ',
            'จำนวนวันเกินกำหนด',
            'เงื่อนไข วางบิล',
            'เงื่อนไขการจ่ายชำระ',
        ]];

        $sheet->mergeCells('P1:S1');
        $sheet->setCellValue('P1', 'แผนรับชำระ');
        $sheet->getStyle('P1:S1')->getFont()->setBold(true);
        $sheet->getStyle('P1:S1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->fromArray($headers, null, 'A2');
        $this->styleHeader($sheet, 'A2:S2');
        $sheet->getStyle('P1:S2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
        $sheet->getStyle('D2')->getFont()->getColor()->setRGB('CC0000');
        $sheet->freezePane('E3');
        $sheet->setAutoFilter('A2:S2');

        $data = [];
        $domesticCount = 0;
        $foreignCount = 0;
        $domesticSums = array_fill(4, 8, 0.0);
        $foreignSums = array_fill(4, 8, 0.0);

        foreach ($invoiceRows as $row) {
            $remaining = (float) ($row->remaining ?? 0);
            $days = (int) ($row->payment_days_overdue ?? $row->days_outstanding ?? 0);
            $isDomestic = str_starts_with(strtoupper((string) ($row->customer_code ?? '')), 'D');
            $bucketValues = $this->paymentPlanBucketValues($remaining, $days);
            $overdueTotal = array_sum(array_slice($bucketValues, 1));
            $line = [
                $row->site,
                $row->transdate,
                $row->invnumber,
                $row->duedate,
                ...$bucketValues,
                $overdueTotal,
                $remaining,
                $days,
                (int) ($row->payment_term?->credit_days ?? $row->terms ?? 0),
                $row->payment_term_label ?? '',
                $row->aging_base_date,
                $days,
                $row->billing_plan_label ?? '',
                $row->payment_schedule_label ?? '',
            ];
            $data[] = $line;

            if ($isDomestic) {
                $domesticCount++;
                foreach (range(4, 11) as $idx) {
                    $domesticSums[$idx] += (float) ($line[$idx] ?? 0);
                }
            } else {
                $foreignCount++;
                foreach (range(4, 11) as $idx) {
                    $foreignSums[$idx] += (float) ($line[$idx] ?? 0);
                }
            }
        }

        if ($data !== []) {
            $sheet->fromArray($data, null, 'A3');
            $lastRow = 2 + count($data);
            $sheet->getStyle("E3:L{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("M3:M{$lastRow}")->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle("Q3:Q{$lastRow}")->getNumberFormat()->setFormatCode('0.00');

            $summaryStart = $lastRow + 2;
            $summaryRows = [
                ['รวมลูกหนี้ในประเทศ ' . $domesticCount . ' รายการ', '', '', '', $domesticSums[4], $domesticSums[5], $domesticSums[6], $domesticSums[7], $domesticSums[8], $domesticSums[9], $domesticSums[10], $domesticSums[11]],
                ['รวมลูกหนี้ต่างประเทศ ' . $foreignCount . ' รายการ', '', '', '', $foreignSums[4], $foreignSums[5], $foreignSums[6], $foreignSums[7], $foreignSums[8], $foreignSums[9], $foreignSums[10], $foreignSums[11]],
            ];
            $sheet->fromArray($summaryRows, null, "A{$summaryStart}");
            $summaryEnd = $summaryStart + 1;
            $sheet->mergeCells("A{$summaryStart}:D{$summaryStart}");
            $sheet->mergeCells("A{$summaryEnd}:D{$summaryEnd}");
            $sheet->getStyle("A{$summaryStart}:L{$summaryEnd}")->getFont()->setBold(true);
            $sheet->getStyle("E{$summaryStart}:L{$summaryEnd}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $this->autoSize($sheet, 19);
    }

    private function paymentPlanBucketValues(float $remaining, int $days): array
    {
        return [
            $days < 0 ? $remaining : 0.0,
            $days >= 0 && $days <= 30 ? $remaining : 0.0,
            $days >= 31 && $days <= 60 ? $remaining : 0.0,
            $days >= 61 && $days <= 90 ? $remaining : 0.0,
            $days >= 91 && $days <= 180 ? $remaining : 0.0,
            $days > 180 ? $remaining : 0.0,
        ];
    }

    private function exportHeaders(): array
    {
        return [[
            'Site',
            'Trans Date',
            'Invoice',
            'Order No.',
            'Ref No.',
            'AR Amount',
            'Net Amount',
            'Taxable',
            'Paid',
            'Date Paid',
            'Invoice Flag',
            'Cash Flag',
            'Has Items',
            'Has FA',
            'Due Date',
            'Currency',
            'Exchange Rate ID',
            'AR Notes',
            'F1',
            'F2',
            'Voucher No.',
            'Payment Type',
            'Payment Description',
            'Payment Date',
            'Payment Amount',
            'Payment Notes',
            'Customer',
        ]];
    }

    private function rowToArray($row): array
    {
        return [
            $row->site,
            $row->transdate,
            $row->invnumber,
            $row->ordnumber,
            $row->refnumber,
            $row->ar_amount,
            $row->netamount,
            $row->taxable,
            $row->paid,
            $row->datepaid,
            $this->boolText($row->invoice),
            $this->boolText($row->cash),
            $this->boolText($row->hasitems),
            $this->boolText($row->hasfa),
            $row->duedate,
            $row->curr,
            $row->exchangerate_id,
            $row->ar_notes,
            $row->f1,
            $row->f2,
            $row->vouchernumber,
            $row->payment_source,
            $row->cashin_description,
            $row->cashin_transdate,
            $row->cash_amount,
            $row->cashin_notes,
            $row->customer_name,
        ];
    }

    private function boolText($value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Y' : 'N';
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('2F4357');
        $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function autoSize(Worksheet $sheet, int $columns): void
    {
        for ($i = 1; $i <= $columns; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }
}
