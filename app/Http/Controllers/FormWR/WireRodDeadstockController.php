<?php

namespace App\Http\Controllers\FormWR;

use App\Http\Controllers\Controller;
use App\Services\FormWR\WireRodDeadstockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WireRodDeadstockController extends Controller
{
    private string $connection;

    public function __construct(private WireRodDeadstockService $service)
    {
        $this->connection = config('database.workflow_connection', 'sqlsrv_menam');
    }

    public function dashboard(Request $request): View
    {
        $query = $this->filteredQuery($request);
        $rows = (clone $query)->get();
        $latestRun = DB::connection($this->connection)->table('wr_ds_sync_runs')->orderByDesc('started_at')->first();
        $byGroup = $rows->groupBy('material_group')->map(fn ($items, $group) => [
            'group' => $group,
            'lots' => $items->count(),
            'dead_lots' => $items->where('stock_status', 'DEAD')->count(),
            'dead_kg' => $items->where('stock_status', 'DEAD')->where('display_unit', 'KG')->sum('current_qty'),
            'dead_pcs' => $items->where('stock_status', 'DEAD')->where('display_unit', 'PCS')->sum('current_qty'),
            'dead_value' => $items->where('stock_status', 'DEAD')->sum('current_value_thb'),
        ])->values();

        return view('formwr.deadstock.dashboard', [
            'kpi' => [
                'lots' => $rows->count(),
                'stock_kg' => $rows->where('display_unit', 'KG')->sum('current_qty'),
                'stock_pcs' => $rows->where('display_unit', 'PCS')->sum('current_qty'),
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
        ]);
    }

    public function review(Request $request): View
    {
        $items = $this->filteredQuery($request)
            ->leftJoin('wr_ds_item_reviews as r', 'r.lifecycle_id', '=', 'c.lifecycle_id')
            ->leftJoin('wr_ds_sales_overrides as o', function ($join) {
                $join->on('o.lifecycle_id', '=', 'c.lifecycle_id')->where('o.is_active', 1);
            })
            ->addSelect('r.review_status', 'r.review_detail', 'r.corrective_action', 'r.preventive_action', 'r.sales_remark', 'r.next_follow_up_date')
            ->addSelect('o.id as override_id', 'o.sales_name as override_sales_name', 'o.division_code as override_division_code', 'o.reason_code as override_reason_code', 'o.remark as override_remark');

        match ($request->input('sort', 'age_desc')) {
            'qty_desc' => $items->orderByDesc('c.current_qty'),
            'purchase_oldest' => $items->orderBy('c.purchase_date'),
            'follow_up_soon' => $items->orderByRaw('CASE WHEN r.next_follow_up_date IS NULL THEN 1 ELSE 0 END')->orderBy('r.next_follow_up_date'),
            default => $items->orderByRaw("CASE c.stock_status WHEN 'DEAD' THEN 0 WHEN 'UNKNOWN' THEN 1 ELSE 2 END")->orderByDesc('c.stock_age_days'),
        };

        $items = $items->paginate(50)->withQueryString();

        return view('formwr.deadstock.review', [
            'items' => $items,
            'filters' => $this->filterOptions(),
            'selected' => $request->query(),
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        $result = $this->service->refresh($user?->id, $user?->name, 'MANUAL');

        return back()->with($result['status'] === 'SUCCEEDED' ? 'success' : 'warning',
            $result['status'] === 'SUCCEEDED'
                ? 'Refresh สำเร็จทั้ง WIRE และ PLUS'
                : 'Refresh ไม่ครบทุก Site ระบบคงข้อมูลล่าสุดไว้ กรุณาตรวจ Sync status');
    }

    public function saveReview(Request $request, int $lifecycle): RedirectResponse
    {
        $validated = $request->validate([
            'review_status' => ['required', 'in:open,waiting_follow_up,in_progress'],
            'review_detail' => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'preventive_action' => ['nullable', 'string', 'max:5000'],
            'sales_remark' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_date' => ['nullable', 'date'],
        ]);
        $db = DB::connection($this->connection);
        $item = $db->table('wr_ds_current_items as c')->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('c.lifecycle_id', $lifecycle)->select('c.*', 'l.cycle_no')->first();
        abort_unless($item, 404);
        abort_unless($this->canEdit($request, $item), 403);

        $db->transaction(function () use ($db, $validated, $request, $item, $lifecycle) {
            $before = $db->table('wr_ds_item_reviews')->where('lifecycle_id', $lifecycle)->first();
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
        $rows = $this->filteredQuery($request)->orderBy('c.site_code')->orderBy('c.part_number')->orderBy('c.serial_number')->get();
        $filename = 'wire-rod-deadstock-'.now('Asia/Bangkok')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Site', 'Part', 'Description', 'Serial/Heat', 'Purchase Date', 'Age Days', 'Stock Status', 'Material Group', 'Qty', 'Unit', 'Unit Cost THB', 'Value THB', 'Customer', 'Sales', 'Division', 'Trace Status', 'Retrieved At']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->site_code, $r->part_number, $r->part_description, $r->serial_number, $r->purchase_date, $r->stock_age_days, $r->stock_status, $r->material_group, $r->current_qty, $r->display_unit, $r->unitcost_thb, $r->current_value_thb, $r->customer_json, $r->effective_sales_name, $r->effective_division_code, $r->trace_status, $r->source_retrieved_at]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

        return view('formwr.deadstock.show', compact('item', 'live', 'warning', 'reviews', 'overrides'));
    }

    private function filteredQuery(Request $request)
    {
        $q = DB::connection($this->connection)->table('wr_ds_current_items as c')
            ->join('wr_ds_item_lifecycles as lifecycle', 'lifecycle.id', '=', 'c.lifecycle_id')
            ->where('lifecycle.is_current', 1)
            ->select('c.*');
        foreach (['site_code' => 'site', 'material_group' => 'material_group', 'stock_status' => 'stock_status', 'effective_division_code' => 'division', 'trace_status' => 'trace_status', 'display_unit' => 'unit'] as $column => $input) {
            if ($request->filled($input)) $q->where("c.{$column}", $request->input($input));
        }
        if ($request->filled('item')) {
            $term = '%'.strtoupper(trim($request->input('item'))).'%';
            $q->where(fn ($sub) => $sub->whereRaw('UPPER(c.part_number) LIKE ?', [$term])->orWhereRaw('UPPER(c.part_description) LIKE ?', [$term]));
        }
        if ($request->filled('serial')) $q->whereRaw('UPPER(c.serial_number) LIKE ?', ['%'.strtoupper(trim($request->input('serial'))).'%']);
        if ($request->filled('customer')) $q->whereRaw('UPPER(c.customer_json) LIKE ?', ['%'.strtoupper(trim($request->input('customer'))).'%']);
        if ($request->filled('action_status')) {
            $status = $request->input('action_status');
            $matchingReview = fn ($sub) => $sub->selectRaw('1')->from('wr_ds_item_reviews as review_filter')
                ->whereColumn('review_filter.lifecycle_id', 'c.lifecycle_id')->where('review_filter.review_status', $status);
            if ($status === 'open') {
                $q->where(fn ($sub) => $sub->whereExists($matchingReview)->orWhereNotExists(
                    fn ($review) => $review->selectRaw('1')->from('wr_ds_item_reviews as any_review')->whereColumn('any_review.lifecycle_id', 'c.lifecycle_id')
                ));
            } else {
                $q->whereExists($matchingReview);
            }
        }
        if ($request->filled('purchase_from')) $q->whereDate('c.purchase_date', '>=', $request->input('purchase_from'));
        if ($request->filled('purchase_to')) $q->whereDate('c.purchase_date', '<=', $request->input('purchase_to'));
        if ($request->boolean('became_dead')) $q->where('c.became_dead_stock', 1);
        return $q;
    }

    private function filterOptions(): array
    {
        $db = DB::connection($this->connection);
        $current = fn () => $db->table('wr_ds_current_items as c')
            ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
            ->where('l.is_current', 1);
        return [
            'groups' => $current()->whereNotNull('c.material_group')->distinct()->orderBy('c.material_group')->pluck('c.material_group'),
            'divisions' => $current()->whereNotNull('c.effective_division_code')->distinct()->orderBy('c.effective_division_code')->pluck('c.effective_division_code'),
            'trace_statuses' => $current()->whereNotNull('c.trace_status')->distinct()->orderBy('c.trace_status')->pluck('c.trace_status'),
        ];
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
}
