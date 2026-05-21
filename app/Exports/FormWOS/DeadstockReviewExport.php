<?php

namespace App\Exports\FormWOS;

use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Support\FormWOS\DeadstockReasonMap;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DeadstockReviewExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles
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
            'กำหนดส่งเดิม',
            'กำหนดส่งปัจจุบัน',
            'กำหนดส่งใหม่',
            'ลูกค้า',
            'Sales',
            'บริษัท',
            'รหัสสาเหตุ',
            'รายละเอียดสาเหตุ',
            'สถานะงาน',
            'วันติดตามถัดไป',
            'แนวทางแก้ไข',
            'แนวทางป้องกัน',
            'Sales remark',
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

                return [
                    $month?->snapshot_month?->format('Y-m') ?? '',
                    $month?->recv_date?->format('Y-m-d') ?? '',
                    $item->compare_status,
                    $item->purchase_date?->format('Y-m-d') ?? '',
                    $item->partnumber,
                    $item->part_description,
                    $item->serialnumber,
                    $item->transaction_number,
                    (float) $item->snapshot_qty,
                    $item->current_qty !== null ? (float) $item->current_qty : null,
                    $item->due_date?->format('Y-m-d') ?? '',
                    $item->current_due_date?->format('Y-m-d') ?? '',
                    $review?->revised_due_date?->format('Y-m-d') ?? '',
                    $item->customer_name,
                    $item->salesperson_name,
                    $item->company,
                    $reasonCode,
                    DeadstockReasonMap::description($reasonCode, $item->deadstock_desc),
                    $review?->review_status ?? 'open',
                    $review?->next_follow_up_date?->format('Y-m-d') ?? '',
                    $review?->corrective_action,
                    $review?->preventive_action,
                    $review?->sales_remark,
                    $item->last_checked_at?->format('Y-m-d H:i') ?? '',
                ];
            });
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
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
        $company = trim((string) ($this->filters['company'] ?? 'all'));
        $sales = trim((string) ($this->filters['sales'] ?? 'all'));
        $reasonCode = trim((string) ($this->filters['reason_code'] ?? 'all'));
        $serial = trim((string) ($this->filters['serial'] ?? ''));
        $sort = trim((string) ($this->filters['sort'] ?? 'qty_desc'));

        $query = DeadstockSnapshotItem::query()
            ->with(['snapshotMonth', 'review', 'latestCompareLog'])
            ->when($monthIds !== [], fn($q) => $q->whereIn('snapshot_month_id', $monthIds), fn($q) => $q->whereRaw('1 = 0'))
            ->when($company !== 'all' && $company !== '', fn($q) => $q->where('company', $company))
            ->when($sales !== 'all' && $sales !== '', fn($q) => $q->where('salesperson_name', $sales))
            ->when($reasonCode !== 'all' && $reasonCode !== '', fn($q) => $q->where('deadstock_code', $reasonCode))
            ->when($serial !== '', function ($q) use ($serial) {
                $keyword = '%' . $serial . '%';

                $q->where(function ($nested) use ($keyword) {
                    $nested->where('serialnumber', 'like', $keyword)
                        ->orWhere('transaction_number', 'like', $keyword);
                });
            })
            ->when($status === 'review', fn($q) => $q->whereIn('compare_status', ['pending', 'active', 'changed']))
            ->when(!in_array($status, ['review', 'all', ''], true), fn($q) => $q->where('compare_status', $status));

        if ($actionStatus === 'no_action') {
            $query->whereIn('compare_status', ['pending', 'active', 'changed'])
                ->where(function ($nested) {
                    $nested->whereDoesntHave('review')
                        ->orWhereHas('review', function ($reviewQuery) {
                            $reviewQuery
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
}
