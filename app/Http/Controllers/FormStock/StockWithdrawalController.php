<?php

namespace App\Http\Controllers\FormStock;

use App\Http\Controllers\Controller;
use App\Models\FormStock\StockWithdrawalPlanItem;
use App\Models\Users\User;
use App\Services\FormStock\StockWithdrawalPlanningService;
use App\Services\FormStock\StockWithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockWithdrawalController extends Controller
{
    public function index(Request $request, StockWithdrawalPlanningService $service)
    {
        [$rows, $filters, $summary] = $this->plannedItems($request, $service, true);
        $statusLabels = ['NOT_STARTED'=>'ยังไม่เบิก','PARTIAL'=>'เบิกบางส่วน','COMPLETED'=>'เบิกครบแล้ว','PLANNER_CLOSED'=>'ปิดโดย Planner','CANCELED'=>'ยกเลิก'];
        $sortLabels = ['queue_asc'=>'วันที่เข้าคิว','production_asc'=>'วันที่ขึ้นผลิต','withdraw_asc'=>'วันที่ต้องเบิก','mfg_asc'=>'MFG','remaining_desc'=>'คงเหลือมากก่อน'];
        $activeFilters = collect([
            'มุมมองด่วน' => !empty($filters['quick']) ? (['today'=>'วันนี้','backlog'=>'งานค้าง','special'=>'Special','unfinished'=>'ยังไม่ครบ','overdue'=>'เลยกำหนด'][$filters['quick']] ?? $filters['quick']) : null,
            'กลุ่มแผน' => !empty($filters['section']) ? ($filters['section'] === '1' ? 'RM / กัดกรด' : 'FG / NCR / S') : null,
            'วันที่เข้าคิวตั้งแต่' => $filters['date_from'] ?? null,
            'ถึงวันที่' => $filters['date_to'] ?? null,
            'สถานะ' => !empty($filters['status']) ? ($statusLabels[$filters['status']] ?? $filters['status']) : null,
            'Special' => isset($filters['special']) && $filters['special'] !== '' ? ($filters['special'] === '1' ? 'Special' : 'งานปกติ') : null,
            'MFG' => $filters['mfg'] ?? null,
            'โรงงาน' => $filters['site'] ?? null,
            'Part' => $filters['part'] ?? null,
            'รายละเอียด' => $filters['description'] ?? null,
            'ลูกค้า' => $filters['customer'] ?? null,
            'ประเภทงาน' => !empty($filters['job_type']) ? (config('stock-withdrawal.job_types.'.$filters['job_type'].'.name') ?? $filters['job_type']) : null,
        ])->filter(fn($value) => $value !== null && $value !== '');

        return view('formstock.index', [
            'rows' => $rows, 'filters' => $filters, 'summary' => $summary,
            'refreshedAt' => $rows->getCollection()->pluck('erp_synced_at')->filter()->sortDesc()->first(),
            'snapshotEnabled' => $service->erpSnapshotAvailable(), 'jobTypes' => config('stock-withdrawal.job_types'),
            'activeFilters' => $activeFilters, 'sortLabel' => $sortLabels[$filters['sort']] ?? $filters['sort'],
        ]);
    }

    public function export(Request $request, StockWithdrawalPlanningService $service): StreamedResponse
    {
        [$rows] = $this->plannedItems($request, $service, false);
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['MFG', 'Site', 'Part', 'Description', 'Customer', 'First Work Center', 'Production Date', 'Delivery Date', 'Queue Date', 'Withdraw Date', 'Required (KG)', 'Issued (KG)', 'Remaining (KG)', 'Status', 'Special', 'Planner Remark', 'MFG Remark']);
            foreach ($rows as $item) {
                fputcsv($out, [
                    $item->plan->mfg, $item->plan->site, $item->partnumber, $item->part_description,
                    $item->plan->customer_name, $item->first_work_center, $item->plan->production_date?->toDateString(),
                    $item->plan->delivery_date?->toDateString(), $item->queue_date?->toDateString(),
                    $item->planned_withdraw_date?->toDateString(), (float) ($item->withdraw_quantity ?? $item->quantity),
                    (float) $item->withdrawn_qty, (float) $item->remaining_qty, $item->withdrawal_status,
                    $item->is_special ? 'Yes' : 'No', $item->remark, $item->plan->plan_note,
                ]);
            }
            fclose($out);
        }, 'stock-withdrawal-status-'.now('Asia/Bangkok')->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function suggest(Request $request, StockWithdrawalService $service, string $type)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'site' => ['nullable', 'in:WIRE,PLUS']]);
        return response()->json($service->suggestions($type, (string) ($data['q'] ?? ''), $data['site'] ?? null));
    }

    public function issues(Request $request, StockWithdrawalService $service)
    {
        $data = $request->validate([
            'site' => ['required', 'in:WIRE,PLUS'], 'workorder_id' => ['required', 'integer'],
            'parts_id' => ['required', 'integer'], 'mfg' => ['nullable', 'string', 'max:100'],
            'partnumber' => ['nullable', 'string', 'max:100'],
        ]);
        return view('formstock.issues', [
            'details' => $service->issueDetails($data['site'], $data['workorder_id'], $data['parts_id']),
            'context' => $data,
        ]);
    }

    public function history(StockWithdrawalPlanItem $item)
    {
        $item->load('plan');
        $logs = DB::connection('sqlsrv_menam')->table('stock_withdrawal_item_action_logs')
            ->where('stock_withdrawal_plan_item_id', $item->id)->orderByDesc('acted_at')->get();
        $users = User::query()->whereIn('id', $logs->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        return view('formstock.history', compact('item', 'logs', 'users'));
    }

    public function refreshSnapshots(Request $request, StockWithdrawalPlanningService $service)
    {
        if (!$service->erpSnapshotAvailable()) {
            return response()->json(['message' => 'ยังไม่ได้ติดตั้งคอลัมน์ ERP Snapshot'], 409);
        }
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['integer', 'distinct'],
            'force' => ['nullable', 'boolean'],
        ]);
        $items = StockWithdrawalPlanItem::query()->with('plan')->whereIn('id', $data['item_ids'])
            ->when(empty($data['force']), fn ($query) => $query->where(function ($query) {
                $query->whereNull('erp_synced_at')->orWhere('erp_synced_at', '<', now('Asia/Bangkok')->subMinutes(5));
            }))->get();
        $count = $service->syncErpSnapshots($items);

        $freshItems = StockWithdrawalPlanItem::query()->with('plan')->whereIn('id', $data['item_ids'])->get();
        $service->attachSnapshotBalances($freshItems);
        $rows = $freshItems->map(fn ($item) => [
            'id' => $item->id,
            'withdrawn_qty' => (float) $item->withdrawn_qty,
            'remaining_qty' => (float) $item->remaining_qty,
            'status_code' => $item->withdrawal_status_code,
            'status_label' => $item->withdrawal_status,
            'first_work_center' => $item->first_work_center ?: '-',
            'synced_at' => $item->erp_synced_at?->format('d/m/Y H:i:s'),
        ])->values();

        return response()->json(['message' => "ดึงยอด ERP ล่าสุดแล้ว {$count} รายการ", 'count' => $count, 'rows' => $rows]);
    }

    private function plannedItems(Request $request, StockWithdrawalPlanningService $service, bool $paginate): array
    {
        $filters = $this->trackingFilters($request);
        if (!$service->erpSnapshotAvailable()) {
            return $this->plannedItemsLegacy($request, $service, $filters, $paginate);
        }

        $query = StockWithdrawalPlanItem::query()
            ->from('stock_withdrawal_plan_items as i')
            ->join('stock_withdrawal_plans as p', 'p.id', '=', 'i.stock_withdrawal_plan_id')
            ->select('i.*')->with('plan');
        if (!empty($filters['site'])) $query->where('p.site', $filters['site']);
        if (!empty($filters['mfg'])) $query->where('p.mfg', 'like', '%'.trim($filters['mfg']).'%');
        if (!empty($filters['customer'])) $query->where('p.customer_name', 'like', '%'.trim($filters['customer']).'%');
        if (!empty($filters['part'])) $query->where('i.partnumber', 'like', '%'.trim($filters['part']).'%');
        if (!empty($filters['description'])) $query->where('i.part_description', 'like', '%'.trim($filters['description']).'%');
        if (!empty($filters['section'])) $query->where('i.plan_section', $filters['section']);
        if (!empty($filters['date_from'])) $query->whereDate('i.queue_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('i.queue_date', '<=', $filters['date_to']);
        if (!empty($filters['job_type'])) $query->where('i.job_type_code', $filters['job_type']);
        if (isset($filters['special']) && $filters['special'] !== '') $query->where('i.is_special', (int) $filters['special']);

        $statusSql = "CASE WHEN p.cancelled_at IS NOT NULL THEN 'CANCELED' WHEN i.planner_closed_at IS NOT NULL THEN 'PLANNER_CLOSED' ELSE COALESCE(i.erp_status_code, 'NOT_STARTED') END";
        if (!empty($filters['status'])) $query->whereRaw("{$statusSql} = ?", [$filters['status']]);
        $today = now('Asia/Bangkok')->toDateString();
        match ($filters['quick'] ?? null) {
            'today' => $query->whereDate('i.queue_date', $today),
            'backlog' => $query->whereColumn('i.original_queue_date', '<', 'i.queue_date'),
            'special' => $query->where('i.is_special', 1),
            'unfinished' => $query->whereNull('p.cancelled_at')->whereNull('i.planner_closed_at')->whereIn(DB::raw("COALESCE(i.erp_status_code, 'NOT_STARTED')"), ['NOT_STARTED', 'PARTIAL']),
            'overdue' => $query->whereDate('i.planned_withdraw_date', '<', $today)->whereNull('p.cancelled_at')->whereNull('i.planner_closed_at')->whereIn(DB::raw("COALESCE(i.erp_status_code, 'NOT_STARTED')"), ['NOT_STARTED', 'PARTIAL']),
            default => null,
        };

        $summarySql = collect(['NOT_STARTED', 'PARTIAL', 'COMPLETED', 'PLANNER_CLOSED', 'CANCELED'])
            ->map(fn ($status) => "SUM(CASE WHEN {$statusSql} = '{$status}' THEN 1 ELSE 0 END) AS [{$status}]")->implode(', ');
        $summaryRow = (clone $query)->reorder()->select(DB::raw($summarySql))->toBase()->first();
        $summary = collect(['NOT_STARTED', 'PARTIAL', 'COMPLETED', 'PLANNER_CLOSED', 'CANCELED'])
            ->mapWithKeys(fn ($status) => [$status => (int) ($summaryRow->{$status} ?? 0)]);

        $query->orderByDesc('i.is_special');
        match ($filters['sort']) {
            'production_asc' => $query->orderByRaw('CASE WHEN p.production_date IS NULL THEN 1 ELSE 0 END')->orderBy('p.production_date')->orderBy('i.lead_time_days')->orderBy('i.queue_position'),
            'withdraw_asc' => $query->orderByRaw('CASE WHEN i.planned_withdraw_date IS NULL THEN 1 ELSE 0 END')->orderBy('i.planned_withdraw_date')->orderBy('i.queue_position'),
            'mfg_asc' => $query->orderBy('p.mfg')->orderBy('i.partnumber'),
            'remaining_desc' => $query->orderByDesc('i.erp_remaining_qty')->orderBy('i.queue_position'),
            default => $query->orderByRaw('CASE WHEN i.queue_date IS NULL THEN 1 ELSE 0 END')->orderBy('i.queue_date')->orderBy('i.queue_position')->orderBy('i.id'),
        };

        if ($paginate) {
            $rows = $query->paginate((int) $filters['per_page'])->withQueryString();
            $service->attachSnapshotBalances($rows->getCollection());
            foreach ($rows->getCollection() as $item) $item->day_offset = $service->overdueDays($item->planned_withdraw_date);
            return [$rows, $filters, $summary];
        }

        $rows = $service->attachSnapshotBalances($query->get());
        foreach ($rows as $item) $item->day_offset = $service->overdueDays($item->planned_withdraw_date);
        return [$rows, $filters, $summary];
    }

    private function trackingFilters(Request $request): array
    {
        $filters = $request->validate([
            'site' => ['nullable', 'in:WIRE,PLUS'], 'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in(['NOT_STARTED', 'PARTIAL', 'COMPLETED', 'PLANNER_CLOSED', 'CANCELED'])],
            'mfg' => ['nullable', 'string', 'max:100'], 'part' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:150'], 'customer' => ['nullable', 'string', 'max:150'],
            'job_type' => ['nullable', 'string', 'max:30'], 'special' => ['nullable', Rule::in(['0', '1'])],
            'section' => ['nullable', Rule::in(['1', '2'])],
            'quick' => ['nullable', Rule::in(['today', 'backlog', 'special', 'unfinished', 'overdue'])],
            'per_page' => ['nullable', 'integer', Rule::in([50, 100, 150, 200])],
            'sort' => ['nullable', Rule::in(['queue_asc', 'production_asc', 'withdraw_asc', 'mfg_asc', 'remaining_desc'])],
            'refresh' => ['nullable', 'boolean'],
        ]);
        return $filters + ['sort' => 'queue_asc', 'per_page' => 50];
    }

    private function plannedItemsLegacy(Request $request, StockWithdrawalPlanningService $service, array $filters, bool $paginate): array
    {
        $query = StockWithdrawalPlanItem::query()->with('plan');
        if (!empty($filters['site'])) $query->whereHas('plan', fn($q) => $q->where('site', $filters['site']));
        if (!empty($filters['mfg'])) $query->whereHas('plan', fn($q) => $q->where('mfg', 'like', '%'.trim($filters['mfg']).'%'));
        if (!empty($filters['customer'])) $query->whereHas('plan', fn($q) => $q->where('customer_name', 'like', '%'.trim($filters['customer']).'%'));
        if (!empty($filters['part'])) $query->where('partnumber', 'like', '%'.trim($filters['part']).'%');
        if (!empty($filters['description'])) $query->where('part_description', 'like', '%'.trim($filters['description']).'%');
        if (!empty($filters['section'])) $query->where('plan_section', $filters['section']);
        if (!empty($filters['date_from'])) $query->whereDate('queue_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('queue_date', '<=', $filters['date_to']);
        if (!empty($filters['job_type'])) $query->where('job_type_code', $filters['job_type']);
        if (isset($filters['special']) && $filters['special'] !== '') $query->where('is_special', (int) $filters['special']);

        $items = $service->attachWithdrawalBalances($query->get(), !$request->boolean('refresh'));
        if (!empty($filters['status'])) $items = $items->where('withdrawal_status_code', $filters['status'])->values();
        foreach ($items as $item) $item->day_offset = $service->overdueDays($item->planned_withdraw_date);
        $today = now('Asia/Bangkok')->toDateString();
        $items = match ($filters['quick'] ?? null) {
            'today' => $items->filter(fn($item) => $item->queue_date?->toDateString() === $today)->values(),
            'backlog' => $items->filter(fn($item) => $item->original_queue_date?->lt($item->queue_date) ?? false)->values(),
            'special' => $items->where('is_special', true)->values(),
            'unfinished' => $items->whereIn('withdrawal_status_code', ['NOT_STARTED', 'PARTIAL'])->values(),
            'overdue' => $items->filter(fn($item) => $item->day_offset < 0 && !in_array($item->withdrawal_status_code, ['COMPLETED', 'PLANNER_CLOSED', 'CANCELED'], true))->values(),
            default => $items,
        };
        $items = match ($filters['sort']) {
            'production_asc' => $items->sortBy(fn($i) => sprintf('%s|%05d|%010d', $i->plan->production_date?->toDateString() ?? '9999-12-31', $i->lead_time_days, $i->queue_position)),
            'withdraw_asc' => $items->sortBy(fn($i) => sprintf('%s|%010d', $i->planned_withdraw_date?->toDateString() ?? '9999-12-31', $i->queue_position)),
            'mfg_asc' => $items->sortBy(fn($i) => $i->plan->mfg.'|'.$i->partnumber),
            'remaining_desc' => $items->sortByDesc('remaining_qty'),
            default => $items->sortBy(fn($i) => sprintf('%s|%010d|%010d', $i->queue_date?->toDateString() ?? '9999-12-31', $i->queue_position, $i->id)),
        };
        $items = $items->sortByDesc(fn($item) => (int) $item->is_special);
        $items = $items->values();
        $summary = collect(['NOT_STARTED', 'PARTIAL', 'COMPLETED', 'PLANNER_CLOSED', 'CANCELED'])
            ->mapWithKeys(fn($status) => [$status => $items->where('withdrawal_status_code', $status)->count()]);
        if (!$paginate) return [$items, $filters, $summary];

        $perPage = (int) $filters['per_page'];
        $page = LengthAwarePaginator::resolveCurrentPage();
        $rows = new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->except('refresh'),
        ]);
        return [$rows, $filters, $summary];
    }
}
