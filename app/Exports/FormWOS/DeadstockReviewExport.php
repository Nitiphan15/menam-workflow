<?php

namespace App\Exports\FormWOS;

use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Support\FormWOS\DeadstockReasonMap;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DeadstockReviewExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles, WithEvents
{
    public function __construct(private array $filters = [])
    {
    }

    public function headings(): array
    {
        return [
            'เดือน Snapshot',
            'วันที่ Snapshot',
            'สถานะ',
            'วันที่รับเข้า',
            'Part',
            'รายละเอียด Part',
            'Serial no.',
            'Transaction',
            'Qty Snapshot',
            'Qty ปัจจุบัน',
            'Qty ต่าง',
            'กำหนดส่งเดิม',
            'กำหนดส่งปัจจุบัน',
            'สถานะกำหนดส่ง',
            'กำหนดส่งใหม่',
            'ลูกค้า',
            'Sales',
            'Site',
            'รหัสสาเหตุ',
            'รายละเอียดสาเหตุ',
            'สถานะงาน',
            'วันติดตามถัดไป',
            'รายละเอียด',
            'แนวทางแก้ไข',
            'แนวทางป้องกันการเกิดซ้ำ',
            'Remark',
            'บันทึกล่าสุด',
            'ผู้บันทึก',
            'เทียบล่าสุด',
        ];
    }

    public function collection()
    {
        return $this->query()
            ->get()
            ->map(function (DeadstockSnapshotItem $item) {
                $review = $item->review;
                $month = $item->snapshotMonth;
                $reasonCode = $item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code;
                $snapshotQty = (float) $item->snapshot_qty;
                $currentQty = $item->current_qty !== null ? (float) $item->current_qty : null;
                $snapshotDue = $item->due_date?->format('Y-m-d') ?? '';
                $currentDue = $item->current_due_date?->format('Y-m-d') ?? '';

                return [
                    $month?->snapshot_month?->format('Y-m') ?? '',
                    $month?->recv_date?->format('Y-m-d') ?? '',
                    $this->compareStatusLabel((string) $item->compare_status),
                    $item->purchase_date?->format('Y-m-d') ?? '',
                    $item->partnumber,
                    $item->part_description,
                    $item->serialnumber,
                    $item->transaction_number,
                    $snapshotQty,
                    $currentQty,
                    $currentQty !== null ? $currentQty - $snapshotQty : null,
                    $snapshotDue,
                    $currentDue,
                    $currentDue !== '' && $snapshotDue !== '' && $currentDue !== $snapshotDue ? 'เปลี่ยน' : '',
                    $review?->revised_due_date?->format('Y-m-d') ?? '',
                    $item->customer_name,
                    $item->salesperson_name,
                    $this->siteLabel((string) $item->company),
                    $reasonCode,
                    DeadstockReasonMap::description($reasonCode, $item->deadstock_desc),
                    $this->reviewStatusLabel((string) ($review?->review_status ?? 'open')),
                    $review?->next_follow_up_date?->format('Y-m-d') ?? '',
                    $review?->review_detail,
                    $review?->corrective_action,
                    $review?->preventive_action,
                    $review?->sales_remark,
                    $review?->reviewed_at?->format('Y-m-d H:i') ?? '',
                    $review?->reviewer?->name ?? '',
                    $item->last_checked_at?->format('Y-m-d H:i') ?? '',
                ];
            });
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FF1F2937']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFEDF4FF'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();
                $range = $sheet->calculateWorksheetDimension();

                $sheet->setTitle('Details');
                $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle('E:F')->getAlignment()->setWrapText(true);
                $sheet->getStyle('R:AC')->getAlignment()->setWrapText(true);
                $sheet->getStyle('I:K')->getNumberFormat()->setFormatCode('#,##0.00');

                $this->buildSummarySheet($spreadsheet->createSheet(0));
                $spreadsheet->setActiveSheetIndex(0);
            },
        ];
    }

    private function buildSummarySheet(Worksheet $sheet): void
    {
        $report = $this->buildSummaryReport();
        $reportDate = $report['report_date'];
        $targetMonth = $report['target_month_label'];

        $sheet->setTitle('Summary');
        $sheet->setCellValue('A1', 'Deadstock Summary Report');
        $sheet->setCellValue('A2', 'Update ' . $reportDate->format('d/m/y') . ' (อิงจากวันที่รับเข้า/as-of ล่าสุดของ Snapshot ที่เลือก)');
        $sheet->mergeCells('A1:J1');
        $sheet->mergeCells('A2:J2');

        $sheet->setCellValue('A4', 'วันรับเข้า Stock');
        $sheet->setCellValue('B4', $report['start_label']);
        $sheet->setCellValue('C4', 'Update ' . $reportDate->format('d/m/y'));
        $sheet->setCellValue('J4', '% ที่ลดลง');
        $sheet->mergeCells('A4:A6');
        $sheet->mergeCells('B4:B6');
        $sheet->mergeCells('C4:I4');
        $sheet->mergeCells('J4:J6');

        $sheet->setCellValue('C5', 'Sales Problem(SS)');
        $sheet->setCellValue('F5', 'Factory Problem(FF)');
        $sheet->setCellValue('I5', 'Grand Total(KGS)');
        $sheet->mergeCells('C5:E5');
        $sheet->mergeCells('F5:H5');
        $sheet->mergeCells('I5:I6');

        $sheet->fromArray([
            [
                null,
                null,
                'ส่งได้ภายในเดือน ' . $targetMonth,
                'มีปัญหาไม่ได้ส่งภายใน ' . $targetMonth,
                'Total',
                'ส่งได้ภายในเดือน ' . $targetMonth,
                'มีปัญหาไม่ได้ส่งภายใน ' . $targetMonth,
                'Total',
                null,
                null,
            ],
        ], null, 'A6');

        $rowNo = 7;
        foreach ($report['rows'] as $row) {
            $this->writeSummaryRow($sheet, $rowNo, $row);
            $rowNo++;
        }

        $this->writeSummaryRow($sheet, $rowNo, $report['totals']);
        $sheet->getStyle("A{$rowNo}:J{$rowNo}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');

        $noteRow = $rowNo + 2;
        $sheet->setCellValue("A{$noteRow}", '*งานปกติฝั่ง Sales หมายถึง WIP, Stock Grating, สินค้าที่ได้ส่งภายในเดือน');
        $sheet->setCellValue('A' . ($noteRow + 1), '**งานปกติฝั่ง Factory หมายถึงงาน Stock FG, WIP, Stock Grating, สินค้าที่ได้ส่งภายในเดือน');
        $sheet->setCellValue('A' . ($noteRow + 2), '% ที่ลดลง = (Start TOTAL - Grand Total) / Start TOTAL และจะแสดง "-" เมื่อ Start TOTAL เป็น 0');
        $sheet->mergeCells("A{$noteRow}:J{$noteRow}");
        $sheet->mergeCells('A' . ($noteRow + 1) . ':J' . ($noteRow + 1));
        $sheet->mergeCells('A' . ($noteRow + 2) . ':J' . ($noteRow + 2));
        $sheet->getStyle("A{$noteRow}:A" . ($noteRow + 2))->getFont()->getColor()->setARGB('FFFF0000');

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('A4:J6')->getFont()->setBold(true);
        $sheet->getStyle('A4:J6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');
        $sheet->getStyle("A4:J{$rowNo}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A4:J{$rowNo}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("B7:I{$rowNo}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D7:D{$rowNo}")->getFont()->getColor()->setARGB('FFFF0000');
        $sheet->getStyle("G7:G{$rowNo}")->getFont()->getColor()->setARGB('FFFF0000');
        $sheet->getStyle("B7:I{$rowNo}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J7:J{$rowNo}")->getNumberFormat()->setFormatCode('0.00%');
        $sheet->freezePane('A7');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function writeSummaryRow(Worksheet $sheet, int $rowNo, array $row): void
    {
        $sheet->fromArray([[
            $row['label'],
            $row['start_total'],
            $row['sales']['within_month'],
            $row['sales']['problem'],
            $row['sales']['total'],
            $row['factory']['within_month'],
            $row['factory']['problem'],
            $row['factory']['total'],
            $row['grand_total'],
            $row['decrease_percent'] === null ? '-' : $row['decrease_percent'] / 100,
        ]], null, 'A' . $rowNo);
    }

    private function buildSummaryReport(): array
    {
        $items = $this->query()->get();
        $reportDate = $items
            ->map(fn(DeadstockSnapshotItem $item) => $item->snapshotMonth?->recv_date ?? $item->snapshotMonth?->as_of_date ?? $item->snapshotMonth?->snapshot_month)
            ->filter()
            ->sort()
            ->last()
            ?? $this->reportDateFromFilters()
            ?? now('Asia/Bangkok');
        $reportDate = $reportDate instanceof Carbon ? $reportDate->copy() : Carbon::parse($reportDate);
        $reportYear = (int) $reportDate->year;
        $targetMonth = $reportDate->copy()->addMonthNoOverflow();
        $historicalStart = Carbon::create($reportYear - 3, 1, 1)->startOfDay();
        $yearStart = Carbon::create($reportYear, 1, 1)->startOfDay();
        $currentMonthStart = $reportDate->copy()->startOfMonth();
        $previousMonthEnd = $currentMonthStart->copy()->subDay();
        $previousSnapshotMonth = $this->previousSnapshotMonth($reportDate);

        $rows = [
            'older' => $this->blankSummaryRow('<= ' . $yearStart->copy()->subYears(3)->subDay()->format('d/m/Y')),
            'history' => $this->blankSummaryRow(($reportYear - 3) . '-' . ($reportYear - 1)),
            'year_to_previous' => $this->blankSummaryRow(
                $currentMonthStart->month > 1
                    ? 'Jan-' . $previousMonthEnd->format('M Y')
                    : 'Jan ' . $reportYear
            ),
            'current_month' => $this->blankSummaryRow($reportDate->format('F-Y')),
        ];

        if ($previousSnapshotMonth) {
            $previousSnapshotMonth->items()
                ->with('latestCompareLog')
                ->get()
                ->each(function (DeadstockSnapshotItem $item) use (&$rows, $historicalStart, $yearStart, $currentMonthStart) {
                    $bucket = $this->summaryBucket($item->purchase_date instanceof Carbon ? $item->purchase_date : null, $historicalStart, $yearStart, $currentMonthStart);
                    $code = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));

                    if (!str_starts_with($code, 'SS') && !str_starts_with($code, 'FF')) {
                        return;
                    }

                    $rows[$bucket]['start_total'] += (float) $item->snapshot_qty;
                });
        }

        foreach ($items as $item) {
            $purchaseDate = $item->purchase_date instanceof Carbon ? $item->purchase_date : null;
            $bucket = $this->summaryBucket($purchaseDate, $historicalStart, $yearStart, $currentMonthStart);
            $snapshotQty = (float) $item->snapshot_qty;
            $openQty = (string) $item->compare_status === 'cleared'
                ? 0.0
                : (float) ($item->current_qty ?? $item->snapshot_qty);

            if (!$previousSnapshotMonth && $purchaseDate && $purchaseDate->lt($yearStart)) {
                $rows[$bucket]['start_total'] += $snapshotQty;
            }

            $code = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));
            $owner = str_starts_with($code, 'SS') ? 'sales' : (str_starts_with($code, 'FF') ? 'factory' : null);

            if ($owner === null) {
                continue;
            }

            $slot = str_ends_with($code, '-1') ? 'within_month' : 'problem';
            $rows[$bucket][$owner][$slot] += $openQty;
        }

        $totals = $this->blankSummaryRow('TOTAL(KGS)');

        foreach ($rows as &$row) {
            $row = $this->finalizeSummaryRow($row);

            foreach (['start_total', 'grand_total'] as $key) {
                $totals[$key] += $row[$key];
            }

            foreach (['sales', 'factory'] as $owner) {
                foreach (['within_month', 'problem', 'total'] as $key) {
                    $totals[$owner][$key] += $row[$owner][$key];
                }
            }
        }
        unset($row);

        return [
            'rows' => array_values($rows),
            'totals' => $this->finalizeSummaryRow($totals),
            'report_date' => $reportDate,
            'target_month_label' => $targetMonth->format('F'),
            'start_label' => $previousSnapshotMonth
                ? 'Start ' . ($previousSnapshotMonth->recv_date ?? $previousSnapshotMonth->as_of_date ?? $previousSnapshotMonth->snapshot_month)->format('d/m/y') . ' TOTAL (KGS)'
                : 'Start 1/1/' . $reportDate->format('y') . ' TOTAL (KGS)',
        ];
    }

    private function previousSnapshotMonth(Carbon $reportDate): ?\App\Models\FormWOS\DeadstockSnapshotMonth
    {
        return \App\Models\FormWOS\DeadstockSnapshotMonth::query()
            ->where('item_count', '>', 0)
            ->where('snapshot_month', '<', $reportDate->copy()->startOfMonth()->toDateString())
            ->orderByDesc('snapshot_month')
            ->orderByDesc('recv_date')
            ->first();
    }

    private function blankSummaryRow(string $label): array
    {
        return [
            'label' => $label,
            'start_total' => 0.0,
            'sales' => ['within_month' => 0.0, 'problem' => 0.0, 'total' => 0.0],
            'factory' => ['within_month' => 0.0, 'problem' => 0.0, 'total' => 0.0],
            'grand_total' => 0.0,
            'decrease_percent' => null,
        ];
    }

    private function finalizeSummaryRow(array $row): array
    {
        $row['sales']['total'] = $row['sales']['within_month'] + $row['sales']['problem'];
        $row['factory']['total'] = $row['factory']['within_month'] + $row['factory']['problem'];
        $row['grand_total'] = $row['sales']['total'] + $row['factory']['total'];
        $row['decrease_percent'] = $row['start_total'] > 0
            ? (($row['start_total'] - $row['grand_total']) / $row['start_total']) * 100
            : null;

        return $row;
    }

    private function summaryBucket(?Carbon $purchaseDate, Carbon $historicalStart, Carbon $yearStart, Carbon $currentMonthStart): string
    {
        if (!$purchaseDate) {
            return 'older';
        }

        if ($purchaseDate->lt($historicalStart)) {
            return 'older';
        }

        if ($purchaseDate->lt($yearStart)) {
            return 'history';
        }

        if ($purchaseDate->lt($currentMonthStart)) {
            return 'year_to_previous';
        }

        return 'current_month';
    }

    private function reportDateFromFilters(): ?Carbon
    {
        $monthValue = trim((string) (($this->filters['month_to'] ?? '') ?: ($this->filters['month_from'] ?? '')));

        return $monthValue !== '' ? Carbon::parse($monthValue . '-01')->endOfMonth() : null;
    }

    private function query()
    {
        $monthIds = collect($this->filters['month_ids'] ?? [])
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $status = trim((string) ($this->filters['status'] ?? 'review'));
        $actionStatus = trim((string) ($this->filters['action_status'] ?? 'all'));
        $site = trim((string) ($this->filters['site'] ?? $this->filters['company'] ?? 'all'));
        $customer = $this->normalizeMultiFilter($this->filters['customer'] ?? []);
        $sales = $this->normalizeMultiFilter($this->filters['sales'] ?? []);
        $reasonCode = $this->normalizeMultiFilter($this->filters['reason_code'] ?? []);
        $codeGroup = strtoupper(trim((string) ($this->filters['code_group'] ?? '')));
        $serial = trim((string) ($this->filters['serial'] ?? ''));
        $sort = trim((string) ($this->filters['sort'] ?? 'qty_desc'));

        $query = DeadstockSnapshotItem::query()
            ->with(['snapshotMonth', 'review.reviewer', 'latestCompareLog'])
            ->when($monthIds !== [], fn($q) => $q->whereIn('snapshot_month_id', $monthIds), fn($q) => $q->whereRaw('1 = 0'))
            ->when($customer !== [], fn($q) => $q->whereIn('customer_name', $customer))
            ->when($sales !== [], fn($q) => $q->whereIn('salesperson_name', $sales))
            ->when($reasonCode !== [], fn($q) => $this->applyReasonFilter($q, $reasonCode))
            ->when(in_array($codeGroup, ['FF', 'SS'], true), fn($q) => $q->where('deadstock_code', 'like', $codeGroup . '%'))
            ->when($serial !== '', function ($q) use ($serial) {
                $keyword = '%' . $serial . '%';

                $q->where(function ($nested) use ($keyword) {
                    $nested->where('serialnumber', 'like', $keyword)
                        ->orWhere('transaction_number', 'like', $keyword);
                });
            })
            ->when($status === 'review', fn($q) => $q->whereIn('compare_status', ['pending', 'active', 'changed']))
            ->when(!in_array($status, ['review', 'all', ''], true), fn($q) => $q->where('compare_status', $status));

        $this->applySiteFilter($query, $site);

        if ($actionStatus === 'no_action') {
            $query->whereIn('compare_status', ['pending', 'active', 'changed'])
                ->where(function ($nested) {
                    $nested->whereDoesntHave('review')
                        ->orWhereHas('review', function ($reviewQuery) {
                            $reviewQuery
                                ->whereRaw("COALESCE(review_status, 'open') = 'open'")
                                ->whereNull('review_detail')
                                ->whereNull('corrective_action')
                                ->whereNull('preventive_action')
                                ->whereNull('sales_remark')
                                ->whereNull('next_follow_up_date');
                        });
                });
        } elseif ($actionStatus === 'due_follow_up') {
            $today = now('Asia/Bangkok')->toDateString();

            $query->whereHas('review', function ($reviewQuery) use ($today) {
                $reviewQuery
                    ->whereNotNull('next_follow_up_date')
                    ->where('next_follow_up_date', '<=', $today)
                    ->whereRaw("COALESCE(review_status, 'open') <> 'closed'");
            });
        } elseif (!in_array($actionStatus, ['all', ''], true)) {
            $query->whereHas('review', fn($reviewQuery) => $reviewQuery->where('review_status', $actionStatus));
        }

        return $query
            ->when($sort === 'purchase_oldest', fn($q) => $q->orderByRaw('CASE WHEN purchase_date IS NULL THEN 1 ELSE 0 END')->orderBy('purchase_date'))
            ->when($sort === 'due_soon', fn($q) => $q->orderByRaw('CASE WHEN COALESCE(current_due_date, due_date) IS NULL THEN 1 ELSE 0 END')->orderByRaw('COALESCE(current_due_date, due_date) ASC'))
            ->when(!in_array($sort, ['purchase_oldest', 'due_soon'], true), fn($q) => $q->orderByDesc(DB::raw('COALESCE(current_qty, snapshot_qty)')))
            ->orderByDesc('snapshot_month_id')
            ->orderByRaw("CASE compare_status WHEN 'changed' THEN 1 WHEN 'active' THEN 2 WHEN 'pending' THEN 3 WHEN 'cleared' THEN 4 ELSE 5 END");
    }

    private function applySiteFilter($query, string $siteFilter): void
    {
        $site = strtoupper(trim($siteFilter));

        if ($site === 'PLUS') {
            $query->where('company', 'like', '%PLUS%');
            return;
        }

        if ($site === 'WIRE') {
            $query->where(function ($nested) {
                $nested->whereNull('company')
                    ->orWhere('company', 'not like', '%PLUS%');
            });
            return;
        }

        if (!in_array(strtolower(trim($siteFilter)), ['all', ''], true)) {
            $query->where('company', $siteFilter);
        }
    }

    private function applyReasonFilter($query, array $reasonFilter): void
    {
        $reasonCodes = $this->reasonCodesForFilter($reasonFilter);

        $query->where(function ($nested) use ($reasonFilter, $reasonCodes) {
            if ($reasonCodes !== []) {
                $nested->whereIn('deadstock_code', $reasonCodes);
            }

            $nested->orWhereIn('deadstock_desc', $reasonFilter);
        });
    }

    private function reasonCodesForFilter(array $reasonFilter): array
    {
        $reasonMap = DeadstockReasonMap::all();
        $codes = [];

        foreach ($reasonFilter as $filter) {
            $filter = trim((string) $filter);
            if ($filter === '') {
                continue;
            }

            $codeCandidate = trim((string) preg_split('/\s+-\s+/', $filter, 2)[0]);
            foreach ([$filter, $codeCandidate] as $candidate) {
                $candidate = strtoupper(trim((string) $candidate));
                if ($candidate !== '' && array_key_exists($candidate, $reasonMap)) {
                    $codes[] = $candidate;
                }
            }

            foreach ($reasonMap as $code => $description) {
                if ($filter === $description) {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function siteLabel(string $company): string
    {
        return stripos($company, 'PLUS') !== false ? 'PLUS' : 'WIRE';
    }

    private function normalizeMultiFilter($value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->flatten()
            ->map(fn($item) => trim((string) $item))
            ->reject(fn($item) => $item === '' || strtolower($item) === 'all')
            ->unique()
            ->values()
            ->all();
    }

    private function compareStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'รอเทียบข้อมูล',
            'active' => 'คงค้าง',
            'changed' => 'เปลี่ยนแปลง',
            'cleared' => 'เคลียร์แล้ว',
            default => $status,
        };
    }

    private function reviewStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'ยังไม่เริ่ม',
            'waiting_sales' => 'รอ Sales',
            'waiting_customer' => 'รอลูกค้า',
            'waiting_delivery' => 'รอจัดส่ง',
            'not_delivered_current_month' => 'ยังไม่ได้ส่งมอบเดือนปัจจุบัน',
            'follow_up' => 'ต้องติดตามต่อ',
            'closed' => 'ปิดแล้ว',
            default => $status,
        };
    }
}
