<?php

namespace App\Exports\FormWOS;

use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Support\FormWOS\DeadstockReasonMap;
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
            'กำหนดส่งต่าง',
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
                $range = $sheet->calculateWorksheetDimension();

                $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle('E:F')->getAlignment()->setWrapText(true);
                $sheet->getStyle('R:Y')->getAlignment()->setWrapText(true);
                $sheet->getStyle('I:K')->getNumberFormat()->setFormatCode('#,##0.00');
            },
        ];
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
        $serial = trim((string) ($this->filters['serial'] ?? ''));
        $sort = trim((string) ($this->filters['sort'] ?? 'qty_desc'));

        $query = DeadstockSnapshotItem::query()
            ->with(['snapshotMonth', 'review', 'latestCompareLog'])
            ->when($monthIds !== [], fn($q) => $q->whereIn('snapshot_month_id', $monthIds), fn($q) => $q->whereRaw('1 = 0'))
            ->when($customer !== [], fn($q) => $q->whereIn('customer_name', $customer))
            ->when($sales !== [], fn($q) => $q->whereIn('salesperson_name', $sales))
            ->when($reasonCode !== [], fn($q) => $this->applyReasonFilter($q, $reasonCode))
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
            'follow_up' => 'ต้องติดตามต่อ',
            'closed' => 'ปิดแล้ว',
            default => $status,
        };
    }
}
