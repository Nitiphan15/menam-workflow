<?php

namespace App\Http\Controllers\FormWR;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateWireRodDeadstockExport;
use App\Services\FormWR\WireRodDeadstockService;
use App\Services\FormWR\WireRodDeadstockQuery;
use App\Support\FormWR\CsvExportCell;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WireRodDeadstockController extends Controller
{
    private string $connection;

    public function __construct(private WireRodDeadstockService $service, private WireRodDeadstockQuery $deadstockQuery)
    {
        $this->connection = config('database.workflow_connection', 'sqlsrv_menam');
    }

    public function dashboard(Request $request): View
    {
        $query = $this->filteredQuery($request);
        $rows = (clone $query)->get();
        $latestRun = DB::connection($this->connection)->table('wr_ds_sync_runs')->orderByDesc('started_at')->first();
        $latestMonthEnd = DB::connection($this->connection)->table('wr_ds_snapshots')
            ->where('snapshot_kind', 'MONTH_END')->where('completion_status', 'COMPLETE')
            ->orderByDesc('as_of_at')->first();
        $byGroup = $rows->groupBy('material_group')->map(fn ($items, $group) => [
            'group' => $group,
            'lots' => $items->count(),
            'dead_lots' => $items->where('stock_status', 'DEAD')->count(),
            'dead_kg' => $items->where('stock_status', 'DEAD')->where('display_unit', 'KG')->sum('current_qty'),
            'dead_value' => $items->where('stock_status', 'DEAD')->sum('current_value_thb'),
        ])->values();

        $monthEndComparison = null;
        if ($latestMonthEnd) {
            $snapshotRows = $this->filteredSnapshotQuery($request, $latestMonthEnd->id)->get();
            $previousValue = (float) $snapshotRows->where('stock_status', 'DEAD')->sum('snapshot_value_thb');
            $currentValue = (float) $rows->where('stock_status', 'DEAD')->sum('current_value_thb');
            $monthEndComparison = [
                'as_of_at' => $latestMonthEnd->as_of_at,
                'difference' => $currentValue - $previousValue,
                'percent' => $previousValue != 0.0 ? (($currentValue - $previousValue) / abs($previousValue)) * 100 : null,
            ];
        }

        return view('formwr.deadstock.dashboard', [
            'kpi' => [
                'lots' => $rows->count(),
                'stock_kg' => $rows->where('display_unit', 'KG')->sum('current_qty'),
                'stock_pcs' => $rows->where('display_unit', 'PCS')->sum('current_qty'),
                'incoming_lots' => $rows->where('stock_status', 'INCOMING')->count(),
                'incoming_kg' => $rows->where('stock_status', 'INCOMING')->where('display_unit', 'KG')->sum('current_qty'),
                'incoming_pcs' => $rows->where('stock_status', 'INCOMING')->where('display_unit', 'PCS')->sum('current_qty'),
                'dead_lots' => $rows->where('stock_status', 'DEAD')->count(),
                'dead_kg' => $rows->where('stock_status', 'DEAD')->where('display_unit', 'KG')->sum('current_qty'),
                'dead_pcs' => $rows->where('stock_status', 'DEAD')->where('display_unit', 'PCS')->sum('current_qty'),
                'dead_value' => $rows->where('stock_status', 'DEAD')->sum('current_value_thb'),
                'unknown_lots' => $rows->where('stock_status', 'UNKNOWN')->count(),
                'became_dead_lots' => $rows->where('became_dead_stock', 1)->count(),
            ],
            'byGroup' => $byGroup,
            'filters' => $this->filterOptions(),
            'selected' => $request->query(),
            'latestRun' => $latestRun,
            'monthEndComparison' => $monthEndComparison,
        ]);
    }

    public function review(Request $request): View
    {
        $items = $this->filteredQuery($request)
            ->leftJoin('wr_ds_item_reviews as r', 'r.lifecycle_id', '=', 'c.lifecycle_id')
            ->leftJoin('wr_ds_sales_overrides as o', function ($join) {
                $join->on('o.lifecycle_id', '=', 'c.lifecycle_id')->where('o.is_active', 1);
            })
            ->addSelect('r.review_status', 'r.review_detail', 'r.corrective_action', 'r.preventive_action', 'r.sales_remark', 'r.next_follow_up_date', 'r.updated_at as review_updated_at')
            ->addSelect('o.id as override_id', 'o.sales_name as override_sales_name', 'o.division_code as override_division_code', 'o.reason_code as override_reason_code', 'o.remark as override_remark')
            ->selectSub(function ($query) {
                $query->from('wr_ds_customer_additions as customer_count')->selectRaw('COUNT_BIG(*)')
                    ->whereColumn('customer_count.lifecycle_id', 'c.lifecycle_id')->where('customer_count.is_active', 1);
            }, 'additional_customer_count');

        match ($request->input('sort', 'age_desc')) {
            'qty_desc' => $items->orderByDesc('c.current_qty'),
            'purchase_oldest' => $items->orderBy('c.purchase_date'),
            'follow_up_soon' => $items->orderByRaw('CASE WHEN r.next_follow_up_date IS NULL THEN 1 ELSE 0 END')->orderBy('r.next_follow_up_date'),
            default => $items->orderByRaw("CASE c.stock_status WHEN 'DEAD' THEN 0 WHEN 'UNKNOWN' THEN 1 ELSE 2 END")->orderByDesc('c.stock_age_days'),
        };

        $items = $items->paginate($this->perPage($request))->withQueryString();
        $reviewAccess = $this->reviewAccessContext($request);
        $items->getCollection()->each(function ($item) use ($reviewAccess) {
            $item->can_edit_review = $this->canEditFromContext($item, $reviewAccess);
        });

        return view('formwr.deadstock.review', [
            'items' => $items,
            'filters' => $this->filterOptions(),
            'selected' => $request->query(),
        ]);
    }

    public function itemSummary(Request $request): View
    {
        $items = $this->filteredQuery($request)
            ->select('c.site_code', 'c.material_group', 'c.part_number')
            ->selectRaw('MAX(c.part_description) AS part_description')
            ->selectRaw('COUNT_BIG(*) AS serial_count')
            ->selectRaw("SUM(CASE WHEN c.display_unit = 'KG' THEN c.current_qty ELSE 0 END) AS qty_kg")
            ->selectRaw('SUM(COALESCE(c.current_value_thb, 0)) AS value_thb')
            ->selectRaw("SUM(CASE WHEN c.stock_status = 'DEAD' THEN 1 ELSE 0 END) AS dead_serial_count")
            ->groupBy('c.site_code', 'c.material_group', 'c.part_number')
            ->orderBy('c.site_code')->orderBy('c.material_group')->orderBy('c.part_number')
            ->paginate($this->perPage($request))->withQueryString();

        return view('formwr.deadstock.items', [
            'items' => $items,
            'filters' => $this->filterOptions(),
            'selected' => $request->query(),
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        $result = $this->service->refresh($user?->id, $user?->name, 'MANUAL');
        Cache::forget('wr_ds:filter-options');

        return back()->with($result['status'] === 'SUCCEEDED' ? 'success' : 'warning',
            $result['status'] === 'SUCCEEDED'
                ? 'Refresh สำเร็จทั้ง WIRE และ PLUS'
                : 'Refresh ไม่ครบทุก Site ระบบคงข้อมูลล่าสุดไว้ กรุณาตรวจ Sync status');
    }

    public function autocomplete(Request $request): JsonResponse
    {
        $type = (string) $request->query('type');
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2 || mb_strlen($term) > 100 || ! in_array($type, ['item', 'serial', 'customer'], true)) {
            return response()->json([]);
        }

        $db = DB::connection($this->connection);
        $escapedTerm = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], mb_strtoupper($term));
        $like = '%'.$escapedTerm.'%';
        $current = fn () => $db->table('wr_ds_current_items as c')
            ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('l.is_current', 1);

        if ($type === 'item') {
            $items = $current()->where(function ($query) use ($like) {
                $query->whereRaw('UPPER(c.part_number) LIKE ?', [$like])
                    ->orWhereRaw('UPPER(c.part_description) LIKE ?', [$like]);
            })->select('c.part_number', 'c.part_description')->distinct()->orderBy('c.part_number')->limit(30)->get()
                ->map(fn ($row) => ['value' => $row->part_number, 'text' => $row->part_number.' · '.($row->part_description ?: '-')]);
        } elseif ($type === 'serial') {
            $items = $current()->whereRaw('UPPER(c.serial_number) LIKE ?', [$like])
                ->select('c.serial_number')->distinct()->orderBy('c.serial_number')->limit(30)->get()
                ->map(fn ($row) => ['value' => $row->serial_number, 'text' => $row->serial_number]);
        } else {
            $items = collect($db->select(
                'SELECT DISTINCT TOP 30 CONVERT(NVARCHAR(500), customer_list.[value]) AS customer_name
                 FROM wr_ds_current_items AS c
                 INNER JOIN wr_ds_item_lifecycles AS l ON l.id = c.lifecycle_id
                 CROSS APPLY OPENJSON(c.customer_json) AS customer_list
                 WHERE l.is_current = 1 AND UPPER(CONVERT(NVARCHAR(500), customer_list.[value])) LIKE ?
                 ORDER BY customer_name',
                [$like]
            ))->map(fn ($row) => ['value' => $row->customer_name, 'text' => $row->customer_name]);
            $additional = $db->table('wr_ds_customer_additions')->where('is_active', 1)
                ->whereRaw('UPPER(customer_name) LIKE ?', [$like])->distinct()->orderBy('customer_name')->limit(30)
                ->pluck('customer_name')->map(fn ($name) => ['value' => $name, 'text' => $name.' · เพิ่มเติม']);
            $items = $items->merge($additional)->unique('value')->take(30)->values();
        }

        return response()->json($items->values());
    }

    public function modalData(Request $request, int $lifecycle): JsonResponse
    {
        $item = DB::connection($this->connection)->table('wr_ds_current_items as c')
            ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->leftJoin('wr_ds_item_reviews as r', 'r.lifecycle_id', '=', 'c.lifecycle_id')
            ->where('c.lifecycle_id', $lifecycle)
            ->select('c.*', 'l.cycle_no', 'r.review_status', 'r.review_detail', 'r.corrective_action',
                'r.preventive_action', 'r.sales_remark', 'r.next_follow_up_date', 'r.updated_at as review_updated_at')
            ->first();
        abort_unless($item, 404);

        return response()->json($this->modalPayload($request, $item));
    }

    public function saveReview(Request $request, int $lifecycle): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'review_status' => ['required', 'in:open,waiting_follow_up,in_progress'],
            'review_detail' => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'preventive_action' => ['nullable', 'string', 'max:5000'],
            'sales_remark' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_date' => ['nullable', 'date'],
            'review_updated_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);
        $submittedUpdatedAt = $validated['review_updated_at'] ?? null;
        unset($validated['review_updated_at']);
        $db = DB::connection($this->connection);
        $item = $db->table('wr_ds_current_items as c')->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('c.lifecycle_id', $lifecycle)->select('c.*', 'l.cycle_no')->first();
        abort_unless($item, 404);
        abort_unless($this->canEdit($request, $item), 403);

        $db->transaction(function () use ($db, $validated, $submittedUpdatedAt, $request, $item, $lifecycle) {
            $db->table('wr_ds_item_lifecycles')->where('id', $lifecycle)->lockForUpdate()->first();
            $before = $db->table('wr_ds_item_reviews')->where('lifecycle_id', $lifecycle)->lockForUpdate()->first();
            $currentUpdatedAt = $before?->updated_at
                ? Carbon::parse($before->updated_at)->format('Y-m-d H:i:s')
                : null;
            if ($currentUpdatedAt !== $submittedUpdatedAt) {
                throw ValidationException::withMessages([
                    'review_updated_at' => 'ข้อมูลถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณา Refresh ก่อนบันทึกอีกครั้ง',
                ]);
            }
            $now = now('Asia/Bangkok');
            $payload = $validated + [
                'reviewed_by' => $request->user()->id, 'reviewed_by_name' => $request->user()->name,
                'reviewed_at' => $now, 'updated_by' => $request->user()->id, 'updated_at' => $now,
            ];
            if ($before) {
                $db->table('wr_ds_item_reviews')->where('id', $before->id)->update($payload);
                $reviewId = $before->id;
            } else {
                $payload += ['lifecycle_id' => $lifecycle, 'created_by' => $request->user()->id, 'created_at' => $now];
                $reviewId = $db->table('wr_ds_item_reviews')->insertGetId($payload);
            }
            $db->table('wr_ds_item_review_logs')->insert([
                'lifecycle_id' => $lifecycle, 'review_id' => $reviewId, 'site_code' => $item->site_code,
                'serial_number' => $item->serial_number, 'cycle_no' => $item->cycle_no,
                'action' => $before ? 'UPDATE' : 'CREATE', 'source' => 'WEB',
                'changed_fields' => json_encode(array_keys($validated)),
                'before_values' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after_values' => json_encode($validated, JSON_UNESCAPED_UNICODE),
                'changed_by' => $request->user()->id, 'changed_by_name' => $request->user()->name,
                'changed_at' => $now, 'created_at' => $now,
            ]);
        });

        if ($request->expectsJson()) {
            $item->review_status = $validated['review_status'];
            $item->next_follow_up_date = $validated['next_follow_up_date'] ?? null;
            $item->review_updated_at = now('Asia/Bangkok')->format('Y-m-d H:i:s');
            return response()->json([
                'message' => 'บันทึกการติดตามแล้ว',
                'review_status' => $item->review_status,
                'next_follow_up_date' => $item->next_follow_up_date,
                'review_updated_at' => $item->review_updated_at,
            ]);
        }

        return back()->with('success', 'บันทึกการติดตามแล้ว');
    }

    public function storeOverride(Request $request, int $lifecycle): RedirectResponse
    {
        $validated = $request->validate([
            'sales_name' => ['required', 'string', 'max:255'],
            'division_code' => ['required', 'string', 'max:30'],
            'reason_code' => ['required', 'string', 'max:50'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);
        $db = DB::connection($this->connection);
        $item = $db->table('wr_ds_current_items as c')->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('c.lifecycle_id', $lifecycle)->select('c.*', 'l.cycle_no')->first();
        abort_unless($item, 404);

        $db->transaction(function () use ($db, $request, $validated, $item, $lifecycle) {
            $now = now('Asia/Bangkok');
            $previous = $db->table('wr_ds_sales_overrides')->where('lifecycle_id', $lifecycle)->where('is_active', 1)->first();
            if ($previous) {
                $db->table('wr_ds_sales_overrides')->where('id', $previous->id)->update([
                    'is_active' => 0, 'revoked_by' => $request->user()->id,
                    'revoked_by_name' => $request->user()->name, 'revoked_at' => $now,
                    'revoke_reason' => 'REPLACED_BY_NEW_OVERRIDE', 'updated_at' => $now,
                ]);
            }
            $salesKey = strtoupper(trim($validated['division_code']));
            $overrideId = $db->table('wr_ds_sales_overrides')->insertGetId([
                'lifecycle_id' => $lifecycle, 'sales_key' => $salesKey,
                'sales_name' => $validated['sales_name'], 'division_code' => $salesKey,
                'reason_code' => $validated['reason_code'], 'remark' => $validated['remark'],
                'is_active' => 1, 'overridden_by' => $request->user()->id,
                'overridden_by_name' => $request->user()->name, 'overridden_at' => $now,
                'source_sales_json' => $item->sales_json, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('wr_ds_current_items')->where('lifecycle_id', $lifecycle)->update([
                'mapping_source' => 'MANUAL', 'effective_sales_key' => $salesKey,
                'effective_sales_name' => $validated['sales_name'], 'effective_division_code' => $salesKey,
                'display_unit' => $salesKey === 'D8' ? 'PCS' : 'KG', 'updated_at' => $now,
            ]);
            $db->table('wr_ds_item_review_logs')->insert([
                'lifecycle_id' => $lifecycle, 'site_code' => $item->site_code, 'serial_number' => $item->serial_number,
                'cycle_no' => $item->cycle_no, 'action' => 'OVERRIDE', 'source' => 'WEB',
                'changed_fields' => json_encode(['sales', 'division']),
                'before_values' => json_encode($previous ?: ['sales_name' => $item->effective_sales_name, 'division_code' => $item->effective_division_code], JSON_UNESCAPED_UNICODE),
                'after_values' => json_encode($validated + ['override_id' => $overrideId], JSON_UNESCAPED_UNICODE),
                'changed_by' => $request->user()->id, 'changed_by_name' => $request->user()->name,
                'changed_at' => $now, 'created_at' => $now,
            ]);
        });

        return back()->with('success', 'Manual Override มีผลแล้ว และไม่ได้เขียนกลับ ERP');
    }

    public function revokeOverride(Request $request, int $override): RedirectResponse
    {
        $validated = $request->validate(['revoke_reason' => ['required', 'string', 'max:1000']]);
        $db = DB::connection($this->connection);
        $row = $db->table('wr_ds_sales_overrides as o')->join('wr_ds_current_items as c', 'c.lifecycle_id', '=', 'o.lifecycle_id')
            ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'o.lifecycle_id')->where('o.id', $override)->where('o.is_active', 1)
            ->select('o.*', 'c.site_code', 'c.serial_number', 'c.source_unit_code', 'c.erp_salesperson_name', 'c.erp_division_code', 'c.trace_status', 'l.cycle_no')->first();
        abort_unless($row, 404);
        $db->transaction(function () use ($db, $request, $validated, $row) {
            $now = now('Asia/Bangkok');
            $db->table('wr_ds_sales_overrides')->where('id', $row->id)->update([
                'is_active' => 0, 'revoked_by' => $request->user()->id, 'revoked_by_name' => $request->user()->name,
                'revoked_at' => $now, 'revoke_reason' => $validated['revoke_reason'], 'updated_at' => $now,
            ]);
            $division = \App\Support\FormWR\WireRodDeadstockRule::division($row->erp_salesperson_name);
            $db->table('wr_ds_current_items')->where('lifecycle_id', $row->lifecycle_id)->update([
                'mapping_source' => $row->erp_salesperson_name ? 'PO' : 'UNMAPPED',
                'effective_sales_key' => $division, 'effective_sales_name' => $row->erp_salesperson_name,
                'effective_division_code' => $division,
                'display_unit' => \App\Support\FormWR\WireRodDeadstockRule::displayUnit($division, $row->source_unit_code),
                'trace_status' => $row->erp_salesperson_name ? 'MAPPED' : 'UNMAPPED', 'updated_at' => $now,
            ]);
            $db->table('wr_ds_item_review_logs')->insert([
                'lifecycle_id' => $row->lifecycle_id, 'site_code' => $row->site_code, 'serial_number' => $row->serial_number,
                'cycle_no' => $row->cycle_no, 'action' => 'REVOKE_OVERRIDE', 'source' => 'WEB',
                'changed_fields' => json_encode(['override']), 'before_values' => json_encode($row, JSON_UNESCAPED_UNICODE),
                'after_values' => json_encode($validated, JSON_UNESCAPED_UNICODE),
                'changed_by' => $request->user()->id, 'changed_by_name' => $request->user()->name,
                'changed_at' => $now, 'created_at' => $now,
            ]);
        });

        return back()->with('success', 'ยกเลิก Manual Override แล้ว; ระบบจะประเมิน Mapping ใหม่ในการ Refresh ถัดไป');
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)
            ->leftJoin('wr_ds_item_reviews as export_review', 'export_review.lifecycle_id', '=', 'c.lifecycle_id')
            ->leftJoin('wr_ds_sales_overrides as export_override', function ($join) {
                $join->on('export_override.lifecycle_id', '=', 'c.lifecycle_id')->where('export_override.is_active', 1);
            })
            ->addSelect(
                'lifecycle.cycle_no',
                'export_review.review_status',
                'export_review.review_detail',
                'export_review.corrective_action',
                'export_review.preventive_action',
                'export_review.sales_remark',
                'export_review.next_follow_up_date',
                'export_override.sales_name as override_sales_name',
                'export_override.division_code as override_division_code',
                'export_override.reason_code as override_reason_code',
                'export_override.remark as override_remark'
            )
            ->orderBy('c.id');
        $filename = 'wire-rod-deadstock-'.now('Asia/Bangkok')->format('Ymd-His').'.csv';
        $auditId = $this->startExportAudit($request, $filename);

        return response()->streamDownload(function () use ($rows, $auditId) {
            set_time_limit(300);
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Site', 'Lifecycle ID', 'Cycle', 'Part', 'Description', 'Grade', 'Diameter / Size',
                'Serial / Heat', 'Heat Number', 'Purchase Date', 'Age Days', 'Stock Status', 'Newly Dead',
                'Material Group', 'Qty', 'Unit', 'Unit Cost THB', 'Value THB', 'PO Number', 'PO Date',
                'Purchase Qty', 'Used Qty', 'MFG', 'Sales Order', 'Customers', 'All Related Sales',
                'Effective Sales', 'Division', 'Trace Status', 'Mapping Source', 'Review Status',
                'Next Follow-up Date', 'Review Detail', 'Corrective Action', 'Preventive Action',
                'Sales Remark', 'Override Sales', 'Override Division', 'Override Reason', 'Override Remark',
                'Data Retrieved At', 'Last Successful Sync At',
            ]);
            fflush($out);

            $jsonValues = static function ($json): string {
                return collect(json_decode($json ?: '[]', true))->filter()->map(
                    fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value
                )->implode(' | ');
            };
            $mfgValues = static fn ($json): string => collect(json_decode($json ?: '[]', true))
                ->pluck('mfg')->filter()->unique()->implode(' | ');

            $written = 0;
            try {
                foreach ($rows->cursor() as $r) {
                    fputcsv($out, CsvExportCell::row([
                        $r->site_code, $r->lifecycle_id, $r->cycle_no, $r->part_number, $r->part_description,
                        $r->grade, $r->diameter_size, $r->serial_number, $r->heat_number, $r->purchase_date,
                        $r->stock_age_days, $r->stock_status, $r->became_dead_stock ? 'Yes' : 'No',
                        $r->material_group, $r->current_qty, $r->display_unit, $r->unitcost_thb,
                        $r->current_value_thb, $r->po_number, $r->po_date, $r->purchase_qty, $r->used_qty,
                        $mfgValues($r->mfg_json), $jsonValues($r->sales_order_json), $jsonValues($r->customer_json),
                        $jsonValues($r->sales_json), $r->effective_sales_name, $r->effective_division_code,
                        $r->trace_status, $r->mapping_source, $r->review_status ?: 'open',
                        $r->next_follow_up_date, $r->review_detail, $r->corrective_action,
                        $r->preventive_action, $r->sales_remark, $r->override_sales_name,
                        $r->override_division_code, $r->override_reason_code, $r->override_remark,
                        $r->source_retrieved_at, $r->last_successful_sync_at,
                    ]));
                    if (++$written % 250 === 0) fflush($out);
                }
                $this->finishExportAudit($auditId, $written);
            } catch (Throwable $e) {
                $this->failExportAudit($auditId, $written, $e);
                throw $e;
            } finally {
                fclose($out);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function startExportAudit(Request $request, string $filename): ?int
    {
        try {
            if (! Schema::connection($this->connection)->hasTable('wr_ds_export_logs')) {
                Log::warning('Wire Rod export audit table missing; run 07_add_wr_ds_export_security.sql.');
                return null;
            }

            return DB::connection($this->connection)->table('wr_ds_export_logs')->insertGetId([
                'user_id' => $request->user()?->id,
                'user_name' => $request->user()?->name,
                'filename' => $filename,
                'filter_json' => json_encode($request->query(), JSON_UNESCAPED_UNICODE),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'status' => 'STARTED',
                'started_at' => now('Asia/Bangkok'),
                'created_at' => now('Asia/Bangkok'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Wire Rod export audit start failed: '.$e->getMessage());
            return null;
        }
    }

    private function finishExportAudit(?int $auditId, int $rowCount): void
    {
        if (! $auditId) return;

        try {
            DB::connection($this->connection)->table('wr_ds_export_logs')->where('id', $auditId)->update([
                'row_count' => $rowCount,
                'status' => 'COMPLETED',
                'completed_at' => now('Asia/Bangkok'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Wire Rod export audit completion failed: '.$e->getMessage());
        }
    }

    private function failExportAudit(?int $auditId, int $rowCount, Throwable $error): void
    {
        if (! $auditId) return;

        try {
            DB::connection($this->connection)->table('wr_ds_export_logs')->where('id', $auditId)->update([
                'row_count' => $rowCount,
                'status' => 'FAILED',
                'error_message' => mb_substr($error->getMessage(), 0, 1000),
                'completed_at' => now('Asia/Bangkok'),
            ]);
        } catch (Throwable $auditError) {
            Log::warning('Wire Rod export audit failure update failed: '.$auditError->getMessage());
        }
    }

    public function show(int $lifecycle): View
    {
        $db = DB::connection($this->connection);
        $item = $db->table('wr_ds_current_items as c')->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('c.lifecycle_id', $lifecycle)->select('c.*', 'l.cycle_no', 'l.opened_at', 'l.last_seen_at')->first();
        abort_unless($item, 404);
        $warning = null;
        $live = null;
        try {
            $live = $this->service->latestLot($item->site_code, $item->serial_number);
        } catch (Throwable $e) {
            $warning = str_contains(strtolower($e->getMessage()), 'timeout') ? 'Refresh timeout' : 'อ่าน ERP ล่าสุดไม่สำเร็จ';
        }
        $reviews = $db->table('wr_ds_item_review_logs')->where('lifecycle_id', $lifecycle)->orderByDesc('changed_at')->get();
        $overrides = $db->table('wr_ds_sales_overrides')->where('lifecycle_id', $lifecycle)->orderByDesc('overridden_at')->get();
        $additionalCustomers = $db->table('wr_ds_customer_additions')->where('lifecycle_id', $lifecycle)
            ->orderByDesc('revision_no')->orderByDesc('created_at')->get();

        return view('formwr.deadstock.show', compact('item', 'live', 'warning', 'reviews', 'overrides', 'additionalCustomers'));
    }

    public function storeAdditionalCustomer(Request $request, int $lifecycle): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:2000'],
            'supersedes_id' => ['nullable', 'integer'],
        ]);
        $db = DB::connection($this->connection);
        $item = $db->table('wr_ds_current_items')->where('lifecycle_id', $lifecycle)->first();
        abort_unless($item, 404);
        abort_unless($this->canEdit($request, $item), 403);

        $db->transaction(function () use ($db, $request, $validated, $lifecycle) {
            $now = now('Asia/Bangkok');
            $previous = null;
            if (! empty($validated['supersedes_id'])) {
                $previous = $db->table('wr_ds_customer_additions')->where('id', $validated['supersedes_id'])
                    ->where('lifecycle_id', $lifecycle)->where('is_active', 1)->lockForUpdate()->first();
                abort_unless($previous, 422, 'ไม่พบ Customer เพิ่มเติมฉบับปัจจุบัน');
                $db->table('wr_ds_customer_additions')->where('id', $previous->id)->update([
                    'is_active' => 0, 'superseded_at' => $now, 'updated_at' => $now,
                ]);
            }
            $revision = (int) $db->table('wr_ds_customer_additions')->where('lifecycle_id', $lifecycle)->max('revision_no') + 1;
            $db->table('wr_ds_customer_additions')->insert([
                'lifecycle_id' => $lifecycle, 'revision_no' => $revision,
                'customer_name' => trim($validated['customer_name']),
                'reason' => trim($validated['reason']), 'explanation' => trim($validated['explanation']),
                'supersedes_id' => $previous?->id, 'is_active' => 1,
                'created_by' => $request->user()->id, 'created_by_name' => $request->user()->name,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'บันทึก Customer เพิ่มเติมแล้ว โดยไม่เปลี่ยน Customer จาก CPA หรือ Sales Mapping']);
        }

        return back()->with('success', 'บันทึก Customer เพิ่มเติมแล้ว โดยไม่เปลี่ยน Customer จาก CPA หรือ Sales Mapping');
    }

    public function revokeAdditionalCustomer(Request $request, int $customer): RedirectResponse
    {
        $validated = $request->validate(['revoke_reason' => ['required', 'string', 'max:1000']]);
        $db = DB::connection($this->connection);
        $row = $db->table('wr_ds_customer_additions')->where('id', $customer)->where('is_active', 1)->first();
        abort_unless($row, 404);

        $db->table('wr_ds_customer_additions')->where('id', $row->id)->update([
            'is_active' => 0, 'revoked_by' => $request->user()->id,
            'revoked_by_name' => $request->user()->name, 'revoked_at' => now('Asia/Bangkok'),
            'revoke_reason' => $validated['revoke_reason'], 'updated_at' => now('Asia/Bangkok'),
        ]);

        return back()->with('success', 'ยกเลิก Customer เพิ่มเติมแล้ว โดยยังเก็บประวัติเดิม');
    }

    public function queueExport(Request $request): RedirectResponse
    {
        $allowed = ['site', 'material_group', 'stock_status', 'action_status', 'follow_up', 'division', 'trace_status', 'unit',
            'item', 'part_number_exact', 'serial', 'customer', 'purchase_from', 'purchase_to', 'became_dead'];
        $filters = collect($request->only($allowed))->filter(fn ($value) => filled($value))->all();
        $db = DB::connection($this->connection);
        $token = (string) Str::uuid();
        $now = now('Asia/Bangkok');
        $id = $db->table('wr_ds_export_requests')->insertGetId([
            'token' => $token, 'user_id' => $request->user()->id, 'user_name' => $request->user()->name,
            'filename' => 'wire-rod-deadstock-'.$now->format('Ymd-His').'.xlsx',
            'filter_json' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'status' => 'QUEUED',
            'requested_at' => $now, 'created_at' => $now,
        ]);

        try {
            dispatch(new GenerateWireRodDeadstockExport($id));
        } catch (Throwable $e) {
            $db->table('wr_ds_export_requests')->where('id', $id)->update([
                'status' => 'FAILED', 'error_message' => mb_substr($e->getMessage(), 0, 1000), 'completed_at' => now('Asia/Bangkok'),
            ]);
            return redirect()->route('wr.deadstock.exports.index')->with('warning', 'ส่งงาน Export ไม่สำเร็จ กรุณาตรวจ Queue worker');
        }

        return redirect()->route('wr.deadstock.exports.index')->with('success', 'รับคำขอ Export แล้ว ระบบกำลังสร้างไฟล์ Excel');
    }

    public function exportHistory(Request $request): View
    {
        $rows = DB::connection($this->connection)->table('wr_ds_export_requests')
            ->where('user_id', $request->user()->id)->orderByDesc('requested_at')->paginate(30);

        return view('formwr.deadstock.exports', compact('rows'));
    }

    public function exportStatus(Request $request): JsonResponse
    {
        $rows = DB::connection($this->connection)->table('wr_ds_export_requests')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED'])
            ->orderByDesc('requested_at')->limit(10)->get()
            ->map(fn ($row) => [
                'token' => $row->token,
                'filename' => $row->filename,
                'status' => $row->status,
                'completed_at' => $row->completed_at,
                'download_url' => $row->status === 'COMPLETED'
                    ? route('wr.deadstock.exports.download', $row->token)
                    : null,
            ]);

        return response()->json(['items' => $rows]);
    }

    public function downloadExport(Request $request, string $token)
    {
        $row = DB::connection($this->connection)->table('wr_ds_export_requests')->where('token', $token)->first();
        abort_unless($row && (int) $row->user_id === (int) $request->user()->id, 403);
        abort_unless($row->status === 'COMPLETED' && $row->file_path && Storage::disk('local')->exists($row->file_path), 404);

        return Storage::disk('local')->download($row->file_path, $row->filename ?: 'wire-rod-deadstock.xlsx');
    }

    private function filteredQuery(Request $request)
    {
        return $this->deadstockQuery->current($request->query());
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 50);

        return in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
    }

    private function filterOptions(): array
    {
        return Cache::remember('wr_ds:filter-options', now()->addMinutes(5), function () {
            $db = DB::connection($this->connection);
            $current = fn () => $db->table('wr_ds_current_items as c')
                ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
                ->where('l.is_current', 1);
            return [
                'groups' => $current()->whereNotNull('c.material_group')->distinct()->orderBy('c.material_group')->pluck('c.material_group'),
                'divisions' => $current()->whereNotNull('c.effective_division_code')->distinct()->orderBy('c.effective_division_code')->pluck('c.effective_division_code'),
                'trace_statuses' => $current()->whereNotNull('c.trace_status')->distinct()->orderBy('c.trace_status')->pluck('c.trace_status'),
            ];
        });
    }

    private function reviewAccessContext(Request $request): array
    {
        $user = $request->user();
        $roleCodes = $user->deptRoles()->whereIn('code', ['WR_DS_EDIT_OWN', 'WR_DS_MANAGE_ALL', 'WR_DS_ADMIN'])
            ->pluck('code')->map(fn ($code) => mb_strtoupper((string) $code))->all();
        $manageAll = ($user->is_superadmin ?? false)
            || count(array_intersect($roleCodes, ['WR_DS_MANAGE_ALL', 'WR_DS_ADMIN'])) > 0;
        $editableSales = [];
        if (! $manageAll && in_array('WR_DS_EDIT_OWN', $roleCodes, true)) {
            $editableSales = DB::connection($this->connection)->table('wr_ds_user_sales_access')
                ->where('user_id', $user->id)->where('can_edit', 1)->where('is_active', 1)
                ->pluck('salesperson_key')->map(fn ($key) => mb_strtoupper((string) $key))->all();
        }

        return ['manage_all' => $manageAll, 'editable_sales' => $editableSales];
    }

    private function canEditFromContext(object $item, array $context): bool
    {
        if ($context['manage_all']) return true;
        if (in_array($item->trace_status, ['MULTIPLE_ALLOCATION', 'SALES_MAPPING_CONFLICT'], true)) return false;
        return $item->effective_sales_key
            && in_array(mb_strtoupper((string) $item->effective_sales_key), $context['editable_sales'], true);
    }

    private function filteredSnapshotQuery(Request $request, int $snapshotId)
    {
        $q = DB::connection($this->connection)->table('wr_ds_snapshot_items as s')->where('s.snapshot_id', $snapshotId);
        foreach (['site_code' => 'site', 'material_group' => 'material_group', 'stock_status' => 'stock_status', 'division_code' => 'division', 'trace_status' => 'trace_status', 'display_unit' => 'unit'] as $column => $input) {
            if ($request->filled($input)) $q->where("s.{$column}", $request->input($input));
        }
        if ($request->filled('item')) {
            $term = '%'.mb_strtoupper(trim((string) $request->input('item'))).'%';
            $q->where(fn ($sub) => $sub->whereRaw('UPPER(s.part_number) LIKE ?', [$term])
                ->orWhereRaw('UPPER(s.part_description) LIKE ?', [$term]));
        }
        if ($request->filled('part_number_exact')) $q->where('s.part_number', $request->input('part_number_exact'));
        if ($request->filled('serial')) $q->whereRaw('UPPER(s.serial_number) LIKE ?', ['%'.mb_strtoupper(trim((string) $request->input('serial'))).'%']);
        if ($request->filled('customer')) $q->whereRaw('UPPER(s.trace_json) LIKE ?', ['%'.mb_strtoupper(trim((string) $request->input('customer'))).'%']);
        if ($request->filled('purchase_from')) $q->whereDate('s.purchase_date', '>=', $request->input('purchase_from'));
        if ($request->filled('purchase_to')) $q->whereDate('s.purchase_date', '<=', $request->input('purchase_to'));
        if ($request->boolean('became_dead')) $q->where('s.became_dead_stock', 1);
        return $q;
    }

    private function canEdit(Request $request, object $item): bool
    {
        $user = $request->user();
        if (($user->is_superadmin ?? false) || $user->hasRoleCode(['WR_DS_MANAGE_ALL', 'WR_DS_ADMIN'])) return true;
        if (! $user->hasRoleCode(['WR_DS_EDIT_OWN']) || in_array($item->trace_status, ['MULTIPLE_ALLOCATION', 'SALES_MAPPING_CONFLICT'], true)) return false;
        return DB::connection($this->connection)->table('wr_ds_user_sales_access')
            ->where('user_id', $user->id)->where('salesperson_key', $item->effective_sales_key)
            ->where('can_edit', 1)->where('is_active', 1)->exists();
    }

    private function modalPayload(Request $request, object $item): array
    {
        $canEdit = $this->canEdit($request, $item);
        $denialReason = null;
        if (! $canEdit) {
            if ($item->trace_status === 'MULTIPLE_ALLOCATION') {
                $denialReason = 'Multiple Allocation — ให้ Planning/Procurement จัดการข้อมูลกลาง';
            } elseif ($item->trace_status === 'SALES_MAPPING_CONFLICT') {
                $denialReason = 'Sales Mapping Conflict — รอ Planning/Procurement ตรวจสอบ';
            } elseif (! $request->user()->hasRoleCode(['WR_DS_EDIT_OWN', 'WR_DS_MANAGE_ALL', 'WR_DS_ADMIN'])) {
                $denialReason = 'บัญชีของคุณมีสิทธิ์ดูข้อมูลเท่านั้น';
            } else {
                $denialReason = 'Lot นี้ไม่ได้ Mapping ถึง Sales ที่คุณรับผิดชอบ';
            }
        }

        $additionalCustomers = DB::connection($this->connection)->table('wr_ds_customer_additions')
            ->where('lifecycle_id', $item->lifecycle_id)->where('is_active', 1)
            ->orderBy('customer_name')->get()->map(fn ($customer) => [
                'id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'reason' => $customer->reason,
                'explanation' => $customer->explanation,
                'revision_no' => $customer->revision_no,
                'created_by_name' => $customer->created_by_name,
                'created_at' => $customer->created_at,
            ])->values();

        return [
            'item' => [
                'lifecycle_id' => $item->lifecycle_id,
                'site_code' => $item->site_code,
                'material_group' => $item->material_group,
                'part_number' => $item->part_number,
                'part_description' => $item->part_description,
                'serial_number' => $item->serial_number,
                'heat_number' => $item->heat_number,
                'trace_status' => $item->trace_status,
                'customers' => collect(json_decode($item->customer_json ?: '[]', true))->filter()->unique()->values(),
                'sales' => collect(json_decode($item->sales_json ?: '[]', true))->filter()->unique()->values(),
            ],
            'review' => [
                'review_status' => $item->review_status ?: 'open',
                'review_detail' => $item->review_detail,
                'corrective_action' => $item->corrective_action,
                'preventive_action' => $item->preventive_action,
                'sales_remark' => $item->sales_remark,
                'next_follow_up_date' => $item->next_follow_up_date,
                'review_updated_at' => $item->review_updated_at
                    ? Carbon::parse($item->review_updated_at)->format('Y-m-d H:i:s')
                    : '',
            ],
            'additional_customers' => $additionalCustomers,
            'can_edit' => $canEdit,
            'denial_reason' => $denialReason,
            'urls' => [
                'review' => route('wr.deadstock.review.save', $item->lifecycle_id),
                'customer' => route('wr.deadstock.customer.store', $item->lifecycle_id),
                'modal_data' => route('wr.deadstock.modal-data', $item->lifecycle_id),
            ],
        ];
    }
}
