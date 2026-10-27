<?php

namespace App\Http\Controllers\FormStock;

use App\Http\Controllers\Controller;
use App\Models\FormStock\StockWithdrawalPlan;
use App\Models\FormStock\StockWithdrawalPlanItem;
use App\Models\FormStock\StockWithdrawalType;
use App\Models\Users\User;
use App\Mail\StockWithdrawalFilteredAlertMail;
use App\Services\FormStock\StockWithdrawalPlanningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockWithdrawalPlanController extends Controller
{
    public function index(Request $request, StockWithdrawalPlanningService $service)
    {
        $readOnly = false;
        $this->rolloverQueue($service);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'site' => ['nullable', 'in:WIRE,PLUS'],
            'type' => ['nullable', 'string', 'max:30'], 'mfg' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['NOT_STARTED', 'PARTIAL'])],
            'section' => ['nullable', Rule::in(['1', '2'])], 'special' => ['nullable', Rule::in(['0', '1'])],
            'quick' => ['nullable', Rule::in(['today', 'backlog', 'special', 'unfinished', 'overdue'])],
            'per_page' => ['nullable', 'integer', Rule::in([50, 100, 150, 200])],
        ]);
        $filters += ['per_page' => 50];
        if (!$readOnly && empty($filters['date_from']) && empty($filters['date_to'])) {
            $filters['date_from'] = now('Asia/Bangkok')->toDateString();
            $filters['date_to'] = now('Asia/Bangkok')->toDateString();
        }
        $query = StockWithdrawalPlanItem::query()->with('plan')->whereNull('planner_closed_at')
            ->whereHas('plan', fn($q) => $q->whereNull('cancelled_at'));
        if (!empty($filters['site'])) $query->whereHas('plan', fn($q) => $q->where('site', $filters['site']));
        if (!empty($filters['mfg'])) $query->whereHas('plan', fn($q) => $q->where('mfg', 'like', '%'.trim($filters['mfg']).'%'));
        if (!empty($filters['date_from'])) $query->whereDate('queue_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('queue_date', '<=', $filters['date_to']);
        if (!empty($filters['section'])) $query->where('stock_withdrawal_plan_items.plan_section', $filters['section']);
        if (isset($filters['special']) && $filters['special'] !== '') $query->where('is_special', (int)$filters['special']);
        if (!empty($filters['type'])) $query->where('job_type_code', $filters['type']);
        $query->orderBy('stock_withdrawal_plan_items.queue_position')
            ->orderBy('stock_withdrawal_plan_items.id');
        $allItems = $query->select('stock_withdrawal_plan_items.*')->get();
        $allItems = $allItems->unique(fn($item) => implode('|', [
            $item->plan?->site,
            $item->plan?->workorder_id,
            $item->source_part_id ?: $item->partnumber,
        ]))->values();
        $this->attachWithdrawalBalances($allItems, $service);
        $allItems = $allItems->whereIn('withdrawal_status_code', ['NOT_STARTED', 'PARTIAL'])->values();
        $sentRows = DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_log_items as li')
            ->join('stock_withdrawal_plan_items as pi','pi.id','=','li.stock_withdrawal_plan_item_id')
            ->whereIn('pi.stock_withdrawal_plan_id', $allItems->pluck('stock_withdrawal_plan_id')->unique())->get(['li.stock_withdrawal_plan_item_id','pi.stock_withdrawal_plan_id']);
        $sentItemIds = $sentRows->pluck('stock_withdrawal_plan_item_id')->flip();
        $sentPlanIds = $sentRows->pluck('stock_withdrawal_plan_id')->flip();
        $closedPlanIds = $allItems->isEmpty() ? collect() : StockWithdrawalPlanItem::query()
            ->whereIn('stock_withdrawal_plan_id', $allItems->pluck('stock_withdrawal_plan_id')->unique())
            ->whereNotNull('planner_closed_at')->pluck('stock_withdrawal_plan_id')->flip();
        foreach ($allItems as $item) {
            $item->mail_sent = $sentItemIds->has($item->id);
            $item->plan_mail_sent = $sentPlanIds->has($item->stock_withdrawal_plan_id);
            $item->plan_cancel_locked = $item->plan_mail_sent || $closedPlanIds->has($item->stock_withdrawal_plan_id);
        }
        $latestLogs = $allItems->isEmpty() ? collect() : DB::connection('sqlsrv_menam')
            ->table('stock_withdrawal_item_action_logs')
            ->whereIn('stock_withdrawal_plan_item_id', $allItems->pluck('id'))
            ->orderByDesc('acted_at')->orderByDesc('id')->get()
            ->unique('stock_withdrawal_plan_item_id')->keyBy('stock_withdrawal_plan_item_id');
        $latestUsers = User::query()->whereIn('id', $latestLogs->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        foreach ($allItems as $item) {
            $latest = $latestLogs->get($item->id);
            $actor = $latest ? $latestUsers->get($latest->actor_id) : null;
            $item->latest_action = $latest->action ?? null;
            $item->latest_action_at = $latest->acted_at ?? null;
            $item->latest_actor_name = $latest ? ($actor->username ?? ($latest->actor_id ? 'User #'.$latest->actor_id : 'ระบบ')) : null;
        }
        if (!empty($filters['status'])) {
            $allItems = $allItems->where('withdrawal_status_code', $filters['status'])->values();
        }
        foreach ($allItems as $item) $item->day_offset = $service->overdueDays($item->planned_withdraw_date);
        $allItems = match ($filters['quick'] ?? null) {
            'backlog' => $allItems->filter(fn($item) => $item->original_queue_date?->lt($item->queue_date) ?? false)->values(),
            'special' => $allItems->where('is_special', true)->values(),
            'unfinished' => $allItems->whereIn('withdrawal_status_code', ['NOT_STARTED', 'PARTIAL'])->values(),
            'overdue' => $allItems->filter(fn($item) => $item->day_offset < 0 && !in_array($item->withdrawal_status_code, ['COMPLETED', 'PLANNER_CLOSED'], true))->values(),
            default => $allItems,
        };
        $allItems = $allItems->sortByDesc(fn($item) => (int) $item->is_special)->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = (int) $filters['per_page'];
        $items = new LengthAwarePaginator($allItems->forPage($page, $perPage)->values(), $allItems->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
        $mailLogs = DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_logs')
            ->orderByDesc('run_at')->orderByDesc('id')->limit(30)->get();
        $mailUsers = User::query()->whereIn('id', $mailLogs->pluck('sent_by')->filter()->unique())->get()->keyBy('id');
        foreach ($mailLogs as $log) {
            $user = $mailUsers->get($log->sent_by);
            $log->sent_by_username = $user->username ?? ($log->sent_by ? 'User #'.$log->sent_by : 'ระบบ');
        }

        return view('formstock.plans.index', [
            'items' => $items, 'filters' => $filters,
            'jobTypes' => config('stock-withdrawal.job_types'), 'readOnly' => $readOnly,
            'refreshedAt' => now('Asia/Bangkok'), 'mailLogs' => $mailLogs,
        ]);
    }

    public function create(Request $request, StockWithdrawalPlanningService $service)
    {
        $selected = collect($request->query('selected', []));
        if ($request->filled('site') && $request->filled('workorder_id')) {
            $selected->push(strtoupper((string) $request->query('site')).'|'.(int) $request->query('workorder_id'));
        }
        $mfgs = $selected->unique()->take(50)->map(function ($value) use ($service) {
            [$site, $workorderId] = array_pad(explode('|', (string) $value, 2), 2, null);
            if (!in_array($site, ['WIRE', 'PLUS'], true) || !(int) $workorderId) return null;
            $mfg = $service->findMfg($site, (int) $workorderId);
            if (!$mfg) return null;
            $mfg->site = $site;
            return $mfg;
        })->filter()->values();
        return view('formstock.plans.create', ['types' => $this->activeTypes(), 'mfgs' => $mfgs]);
    }

    public function bom(string $site, int $workorderId, StockWithdrawalPlanningService $service)
    {
        $types = $this->activeTypes()->whereIn('code', ['FG', 'RM'])->keyBy('code');
        $mfg = $service->findMfg($site, $workorderId);
        $items = $service->bomItems($site, $workorderId)->map(function ($item) use ($types, $service, $mfg) {
            $type = $types->get(in_array($item->type_code, ['RM', 'ACID'], true) ? 'RM' : 'FG');
            $jobType = $service->suggestedJobType($mfg?->fcat, $item->partnumber);
            return (array) $item + [
                'type_id' => $type?->id,
                'type_name' => $item->type_name,
                'job_type_code' => $jobType['code'], 'job_type_name' => $jobType['name'],
                'lead_time_days' => $jobType['lead_time_days'],
            ];
        });

        return response()->json(['items' => $items->values(), 'job_types' => config('stock-withdrawal.job_types')]);
    }

    public function store(Request $request, StockWithdrawalPlanningService $service)
    {
        $data = $request->validate([
            'plans' => ['required', 'array', 'min:1', 'max:50'],
            'plans.*.site' => ['required', Rule::in(['WIRE', 'PLUS'])], 'plans.*.workorder_id' => ['required', 'integer'],
            'plans.*.production_date' => ['required', 'date'], 'plans.*.delivery_date' => ['required', 'date'],
            'plans.*.plan_note' => ['nullable', 'string', 'max:2000'],
            'plans.*.items' => ['required', 'array', 'min:1'], 'plans.*.items.*.source_part_id' => ['required', 'integer'],
            'plans.*.items.*.job_type_code' => ['required', Rule::in(array_keys(config('stock-withdrawal.job_types')))],
            'plans.*.items.*.split_quantities' => ['required', 'array', 'min:1'],
            'plans.*.items.*.split_quantities.*' => ['required', 'numeric', 'gt:0'],
            'plans.*.items.*.request_date' => ['required', 'date'], 'plans.*.items.*.remark' => ['nullable', 'string', 'max:500'],
            'plans.*.items.*.is_special' => ['nullable', 'boolean'],
        ]);
        $types = $this->activeTypes()->whereIn('code', ['FG', 'RM'])->keyBy('code');
        $prepared = [];
        foreach ($data['plans'] as $planData) {
            $mfg = $service->findMfg($planData['site'], (int) $planData['workorder_id']);
            if (!$mfg) throw ValidationException::withMessages(['plans' => 'ไม่พบ MFG ใน Manufacturing Cost']);
            $bomItems = $service->bomItems($planData['site'], (int) $planData['workorder_id'])->keyBy('source_part_id');
            foreach ($planData['items'] as $itemIndex => $item) {
                $bom = $bomItems->get((int) $item['source_part_id']);
                $legacyTypeCode = in_array($bom?->type_code, ['RM', 'ACID'], true) ? 'RM' : 'FG';
                if (!$bom || !$types->has($legacyTypeCode)) throw ValidationException::withMessages(['plans' => 'พบ Part ที่ไม่อยู่ใน BOM หรือไม่มี Lead Time']);
                $item['withdraw_quantity'] = collect($item['split_quantities'])->sum(fn($qty) => (float) $qty);
                $item['plan_section'] = (int) $bom->plan_section;
                if ((float) $item['withdraw_quantity'] > (float) $bom->quantity) {
                    throw ValidationException::withMessages(['plans' => "จำนวนเบิก {$bom->partnumber} ห้ามเกิน BOM Qty ".number_format((float) $bom->quantity, 4)]);
                }
                $item['is_split'] = count($item['split_quantities']) > 1;
                $planData['items'][$itemIndex] = $item;
            }
            $prepared[] = compact('planData', 'mfg', 'bomItems');
        }

        DB::connection('sqlsrv_menam')->transaction(function () use ($prepared, $types, $service) {
          foreach ($prepared as $entry) {
            $planData = $entry['planData']; $mfg = $entry['mfg']; $bomItems = $entry['bomItems'];
            $lock = DB::connection('sqlsrv_menam')->selectOne(
                "DECLARE @result int; EXEC @result = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000; SELECT @result AS result",
                ['stock-withdrawal-plan:'.$planData['site'].':'.$mfg->workorder_id]
            );
            if ((int)($lock->result ?? -999) < 0) throw ValidationException::withMessages(['plans' => "ไม่สามารถล็อก MFG {$mfg->mfg} เพื่อบันทึก กรุณาลองใหม่"]);
            $existing = StockWithdrawalPlan::query()->where('site', $planData['site'])->where('workorder_id', $mfg->workorder_id)->whereNull('cancelled_at')->exists();
            if ($existing) throw ValidationException::withMessages(['plans' => "MFG {$mfg->mfg} มีแผนเบิกแล้ว"]);
            $plan = StockWithdrawalPlan::create([
                'site' => $planData['site'], 'workorder_id' => $mfg->workorder_id, 'mfg' => $mfg->mfg, 'fcat' => $mfg->fcat,
                'plan_section' => collect($planData['items'])->pluck('plan_section')->unique()->count() === 1
                    ? (int) $planData['items'][0]['plan_section'] : 0,
                'priority' => 'NORMAL',
                'partnumber' => $mfg->partnumber, 'part_description' => $mfg->part_description, 'size' => $mfg->size,
                'quantity' => $mfg->quantity, 'unit' => $mfg->unit, 'customer_name' => $mfg->customer_name,
                'delivery_date' => $planData['delivery_date'], 'original_delivery_date' => $mfg->delivery_date, 'production_date' => $planData['production_date'],
                'mfg_note' => $mfg->mfg_note, 'plan_note' => $planData['plan_note'] ?? null,
                'created_by' => auth()->id(), 'updated_by' => auth()->id(),
            ]);
            foreach ($planData['items'] as $itemData) {
                $bom = $bomItems->get((int) $itemData['source_part_id']);
                $type = $types->get(in_array($bom->type_code, ['RM', 'ACID'], true) ? 'RM' : 'FG');
                $jobType = config('stock-withdrawal.job_types.'.$itemData['job_type_code']);
                $leadDays = ($itemData['is_split'] || str_starts_with(ltrim($mfg->mfg, '+'), 'S')) ? 3 : (int) $jobType['lead_time_days'];
                $completionDate = $service->completionDate(Carbon::parse($itemData['request_date']), $leadDays)->toDateString();
                $isSpecial = (bool) ($itemData['is_special'] ?? false);
                $queueDate = $isSpecial ? now('Asia/Bangkok')->toDateString() : $itemData['request_date'];
                $plannedDate = $isSpecial ? now('Asia/Bangkok')->toDateString() : $completionDate;
                $queuePosition = (int) StockWithdrawalPlanItem::query()->whereDate('queue_date', $queueDate)->max('queue_position') + 1;
                $createdItem = $plan->items()->create([
                    'stock_withdrawal_type_id' => $type->id, 'type_code' => $bom->type_code, 'type_name' => $bom->type_name,
                    'job_type_code' => $itemData['job_type_code'], 'job_type_name' => $jobType['name'],
                    'plan_section' => (int) $itemData['plan_section'], 'is_split' => $itemData['is_split'],
                    'source_part_id' => $bom->source_part_id, 'partnumber' => $bom->partnumber,
                    'part_description' => $bom->part_description, 'size' => $bom->size,
                    'quantity' => $bom->quantity, 'withdraw_quantity' => $itemData['withdraw_quantity'], 'split_quantities' => $itemData['split_quantities'], 'unit' => $bom->unit,
                    'lead_time_days' => $leadDays, 'request_date' => $itemData['request_date'],
                    'queue_date' => $queueDate, 'original_queue_date' => $itemData['request_date'], 'queue_position' => $queuePosition,
                    'is_special' => $isSpecial, 'special_note' => $isSpecial ? ($itemData['remark'] ?? null) : null,
                    'recommended_withdraw_date' => $completionDate,
                    'planned_withdraw_date' => $plannedDate, 'remark' => $itemData['remark'] ?? null,
                ]);
                $this->logItemAction($createdItem, 'CREATE', $isSpecial ? ($itemData['remark'] ?? 'สร้างเป็น Special') : null, [], $createdItem->toArray(), now('Asia/Bangkok'));
            }
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
            'planned' => ['nullable', Rule::in(['0', '1'])],
            'production_type' => ['nullable', Rule::in(['FG', 'NCR', 'ACID', 'RM', 'S'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100, 200])],
        ]);
        $filters += ['date_from' => now('Asia/Bangkok')->toDateString(), 'date_to' => now('Asia/Bangkok')->addDays(60)->toDateString(), 'per_page' => 20];
        $rows = $this->availableMfgRows($service->allMfg($filters), $service);
        if (!empty($filters['type'])) {
            $rows = $rows->filter(fn($row) => $service->suggestedJobType($row->fcat, $row->partnumber)['code'] === $filters['type'])->values();
        }
        if (!empty($filters['production_type'])) {
            $rows = $rows->where('production_type_code', $filters['production_type'])->values();
        }
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $filters['per_page'];
        $rows = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
        return view('formstock.plans.all-mfg', ['rows' => $rows, 'filters' => $filters, 'jobTypes' => config('stock-withdrawal.job_types')]);
    }

    public function export(Request $request, StockWithdrawalPlanningService $service): StreamedResponse
    {
        $filters = $request->validate([
            'site' => ['nullable', 'in:WIRE,PLUS'], 'mfg' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:30'], 'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'planned' => ['nullable', Rule::in(['0', '1'])],
            'production_type' => ['nullable', Rule::in(['FG', 'NCR', 'ACID', 'RM', 'S'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100, 200])],
        ]);
        $rows = $this->availableMfgRows($service->allMfg($filters), $service);
        if (!empty($filters['type'])) {
            $rows = $rows->filter(fn ($row) => $service->suggestedJobType($row->fcat, $row->partnumber)['code'] === $filters['type'])->values();
        }
        if (!empty($filters['production_type'])) {
            $rows = $rows->where('production_type_code', $filters['production_type'])->values();
        }
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Site','MFG','Work Group','Current Work Center','Part','Size','Quantity','Unit','Customer','Delivery Date','MFG Note']);
            foreach ($rows as $r) fputcsv($out, [$r->site,$r->mfg,$r->production_type_name,$r->current_work_center,$r->partnumber,$r->size,$r->quantity,$r->unit,$r->customer_name,$r->delivery_date,$r->mfg_note]);
            fclose($out);
        }, 'stock-withdrawal-mfg-'.now('Asia/Bangkok')->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cancel(Request $request, StockWithdrawalPlan $plan)
    {
        abort_if($plan->cancelled_at, 422, 'รายการนี้ถูกยกเลิกแล้ว');
        $sent = DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_log_items as li')
            ->join('stock_withdrawal_plan_items as pi', 'pi.id', '=', 'li.stock_withdrawal_plan_item_id')
            ->where('pi.stock_withdrawal_plan_id', $plan->id)->exists();
        abort_if($sent, 422, 'MFG นี้มีรายการส่งเมลแล้ว ไม่สามารถยกเลิกได้');
        abort_if($plan->items()->whereNotNull('planner_closed_at')->exists(), 422, 'MFG นี้มีรายการปิดโดย Planner แล้ว ไม่สามารถยกเลิกได้');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::connection('sqlsrv_menam')->transaction(function () use ($plan, $data) {
            $before = $plan->toArray();
            $now = now('Asia/Bangkok');
            $plan->update(['cancelled_at' => $now, 'cancelled_by' => auth()->id(), 'cancellation_reason' => $data['reason'], 'updated_by' => auth()->id()]);
            foreach ($plan->items as $item) $this->logItemAction($item, 'CANCEL', $data['reason'], ['plan'=>$before], ['plan'=>$plan->fresh()->toArray()], $now);
        });
        return back()->with('success', "ยกเลิกแผน {$plan->mfg} แล้ว");
    }

    public function reopenCancelledPlan(Request $request, StockWithdrawalPlan $plan)
    {
        abort_unless(auth()->user()?->can('STOCK_ADMIN'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        DB::connection('sqlsrv_menam')->transaction(function () use ($plan, $data) {
            $lockedPlan = StockWithdrawalPlan::query()->with('items')->lockForUpdate()->findOrFail($plan->id);
            abort_if(!$lockedPlan->cancelled_at, 422, 'แผนนี้ไม่ได้อยู่ในสถานะยกเลิก');
            $duplicate = StockWithdrawalPlan::query()
                ->where('site', $lockedPlan->site)
                ->where('workorder_id', $lockedPlan->workorder_id)
                ->where('id', '<>', $lockedPlan->id)
                ->whereNull('cancelled_at')
                ->lockForUpdate()
                ->exists();
            abort_if($duplicate, 422, "MFG {$lockedPlan->mfg} มีแผนที่ใช้งานอยู่แล้ว ไม่สามารถเปิดแผนเก่ากลับได้");

            $before = $lockedPlan->toArray();
            $now = now('Asia/Bangkok');
            $lockedPlan->update([
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'updated_by' => auth()->id(),
            ]);
            $after = $lockedPlan->fresh()->toArray();
            foreach ($lockedPlan->items as $item) {
                $this->logItemAction($item, 'REOPEN_CANCEL', $data['reason'], ['plan' => $before], ['plan' => $after], $now);
            }
        });

        return back()->with('success', "เปิดแผน {$plan->mfg} กลับแล้ว");
    }

    public function sendMail(Request $request, StockWithdrawalPlanningService $service)
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);
        [$dateFrom, $dateTo] = $this->mailQueueDateRange($filters);
        $dates = $dateFrom === $dateTo ? [$dateFrom] : [$dateFrom, $dateTo];
        $query = StockWithdrawalPlanItem::query()->with('plan')
            ->whereBetween('queue_date', [$dateFrom, $dateTo])
            ->whereNull('planner_closed_at')
            ->whereHas('plan', fn($q) => $q->whereNull('cancelled_at'));
        $items = $query->get();
        $this->attachWithdrawalBalances($items, $service);
        $items = $items->reject(fn($item) => $item->withdrawal_status_code === 'COMPLETED');
        $groups = $this->mailGroups($items);
        if (!$groups) return back()->withErrors(['mail' => 'ไม่พบรายการที่ยังเบิกไม่ครบในช่วงวันที่ที่ Filter ไว้']);
        $riskCounts = $service->alertRiskCounts($items);
        $attachments = [];
        foreach ($groups as $date => $rows) $attachments[] = ['name'=>"stock-withdrawal-{$date}.xlsx", 'content'=>$this->xlsxContent($rows)];
        $recipient = config('stock-withdrawal.fallback_email');
        try {
            Mail::to($recipient)->send(new StockWithdrawalFilteredAlertMail($groups, $attachments));
            DB::connection('sqlsrv_menam')->transaction(function () use ($recipient, $items, $attachments, $filters, $dates, $riskCounts) {
                $now = now('Asia/Bangkok');
                $logId = DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_logs')->insertGetId(array_merge(['run_at'=>$now,'recipient'=>$recipient,'sent_by'=>auth()->id(),'item_count'=>$items->count(),'status'=>'SENT','mail_type'=>'AUTO','attachment_name'=>implode(',',array_column($attachments,'name')),'payload'=>json_encode(['filters'=>$filters,'dates'=>$dates]),'created_at'=>$now,'updated_at'=>$now], $riskCounts));
                $rows = $items->map(fn($item) => ['stock_withdrawal_alert_log_id'=>$logId,'stock_withdrawal_plan_item_id'=>$item->id,'snapshot'=>json_encode(['mfg'=>$item->plan->mfg,'partnumber'=>$item->partnumber,'quantity'=>(float)($item->withdraw_quantity??$item->quantity),'request_date'=>$item->request_date?->toDateString(),'due_date'=>$item->planned_withdraw_date?->toDateString()]),'created_at'=>$now,'updated_at'=>$now])->all();
                if ($rows) DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_log_items')->insert($rows);
            });
        } catch (\Throwable $e) {
            DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_logs')->insert(array_merge(['run_at'=>now('Asia/Bangkok'),'recipient'=>$recipient,'sent_by'=>auth()->id(),'item_count'=>$items->count(),'status'=>'FAILED','mail_type'=>'AUTO','error_message'=>mb_substr($e->getMessage(),0,2000),'payload'=>json_encode(['filters'=>$filters,'dates'=>$dates]),'created_at'=>now(),'updated_at'=>now()], $riskCounts));
            return back()->withErrors(['mail' => 'ส่งเมลไม่สำเร็จ: '.$e->getMessage()]);
        }
        return back()->with('success', "ส่งเมล {$items->count()} รายการไป {$recipient} แล้ว");
    }

    private function mailQueueDateRange(array $filters): array
    {
        return [
            Carbon::parse($filters['date_from'])->toDateString(),
            Carbon::parse($filters['date_to'])->toDateString(),
        ];
    }

    public function closeItems(Request $request, StockWithdrawalPlanningService $service)
    {
        $data = $request->validate(['item_ids'=>['required','array','min:1'],'item_ids.*'=>['integer'],'reason'=>['required','string','max:500']]);
        $items = StockWithdrawalPlanItem::query()->whereIn('id', $data['item_ids'])->whereNull('planner_closed_at')->get();
        $this->attachWithdrawalBalances($items, $service);
        $items = $items->whereIn('withdrawal_status_code', ['NOT_STARTED', 'PARTIAL'])->values();
        abort_if($items->isEmpty(), 422, 'ไม่พบรายการที่สามารถปิดได้');
        DB::connection('sqlsrv_menam')->transaction(function () use ($items, $data) {
            $now = now('Asia/Bangkok');
            foreach ($items as $item) {
                $before = $item->toArray();
                $item->update(['planner_closed_at'=>$now,'planner_closed_by'=>auth()->id(),'planner_close_reason'=>$data['reason']]);
                $this->logItemAction($item, 'CLOSE', $data['reason'], $before, $item->fresh()->toArray(), $now);
            }
        });
        return back()->with('success', 'ปิดโดย Planner แล้ว '.$items->count().' รายการ');
    }

    public function reopenItems(Request $request)
    {
        abort_unless(auth()->user()?->can('STOCK_ADMIN'), 403);
        $data = $request->validate(['item_ids'=>['required','array','min:1'],'item_ids.*'=>['integer'],'reason'=>['required','string','max:500']]);
        $items = StockWithdrawalPlanItem::query()->whereIn('id', $data['item_ids'])->whereNotNull('planner_closed_at')->get();
        abort_if($items->isEmpty(), 422, 'ไม่พบรายการที่สามารถเปิดกลับได้');
        DB::connection('sqlsrv_menam')->transaction(function () use ($items, $data) {
            $now = now('Asia/Bangkok');
            foreach ($items as $item) {
                $before = $item->toArray();
                $item->update(['planner_closed_at'=>null,'planner_closed_by'=>null,'planner_close_reason'=>null]);
                $this->logItemAction($item, 'REOPEN', $data['reason'], $before, $item->fresh()->toArray(), $now);
            }
        });
        return back()->with('success', 'เปิดงานกลับแล้ว '.$items->count().' รายการ');
    }

    public function rolloverQueue(StockWithdrawalPlanningService $service): int
    {
        $today = now('Asia/Bangkok')->startOfDay();
        if ($today->isSunday()) return 0;
        $items = StockWithdrawalPlanItem::query()->with('plan')
            ->whereDate('queue_date', '<', $today->toDateString())->whereNull('planner_closed_at')
            ->whereHas('plan', fn($q) => $q->whereNull('cancelled_at'))
            ->orderBy('queue_date')->orderBy('queue_position')->get();
        if ($items->isEmpty()) return 0;
        $this->attachWithdrawalBalances($items, $service);
        $items = $items->whereIn('withdrawal_status_code', ['NOT_STARTED','PARTIAL'])->values();
        if ($items->isEmpty()) return 0;
        $start = (int) StockWithdrawalPlanItem::query()->whereDate('queue_date', $today->toDateString())->max('queue_position');
        DB::connection('sqlsrv_menam')->transaction(function () use ($items, $today, $start) {
            foreach ($items as $offset => $item) {
                $before = $item->toArray();
                $item->update(['queue_date'=>$today->toDateString(),'queue_position'=>$start+$offset+1]);
                $this->logItemAction($item, 'ROLLOVER', 'ยกงานค้างเข้าคิว '.$today->format('d/m/Y'), $before, $item->fresh()->toArray(), now('Asia/Bangkok'));
            }
        });
        return $items->count();
    }

    public function reorderItems(Request $request)
    {
        $data = $request->validate(['queue_date'=>['required','date'],'item_ids'=>['required','array','min:1'],'item_ids.*'=>['integer'],'versions'=>['required','array'],'versions.*'=>['nullable','string','max:40']]);
        $versions = DB::connection('sqlsrv_menam')->transaction(function () use ($data) {
            $items = StockWithdrawalPlanItem::query()->whereIn('id',$data['item_ids'])->whereDate('queue_date',$data['queue_date'])
                ->whereNull('planner_closed_at')->whereHas('plan', fn($query) => $query->whereNull('cancelled_at'))
                ->orderBy('queue_position')->lockForUpdate()->get()->keyBy('id');
            abort_if($items->count() !== count(array_unique($data['item_ids'])), 422, 'พบรายการข้ามวันหรือไม่ครบ');
            foreach ($items as $item) abort_if($item->updated_at?->format('Y-m-d H:i:s.u') !== ($data['versions'][$item->id] ?? null), 409, 'คิวถูกแก้ไขโดยผู้ใช้อื่น กรุณาโหลดหน้าใหม่');
            $positions = $items->pluck('queue_position')->sort()->values();
            $orderedIds = collect($data['item_ids'])->sortByDesc(fn($id) => (int) $items[$id]->is_special)->values();
            foreach ($orderedIds as $offset=>$id) {
                $item = $items[$id]; $before = $item->toArray();
                $item->update(['queue_position'=>$positions[$offset]]);
                if ((int)$before['queue_position'] !== (int)$positions[$offset]) $this->logItemAction($item, 'REORDER', 'จัดลำดับคิวด้วยการลาก', $before, $item->fresh()->toArray(), now('Asia/Bangkok'));
            }
            return StockWithdrawalPlanItem::query()->whereIn('id',$data['item_ids'])->get()->mapWithKeys(fn($item)=>[$item->id=>$item->updated_at?->format('Y-m-d H:i:s.u')]);
        });
        return response()->json(['ok'=>true,'versions'=>$versions]);
    }

    public function updateSpecial(Request $request, StockWithdrawalPlanItem $item, StockWithdrawalPlanningService $service)
    {
        abort_if($item->planner_closed_at, 422, 'รายการนี้ปิดโดย Planner แล้ว');
        $item->load('plan');
        $this->attachWithdrawalBalances(collect([$item]), $service);
        abort_if($item->withdrawal_status_code === 'COMPLETED', 422, 'รายการนี้เบิกครบแล้ว');
        $data = $request->validate(['is_special'=>['nullable','boolean'],'special_note'=>['nullable','string','max:500']]);
        $before = $item->toArray(); $now = now('Asia/Bangkok');
        $isSpecial = (bool)($data['is_special']??false);
        $updates = ['is_special'=>$isSpecial,'special_note'=>$data['special_note']??null];
        if ($isSpecial) {
            $today = $now->toDateString();
            $updates['queue_date'] = $today;
            $updates['planned_withdraw_date'] = $today;
            if (($item->queue_date?->toDateString()) !== $today) $updates['queue_position'] = (int) StockWithdrawalPlanItem::query()->whereDate('queue_date',$today)->max('queue_position') + 1;
        }
        $item->update($updates);
        $this->logItemAction($item, 'SPECIAL', $data['special_note']??null, $before, $item->fresh()->toArray(), $now);
        return back()->with('success', 'อัปเดต Special '.$item->partnumber.' แล้ว');
    }

    public function sendSpecialMail(Request $request, StockWithdrawalPlanningService $service)
    {
        $data = $request->validate(['item_ids'=>['required','array','min:1'],'item_ids.*'=>['integer']]);
        $items = StockWithdrawalPlanItem::query()->with('plan')->whereIn('id',$data['item_ids'])->where('is_special',1)
            ->whereNull('planner_closed_at')->whereHas('plan',fn($q)=>$q->whereNull('cancelled_at'))->get();
        $this->attachWithdrawalBalances($items,$service);
        $items = $items->reject(fn($item)=>$item->withdrawal_status_code==='COMPLETED')->values();
        abort_if($items->isEmpty(),422,'ไม่พบ Special ที่ยังเบิกไม่ครบ');
        $groups = $this->mailGroups($items);
        $rows = collect($groups)->flatten(1)->values()->all();
        $attachments = [['name'=>'stock-withdrawal-special-'.now('Asia/Bangkok')->format('Y-m-d').'.xlsx','content'=>$this->xlsxContent($rows)]];
        $recipient = config('stock-withdrawal.fallback_email'); $riskCounts=$service->alertRiskCounts($items);
        try {
            Mail::to($recipient)->send(new StockWithdrawalFilteredAlertMail($groups,$attachments,'SPECIAL'));
            DB::connection('sqlsrv_menam')->transaction(function () use ($items,$recipient,$attachments,$riskCounts) {
                $now=now('Asia/Bangkok');
                $logId=DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_logs')->insertGetId(array_merge(['run_at'=>$now,'recipient'=>$recipient,'sent_by'=>auth()->id(),'item_count'=>$items->count(),'status'=>'SENT','mail_type'=>'SPECIAL','attachment_name'=>$attachments[0]['name'],'payload'=>json_encode(['manual'=>true]),'created_at'=>$now,'updated_at'=>$now],$riskCounts));
                DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_log_items')->insert($items->map(fn($item)=>['stock_withdrawal_alert_log_id'=>$logId,'stock_withdrawal_plan_item_id'=>$item->id,'snapshot'=>json_encode(['mfg'=>$item->plan->mfg,'partnumber'=>$item->partnumber,'special_note'=>$item->special_note]),'created_at'=>$now,'updated_at'=>$now])->all());
                StockWithdrawalPlanItem::query()->whereIn('id',$items->pluck('id'))->update(['special_mail_sent_at'=>$now]);
            });
        } catch (\Throwable $e) {
            $now=now('Asia/Bangkok');
            DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_logs')->insert(array_merge(['run_at'=>$now,'recipient'=>$recipient,'sent_by'=>auth()->id(),'item_count'=>$items->count(),'status'=>'FAILED','mail_type'=>'SPECIAL','attachment_name'=>$attachments[0]['name'],'error_message'=>mb_substr($e->getMessage(),0,2000),'payload'=>json_encode(['manual'=>true]),'created_at'=>$now,'updated_at'=>$now],$riskCounts));
            return back()->withErrors(['mail'=>'ส่งเมลด่วนไม่สำเร็จ: '.$e->getMessage()]);
        }
        return back()->with('success','ส่งเมลด่วน Special '.$items->count().' รายการแล้ว');
    }

    public function updateItem(Request $request, StockWithdrawalPlanItem $item, StockWithdrawalPlanningService $service)
    {
        abort_if($item->planner_closed_at, 422, 'รายการนี้ปิดโดย Planner แล้ว');
        $item->load('plan');
        $this->attachWithdrawalBalances(collect([$item]), $service);
        abort_if($item->withdrawal_status_code === 'COMPLETED', 422, 'รายการนี้เบิกครบแล้ว');
        $sent = DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_log_items')->where('stock_withdrawal_plan_item_id', $item->id)->exists();
        abort_if($sent, 422, 'รายการนี้ส่งเมลแล้ว ไม่สามารถแก้ไขได้');
        $planSent = DB::connection('sqlsrv_menam')->table('stock_withdrawal_alert_log_items as li')->join('stock_withdrawal_plan_items as pi','pi.id','=','li.stock_withdrawal_plan_item_id')->where('pi.stock_withdrawal_plan_id',$item->stock_withdrawal_plan_id)->exists();
        $rules = ['job_type_code'=>['required',Rule::in(array_keys(config('stock-withdrawal.job_types')))],'withdraw_quantity'=>['required','numeric','gt:0','lte:'.$item->quantity],'request_date'=>['required','date'],'remark'=>['nullable','string','max:500']];
        if (!$planSent) $rules += ['delivery_date'=>['required','date'],'production_date'=>['required','date'],'plan_note'=>['nullable','string','max:2000']];
        $data = $request->validate($rules);
        $before = ['item'=>$item->toArray(),'plan'=>$item->plan->toArray()];
        $currentWorkCenter = $service->currentWorkCenter($item->plan->site, (int) $item->plan->workorder_id);
        $classification = $service->withdrawalClassification($item->partnumber, $currentWorkCenter->workcenternumber ?? null, $currentWorkCenter->workcenter_name ?? null);
        DB::connection('sqlsrv_menam')->transaction(function () use ($item, $data, $planSent, $service, $before, $classification) {
            $jobType = config('stock-withdrawal.job_types.'.$data['job_type_code']);
            $leadDays = ($item->is_split || str_starts_with(ltrim($item->plan->mfg, '+'), 'S')) ? 3 : (int)$jobType['lead_time_days'];
            $completion = $service->completionDate(Carbon::parse($data['request_date']), $leadDays)->toDateString();
            $queueDate = $item->is_special ? now('Asia/Bangkok')->toDateString() : $data['request_date'];
            $plannedDate = $item->is_special ? now('Asia/Bangkok')->toDateString() : $completion;
            $queuePosition = (int) StockWithdrawalPlanItem::query()
                ->whereDate('queue_date', $queueDate)
                ->where('id', '<>', $item->id)
                ->max('queue_position') + 1;
            $item->update(['type_code'=>$classification['code'],'type_name'=>$classification['name'],'plan_section'=>$classification['section'],'job_type_code'=>$data['job_type_code'],'job_type_name'=>$jobType['name'],'withdraw_quantity'=>$data['withdraw_quantity'],'lead_time_days'=>$leadDays,'request_date'=>$data['request_date'],'queue_date'=>$queueDate,'original_queue_date'=>$item->original_queue_date ?: $data['request_date'],'queue_position'=>$queuePosition,'recommended_withdraw_date'=>$completion,'planned_withdraw_date'=>$plannedDate,'remark'=>$data['remark']??null]);
            $sections = $item->plan->items()->pluck('plan_section')->unique()->values();
            $planChanges = ['plan_section' => $sections->count() === 1 ? (int) $sections->first() : 0, 'updated_by' => auth()->id()];
            if (!$planSent) $planChanges += ['delivery_date'=>$data['delivery_date'],'production_date'=>$data['production_date'],'plan_note'=>$data['plan_note']??null];
            $item->plan->update($planChanges);
            $this->logItemAction($item, 'UPDATE', null, $before, ['item'=>$item->fresh()->toArray(),'plan'=>$item->plan->fresh()->toArray()], now('Asia/Bangkok'));
        });
        return back()->with('success', 'แก้ไขรายการ '.$item->partnumber.' แล้ว');
    }

    private function mailGroups($items): array
    {
        return $items->sortBy(fn($item)=>sprintf('%s|%01d|%08d|%08d',
            $item->queue_date?->format('Y-m-d') ?? $item->request_date?->format('Y-m-d') ?? '9999-12-31',
            $item->is_special ? 0 : 1,
            $item->queue_position,
            $item->id
        ))->groupBy(fn($item)=>$item->queue_date?->format('Y-m-d')??$item->request_date->format('Y-m-d'))
            ->map(fn($rows)=>$rows->map(fn($item)=>[
                'site'=>$item->plan->site,'mfg'=>$item->plan->mfg,'customer'=>$item->plan->customer_name,
                'partnumber'=>$item->partnumber,'job_type'=>$item->job_type_name,'quantity'=>(float)($item->withdraw_quantity??$item->quantity),
                'remaining_quantity'=>(float)$item->remaining_qty,'status'=>$item->withdrawal_status,'unit'=>$item->unit_label,
                'lead_time_days'=>$item->lead_time_days,'due_date'=>$item->planned_withdraw_date?->format('d/m/Y'),
                'request_date'=>$item->request_date?->format('d/m/Y'),'queue_date'=>$item->queue_date?->format('d/m/Y'),
                'production_date'=>$item->plan->production_date?->format('d/m/Y'),'delivery_date'=>$item->plan->delivery_date?->format('d/m/Y'),
                'section'=>$item->plan_section,'is_special'=>(bool)$item->is_special,'special_note'=>$item->special_note,
                'is_backlog'=>$item->original_queue_date?->lt($item->queue_date)??false,
            ])->values()->all())->all();
    }

    private function xlsxContent(array $rows): string
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $headers = ['คิว','Special','MFG','โรงงาน','ลูกค้า','Part Number','ประเภทงาน','จำนวนที่ต้องเบิก','จำนวนคงเหลือ','หน่วย','วันแจ้ง','วันที่ต้องเบิก','วันที่เข้าคิว','วันขึ้นผลิต','วันส่งมอบ','สถานะ','หมายเหตุ'];
        $writeSheet = function ($worksheet, string $title, array $sheetRows) use ($headers): void {
            $worksheet->setTitle($title);
            $worksheet->fromArray($headers, null, 'A1');
            $line = 2;
            foreach ($sheetRows as $row) {
                $worksheet->fromArray([
                    $row['is_backlog']?'งานค้าง':'วันนี้', $row['is_special']?'Special':'', $row['mfg'], $row['site'] ?? '', $row['customer'] ?? '', $row['partnumber'],
                    $row['job_type'], $row['quantity'], $row['remaining_quantity'] ?? $row['quantity'], $row['unit'],
                    $row['request_date'], $row['due_date'], $row['queue_date'], $row['production_date'], $row['delivery_date'], $row['status'] ?? 'ยังไม่เบิก', $row['special_note'] ?? '',
                ], null, 'A'.$line++);
            }
            $lastRow = max(1, $line - 1);
            $worksheet->freezePane('A2');
            $worksheet->setAutoFilter("A1:Q{$lastRow}");
            $worksheet->getStyle('A1:Q1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $worksheet->getStyle('A1:Q1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF0F766E');
            foreach (range('A', 'Q') as $column) $worksheet->getColumnDimension($column)->setAutoSize(true);
        };

        $writeSheet($book->getActiveSheet(), 'คิวทั้งหมด', $rows);
        $writeSheet($book->createSheet(), 'งานค้าง', array_values(array_filter($rows, fn($row) => $row['is_backlog'])));
        $writeSheet($book->createSheet(), 'Special', array_values(array_filter($rows, fn($row) => $row['is_special'])));
        $book->setActiveSheetIndex(0);

        ob_start();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        return (string) ob_get_clean();
    }

    private function activeTypes()
    {
        $today = now('Asia/Bangkok')->toDateString();
        return StockWithdrawalType::query()->where('is_active', 1)->whereDate('effective_from', '<=', $today)
            ->where(fn($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->orderBy('name')->get();
    }

    private function availableMfgRows(Collection $rows, StockWithdrawalPlanningService $service): Collection
    {
        $rows = $service->excludeFullyWithdrawnMfg($rows);
        if ($rows->isEmpty()) return $rows;

        $plannedKeys = StockWithdrawalPlan::query()
            ->whereNull('cancelled_at')
            ->whereIn('workorder_id', $rows->pluck('workorder_id')->unique())
            ->get(['site', 'workorder_id'])
            ->mapWithKeys(fn ($plan) => [strtoupper((string) $plan->site).'|'.$plan->workorder_id => true]);

        return $rows->reject(fn ($row) => $plannedKeys->has(strtoupper((string) $row->site).'|'.$row->workorder_id))->values();
    }

    private function attachWithdrawalBalances($items, StockWithdrawalPlanningService $service): void
    {
        $service->attachWithdrawalBalances($items);
    }

    private function logItemAction(StockWithdrawalPlanItem $item, string $action, ?string $reason, array $before, array $after, $actedAt): void
    {
        DB::connection('sqlsrv_menam')->table('stock_withdrawal_item_action_logs')->insert(['stock_withdrawal_plan_item_id'=>$item->id,'action'=>$action,'actor_id'=>auth()->id(),'reason'=>$reason,'before_data'=>json_encode($before),'after_data'=>json_encode($after),'acted_at'=>$actedAt,'created_at'=>$actedAt,'updated_at'=>$actedAt]);
    }
}
