<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use App\Exports\FormWOS\DeadstockReviewExport;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\FormWOS\DeadstockItemReview;
use App\Models\FormWOS\DeadstockItemReviewLog;
use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use App\Models\FormWOS\DeadstockUserSalesAccess;
use App\Models\Users\User;
use App\Services\FormWOS\DeadstockReviewService;
use App\Services\FormWOS\DeadstockSnapshotImportService;
use App\Support\FormWOS\DeadstockCompareStatus;
use App\Support\FormWOS\DeadstockReasonMap;
use App\Support\FormWOS\DeadstockReviewStatus;
use App\Support\FormWOS\DeadstockSalesAccess;
use App\Support\FormWOS\DeadstockSalesMap;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeadstockReportController extends Controller
{
    private const DEADSTOCK_REVIEW_LOG_FIELDS = [
        'revised_due_date',
        'next_follow_up_date',
        'review_detail',
        'corrective_action',
        'preventive_action',
        'sales_remark',
        'review_status',
    ];

    private function mailDailyPath(): string
    {
        return rtrim((string) env('DEADSTOCK_MAIL_DAILY_PATH', 'M:\\htdocs\\mail-daily'), '\\/');
    }

    public function dashboard(): View
    {
        $usableFilter = fn(array $snap) => (float) data_get($snap, 'totals.total_qty', 0) > 0
            || (float) data_get($snap, 'totals.total_value', 0) > 0
            || (int) data_get($snap, 'totals.customers', 0) > 0
            || (int) data_get($snap, 'totals.parts', 0) > 0;

        $snapshots = $this->loadSnapshots(60);
        $usableSnapshots = $snapshots->filter($usableFilter)->values();

        if ($usableSnapshots->isEmpty()) {
            // prod อ่านไฟล์ JSON สรุปไม่ได้/ไม่มีไฟล์สรุป → ใช้ข้อมูลจาก DB (แหล่งเดียวกับหน้า review)
            $snapshots = $this->loadSnapshotsFromDb(60);
            $usableSnapshots = $snapshots->filter($usableFilter)->values();
        }
        $latest = $usableSnapshots->first() ?? $snapshots->first();
        $previous = $usableSnapshots->skip(1)->first();
        $reviewMonth = DeadstockSnapshotMonth::query()
            ->where('item_count', '>', 0)
            ->orderByDesc('snapshot_month')
            ->orderByDesc('recv_date')
            ->first();

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
                ->selectRaw("SUM(CASE WHEN COALESCE(r.review_status, 'open') = 'open' THEN 1 ELSE 0 END) AS not_followed_up_items")
                ->selectRaw("SUM(CASE WHEN r.review_status IN ('waiting_follow_up', 'waiting_sales', 'waiting_customer', 'waiting_delivery', 'not_delivered_current_month') THEN 1 ELSE 0 END) AS waiting_follow_up_items")
                ->selectRaw("SUM(CASE WHEN r.review_status IN ('in_progress', 'follow_up', 'closed') THEN 1 ELSE 0 END) AS in_progress_items")
                ->selectRaw('MAX(ds_snapshot_items.last_checked_at) AS last_checked_at')
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

    public function config(): View
    {
        $rawSnapshots = $this->loadSnapshotIndex();

        return view('formwos.deadstock.config', [
            'sourcePath' => $this->snapshotPath(),
            'mailDailyPath' => $this->mailDailyPath(),
            'itemSnapshotCount' => $this->itemSnapshotFiles()->count(),
            'latestItemSnapshotDate' => $this->itemSnapshotDate($this->latestItemSnapshotFile()),
            'excelBackfillCount' => $this->excelBackfillFiles()->count(),
            'rawSnapshotCount' => $rawSnapshots->count(),
            'latestRawSnapshotDate' => $rawSnapshots->first()['recv_date']
                ?? $rawSnapshots->first()['as_of']
                ?? $rawSnapshots->first()['_date']
                ?? null,
            'salesAccesses' => DeadstockUserSalesAccess::query()
                ->with('user:id,username,name')
                ->orderBy('salesperson_key')
                ->orderBy('user_id')
                ->get(),
            'salesAccessUsers' => User::query()
                ->where('is_active', 1)
                ->orderBy('name')
                ->get(['id', 'username', 'name']),
            'salesAccessOptions' => DeadstockSalesMap::accessOptions(),
        ]);
    }

    public function storeSalesAccess(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'salesperson_key' => ['required', 'string', Rule::in(array_keys(DeadstockSalesMap::accessOptions()))],
            'can_import' => ['nullable', 'boolean'],
            'can_edit' => ['nullable', 'boolean'],
        ]);

        abort_unless(User::query()->whereKey($validated['user_id'])->where('is_active', 1)->exists(), 422, 'ไม่พบ User ที่ Active');

        $canImport = $request->boolean('can_import');
        $canEdit = $request->boolean('can_edit');

        if (!$canImport && !$canEdit) {
            return back()->withErrors(['salesperson_key' => 'กรุณาเลือกอย่างน้อยหนึ่งสิทธิ์: Import หรือแก้ไข']);
        }

        $access = DeadstockUserSalesAccess::query()->firstOrNew([
            'user_id' => (int) $validated['user_id'],
            'salesperson_key' => strtoupper($validated['salesperson_key']),
        ]);

        $access->fill([
            'can_import' => $canImport,
            'can_edit' => $canEdit,
            'is_active' => true,
            'updated_by' => $request->user()->id,
            'created_by' => $access->created_by ?: $request->user()->id,
        ])->save();

        return back()->with('success', 'บันทึก Deadstock Sales Mapping แล้ว');
    }

    public function destroySalesAccess(DeadstockUserSalesAccess $salesAccess): RedirectResponse
    {
        $salesAccess->delete();

        return back()->with('success', 'ลบ Deadstock Sales Mapping แล้ว');
    }

    public function review(Request $request): View
    {
        $rawSnapshots = $this->loadSnapshotIndex();
        $months = $this->deadstockReviewMonths();
        $selectedMonthIds = $this->selectedMonthIds($request, $months);
        $selectedMonths = $months
            ->filter(fn(DeadstockSnapshotMonth $month) => in_array((int) $month->id, $selectedMonthIds, true))
            ->values();
        $hasMonthFilter = $request->filled('month_from')
            || $request->filled('month_to')
            || $request->filled('month_id')
            || $request->has('month_ids');
        $selectedMonth = $selectedMonths->first() ?? ($hasMonthFilter ? null : $months->first());
        [$monthFrom, $monthTo] = $this->selectedMonthRange($request, $selectedMonths);
        $status = $this->deadstockListStatus($request->query('status', DeadstockCompareStatus::DEFAULT));
        $rawActionStatus = trim((string) $request->query('action_status', 'all'));
        $actionStatus = $rawActionStatus === 'all'
            ? 'all'
            : (DeadstockReviewStatus::normalize($rawActionStatus) ?? 'all');
        $siteFilter = trim((string) $request->query('site', $request->query('company', 'all')));
        $customerFilter = $this->normalizeMultiFilter($request->query('customer', []));
        $salesDivisionFilter = $this->normalizeMultiFilter($request->query('sales_division', []));
        $reasonFilter = $this->normalizeMultiFilter($request->query('reason_code', []));
        $codeGroup = strtoupper(trim((string) $request->query('code_group', '')));
        $serialFilter = trim((string) $request->query('serial', ''));
        $sort = trim((string) $request->query('sort', 'qty_desc'));

        $baseFilters = [
            'month_ids' => $selectedMonthIds,
            'month_from' => $monthFrom,
            'month_to' => $monthTo,
            'status' => $status,
            'action_status' => $actionStatus,
            'site' => $siteFilter,
            'customer' => $customerFilter,
            'sales_division' => $salesDivisionFilter,
            'reason_code' => $reasonFilter,
            'code_group' => $codeGroup,
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
            ->selectRaw("SUM(CASE WHEN COALESCE(r.review_status, 'open') = 'open' THEN 1 ELSE 0 END) AS not_followed_up_items")
            ->selectRaw("SUM(CASE WHEN r.review_status IN ('waiting_follow_up', 'waiting_sales', 'waiting_customer', 'waiting_delivery', 'not_delivered_current_month') THEN 1 ELSE 0 END) AS waiting_follow_up_items")
            ->selectRaw("SUM(CASE WHEN r.review_status IN ('in_progress', 'follow_up', 'closed') THEN 1 ELSE 0 END) AS in_progress_items")
            ->selectRaw("SUM(CASE WHEN ds_snapshot_items.last_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_items")
            ->selectRaw("SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS reviewed_items")
            ->selectRaw('MAX(ds_snapshot_items.last_checked_at) AS last_checked_at')
            ->selectRaw('SUM(ds_snapshot_items.snapshot_qty) AS total_qty')
            ->selectRaw("SUM(CASE WHEN compare_status = 'cleared' THEN 0 ELSE COALESCE(ds_snapshot_items.current_qty, ds_snapshot_items.snapshot_qty) END) AS current_total_qty")
            ->first();

        $compareHealth = $this->deadstockCompareHealth($summary, $selectedMonth);

        $siteOptions = ['WIRE' => 'WIRE', 'PLUS' => 'PLUS'];
        $customerOptions = collect();
        $reasonOptions = collect();

        if ($selectedMonthIds !== []) {
            $customerOptionsQuery = DeadstockSnapshotItem::query()
                ->whereIn('snapshot_month_id', $selectedMonthIds);
            $this->applyDeadstockSiteFilter($customerOptionsQuery, $siteFilter);
            $customerOptions = $customerOptionsQuery
                ->whereNotNull('customer_name')
                ->where('customer_name', '<>', '')
                ->select('customer_name')
                ->distinct()
                ->orderBy('customer_name')
                ->pluck('customer_name');

            $reasonOptionsQuery = DeadstockSnapshotItem::query()
                ->whereIn('snapshot_month_id', $selectedMonthIds);
            $this->applyDeadstockSiteFilter($reasonOptionsQuery, $siteFilter);
            $reasonOptions = $reasonOptionsQuery
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
                });
        }

        return view('formwos.deadstock.review', [
            'months' => $months,
            'selectedMonthIds' => $selectedMonthIds,
            'selectedMonths' => $selectedMonths,
            'selectedMonth' => $selectedMonth,
            'monthFrom' => $monthFrom,
            'monthTo' => $monthTo,
            'items' => $items,
            'summary' => $summary,
            'compareHealth' => $compareHealth,
            'status' => $status,
            'actionStatus' => $actionStatus,
            'siteFilter' => $siteFilter,
            'siteOptions' => $siteOptions,
            'customerFilter' => $customerFilter,
            'customerOptions' => $customerOptions,
            'salesDivisionFilter' => $salesDivisionFilter,
            'salesDivisionOptions' => DeadstockSalesMap::divisionOptions(),
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
        $months = $this->deadstockReviewMonths();
        $monthIds = $this->selectedMonthIds($request, $months);
        $selectedMonths = $months
            ->filter(fn(DeadstockSnapshotMonth $month) => in_array((int) $month->id, $monthIds, true))
            ->values();
        [$monthFrom, $monthTo] = $this->selectedMonthRange($request, $selectedMonths);
        $filename = 'deadstock_review_' . now('Asia/Bangkok')->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new DeadstockReviewExport($this->deadstockExportFilters($request, $monthIds, $monthFrom, $monthTo)),
            $filename
        );
    }

    public function exportSummaryPdf(Request $request)
    {
        $months = $this->deadstockReviewMonths();
        $monthIds = $this->selectedMonthIds($request, $months);
        $selectedMonths = $months
            ->filter(fn(DeadstockSnapshotMonth $month) => in_array((int) $month->id, $monthIds, true))
            ->values();
        [$monthFrom, $monthTo] = $this->selectedMonthRange($request, $selectedMonths);
        $report = $this->buildDeadstockSummaryReport(
            $this->deadstockExportFilters($request, $monthIds, $monthFrom, $monthTo)
        );

        $pdf = Pdf::loadView('pdf.deadstock-summary', [
            'report' => $report,
            'generatedAt' => now('Asia/Bangkok'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('deadstock_summary_' . now('Asia/Bangkok')->format('Ymd_His') . '.pdf');
    }

    private function buildDeadstockSummaryReport(array $filters = []): array
    {
        return (new DeadstockReviewExport($filters))->summaryReport();
    }

    private function deadstockExportFilters(
        Request $request,
        array $monthIds,
        string $monthFrom,
        string $monthTo
    ): array
    {
        return [
            'month_ids' => $monthIds,
            'status' => $this->deadstockListStatus($request->query('status', DeadstockCompareStatus::DEFAULT)),
            'action_status' => (($rawActionStatus = trim((string) $request->query('action_status', 'all'))) === 'all')
                ? 'all'
                : (DeadstockReviewStatus::normalize($rawActionStatus) ?? 'all'),
            'site' => trim((string) $request->query('site', $request->query('company', 'all'))),
            'customer' => $this->normalizeMultiFilter($request->query('customer', [])),
            'sales_division' => $this->normalizeMultiFilter($request->query('sales_division', [])),
            'reason_code' => $this->normalizeMultiFilter($request->query('reason_code', [])),
            'code_group' => strtoupper(trim((string) $request->query('code_group', ''))),
            'serial' => trim((string) $request->query('serial', '')),
            'sort' => trim((string) $request->query('sort', 'qty_desc')),
            'month_from' => $monthFrom,
            'month_to' => $monthTo,
        ];
    }

    private function selectedMonthIds(Request $request, Collection $months): array
    {
        $hasExplicitMonthFilter = $request->filled('month_from')
            || $request->filled('month_to')
            || $request->filled('month_id')
            || $request->has('month_ids');

        if ($request->filled('month_from') || $request->filled('month_to')) {
            $from = $this->monthBoundary($request->query('month_from'));
            $to = $this->monthBoundary($request->query('month_to'));

            if ($from || $to) {
                $monthIds = $months
                    ->filter(function (DeadstockSnapshotMonth $month) use ($from, $to) {
                        return $this->deadstockMonthMatchesRange($month, $from, $to);
                    })
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->all();

                return $monthIds;
            }
        }

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

        if ($monthIds === [] && !$hasExplicitMonthFilter && $months->isNotEmpty()) {
            $currentMonth = now('Asia/Bangkok')->startOfMonth();
            $currentMonthIds = $months
                ->filter(fn(DeadstockSnapshotMonth $month) => $this->deadstockMonthMatchesRange($month, $currentMonth, $currentMonth))
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();

            return $currentMonthIds !== []
                ? $currentMonthIds
                : [(int) $months->first()->id];
        }

        $validMonthIds = $months->pluck('id')->map(fn($id) => (int) $id)->all();

        $selectedMonthIds = array_values(array_intersect($validMonthIds, $monthIds));

        return $selectedMonthIds !== [] || $months->isEmpty()
            ? $selectedMonthIds
            : [(int) $months->first()->id];
    }

    private function deadstockReviewMonths(): Collection
    {
        return DeadstockSnapshotMonth::query()
            ->where('item_count', '>', 0)
            ->orderByDesc(DB::raw('COALESCE(recv_date, as_of_date, snapshot_month)'))
            ->orderByDesc('snapshot_month')
            ->get();
    }

    private function selectedMonthRange(Request $request, Collection $selectedMonths): array
    {
        $from = trim((string) $request->query('month_from', ''));
        $to = trim((string) $request->query('month_to', ''));

        if ($from !== '' || $to !== '') {
            $fromBoundary = $this->monthBoundary($from);
            $toBoundary = $this->monthBoundary($to);
            $rangeContainsSelectedMonth = $selectedMonths->contains(function (DeadstockSnapshotMonth $month) use ($fromBoundary, $toBoundary) {
                return $this->deadstockMonthMatchesRange($month, $fromBoundary, $toBoundary);
            });

            return [$from, $to];
        }

        if ($selectedMonths->isEmpty()) {
            return ['', ''];
        }

        $oldestMonth = $selectedMonths->last();
        $latestMonth = $selectedMonths->first();
        $oldest = ($oldestMonth?->recv_date ?? $oldestMonth?->as_of_date ?? $oldestMonth?->snapshot_month)?->format('Y-m') ?? '';
        $latest = ($latestMonth?->recv_date ?? $latestMonth?->as_of_date ?? $latestMonth?->snapshot_month)?->format('Y-m') ?? '';

        return [$oldest, $latest];
    }

    private function deadstockMonthMatchesRange(DeadstockSnapshotMonth $month, ?Carbon $from, ?Carbon $to): bool
    {
        $candidateMonths = collect([
            $month->snapshot_month,
            $month->recv_date,
            $month->as_of_date,
        ])
            ->filter()
            ->map(fn($date) => $date instanceof Carbon ? $date->copy()->startOfMonth() : Carbon::parse($date)->startOfMonth())
            ->unique(fn(Carbon $date) => $date->format('Y-m'));

        return $candidateMonths->contains(function (Carbon $candidate) use ($from, $to) {
            if ($from && $candidate->lt($from)) {
                return false;
            }

            if ($to && $candidate->gt($to)) {
                return false;
            }

            return true;
        });
    }

    private function monthBoundary(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return Carbon::parse($value . '-01')->startOfMonth();
    }

    private function applyDeadstockFilters($query, array $filters, bool $joinedReview): void
    {
        $status = trim((string) ($filters['status'] ?? DeadstockCompareStatus::DEFAULT));
        $compareStatuses = DeadstockCompareStatus::valuesForFilter($status);
        $actionStatus = trim((string) ($filters['action_status'] ?? 'all'));
        $siteFilter = trim((string) ($filters['site'] ?? $filters['company'] ?? 'all'));
        $customerFilter = $this->normalizeMultiFilter($filters['customer'] ?? []);
        $salesDivisionFilter = $this->normalizeMultiFilter($filters['sales_division'] ?? []);
        $reasonFilter = $this->normalizeMultiFilter($filters['reason_code'] ?? []);
        $codeGroup = strtoupper(trim((string) ($filters['code_group'] ?? '')));
        $serialFilter = trim((string) ($filters['serial'] ?? ''));
        $purchaseFrom = $this->monthBoundary($filters['month_from'] ?? null);
        $purchaseTo = $this->monthBoundary($filters['month_to'] ?? null)?->endOfMonth();

        $this->applyDeadstockSiteFilter($query, $siteFilter);

        $query
            ->when($purchaseFrom, fn($q) => $q->whereDate('purchase_date', '>=', $purchaseFrom->toDateString()))
            ->when($purchaseTo, fn($q) => $q->whereDate('purchase_date', '<=', $purchaseTo->toDateString()))
            ->when($customerFilter !== [], fn($q) => $q->whereIn('customer_name', $customerFilter))
            ->when($salesDivisionFilter !== [], fn($q) => $q->whereIn('salesperson_name', DeadstockSalesMap::rawValuesForDivisions($salesDivisionFilter)))
            ->when($reasonFilter !== [], fn($q) => $this->applyDeadstockReasonFilter($q, $reasonFilter))
            ->when(in_array($codeGroup, ['FF', 'SS'], true), fn($q) => $q->where('deadstock_code', 'like', $codeGroup . '%'))
            ->when($serialFilter !== '', function ($q) use ($serialFilter) {
                $keyword = '%' . $serialFilter . '%';

                $q->where(function ($nested) use ($keyword) {
                    $nested->where('serialnumber', 'like', $keyword)
                        ->orWhere('transaction_number', 'like', $keyword);
                });
            })
            ->when($compareStatuses !== [], fn($q) => $q->whereIn('compare_status', $compareStatuses));

        $reviewStatuses = DeadstockReviewStatus::valuesForFilter($actionStatus);
        if ($reviewStatuses !== []) {
            if ($joinedReview) {
                if ($actionStatus === DeadstockReviewStatus::NOT_FOLLOWED_UP) {
                    $query->whereRaw("COALESCE(r.review_status, 'open') = 'open'");
                } else {
                    $query->whereIn('r.review_status', $reviewStatuses);
                }
            } else {
                $query->where(function ($nested) use ($actionStatus, $reviewStatuses) {
                    if ($actionStatus === DeadstockReviewStatus::NOT_FOLLOWED_UP) {
                        $nested->whereDoesntHave('review')
                            ->orWhereHas('review', fn($reviewQuery) => $reviewQuery->where('review_status', 'open'));
                        return;
                    }

                    $nested->whereHas('review', fn($reviewQuery) => $reviewQuery->whereIn('review_status', $reviewStatuses));
                });
            }
        }
    }

    private function applyDeadstockSiteFilter($query, string $siteFilter): void
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

    private function applyDeadstockReasonFilter($query, array $reasonFilter): void
    {
        $reasonCodes = $this->deadstockReasonCodesForFilter($reasonFilter);

        $query->where(function ($nested) use ($reasonFilter, $reasonCodes) {
            if ($reasonCodes !== []) {
                $nested->whereIn('deadstock_code', $reasonCodes);
            }

            $nested->orWhereIn('deadstock_desc', $reasonFilter);
        });
    }

    private function deadstockReasonCodesForFilter(array $reasonFilter): array
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

    private function deadstockCompareHealth($summary, ?DeadstockSnapshotMonth $selectedMonth): array
    {
        $total = (int) ($summary->total_items ?? 0);
        $active = (int) ($summary->active_items ?? 0);
        $changed = (int) ($summary->changed_items ?? 0);
        $cleared = (int) ($summary->cleared_items ?? 0);
        $pending = (int) ($summary->pending_items ?? 0);
        $checked = (int) ($summary->checked_items ?? 0);
        $reviewed = (int) ($summary->reviewed_items ?? 0);
        $unchecked = max(0, $total - $checked);
        $snapshotDate = $selectedMonth?->recv_date ?? $selectedMonth?->as_of_date ?? $selectedMonth?->snapshot_month;
        $snapshotAgeDays = $snapshotDate ? $snapshotDate->diffInDays(now('Asia/Bangkok')->startOfDay(), false) : null;

        return [
            'total' => $total,
            'active' => $active,
            'changed' => $changed,
            'cleared' => $cleared,
            'pending' => $pending,
            'checked' => $checked,
            'unchecked' => $unchecked,
            'checked_percent' => $total > 0 ? round(($checked / $total) * 100, 1) : 0.0,
            'reviewed' => $reviewed,
            'last_checked_at' => $summary->last_checked_at ?? null,
            'all_active' => $total > 0 && $active === $total && $changed === 0 && $cleared === 0,
            'snapshot_age_days' => $snapshotAgeDays,
            'snapshot_is_recent' => is_numeric($snapshotAgeDays) && $snapshotAgeDays >= 0 && $snapshotAgeDays <= 2,
        ];
    }

    public function saveReview(Request $request, DeadstockSnapshotItem $item): RedirectResponse
    {
        abort_unless(
            DeadstockSalesAccess::canManage($request->user(), $item->salesperson_name),
            403,
            'ไม่มีสิทธิ์บันทึกรายการ Deadstock ของ Sales นี้'
        );

        $validated = $request->validate([
            'revised_due_date' => ['nullable', 'date'],
            'next_follow_up_date' => ['nullable', 'date'],
            'review_detail' => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'preventive_action' => ['nullable', 'string', 'max:5000'],
            'sales_remark' => ['nullable', 'string', 'max:5000'],
            'review_status' => ['required', 'in:open,waiting_follow_up,in_progress'],
            'no_revised_due' => ['nullable', 'boolean'],
        ]);

        if (!empty($validated['no_revised_due'])) {
            $validated['revised_due_date'] = null;
        }
        unset($validated['no_revised_due']);

        $reviewStatusMaxLength = $this->deadstockReviewStatusMaxLength();
        if (strlen((string) $validated['review_status']) > $reviewStatusMaxLength) {
            return back()
                ->withErrors(['review_status' => "สถานะงานยาวเกินขนาดคอลัมน์ review_status ({$reviewStatusMaxLength} ตัวอักษร) กรุณาอัปเดต schema ก่อนบันทึก"])
                ->withInput();
        }

        if ($validated['review_status'] === DeadstockReviewStatus::WAITING
            && empty($validated['next_follow_up_date'])) {
            return back()
                ->withErrors(['next_follow_up_date' => 'กรุณาระบุวันติดตามถัดไปเมื่อเลือกสถานะกำลังติดตาม รอคำตอบจากลูกค้า'])
                ->withInput();
        }

        $review = DeadstockItemReview::query()->firstOrNew(['snapshot_item_id' => $item->id]);
        $isNew = !$review->exists;
        $before = $this->deadstockReviewLogValues($review);

        $review->fill([
            ...$validated,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now('Asia/Bangkok'),
            'updated_by' => auth()->id(),
            'created_by' => $review->created_by ?: auth()->id(),
        ]);

        $changedFields = $this->deadstockReviewChangedFields($before, $this->deadstockReviewLogValues($review));
        $review->save();
        $this->logDeadstockReviewChange($review, $before, 'manual', null, $isNew ? 'created' : 'updated', $changedFields);

        return back()->with('success', 'บันทึกข้อมูลติดตาม Deadstock แล้ว');
    }

    public function downloadReviewImportTemplate(): BinaryFileResponse
    {
        abort_unless(
            DeadstockSalesAccess::canImportAny(auth()->user()),
            403,
            'ไม่มีสิทธิ์ Import Deadstock'
        );

        $headers = [
            'วันรับเข้า Stock',
            'statustime',
            'transaction_number',
            'serialnumber',
            'partnumber',
            'Description',
            'Quantity(kgs)',
            'unit',
            'unitcost',
            'amount',
            'Product',
            'company',
            'customer',
            'due_date',
            'กำหนดส่งใหม่',
            'salesperson',
            'deadstock_code',
            'deadstock_desc',
            'รายละเอียด',
            'แนวทางแก้ไข',
            'แนวทางป้องกัน',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Deadstock Import');
        $sheet->fromArray($headers, null, 'A1');
        $lastHeaderColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastHeaderColumn}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'deadstock_import_template_');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($path, 'deadstock_import_template.xlsx')
            ->deleteFileAfterSend(true);
    }

    public function importReviewExcel(Request $request): RedirectResponse
    {
        abort_unless(
            DeadstockSalesAccess::canImportAny($request->user()),
            403,
            'ไม่มีสิทธิ์ Import Deadstock'
        );

        $validated = $request->validate([
            'review_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $dryRun = $request->boolean('dry_run');

        $months = $this->deadstockReviewMonths();
        $monthIds = $months->pluck('id')->map(fn($id) => (int) $id)->all();

        if ($monthIds === []) {
            return back()->with('error', 'ยังไม่พบ Snapshot เดือนที่เลือกสำหรับ import');
        }

        $rows = $this->readDeadstockReviewImportRows($validated['review_file']->getRealPath());

        if ($rows->isEmpty()) {
            return back()->with('error', 'ไม่พบข้อมูลในไฟล์ Excel หรือไม่พบหัวคอลัมน์ที่รองรับ');
        }

        $stats = [
            'read' => 0,
            'saved' => 0,
            'created' => 0,
            'updated' => 0,
            'duplicate' => 0,
            'unchanged' => 0,
            'empty' => 0,
            'unmatched' => 0,
            'error' => 0,
            'unauthorized' => 0,
        ];
        $unmatchedRows = [];
        $importErrors = [];
        $seenItemIds = [];
        $now = now('Asia/Bangkok');
        $userId = auth()->id();
        $reviewStatusMaxLength = $this->deadstockReviewStatusMaxLength();

        foreach ($rows as $row) {
            $stats['read']++;
            $payload = $this->deadstockReviewPayloadFromImportRow($row, $now);

            if ($payload === []) {
                $stats['empty']++;
                continue;
            }

            if (isset($payload['review_status']) && strlen((string) $payload['review_status']) > $reviewStatusMaxLength) {
                $stats['error']++;
                $importErrors[] = $this->deadstockImportRowLabel($row) . " review_status ยาวเกิน {$reviewStatusMaxLength} ตัวอักษร: {$payload['review_status']}";
                continue;
            }

            $items = $this->findDeadstockReviewImportItems($row, $monthIds);

            if ($items->isEmpty()) {
                $stats['unmatched']++;
                $unmatchedRows[] = $this->deadstockImportRowLabel($row);
                continue;
            }

            foreach ($items as $item) {
                if (!DeadstockSalesAccess::canImport($request->user(), $item->salesperson_name)) {
                    $stats['unauthorized']++;
                    $importErrors[] = $this->deadstockImportRowLabel($row)
                        . ' ไม่มีสิทธิ์บันทึก Sales '
                        . DeadstockSalesMap::label($item->salesperson_name);
                    continue;
                }

                if (isset($seenItemIds[$item->id])) {
                    $stats['duplicate']++;
                    continue;
                }

                $seenItemIds[$item->id] = true;
                $review = DeadstockItemReview::query()->firstOrNew(['snapshot_item_id' => $item->id]);
                $isNew = !$review->exists;

                if ($isNew && !isset($payload['review_status'])) {
                    $payload['review_status'] = 'open';
                }

                $before = $this->deadstockReviewLogValues($review);
                $review->fill($payload);
                $changedFields = $this->deadstockReviewChangedFields($before, $this->deadstockReviewLogValues($review));

                if (!$isNew && $changedFields === []) {
                    $stats['unchanged']++;
                    continue;
                }

                if ($dryRun) {
                    $stats['saved']++;
                    $stats[$isNew ? 'created' : 'updated']++;
                    continue;
                }

                $review->reviewed_by = $userId;
                $review->reviewed_at = $now;
                $review->updated_by = $userId;
                $review->created_by = $review->created_by ?: $userId;

                try {
                    $review->save();
                    $this->logDeadstockReviewChange($review, $before, 'import', (int) ($row['row_number'] ?? 0), $isNew ? 'created' : 'updated', $changedFields);
                } catch (\Throwable $e) {
                    $stats['error']++;
                    $importErrors[] = $this->deadstockImportRowLabel($row) . ' save error: ' . $e->getMessage();
                    continue;
                }

                $stats['saved']++;
                $stats[$isNew ? 'created' : 'updated']++;
            }
        }

        $message = "Import Excel สำเร็จ: อ่าน {$stats['read']} row, บันทึก {$stats['saved']} row, เพิ่มใหม่ {$stats['created']} row, แก้ไข {$stats['updated']} row, ข้อมูลเดิมไม่เปลี่ยน {$stats['unchanged']} row, ไม่มีสิทธิ์ {$stats['unauthorized']} row, ไม่พบข้อมูล {$stats['unmatched']} row, ข้ามซ้ำ {$stats['duplicate']} row, ข้ามว่าง {$stats['empty']} row";

        if ($dryRun) {
            $message = "Dry run Import Excel: อ่าน {$stats['read']} row, ถ้ารันจริงจะบันทึก {$stats['saved']} row, เพิ่มใหม่ {$stats['created']} row, แก้ไข {$stats['updated']} row, ข้อมูลเดิมไม่เปลี่ยน {$stats['unchanged']} row, ไม่มีสิทธิ์ {$stats['unauthorized']} row, ไม่พบข้อมูล {$stats['unmatched']} row, ข้ามซ้ำ {$stats['duplicate']} row, ข้ามว่าง {$stats['empty']} row (ยังไม่บันทึกลงฐานข้อมูล)";
        }

        if ($stats['error'] > 0) {
            $message .= ", error {$stats['error']} row";
        }

        if ($unmatchedRows !== []) {
            $message .= ' | ตัวอย่างที่ match ไม่เจอ: ' . implode(' ; ', array_slice($unmatchedRows, 0, 12));
        }

        if ($importErrors !== []) {
            $message .= ' | error: ' . implode(' ; ', array_slice($importErrors, 0, 12));
        }

        return back()->with($dryRun || $stats['saved'] > 0 ? 'success' : 'error', $message);
    }

    private function deadstockReviewStatusMaxLength(): int
    {
        try {
            $column = DB::connection(config('database.workflow_connection', 'sqlsrv_menam'))
                ->selectOne("
                    SELECT CHARACTER_MAXIMUM_LENGTH AS max_length
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = 'dbo'
                      AND TABLE_NAME = 'ds_item_reviews'
                      AND COLUMN_NAME = 'review_status'
                ");

            return max(1, (int) ($column->max_length ?? 50));
        } catch (\Throwable) {
            return 50;
        }
    }

    private function readDeadstockReviewImportRows(string $path): Collection
    {
        $spreadsheet = IOFactory::load($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = (int) $sheet->getHighestRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $headerMap = $this->deadstockReviewImportHeaderMap();
            $headerRow = null;
            $columnMap = [];

            for ($row = 1; $row <= min($highestRow, 20); $row++) {
                $mapped = [];

                for ($col = 1; $col <= $highestColumn; $col++) {
                    $header = $this->normalizeDeadstockImportHeader($sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row)->getFormattedValue());

                    if (isset($headerMap[$header])) {
                        $mapped[$col] = $headerMap[$header];
                    }
                }

                if (count($mapped) >= 2) {
                    $headerRow = $row;
                    $columnMap = $mapped;
                    break;
                }
            }

            if ($headerRow === null) {
                return collect();
            }

            $rows = [];

            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $data = ['row_number' => $row];
                $hasValue = false;

                foreach ($columnMap as $col => $key) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                    $text = trim((string) $cell->getFormattedValue());
                    $raw = $cell->getValue();

                    if ($text !== '' || $raw !== null) {
                        $hasValue = true;
                    }

                    $data[$key] = [
                        'raw' => $raw,
                        'text' => $text,
                    ];
                }

                if ($hasValue) {
                    $rows[] = $data;
                }
            }

            return collect($rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function deadstockReviewImportHeaderMap(): array
    {
        $aliases = [
            'purchase_date' => ['วันรับเข้า Stock', 'purchase_date', 'purchasedate'],
            'status_time' => ['statustime', 'status_time'],
            'transaction_number' => ['transaction_number', 'transactionnumber'],
            'serialnumber' => ['serialnumber', 'serial_number'],
            'partnumber' => ['partnumber', 'part_number'],
            'part_description' => ['Description', 'part_description'],
            'quantity' => ['Quantity(kgs)', 'Quantity', 'qty'],
            'unit' => ['unit'],
            'unitcost' => ['unitcost', 'unit_cost'],
            'amount' => ['amount'],
            'product' => ['Product'],
            'company' => ['company'],
            'customer' => ['customer'],
            'due_date' => ['due_date', 'duedate', 'กำหนดส่ง'],
            'revised_due_date' => ['กำหนดส่งใหม่', 'revised_due_date', 'reviseddue_date', 'new_due_date', 'newduedate'],
            'salesperson' => ['salesperson', 'sales_person'],
            'deadstock_code' => ['deadstock_code', 'deadstockcode'],
            'deadstock_desc' => ['deadstock_desc', 'deadstockdesc'],
            'review_detail' => ['รายละเอียด', 'review_detail', 'detail'],
            'corrective_action' => ['แนวทางแก้ไข', 'corrective_action'],
            'preventive_action' => ['แนวทางป้องกัน', 'preventive_action'],
            'sales_remark' => ['Remark', 'หมายเหตุ', 'sales_remark'],
            'next_follow_up_date' => ['วันติดตามถัดไป', 'next_follow_up_date', 'nextfollowupdate'],
            'review_status' => ['สถานะการติดตาม', 'review_status', 'status'],
        ];

        $map = [];

        foreach ($aliases as $key => $headers) {
            foreach ($headers as $header) {
                $map[$this->normalizeDeadstockImportHeader($header)] = $key;
            }
        }

        $exportAliases = [
            'purchase_date' => ['วันที่รับเข้า'],
            'transaction_number' => ['Transaction'],
            'serialnumber' => ['Serial no.'],
            'partnumber' => ['Part'],
            'part_description' => ['รายละเอียด Part'],
            'quantity' => ['Qty Snapshot', 'Qty ปัจจุบัน', 'Qty ต่าง'],
            'company' => ['Site'],
            'customer' => ['ลูกค้า'],
            'due_date' => ['กำหนดส่งเดิม'],
            'revised_due_date' => ['กำหนดส่งใหม่'],
            'deadstock_code' => ['รหัสสาเหตุ'],
            'deadstock_desc' => ['รายละเอียดสาเหตุ'],
            'review_status' => ['สถานะงาน'],
            'preventive_action' => ['แนวทางป้องกันการเกิดซ้ำ'],
        ];

        foreach ($exportAliases as $key => $headers) {
            foreach ($headers as $header) {
                $map[$this->normalizeDeadstockImportHeader($header)] = $key;
            }
        }

        return $map;
    }

    private function deadstockReviewPayloadFromImportRow(array $row, ?Carbon $importedAt = null): array
    {
        $payload = [];
        $revisedDueDate = $this->deadstockImportDate($row['revised_due_date'] ?? null);
        if (!$revisedDueDate && !array_key_exists('revised_due_date', $row)) {
            $revisedDueDate = $this->deadstockImportDate($row['due_date'] ?? null);
        }
        $nextFollowUpDate = $this->deadstockImportDate($row['next_follow_up_date'] ?? null);
        $reviewStatus = $this->normalizeDeadstockReviewStatus($this->deadstockImportText($row['review_status'] ?? null));

        if ($revisedDueDate) {
            $payload['revised_due_date'] = $revisedDueDate;
        }

        foreach ([
            'review_detail',
            'corrective_action',
            'preventive_action',
            'sales_remark',
        ] as $key) {
            $value = $this->deadstockImportText($row[$key] ?? null);

            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        $hasTrackingDetail = collect([
            $payload['review_detail'] ?? null,
            $payload['corrective_action'] ?? null,
            $payload['preventive_action'] ?? null,
            $payload['sales_remark'] ?? null,
        ])->contains(fn($value) => trim((string) $value) !== '');

        if (!$reviewStatus && $hasTrackingDetail) {
            $reviewStatus = DeadstockReviewStatus::IN_PROGRESS;
        }

        if ($reviewStatus) {
            $payload['review_status'] = $reviewStatus;
        }

        if ($nextFollowUpDate) {
            $payload['next_follow_up_date'] = $nextFollowUpDate;
        } elseif (in_array($reviewStatus, [
            DeadstockReviewStatus::WAITING,
            DeadstockReviewStatus::IN_PROGRESS,
        ], true)) {
            $payload['next_follow_up_date'] = ($importedAt ?? now('Asia/Bangkok'))
                ->copy()
                ->endOfMonth()
                ->toDateString();
        }

        return $payload;
    }

    private function findDeadstockReviewImportItems(array $row, array $monthIds): Collection
    {
        $company = $this->deadstockImportText($row['company'] ?? null);
        $serial = $this->deadstockImportText($row['serialnumber'] ?? null);
        $transaction = $this->deadstockImportText($row['transaction_number'] ?? null);
        $partNumber = $this->deadstockImportText($row['partnumber'] ?? null);
        $purchaseDate = $this->deadstockImportDate($row['purchase_date'] ?? null);

        if ($company && !$this->isDeadstockImportSiteLabel($company) && ($serial || $transaction) && $partNumber && $purchaseDate) {
            $itemKey = hash('sha256', implode('|', [
                strtoupper($company),
                strtoupper($serial ?: $transaction),
                strtoupper($partNumber),
                $purchaseDate,
            ]));

            $items = DeadstockSnapshotItem::query()
                ->whereIn('snapshot_month_id', $monthIds)
                ->where('item_key', $itemKey)
                ->get();

            if ($items->isNotEmpty()) {
                return $items;
            }
        }

        if (!$partNumber || (!$serial && !$transaction)) {
            return collect();
        }

        return DeadstockSnapshotItem::query()
            ->whereIn('snapshot_month_id', $monthIds)
            ->where('partnumber', $partNumber)
            ->when($company, fn($query) => $this->applyDeadstockImportCompanyFilter($query, $company))
            ->when($serial, fn($query) => $query->where('serialnumber', $serial))
            ->when(!$serial && $transaction, fn($query) => $query->where('transaction_number', $transaction))
            ->when($purchaseDate, fn($query) => $query->whereDate('purchase_date', $purchaseDate))
            ->get();
    }

    private function applyDeadstockImportCompanyFilter($query, string $company): void
    {
        $site = strtoupper(trim($company));

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

        $query->where('company', $company);
    }

    private function isDeadstockImportSiteLabel(string $company): bool
    {
        return in_array(strtoupper(trim($company)), ['PLUS', 'WIRE'], true);
    }

    private function deadstockImportRowLabel(array $row): string
    {
        return ($row['row_number'] ?? '-') . "\t" . ($this->deadstockImportText($row['serialnumber'] ?? null) ?? '-');
    }

    private function deadstockReviewLogValues(DeadstockItemReview $review): array
    {
        $values = [];

        foreach (self::DEADSTOCK_REVIEW_LOG_FIELDS as $field) {
            $values[$field] = $this->normalizeDeadstockReviewLogValue($review->{$field} ?? null);
        }

        return $values;
    }

    private function deadstockReviewChangedFields(array $before, array $after): array
    {
        return collect(self::DEADSTOCK_REVIEW_LOG_FIELDS)
            ->filter(fn(string $field) => ($before[$field] ?? null) !== ($after[$field] ?? null))
            ->values()
            ->all();
    }

    private function logDeadstockReviewChange(
        DeadstockItemReview $review,
        array $before,
        string $source,
        ?int $importRowNumber,
        string $action,
        array $changedFields
    ): void {
        if ($action === 'updated' && $changedFields === []) {
            return;
        }

        $after = $this->deadstockReviewLogValues($review);
        $item = $review->relationLoaded('snapshotItem')
            ? $review->snapshotItem
            : DeadstockSnapshotItem::query()->find($review->snapshot_item_id);

        DeadstockItemReviewLog::query()->create([
            'snapshot_item_id' => $review->snapshot_item_id,
            'review_id' => $review->id,
            'company' => $item?->company,
            'serialnumber' => $item?->serialnumber,
            'action' => $action,
            'source' => $source,
            'import_row_number' => $importRowNumber ?: null,
            'changed_fields' => $changedFields,
            'before_values' => $before,
            'after_values' => $after,
            'changed_by' => auth()->id(),
            'changed_by_name' => auth()->user()?->name,
            'changed_at' => now('Asia/Bangkok'),
        ]);
    }

    private function normalizeDeadstockReviewLogValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }

        return $value;
    }

    private function normalizeDeadstockImportHeader(mixed $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value));
        $value = strtolower($value);

        return preg_replace('/[\s\(\)\[\]\{\}._\-\/\\\\]+/u', '', $value) ?? '';
    }

    private function deadstockImportText(?array $cell): ?string
    {
        if (!$cell) {
            return null;
        }

        $value = trim((string) ($cell['text'] ?? ''));

        if ($value === '' || $value === '-' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }

    private function deadstockImportDate(?array $cell): ?string
    {
        if (!$cell) {
            return null;
        }

        $raw = $cell['raw'] ?? null;
        $text = $this->deadstockImportText($cell);

        try {
            if (is_numeric($raw) && (float) $raw > 1000 && (float) $raw < 100000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
            }

            return $text ? Carbon::parse($text)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDeadstockReviewStatus(?string $status): ?string
    {
        return DeadstockReviewStatus::normalize($status);
    }

    public function compareMonth(
        Request $request,
        DeadstockSnapshotMonth $month,
        DeadstockReviewService $reviewService
    ): RedirectResponse {
        $monthIds = collect($request->input('month_ids', []))
            ->flatten()
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($monthIds->isEmpty()) {
            $monthIds = collect([(int) $month->id]);
        }

        $months = DeadstockSnapshotMonth::query()
            ->whereIn('id', $monthIds->all())
            ->where('item_count', '>', 0)
            ->orderByDesc(DB::raw('COALESCE(recv_date, as_of_date, snapshot_month)'))
            ->get();

        if ($months->isEmpty()) {
            return redirect()
                ->route('deadstock.review', ['month_id' => $month->id, 'status' => 'review'])
                ->with('error', 'ยังไม่พบ Snapshot ที่มีรายการสำหรับเทียบข้อมูล');
        }

        $totalCounts = ['active' => 0, 'changed' => 0, 'cleared' => 0];
        foreach ($months as $monthToCompare) {
            $counts = $reviewService->compareMonth($monthToCompare);
            foreach ($totalCounts as $key => $value) {
                $totalCounts[$key] += (int) ($counts[$key] ?? 0);
            }
        }

        return redirect()
            ->route('deadstock.review', [
                'month_ids' => $months->pluck('id')->map(fn($id) => (int) $id)->all(),
                'status' => 'review',
            ])
            ->with('success', "เทียบ Snapshot {$months->count()} เดือนแล้ว: คงค้าง {$totalCounts['active']} / เปลี่ยนแปลง {$totalCounts['changed']} / เคลียร์แล้ว {$totalCounts['cleared']}");
    }

    private function deadstockListStatus($status): string
    {
        return DeadstockCompareStatus::normalizePublic($status);
    }

    public function importLatestSnapshot(
        Request $request,
        DeadstockSnapshotImportService $importService,
        DeadstockReviewService $reviewService
    ): RedirectResponse {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $selectedDate = !empty($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : null;
        $dateFrom = !empty($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->toDateString()
            : null;
        $dateTo = !empty($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->toDateString()
            : null;

        if ($dateFrom && !$dateTo) {
            $dateTo = $dateFrom;
        } elseif (!$dateFrom && $dateTo) {
            $dateFrom = $dateTo;
        }

        if (($dateFrom || $dateTo) && $dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            return back()
                ->withInput()
                ->with('error', 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');
        }

        $files = collect();
        if ($dateFrom || $dateTo) {
            $files = $this->itemSnapshotFilesBetween($dateFrom, $dateTo);
        } elseif ($selectedDate) {
            $file = $this->itemSnapshotFileByDate($selectedDate);
            $files = $file ? collect([$file]) : collect();
        } else {
            $file = $this->latestItemSnapshotFile();
            $files = $file ? collect([$file]) : collect();
        }

        if ($files->isEmpty()) {
            $message = ($dateFrom || $dateTo)
                ? 'ยังไม่พบไฟล์ deadstock_items_*.json ในช่วงวันที่ที่เลือก'
                : ($selectedDate
                ? "ยังไม่พบไฟล์ deadstock_items_{$selectedDate}.json สำหรับ import"
                : 'ยังไม่พบไฟล์ deadstock_items_*.json สำหรับ import');

            return back()->withInput()->with('error', $message);
        }

        $results = [];
        $lastMonthId = null;
        foreach ($files as $file) {
            $result = $importService->importFile($file);

            if (!empty($result['skipped'])) {
                $results[] = [
                    'result' => $result,
                    'counts' => null,
                ];
                continue;
            }

            $month = DeadstockSnapshotMonth::query()->find($result['month_id']);
            $counts = $month
                ? $reviewService->compareMonth($month, false)
                : ['active' => 0, 'changed' => 0, 'cleared' => 0];

            $lastMonthId = $result['month_id'];
            $results[] = [
                'result' => $result,
                'counts' => $counts,
            ];
        }

        $summary = collect($results)
            ->map(function (array $row) {
                $result = $row['result'];
                $counts = $row['counts'];

                if ($counts === null) {
                    return "{$result['recv_date']} ไฟล์ไม่มีรายการ (ข้าม ไม่แตะข้อมูลเดิม)";
                }

                return "{$result['recv_date']} {$result['items']} รายการ (คงค้าง {$counts['active']} / เปลี่ยนแปลง {$counts['changed']} / เคลียร์แล้ว {$counts['cleared']})";
            })
            ->implode(' | ');

        $this->forgetDeadstockSnapshotCache();

        return redirect()
            ->route($this->deadstockConfigRedirectRoute(), $lastMonthId ? ['month_id' => $lastMonthId] : [])
            ->with(
                'success',
                "Import + compare snapshot สำเร็จ: {$summary}"
            );
    }

    public function createBaselineSnapshot(
        Request $request,
        DeadstockSnapshotImportService $importService,
        DeadstockReviewService $reviewService
    ): RedirectResponse {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dateFrom = !empty($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : null;
        $dateTo = !empty($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->startOfDay()
            : null;

        if ($dateFrom && !$dateTo) {
            $dateTo = $dateFrom->copy();
        } elseif (!$dateFrom && $dateTo) {
            $dateFrom = $dateTo->copy();
        }

        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            return back()
                ->withInput()
                ->with('error', 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');
        }

        if ($dateFrom && $dateTo && $dateFrom->diffInDays($dateTo) + 1 > 10000) {
            return back()
                ->withInput()
                ->with('error', 'สร้าง baseline ได้ครั้งละไม่เกิน 10,000 วัน กรุณาแบ่งช่วงวันที่ให้สั้นลง');
        }

        $dates = [];
        if ($dateFrom && $dateTo) {
            for ($cursor = $dateFrom->copy(); $cursor->lte($dateTo); $cursor->addDay()) {
                $dates[] = $cursor->toDateString();
            }
        } elseif (!empty($validated['date'])) {
            $dates[] = Carbon::parse($validated['date'])->toDateString();
        } else {
            $dates[] = null;
        }

        $mailDailyPath = $this->mailDailyPath();

        if (!is_dir($mailDailyPath) || !is_file($mailDailyPath . DIRECTORY_SEPARATOR . 'artisan')) {
            return back()
                ->withInput()
                ->with('error', 'ไม่พบ path mail-daily สำหรับสร้าง baseline snapshot')
                ->with('deadstock_output', 'DEADSTOCK_MAIL_DAILY_PATH=' . $mailDailyPath);
        }

        if (count($dates) > 1) {
            set_time_limit(0);
        }

        $outputs = [];
        $summaries = [];
        $importedFiles = [];
        $lastMonthId = null;
        $processedCount = 0;
        $skippedCount = 0;
        $totalItems = 0;
        $totalCounts = ['active' => 0, 'changed' => 0, 'cleared' => 0];
        $detailLimit = 50;

        foreach ($dates as $snapshotDate) {
            $dateLabel = $snapshotDate ?: 'วันนี้';
            $runStartedAt = time();
            $command = [
                $this->phpBinary(),
                'artisan',
                'report:deadstock',
                '--snapshot-only',
            ];

            if ($snapshotDate) {
                $command[] = '--date=' . $snapshotDate;
            }

            try {
                $process = new Process($command, $mailDailyPath);
                $process->setTimeout(1200);
                $process->run();

                $output = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());
            } catch (\Throwable $e) {
                $outputs[] = "[{$dateLabel}] " . $e->getMessage();
                if (count($outputs) > $detailLimit) {
                    array_shift($outputs);
                }

                return back()
                    ->withInput()
                    ->with('error', "สร้าง baseline snapshot วันที่ {$dateLabel} ไม่สำเร็จ" . $this->baselineDoneSuffix($summaries))
                    ->with('deadstock_output', implode(PHP_EOL . PHP_EOL, $outputs));
            }

            $outputs[] = "[{$dateLabel}]" . PHP_EOL . $output;
            if (count($outputs) > $detailLimit) {
                array_shift($outputs);
            }

            if (!$process->isSuccessful()) {
                return back()
                    ->withInput()
                    ->with('error', "สร้าง baseline snapshot วันที่ {$dateLabel} ไม่สำเร็จ" . $this->baselineDoneSuffix($summaries))
                    ->with('deadstock_output', implode(PHP_EOL . PHP_EOL, $outputs));
            }

            // mail-daily ตั้งชื่อไฟล์ตาม recv date (วันทำงานก่อนหน้าของ --date) ไม่ใช่วันที่ที่ส่งไป
            // จึงหาไฟล์ที่เพิ่งถูกเขียนจากการรันรอบนี้ก่อน แล้วค่อย fallback เป็นชื่อไฟล์ตามวันที่
            $file = $this->itemSnapshotFileTouchedSince($runStartedAt, $snapshotDate)
                ?? ($snapshotDate ? $this->itemSnapshotFileByDate($snapshotDate) : $this->latestItemSnapshotFile());
            if (!$file) {
                return back()
                    ->withInput()
                    ->with('error', "สร้างรายงานวันที่ {$dateLabel} แล้ว แต่ยังไม่พบไฟล์ deadstock_items_*.json สำหรับ import" . $this->baselineDoneSuffix($summaries))
                    ->with('deadstock_output', implode(PHP_EOL . PHP_EOL, $outputs));
            }

            if (in_array($file, $importedFiles, true)) {
                $skippedCount++;
                if (count($summaries) >= $detailLimit) {
                    array_shift($summaries);
                }
                $summaries[] = "{$dateLabel} → " . basename($file) . ' (ข้าม: ซ้ำกับวันก่อนหน้า เพราะเป็นวันหยุด)';
                continue;
            }
            $importedFiles[] = $file;

            try {
                $result = $importService->importFile($file);
            } catch (\Throwable $e) {
                $outputs[] = "[{$dateLabel}] import error: " . $e->getMessage();
                if (count($outputs) > $detailLimit) {
                    array_shift($outputs);
                }

                return back()
                    ->withInput()
                    ->with('error', "สร้าง baseline วันที่ {$dateLabel} แล้ว แต่ import เข้า Monthly Review ไม่สำเร็จ" . $this->baselineDoneSuffix($summaries))
                    ->with('deadstock_output', implode(PHP_EOL . PHP_EOL, $outputs));
            }

            if (!empty($result['skipped'])) {
                if (count($summaries) >= $detailLimit) {
                    array_shift($summaries);
                }
                $summaries[] = "{$result['recv_date']} ไฟล์ไม่มีรายการ (ข้าม ไม่แตะข้อมูลเดิม)";
                $skippedCount++;
                continue;
            }

            try {
                $month = DeadstockSnapshotMonth::query()->find($result['month_id']);
                $counts = $month
                    ? $reviewService->compareMonth($month, false)
                    : ['active' => 0, 'changed' => 0, 'cleared' => 0];
            } catch (\Throwable $e) {
                $outputs[] = "[{$dateLabel}] compare error: " . $e->getMessage();
                if (count($outputs) > $detailLimit) {
                    array_shift($outputs);
                }

                return redirect()
                    ->route($this->deadstockConfigRedirectRoute(), ['month_id' => $result['month_id']])
                    ->with('error', "สร้าง baseline วันที่ {$dateLabel} และ import สำเร็จ แต่ compare ไม่สำเร็จ" . $this->baselineDoneSuffix($summaries))
                    ->with('deadstock_output', implode(PHP_EOL . PHP_EOL, $outputs));
            }

            if (count($summaries) >= $detailLimit) {
                array_shift($summaries);
            }
            $lastMonthId = $result['month_id'];
            $processedCount++;
            $totalItems += (int) ($result['items'] ?? 0);
            foreach ($totalCounts as $key => $value) {
                $totalCounts[$key] += (int) ($counts[$key] ?? 0);
            }
            $summaries[] = "{$result['recv_date']} {$result['items']} รายการ (คงค้าง {$counts['active']} / เปลี่ยนแปลง {$counts['changed']} / เคลียร์แล้ว {$counts['cleared']})";
        }

        $this->forgetDeadstockSnapshotCache();

        session()->flash(
            'success_compare',
            "สรุป backfill: import+compare {$processedCount} snapshot, ข้ามซ้ำ {$skippedCount} วัน, รวม {$totalItems} รายการ (คงค้าง {$totalCounts['active']} / เปลี่ยนแปลง {$totalCounts['changed']} / เคลียร์แล้ว {$totalCounts['cleared']})"
        );

        return redirect()
            ->route($this->deadstockConfigRedirectRoute(), ['month_id' => $lastMonthId])
            ->with('success', 'สร้าง baseline snapshot สำเร็จ: ' . implode(' | ', $summaries))
            ->with('deadstock_output', implode(PHP_EOL . PHP_EOL, $outputs));
    }

    public function syncCurrentDeadstock(
        DeadstockSnapshotImportService $importService,
        DeadstockReviewService $reviewService
    ): RedirectResponse {
        $month = DeadstockSnapshotMonth::query()
            ->where('item_count', '>', 0)
            ->orderByDesc('snapshot_month')
            ->orderByDesc('recv_date')
            ->first();

        if (!$month) {
            return back()->with('error', 'ยังไม่มี snapshot เดือนสำหรับรับรายการ — สร้าง baseline ก่อน');
        }

        set_time_limit(0);

        try {
            $sync = $reviewService->syncCurrentDeadstock($month, $importService);
        } catch (\Throwable $e) {
            return back()->with('error', 'ดึง deadstock ปัจจุบันจาก ERP ไม่สำเร็จ: ' . $e->getMessage());
        }

        if (($sync['added'] ?? 0) > 0) {
            try {
                $counts = $reviewService->compareMonth($month->refresh(), false);
                session()->flash(
                    'success_compare',
                    "ผล compare เดือนล่าสุดหลัง sync: คงค้าง {$counts['active']} / เปลี่ยนแปลง {$counts['changed']} / เคลียร์แล้ว {$counts['cleared']}"
                );
            } catch (\Throwable $e) {
                session()->flash('error', "เพิ่มรายการแล้ว แต่ compare ไม่สำเร็จ: {$e->getMessage()}");
            }
        }

        $this->forgetDeadstockSnapshotCache();

        return redirect()
            ->route($this->deadstockConfigRedirectRoute(), ['month_id' => $month->id])
            ->with(
                'success',
                "ดึง deadstock ปัจจุบันจาก ERP สำเร็จ: พบทั้งหมด {$sync['live_total']} รายการ, ไม่อยู่ใน snapshot {$sync['missing']} รายการ, เพิ่มเข้าเดือนล่าสุด {$sync['added']} รายการ"
            );
    }

    private function forgetDeadstockSnapshotCache(): void
    {
        Cache::forget('deadstock.snapshot.index');
        Cache::forget('deadstock.snapshot.summaries.0');
        Cache::forget('deadstock.snapshot.summaries.60');
    }

    private function baselineDoneSuffix(array $summaries): string
    {
        return $summaries === []
            ? ''
            : ' (สำเร็จก่อนหน้า: ' . implode(' | ', $summaries) . ')';
    }

    private function itemSnapshotFileTouchedSince(int $timestamp, ?string $aroundDate = null): ?string
    {
        clearstatcache();

        $files = $this->itemSnapshotFiles();

        // จำกัดจำนวนไฟล์ที่ต้องเช็ค mtime ให้เหลือไม่กี่ไฟล์ เลี่ยง stat ผ่าน network drive ทั้งโฟลเดอร์
        if ($aroundDate) {
            $from = Carbon::parse($aroundDate)->subDays(14)->toDateString();
            $files = $files->filter(function (string $path) use ($from, $aroundDate) {
                $date = $this->itemSnapshotDate($path);
                return $date !== null && $date >= $from && $date <= $aroundDate;
            });
        } else {
            $files = $files->take(20);
        }

        return $files->first(fn(string $path) => (filemtime($path) ?: 0) >= $timestamp);
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

    private function publicReportPath(): string
    {
        return $this->mailDailyPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'reports';
    }

    private function itemSnapshotFiles(): Collection
    {
        // เรียงจากวันที่ในชื่อไฟล์แทน filemtime — เลี่ยงการ stat ไฟล์หลายพันไฟล์ผ่าน network drive
        $files = glob($this->snapshotPath() . DIRECTORY_SEPARATOR . 'deadstock_items_????-??-??.json') ?: [];

        return collect($files)
            ->sortByDesc(fn(string $path) => $this->itemSnapshotDate($path) ?? '')
            ->values();
    }

    private function latestItemSnapshotFile(): ?string
    {
        // ไฟล์ที่ลงวันที่อนาคต (สร้างผิดพลาด) ห้ามถูกนับเป็นไฟล์ล่าสุด
        $today = now('Asia/Bangkok')->toDateString();

        return $this->itemSnapshotFiles()
            ->first(fn(string $path) => ($this->itemSnapshotDate($path) ?? '') <= $today);
    }

    private function itemSnapshotFileByDate(string $date): ?string
    {
        $path = $this->snapshotPath() . DIRECTORY_SEPARATOR . "deadstock_items_{$date}.json";

        return is_file($path) ? $path : null;
    }

    private function itemSnapshotFilesBetween(?string $from, ?string $to): Collection
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to ? Carbon::parse($to)->endOfDay() : null;

        return $this->itemSnapshotFiles()
            ->map(function (string $path) {
                return [
                    'path' => $path,
                    'date' => $this->itemSnapshotDate($path),
                ];
            })
            ->filter(function (array $file) use ($fromDate, $toDate) {
                if (!$file['date']) {
                    return false;
                }

                $date = Carbon::parse($file['date']);

                if ($fromDate && $date->lt($fromDate)) {
                    return false;
                }

                if ($toDate && $date->gt($toDate)) {
                    return false;
                }

                return true;
            })
            ->sortBy('date')
            ->groupBy(fn(array $file) => Carbon::parse($file['date'])->format('Y-m'))
            ->map(fn(Collection $files) => $files->last()['path'])
            ->values();
    }

    private function itemSnapshotDate(?string $path): ?string
    {
        if (!$path || !preg_match('/deadstock_items_(\d{4}-\d{2}-\d{2})\.json$/', basename($path), $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function excelBackfillFiles(): Collection
    {
        // เรียงจากชื่อไฟล์ (มีวันที่อยู่ในชื่อ) แทน filemtime — เลี่ยง stat ไฟล์หลายพันไฟล์ผ่าน network drive
        $files = glob($this->publicReportPath() . DIRECTORY_SEPARATOR . 'deadstock_*.xlsx') ?: [];

        return collect($files)
            ->sortByDesc(fn(string $path) => basename($path))
            ->values();
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

    private function deadstockConfigRedirectRoute(): string
    {
        return auth()->check() && auth()->user()->hasRoleCode('ADMINWEB')
            ? 'adminweb.deadstock.config'
            : 'deadstock.review';
    }

    private function loadSnapshots(int $limit = 0): Collection
    {
        $dir = $this->snapshotPath();

        if (!is_dir($dir)) {
            return collect();
        }

        // glob เฉพาะไฟล์สรุป (deadstock_YYYY-MM-DD.json) ไม่รวม deadstock_items_* และไม่ stat ไฟล์
        // อ่านเนื้อไฟล์ผ่าน network ช้า จึง cache ผลไว้ 5 นาที
        return collect(Cache::remember(
            "deadstock.snapshot.summaries.{$limit}",
            300,
            function () use ($dir, $limit) {
                $today = now('Asia/Bangkok')->toDateString();

                $paths = collect(glob($dir . DIRECTORY_SEPARATOR . 'deadstock_????-??-??.json') ?: [])
                    ->filter(function (string $path) use ($today) {
                        $date = preg_replace('/.*deadstock_(\d{4}-\d{2}-\d{2})\.json$/', '$1', str_replace('\\', '/', $path));
                        // ไฟล์ที่ลงวันที่อนาคต (รันผิดวัน) ห้ามโผล่เป็นข้อมูลล่าสุด
                        return $date <= $today;
                    })
                    ->sortByDesc(fn(string $path) => basename($path))
                    ->values();

                if ($limit > 0) {
                    $paths = $paths->take($limit);
                }

                return $paths
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
                    ->values()
                    ->all();
            }
        ));
    }

    /**
     * สร้าง snapshot summary จาก DB ให้มีรูปแบบเดียวกับไฟล์ JSON
     * ใช้เป็น fallback ของ dashboard เมื่ออ่านไฟล์ JSON สรุปไม่ได้ (เช่นบน prod ที่เข้าถึง path ไม่ได้)
     */
    private function loadSnapshotsFromDb(int $limit = 0): Collection
    {
        $monthsQuery = DeadstockSnapshotMonth::query()
            ->orderByDesc('snapshot_month')
            ->orderByDesc('recv_date');

        if ($limit > 0) {
            $monthsQuery->limit($limit);
        }

        $months = $monthsQuery->get();

        if ($months->isEmpty()) {
            return collect();
        }

        $monthIds = $months->pluck('id')->all();

        $totals = DeadstockSnapshotItem::query()
            ->whereIn('snapshot_month_id', $monthIds)
            ->selectRaw('snapshot_month_id')
            ->selectRaw('SUM(snapshot_qty) AS total_qty')
            ->selectRaw('SUM(snapshot_value) AS total_value')
            ->selectRaw('COUNT(DISTINCT customer_name) AS customers')
            ->selectRaw('COUNT(*) AS parts')
            ->groupBy('snapshot_month_id')
            ->get()
            ->keyBy('snapshot_month_id');

        $bySales = DeadstockSnapshotItem::query()
            ->whereIn('snapshot_month_id', $monthIds)
            ->whereNotNull('salesperson_name')
            ->where('salesperson_name', '<>', '')
            ->selectRaw('snapshot_month_id, salesperson_name, SUM(snapshot_qty) AS qty')
            ->groupBy('snapshot_month_id', 'salesperson_name')
            ->get()
            ->groupBy('snapshot_month_id');

        $byReason = DeadstockSnapshotItem::query()
            ->whereIn('snapshot_month_id', $monthIds)
            ->selectRaw('snapshot_month_id, deadstock_code, MAX(deadstock_desc) AS deadstock_desc, SUM(snapshot_qty) AS qty')
            ->groupBy('snapshot_month_id', 'deadstock_code')
            ->get()
            ->groupBy('snapshot_month_id');

        return $months
            ->map(function (DeadstockSnapshotMonth $month) use ($totals, $bySales, $byReason) {
                $row = $totals->get($month->id);
                $date = optional($month->recv_date ?? $month->as_of_date ?? $month->snapshot_month)->toDateString();

                $salesArr = [];
                foreach ($bySales->get($month->id, collect()) as $sales) {
                    $salesArr[(string) $sales->salesperson_name] = (float) $sales->qty;
                }

                $reasonArr = [];
                foreach ($byReason->get($month->id, collect()) as $reason) {
                    $label = DeadstockReasonMap::description($reason->deadstock_code, $reason->deadstock_desc);
                    $key = (string) ($label ?: ($reason->deadstock_code ?: 'ไม่ระบุ'));
                    $reasonArr[$key] = ($reasonArr[$key] ?? 0) + (float) $reason->qty;
                }

                return [
                    'recv_date' => $date,
                    'as_of' => $date,
                    'generated' => optional($month->captured_at)->toDateTimeString() ?? '',
                    '_date' => $date,
                    '_source' => 'db',
                    'totals' => [
                        'total_qty' => (float) ($row->total_qty ?? $month->total_qty ?? 0),
                        'total_value' => (float) ($row->total_value ?? $month->total_value ?? 0),
                        'customers' => (int) ($row->customers ?? 0),
                        'parts' => (int) ($row->parts ?? 0),
                    ],
                    'by_sales' => $salesArr,
                    'by_reason' => $reasonArr,
                ];
            })
            ->values();
    }

    private function loadSnapshotIndex(): Collection
    {
        $dir = $this->snapshotPath();

        if (!is_dir($dir)) {
            return collect();
        }

        // glob เฉพาะไฟล์สรุป และไม่ stat ไฟล์ — ใช้วันที่จากชื่อไฟล์พอ (cache 5 นาที)
        return collect(Cache::remember('deadstock.snapshot.index', 300, function () use ($dir) {
            $today = now('Asia/Bangkok')->toDateString();

            return collect(glob($dir . DIRECTORY_SEPARATOR . 'deadstock_????-??-??.json') ?: [])
                ->map(function (string $path) {
                    return [
                        '_path' => $path,
                        '_date' => preg_replace('/.*deadstock_(\d{4}-\d{2}-\d{2})\.json$/', '$1', str_replace('\\', '/', $path)),
                    ];
                })
                ->filter(fn(array $snap) => ($snap['_date'] ?? '') <= $today)
                ->sortByDesc(fn(array $snap) => $snap['_date'] ?? '')
                ->values()
                ->all();
        }));
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
