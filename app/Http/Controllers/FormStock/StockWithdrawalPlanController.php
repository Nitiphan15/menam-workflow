<?php

namespace App\Http\Controllers\FormStock;

use App\Http\Controllers\Controller;
use App\Models\FormStock\StockWithdrawalPlan;
use App\Models\FormStock\StockWithdrawalPlanItem;
use App\Models\FormStock\StockWithdrawalType;
use App\Services\FormStock\StockWithdrawalPlanningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockWithdrawalPlanController extends Controller
{
    public function index(Request $request, StockWithdrawalPlanningService $service)
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'], 'site' => ['nullable', 'in:WIRE,PLUS'],
            'type' => ['nullable', 'string', 'max:30'], 'mfg' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['risk', 'withdraw_date', 'delivery_date', 'mfg'])],
        ]);
        $query = StockWithdrawalPlanItem::query()->with('plan');
        if (!empty($filters['site'])) $query->whereHas('plan', fn($q) => $q->where('site', $filters['site']));
        if (!empty($filters['mfg'])) $query->whereHas('plan', fn($q) => $q->where('mfg', 'like', '%'.trim($filters['mfg']).'%'));
        if (!empty($filters['date'])) $query->whereDate('planned_withdraw_date', $filters['date']);
        if (!empty($filters['type'])) $query->where('type_code', $filters['type']);
        match ($filters['sort'] ?? 'risk') {
            'mfg' => $query->join('stock_withdrawal_plans as sort_plan', 'sort_plan.id', '=', 'stock_withdrawal_plan_items.stock_withdrawal_plan_id')->orderBy('sort_plan.mfg'),
            'delivery_date' => $query->join('stock_withdrawal_plans as sort_plan', 'sort_plan.id', '=', 'stock_withdrawal_plan_items.stock_withdrawal_plan_id')->orderBy('sort_plan.delivery_date'),
            default => $query->orderBy('planned_withdraw_date')->orderBy('id'),
        };
        $items = $query->select('stock_withdrawal_plan_items.*')->paginate(50)->withQueryString();
        foreach ($items as $item) $item->day_offset = $service->overdueDays($item->planned_withdraw_date);

        return view('formstock.plans.index', [
            'items' => $items, 'filters' => $filters,
            'types' => StockWithdrawalType::query()->where('is_active', 1)->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('formstock.plans.create', ['types' => $this->activeTypes()]);
    }

    public function bom(string $site, int $workorderId, StockWithdrawalPlanningService $service)
    {
        $types = $this->activeTypes()->whereIn('code', ['FG', 'RM'])->keyBy('code');
        $items = $service->bomItems($site, $workorderId)->map(function ($item) use ($types) {
            $type = $types->get($item->type_code);
            return (array) $item + [
                'type_id' => $type?->id,
                'type_name' => $type?->name,
                'lead_time_days' => $type?->lead_time_days,
            ];
        });

        return response()->json(['items' => $items->values()]);
    }

    public function store(Request $request, StockWithdrawalPlanningService $service)
    {
        $data = $request->validate([
            'site' => ['required', Rule::in(['WIRE', 'PLUS'])], 'workorder_id' => ['required', 'integer'],
            'production_date' => ['required', 'date'], 'plan_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'], 'items.*.source_part_id' => ['required', 'integer'],
            'items.*.planned_withdraw_date' => ['required', 'date'], 'items.*.remark' => ['nullable', 'string', 'max:500'],
        ]);
        $mfg = $service->findMfg($data['site'], (int) $data['workorder_id']);
        if (!$mfg) throw ValidationException::withMessages(['workorder_id' => 'ไม่พบ MFG ใน Manufacturing Cost']);
        $types = $this->activeTypes()->whereIn('code', ['FG', 'RM'])->keyBy('code');
        $bomItems = $service->bomItems($data['site'], (int) $data['workorder_id'])->keyBy('source_part_id');
        foreach ($data['items'] as $item) {
            $bom = $bomItems->get((int) $item['source_part_id']);
            if (!$bom || !$types->has($bom->type_code)) {
                throw ValidationException::withMessages(['items' => 'พบ Part Number ที่ไม่อยู่ใน BOM หรือยังไม่ได้กำหนด Lead Time']);
            }
        }

        DB::connection('sqlsrv_menam')->transaction(function () use ($data, $mfg, $types, $bomItems, $service) {
            $plan = StockWithdrawalPlan::create([
                'site' => $data['site'], 'workorder_id' => $mfg->workorder_id, 'mfg' => $mfg->mfg,
                'partnumber' => $mfg->partnumber, 'part_description' => $mfg->part_description, 'size' => $mfg->size,
                'quantity' => $mfg->quantity, 'unit' => $mfg->unit, 'customer_name' => $mfg->customer_name,
                'delivery_date' => $mfg->delivery_date, 'production_date' => $data['production_date'],
                'mfg_note' => $mfg->mfg_note, 'plan_note' => $data['plan_note'] ?? null,
                'created_by' => auth()->id(), 'updated_by' => auth()->id(),
            ]);
            foreach ($data['items'] as $itemData) {
                $bom = $bomItems->get((int) $itemData['source_part_id']);
                $type = $types->get($bom->type_code);
                $plan->items()->create([
                    'stock_withdrawal_type_id' => $type->id, 'type_code' => $type->code, 'type_name' => $type->name,
                    'source_part_id' => $bom->source_part_id, 'partnumber' => $bom->partnumber,
                    'part_description' => $bom->part_description, 'size' => $bom->size,
                    'quantity' => $bom->quantity, 'unit' => $bom->unit,
                    'lead_time_days' => $type->lead_time_days,
                    'recommended_withdraw_date' => $service->subtractWorkingDays(Carbon::parse($data['production_date']), $type->lead_time_days)->toDateString(),
                    'planned_withdraw_date' => $itemData['planned_withdraw_date'], 'remark' => $itemData['remark'] ?? null,
                ]);
            }
        });
        return redirect()->route('stock-withdrawal.index')->with('success', 'เพิ่มแผนรายการเบิกแล้ว');
    }

    public function allMfg(Request $request, StockWithdrawalPlanningService $service)
    {
        $filters = $request->validate([
            'site' => ['nullable', 'in:WIRE,PLUS'], 'mfg' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:30'], 'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $filters += ['date_from' => now('Asia/Bangkok')->toDateString(), 'date_to' => now('Asia/Bangkok')->addDays(60)->toDateString()];
        $rows = $service->allMfg($filters);
        $planned = StockWithdrawalPlan::query()->with('items')->whereIn('mfg', $rows->pluck('mfg'))->get()->keyBy(fn($p) => $p->site.'|'.$p->mfg);
        $rows = $rows->map(function ($row) use ($planned) { $row->plan = $planned->get($row->site.'|'.$row->mfg); return $row; });
        if (!empty($filters['type'])) {
            $rows = $rows->filter(fn($row) => $row->plan?->items->contains('type_code', $filters['type']))->values();
        }
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);
        return view('formstock.plans.all-mfg', ['rows' => $rows, 'filters' => $filters, 'types' => $this->activeTypes()]);
    }

    public function export(Request $request, StockWithdrawalPlanningService $service): StreamedResponse
    {
        $rows = $service->allMfg($request->only('site', 'mfg', 'date_from', 'date_to'));
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Site','MFG','Part','Size','Quantity','Unit','Customer','Delivery Date','MFG Note']);
            foreach ($rows as $r) fputcsv($out, [$r->site,$r->mfg,$r->partnumber,$r->size,$r->quantity,$r->unit,$r->customer_name,$r->delivery_date,$r->mfg_note]);
            fclose($out);
        }, 'stock-withdrawal-mfg-'.now('Asia/Bangkok')->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function activeTypes()
    {
        $today = now('Asia/Bangkok')->toDateString();
        return StockWithdrawalType::query()->where('is_active', 1)->whereDate('effective_from', '<=', $today)
            ->where(fn($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->orderBy('name')->get();
    }
}
