<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use App\Exports\FormWOS\DeadstockReviewExport;
use App\Models\FormWOS\DeadstockItemReview;
use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use App\Services\FormWOS\DeadstockReviewService;
use App\Services\FormWOS\DeadstockSnapshotImportService;
use App\Support\FormWOS\DeadstockReasonMap;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeadstockReportController extends Controller
{
    private function mailDailyPath(): string
    {
        return rtrim((string) env('DEADSTOCK_MAIL_DAILY_PATH', 'M:\\htdocs\\mail-daily'), '\\/');
    }

    public function dashboard(DeadstockReviewService $reviewService): View
    {
        $snapshots = $this->loadSnapshots();
        $usableSnapshots = $snapshots
            ->filter(fn(array $snap) => (float) data_get($snap, 'totals.total_qty', 0) > 0
                || (float) data_get($snap, 'totals.total_value', 0) > 0
                || (int) data_get($snap, 'totals.customers', 0) > 0
                || (int) data_get($snap, 'totals.parts', 0) > 0)
            ->values();
        $latest = $usableSnapshots->first() ?? $snapshots->first();
        $previous = $usableSnapshots->skip(1)->first();
        $reviewMonth = DeadstockSnapshotMonth::query()
            ->orderByDesc('snapshot_month')
            ->orderByDesc('recv_date')
            ->first();

        if ($reviewMonth) {
            $reviewService->compareMonth($reviewMonth, false);
        }

        $today = now('Asia/Bangkok')->toDateString();

        $reviewSummary = $reviewMonth
            ? DeadstockSnapshotItem::query()
                ->leftJoin('ds_item_reviews as r', 'r.snapshot_item_id', '=', 'ds_snapshot_items.id')
                ->where('snapshot_month_id', $reviewMonth->id)
                ->selectRaw('COUNT(*) AS total_items')
                ->selectRaw("SUM(CASE WHEN compare_status = 'active' THEN 1 ELSE 0 END) AS active_items")
                ->selectRaw("SUM(CASE WHEN compare_status = 'changed' THEN 1 ELSE 0 END) AS changed_items")
                ->selectRaw("SUM(CASE WHEN compare_status = 'cleared' THEN 1 ELSE 0 END) AS cleared_items")
                ->selectRaw("SUM(CASE WHEN compare_status = 'pending' THEN 1 ELSE 0 END) AS pending_items")
                ->selectRaw("SUM(CASE WHEN compare_status IN ('active', 'changed') THEN COALESCE(current_qty, snapshot_qty) ELSE 0 END) AS open_qty")
                ->selectRaw("SUM(CASE WHEN compare_status = 'active' THEN COALESCE(current_qty, snapshot_qty) ELSE 0 END) AS active_qty")
                ->selectRaw("SUM(CASE WHEN compare_status = 'changed' THEN COALESCE(current_qty, snapshot_qty) ELSE 0 END) AS changed_qty")
                ->selectRaw("SUM(CASE WHEN compare_status = 'cleared' THEN snapshot_qty ELSE 0 END) AS cleared_qty")
                ->selectRaw('SUM(snapshot_qty) AS snapshot_qty')
                ->selectRaw("SUM(CASE WHEN compare_status = 'cleared' THEN 0 ELSE COALESCE(current_qty, snapshot_qty) END) AS current_total_qty")
                ->selectRaw("SUM(CASE WHEN compare_status IN ('pending', 'active', 'changed') AND (r.id IS NULL OR (r.corrective_action IS NULL AND r.preventive_action IS NULL AND r.sales_remark IS NULL AND r.next_follow_up_date IS NULL)) THEN 1 ELSE 0 END) AS no_action_items")
                ->selectRaw("SUM(CASE WHEN r.next_follow_up_date IS NOT NULL AND r.next_follow_up_date <= ? AND COALESCE(r.review_status, 'open') <> 'closed' THEN 1 ELSE 0 END) AS due_follow_up_items", [$today])
                ->selectRaw("SUM(CASE WHEN r.review_status = 'closed' THEN 1 ELSE 0 END) AS closed_action_items")
                ->first()
            : null;

        $urgentItems = $reviewMonth
            ? DeadstockSnapshotItem::query()
                ->where('snapshot_month_id', $reviewMonth->id)
                ->whereIn('compare_status', ['changed', 'active'])
                ->selectRaw('MIN(part_description) AS part_description')
                ->selectRaw('partnumber')
                ->selectRaw('customer_name')
                ->selectRaw('salesperson_name')
                ->selectRaw('SUM(COALESCE(current_qty, snapshot_qty)) AS urgent_qty')
                ->selectRaw('COUNT(*) AS item_count')
                ->selectRaw("MIN(CASE compare_status WHEN 'changed' THEN 1 WHEN 'active' THEN 2 ELSE 3 END) AS status_priority")
                ->groupBy('partnumber', 'customer_name', 'salesperson_name')
                ->orderBy('status_priority')
                ->orderByDesc('urgent_qty')
                ->take(5)
                ->get()
            : collect();

        $trend = $usableSnapshots
            ->sortBy('recv_date')
            ->map(fn(array $snap) => [
                'date' => (string) ($snap['recv_date'] ?? $snap['as_of'] ?? $snap['_date'] ?? ''),
                'generated' => (string) ($snap['generated'] ?? ''),
                'qty' => (float) data_get($snap, 'totals.total_qty', 0),
                'value' => (float) data_get($snap, 'totals.total_value', 0),
            ])
            ->filter(fn(array $point) => $point['date'] !== '')
            ->groupBy('date')
            ->map(fn(Collection $points) => $points->sortBy('generated')->last())
            ->sortBy('date')
            ->take(-14)
            ->values();

        $totalQty = (float) data_get($latest, 'totals.total_qty', 0);
        $totalValue = (float) data_get($latest, 'totals.total_value', 0);
        $previousQty = (float) data_get($previous, 'totals.total_qty', 0);
        $previousValue = (float) data_get($previous, 'totals.total_value', 0);
        $previousSevenQty = $usableSnapshots
            ->skip(1)
            ->take(7)
            ->map(fn(array $snap) => (float) data_get($snap, 'totals.total_qty', 0))
            ->filter(fn(float $qty) => $qty > 0);
        $avgSevenQty = $previousSevenQty->isNotEmpty() ? (float) $previousSevenQty->avg() : 0.0;
        $salesBreakdown = $this->buildBreakdown($latest['by_sales'] ?? [], $previous['by_sales'] ?? [], $totalQty);
        $reasonBreakdown = $this->buildBreakdown($latest['by_reason'] ?? [], $previous['by_reason'] ?? [], $totalQty);
        $actionGroups = $this->buildActionGroups($latest['by_reason'] ?? [], $totalQty);

        return view('formwos.deadstock.dashboard', [
            'latest' => $latest,
            'snapshots' => $usableSnapshots->take(30)->values(),
            'trend' => $trend,
            'topSales' => collect($latest['by_sales'] ?? [])->sortDesc()->take(15),
            'topReasons' => collect($latest['by_reason'] ?? [])->sortDesc()->take(15),
            'insights' => [
                'qty_change' => $totalQty - $previousQty,
                'qty_change_pct' => $this->percentChange($totalQty, $previousQty),
                'value_change' => $totalValue - $previousValue,
                'value_change_pct' => $this->percentChange($totalValue, $previousValue),
                'avg_seven_qty' => $avgSevenQty,
                'vs_avg_seven_qty' => $avgSevenQty > 0 ? $totalQty - $avgSevenQty : 0,
                'vs_avg_seven_pct' => $avgSevenQty > 0 ? $this->percentChange($totalQty, $avgSevenQty) : null,
                'value_per_kg' => $totalQty > 0 ? $totalValue / $totalQty : 0,
                'top_reason' => $reasonBreakdown->first(),
                'top_sales_share' => $salesBreakdown->take(3)->sum('share'),
                'top_reason_share' => $reasonBreakdown->take(3)->sum('share'),
                'unassigned_reason' => $reasonBreakdown
                    ->first(fn(array $row) => $this->isUnassignedReason($row['name'])),
            ],
            'paretoSales' => $salesBreakdown->take(10)->values(),
            'paretoReasons' => $reasonBreakdown->take(10)->values(),
            'salesMovers' => $salesBreakdown
                ->filter(fn(array $row) => $row['change'] > 0)
                ->sortByDesc('change')
                ->take(8)
                ->values(),
            'reasonMovers' => $reasonBreakdown
                ->filter(fn(array $row) => $row['change'] > 0)
                ->sortByDesc('change')
                ->take(8)
                ->values(),
            'actionGroups' => $actionGroups,
            'salesDrilldown' => $salesBreakdown->values(),
            'reasonDrilldown' => $reasonBreakdown->values(),
            'sourcePath' => $this->snapshotPath(),
            'reviewMonth' => $reviewMonth,
            'reviewSummary' => $reviewSummary,
            'urgentItems' => $urgentItems,
        ]);
    }

    public function manual(): View
    {
        return view('formwos.deadstock.manual', [
            'defaultTo' => $this->mailDailyEnv('DEADSTOCK_MAIL_TO'),
            'defaultCc' => $this->mailDailyEnv('DEADSTOCK_MAIL_CC'),
            'defaultBcc' => $this->mailDailyEnv('DEADSTOCK_MAIL_BCC'),
            'mailDailyPath' => $this->mailDailyPath(),
        ]);
    }

    public function review(Request $request, DeadstockReviewService $reviewService): View
    {
        $rawSnapshots = $this->loadSnapshots();
        $months = DeadstockSnapshotMonth::query()
            ->orderByDesc('snapshot_month')
            ->get();
        $selectedMonthIds = $this->selectedMonthIds($request, $months);
        $selectedMonths = $months
            ->filter(fn(DeadstockSnapshotMonth $month) => in_array((int) $month->id, $selectedMonthIds, true))
            ->values();
        $selectedMonth = $selectedMonths->first() ?? $months->first();
        $status = trim((string) $request->query('status', 'review'));
        $actionStatus = trim((string) $request->query('action_status', 'all'));
        $companyFilter = trim((string) $request->query('company', 'all'));
        $salesFilter = trim((string) $request->query('sales', 'all'));
        $reasonFilter = trim((string) $request->query('reason_code', 'all'));
        $serialFilter = trim((string) $request->query('serial', ''));
        $sort = trim((string) $request->query('sort', 'qty_desc'));

        foreach ($selectedMonths as $month) {
            $reviewService->compareMonth($month, false);
        }

        $today = now('Asia/Bangkok')->toDateString();
        $baseFilters = [
            'month_ids' => $selectedMonthIds,
            'status' => $status,
            'action_status' => $actionStatus,
            'company' => $companyFilter,
            'sales' => $salesFilter,
            'reason_code' => $reasonFilter,
            'serial' => $serialFilter,
        ];

        $items = DeadstockSnapshotItem::query()
            ->with(['review.reviewer', 'latestCompareLog'])
            ->when($selectedMonthIds !== [], fn($query) => $query->whereIn('snapshot_month_id', $selectedMonthIds), fn($query) => $query->whereRaw('1 = 0'));

        $this->applyDeadstockFilters($items, $baseFilters, false);

        $items = $items
            ->when($sort === 'purchase_oldest', fn($query) => $query->orderByRaw('CASE WHEN purchase_date IS NULL THEN 1 ELSE 0 END')->orderBy('purchase_date'))
            ->when($sort === 'due_soon', fn($query) => $query->orderByRaw('CASE WHEN COALESCE(current_due_date, due_date) IS NULL THEN 1 ELSE 0 END')->orderByRaw('COALESCE(current_due_date, due_date) ASC'))
            ->when(!in_array($sort, ['purchase_oldest', 'due_soon'], true), fn($query) => $query->orderByDesc(DB::raw('COALESCE(current_qty, snapshot_qty)')))
            ->orderByDesc('snapshot_month_id')
            ->orderByRaw("CASE compare_status WHEN 'changed' THEN 1 WHEN 'active' THEN 2 WHEN 'pending' THEN 3 WHEN 'cleared' THEN 4 ELSE 5 END")
            ->paginate(40)
            ->withQueryString();

        $summaryQuery = DeadstockSnapshotItem::query()
            ->leftJoin('ds_item_reviews as r', 'r.snapshot_item_id', '=', 'ds_snapshot_items.id')
            ->when($selectedMonthIds !== [], fn($query) => $query->whereIn('snapshot_month_id', $selectedMonthIds), fn($query) => $query->whereRaw('1 = 0'));

        $this->applyDeadstockFilters($summaryQuery, $baseFilters, true);

        $summary = $summaryQuery
            ->selectRaw('COUNT(*) AS total_items')
            ->selectRaw("SUM(CASE WHEN compare_status = 'active' THEN 1 ELSE 0 END) AS active_items")
            ->selectRaw("SUM(CASE WHEN compare_status = 'changed' THEN 1 ELSE 0 END) AS changed_items")
            ->selectRaw("SUM(CASE WHEN compare_status = 'cleared' THEN 1 ELSE 0 END) AS cleared_items")
            ->selectRaw("SUM(CASE WHEN compare_status = 'pending' THEN 1 ELSE 0 END) AS pending_items")
            ->selectRaw("SUM(CASE WHEN compare_status IN ('pending', 'active', 'changed') AND (r.id IS NULL OR (r.corrective_action IS NULL AND r.preventive_action IS NULL AND r.sales_remark IS NULL AND r.next_follow_up_date IS NULL)) THEN 1 ELSE 0 END) AS no_action_items")
            ->selectRaw("SUM(CASE WHEN r.next_follow_up_date IS NOT NULL AND r.next_follow_up_date <= ? AND COALESCE(r.review_status, 'open') <> 'closed' THEN 1 ELSE 0 END) AS due_follow_up_items", [$today])
            ->selectRaw("SUM(CASE WHEN r.review_status = 'closed' THEN 1 ELSE 0 END) AS closed_action_items")
            ->selectRaw('SUM(ds_snapshot_items.snapshot_qty) AS total_qty')
            ->selectRaw("SUM(CASE WHEN compare_status = 'cleared' THEN 0 ELSE COALESCE(ds_snapshot_items.current_qty, ds_snapshot_items.snapshot_qty) END) AS current_total_qty")
            ->first();

        $salesOptions = $selectedMonthIds !== []
            ? DeadstockSnapshotItem::query()
                ->whereIn('snapshot_month_id', $selectedMonthIds)
                ->when($companyFilter !== 'all' && $companyFilter !== '', fn($query) => $query->where('company', $companyFilter))
                ->when($reasonFilter !== 'all' && $reasonFilter !== '', fn($query) => $query->where('deadstock_code', $reasonFilter))
                ->whereNotNull('salesperson_name')
                ->where('salesperson_name', '<>', '')
                ->select('salesperson_name')
                ->distinct()
                ->orderBy('salesperson_name')
                ->pluck('salesperson_name')
            : collect();

        $companyOptions = $selectedMonthIds !== []
            ? DeadstockSnapshotItem::query()
                ->whereIn('snapshot_month_id', $selectedMonthIds)
                ->whereNotNull('company')
                ->where('company', '<>', '')
                ->select('company')
                ->distinct()
                ->orderBy('company')
                ->pluck('company')
            : collect();

        $reasonOptions = $selectedMonthIds !== []
            ? DeadstockSnapshotItem::query()
                ->whereIn('snapshot_month_id', $selectedMonthIds)
                ->when($companyFilter !== 'all' && $companyFilter !== '', fn($query) => $query->where('company', $companyFilter))
                ->whereNotNull('deadstock_code')
                ->where('deadstock_code', '<>', '')
                ->select('deadstock_code')
                ->selectRaw('MAX(deadstock_desc) AS deadstock_desc')
                ->groupBy('deadstock_code')
                ->orderBy('deadstock_code')
                ->get()
                ->map(function ($row) {
                    $row->deadstock_desc = DeadstockReasonMap::description($row->deadstock_code, $row->deadstock_desc);
                    return $row;
                })
            : collect();

        return view('formwos.deadstock.review', [
            'months' => $months,
            'selectedMonthIds' => $selectedMonthIds,
            'selectedMonths' => $selectedMonths,
            'selectedMonth' => $selectedMonth,
            'items' => $items,
            'summary' => $summary,
            'status' => $status,
            'actionStatus' => $actionStatus,
            'companyFilter' => $companyFilter,
            'companyOptions' => $companyOptions,
            'salesFilter' => $salesFilter,
            'salesOptions' => $salesOptions,
            'reasonFilter' => $reasonFilter,
            'reasonOptions' => $reasonOptions,
            'serialFilter' => $serialFilter,
            'sort' => $sort,
            'sourcePath' => $this->snapshotPath(),
            'rawSnapshotCount' => $rawSnapshots->count(),
            'latestRawSnapshotDate' => $rawSnapshots->first()['recv_date']
                ?? $rawSnapshots->first()['as_of']
                ?? $rawSnapshots->first()['_date']
                ?? null,
        ]);
    }

    public function exportReview(Request $request): BinaryFileResponse
    {
        $months = DeadstockSnapshotMonth::query()
            ->orderByDesc('snapshot_month')
            ->get();
        $monthIds = $this->selectedMonthIds($request, $months);
        $filename = 'deadstock_review_' . now('Asia/Bangkok')->format('Ymd_His') . '.xlsx';

        return Excel::download(new DeadstockReviewExport([
            'month_ids' => $monthIds,
            'status' => trim((string) $request->query('status', 'review')),
            'action_status' => trim((string) $request->query('action_status', 'all')),
            'company' => trim((string) $request->query('company', 'all')),
            'sales' => trim((string) $request->query('sales', 'all')),
            'reason_code' => trim((string) $request->query('reason_code', 'all')),
            'serial' => trim((string) $request->query('serial', '')),
            'sort' => trim((string) $request->query('sort', 'qty_desc')),
        ]), $filename);
    }

    private function selectedMonthIds(Request $request, Collection $months): array
    {
        $rawMonthIds = $request->query('month_ids', []);

        if (!is_array($rawMonthIds)) {
            $rawMonthIds = [$rawMonthIds];
        }

        if ($rawMonthIds === [] && $request->filled('month_id')) {
            $rawMonthIds = [(string) $request->query('month_id')];
        }

        if (in_array('all', array_map('strval', $rawMonthIds), true)) {
            return $months->pluck('id')->map(fn($id) => (int) $id)->all();
        }

        $monthIds = collect($rawMonthIds)
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($monthIds === [] && $months->isNotEmpty()) {
            return [(int) $months->first()->id];
        }

        $validMonthIds = $months->pluck('id')->map(fn($id) => (int) $id)->all();

        return array_values(array_intersect($validMonthIds, $monthIds));
    }

    private function applyDeadstockFilters($query, array $filters, bool $joinedReview): void
    {
        $status = trim((string) ($filters['status'] ?? 'review'));
        $actionStatus = trim((string) ($filters['action_status'] ?? 'all'));
        $companyFilter = trim((string) ($filters['company'] ?? 'all'));
        $salesFilter = trim((string) ($filters['sales'] ?? 'all'));
        $reasonFilter = trim((string) ($filters['reason_code'] ?? 'all'));
        $serialFilter = trim((string) ($filters['serial'] ?? ''));

        $query
            ->when($companyFilter !== 'all' && $companyFilter !== '', fn($q) => $q->where('company', $companyFilter))
            ->when($salesFilter !== 'all' && $salesFilter !== '', fn($q) => $q->where('salesperson_name', $salesFilter))
            ->when($reasonFilter !== 'all' && $reasonFilter !== '', fn($q) => $q->where('deadstock_code', $reasonFilter))
            ->when($serialFilter !== '', function ($q) use ($serialFilter) {
                $keyword = '%' . $serialFilter . '%';

                $q->where(function ($nested) use ($keyword) {
                    $nested->where('serialnumber', 'like', $keyword)
                        ->orWhere('transaction_number', 'like', $keyword);
                });
            })
            ->when($status === 'review', fn($q) => $q->whereIn('compare_status', ['pending', 'active', 'changed']))
            ->when(!in_array($status, ['review', 'all', ''], true), fn($q) => $q->where('compare_status', $status));

        if ($actionStatus === 'no_action') {
            $query->whereIn('compare_status', ['pending', 'active', 'changed']);

            if ($joinedReview) {
                $query->where(function ($nested) {
                    $nested->whereNull('r.id')
                        ->orWhere(function ($reviewQuery) {
                            $reviewQuery
                                ->whereNull('r.corrective_action')
                                ->whereNull('r.preventive_action')
                                ->whereNull('r.sales_remark')
                                ->whereNull('r.next_follow_up_date');
                        });
                });
            } else {
                $query->where(function ($nested) {
                    $nested->whereDoesntHave('review')
                        ->orWhereHas('review', function ($reviewQuery) {
                            $reviewQuery
                                ->whereNull('corrective_action')
                                ->whereNull('preventive_action')
                                ->whereNull('sales_remark')
                                ->whereNull('next_follow_up_date');
                        });
                });
            }
        } elseif ($actionStatus === 'due_follow_up') {
            $today = now('Asia/Bangkok')->toDateString();

            if ($joinedReview) {
                $query->whereNotNull('r.next_follow_up_date')
                    ->where('r.next_follow_up_date', '<=', $today)
                    ->whereRaw("COALESCE(r.review_status, 'open') <> 'closed'");
            } else {
                $query->whereHas('review', function ($reviewQuery) use ($today) {
                    $reviewQuery
                        ->whereNotNull('next_follow_up_date')
                        ->where('next_follow_up_date', '<=', $today)
                        ->whereRaw("COALESCE(review_status, 'open') <> 'closed'");
                });
            }
        } elseif (!in_array($actionStatus, ['all', ''], true)) {
            if ($joinedReview) {
                $query->where('r.review_status', $actionStatus);
            } else {
                $query->whereHas('review', fn($reviewQuery) => $reviewQuery->where('review_status', $actionStatus));
            }
        }
    }

    public function saveReview(Request $request, DeadstockSnapshotItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'revised_due_date' => ['nullable', 'date'],
            'next_follow_up_date' => ['nullable', 'date'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'preventive_action' => ['nullable', 'string', 'max:5000'],
            'sales_remark' => ['nullable', 'string', 'max:5000'],
            'review_status' => ['required', 'in:open,waiting_sales,waiting_customer,waiting_delivery,follow_up,closed'],
        ]);

        $hasActionNote = collect([
            $validated['corrective_action'] ?? null,
            $validated['preventive_action'] ?? null,
            $validated['sales_remark'] ?? null,
        ])->contains(fn($value) => trim((string) $value) !== '');

        if (in_array($validated['review_status'], ['waiting_sales', 'waiting_customer', 'waiting_delivery', 'follow_up'], true)
            && empty($validated['next_follow_up_date'])) {
            return back()
                ->withErrors(['next_follow_up_date' => 'กรุณาระบุวันติดตามถัดไปเมื่อสถานะงานยังต้องติดตามต่อ'])
                ->withInput();
        }

        if ($validated['review_status'] === 'closed'
            && in_array($item->compare_status, ['active', 'changed'], true)
            && !$hasActionNote) {
            return back()
                ->withErrors(['corrective_action' => 'กรุณาระบุ action หรือ remark ก่อนปิดรายการที่ยังค้างหรือเปลี่ยนแปลง'])
                ->withInput();
        }

        DeadstockItemReview::updateOrCreate(
            ['snapshot_item_id' => $item->id],
            [
                ...$validated,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now('Asia/Bangkok'),
                'updated_by' => auth()->id(),
                'created_by' => $item->review?->created_by ?? auth()->id(),
            ]
        );

        return back()->with('success', 'บันทึกข้อมูลติดตาม Deadstock แล้ว');
    }

    public function compareMonth(
        DeadstockSnapshotMonth $month,
        DeadstockReviewService $reviewService
    ): RedirectResponse {
        $counts = $reviewService->compareMonth($month);

        return redirect()
            ->route('deadstock.review', ['month_id' => $month->id])
            ->with('success', "เทียบ snapshot เดือนนี้แล้ว: คงค้าง {$counts['active']} / เปลี่ยนแปลง {$counts['changed']} / เคลียร์แล้ว {$counts['cleared']}");
    }

    public function importLatestSnapshot(DeadstockSnapshotImportService $importService): RedirectResponse
    {
        $files = glob($this->snapshotPath() . DIRECTORY_SEPARATOR . 'deadstock_items_*.json') ?: [];
        rsort($files);
        $file = $files[0] ?? null;

        if (!$file) {
            return back()->with('error', 'ยังไม่พบไฟล์ deadstock_items_*.json สำหรับ import');
        }

        $result = $importService->importFile($file);

        return redirect()
            ->route('deadstock.review', ['month_id' => $result['month_id']])
            ->with('success', "Import snapshot สำเร็จ: {$result['recv_date']} จำนวน {$result['items']} รายการ");
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['nullable', 'string', 'max:2000'],
            'date' => ['nullable', 'date'],
            'force_week' => ['nullable'],
            'force_month' => ['nullable'],
        ]);

        $command = [
            $this->phpBinary(),
            'artisan',
            'report:deadstock',
        ];

        if (!empty($validated['to'])) {
            $command[] = '--to=' . trim((string) $validated['to']);
        }

        if (!empty($validated['date'])) {
            $command[] = '--date=' . Carbon::parse($validated['date'])->toDateString();
        }

        if ($request->boolean('force_week')) {
            $command[] = '--force-week';
        }

        if ($request->boolean('force_month')) {
            $command[] = '--force-month';
        }

        $process = new Process($command, $this->mailDailyPath());
        $process->setTimeout(600);
        $process->run();

        $output = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());

        if (!$process->isSuccessful()) {
            return back()
                ->withInput()
                ->with('error', 'ส่ง Deadstock report ไม่สำเร็จ')
                ->with('deadstock_output', $output);
        }

        return redirect()
            ->route('deadstock.manual')
            ->with('success', 'ส่ง Deadstock report manual สำเร็จ')
            ->with('deadstock_output', $output);
    }

    private function snapshotPath(): string
    {
        return $this->mailDailyPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'snapshots';
    }

    private function phpBinary(): string
    {
        $configured = trim((string) env('DEADSTOCK_PHP_BINARY', ''));
        if ($configured !== '') {
            return $configured;
        }

        $binary = PHP_BINDIR . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');

        return is_file($binary) ? $binary : 'php';
    }

    private function loadSnapshots(): Collection
    {
        $dir = $this->snapshotPath();

        if (!is_dir($dir)) {
            return collect();
        }

        return collect(glob($dir . DIRECTORY_SEPARATOR . 'deadstock_*.json') ?: [])
            ->map(function (string $path) {
                $payload = json_decode((string) file_get_contents($path), true);
                if (!is_array($payload)) {
                    return null;
                }

                $payload['_path'] = $path;
                $payload['_date'] = preg_replace('/.*deadstock_(\d{4}-\d{2}-\d{2})\.json$/', '$1', str_replace('\\', '/', $path));

                return $payload;
            })
            ->filter()
            ->sortByDesc(fn(array $snap) => $snap['recv_date'] ?? $snap['_date'] ?? '')
            ->values();
    }

    private function buildBreakdown(array $current, array $previous, float $totalQty): Collection
    {
        return collect(array_unique(array_merge(array_keys($current), array_keys($previous))))
            ->map(function ($name) use ($current, $previous, $totalQty) {
                $qty = (float) ($current[$name] ?? 0);
                $previousQty = (float) ($previous[$name] ?? 0);

                return [
                    'name' => (string) $name,
                    'qty' => $qty,
                    'previous_qty' => $previousQty,
                    'change' => $qty - $previousQty,
                    'change_pct' => $this->percentChange($qty, $previousQty),
                    'share' => $totalQty > 0 ? ($qty / $totalQty) * 100 : 0,
                ];
            })
            ->sortByDesc('qty')
            ->values();
    }

    private function buildActionGroups(array $reasons, float $totalQty): Collection
    {
        $groups = [
            'ควรรีวิวทันที' => ['qty' => 0.0, 'hint' => 'เกิน 30 วัน หรือยังไม่มีแผนชัดเจน'],
            'รอตามกำหนดลูกค้า' => ['qty' => 0.0, 'hint' => 'ยังอยู่ในช่วง schedule ปกติ'],
            'พร้อมส่ง/รอจัดการ' => ['qty' => 0.0, 'hint' => 'FG/WIP หรือของพร้อมแต่ยังรอดำเนินการ'],
            'ข้อมูลไม่ครบ' => ['qty' => 0.0, 'hint' => 'ยังไม่มีสาเหตุ ทำให้วิเคราะห์ต่อยาก'],
            'อื่น ๆ' => ['qty' => 0.0, 'hint' => 'สาเหตุอื่น'],
        ];

        foreach ($reasons as $reason => $qty) {
            $group = $this->reasonActionGroup((string) $reason);
            $groups[$group]['qty'] += (float) $qty;
        }

        return collect($groups)
            ->map(fn(array $row, string $name) => [
                'name' => $name,
                'qty' => $row['qty'],
                'share' => $totalQty > 0 ? ($row['qty'] / $totalQty) * 100 : 0,
                'hint' => $row['hint'],
            ])
            ->sortByDesc('qty')
            ->values();
    }

    private function reasonActionGroup(string $reason): string
    {
        $normalized = mb_strtolower(trim($reason));

        if ($this->isUnassignedReason($normalized)) {
            return 'ข้อมูลไม่ครบ';
        }

        if (str_contains($normalized, 'เกิน 30') || str_contains($normalized, 'over 30')) {
            return 'ควรรีวิวทันที';
        }

        if (str_contains($normalized, 'ภายใน 30') || str_contains($normalized, 'within 30') || str_contains($normalized, 'schedule')) {
            return 'รอตามกำหนดลูกค้า';
        }

        if (str_contains($normalized, 'fg') || str_contains($normalized, 'wip') || str_contains($normalized, 'พร้อม')) {
            return 'พร้อมส่ง/รอจัดการ';
        }

        return 'อื่น ๆ';
    }

    private function isUnassignedReason(string $reason): bool
    {
        $normalized = mb_strtolower(trim($reason));

        return $normalized === ''
            || str_contains($normalized, 'ไม่ระบุ')
            || str_contains($normalized, 'unassigned')
            || str_contains($normalized, 'unknown');
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.00001) {
            return abs($current) < 0.00001 ? 0.0 : null;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function mailDailyEnv(string $key): ?string
    {
        $path = $this->mailDailyPath() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$envKey, $value] = explode('=', $line, 2);
            if (trim($envKey) !== $key) {
                continue;
            }

            return trim($value, " \t\n\r\0\x0B\"'");
        }

        return null;
    }
}
