<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Services\FormDP\DeliveryConfirmationService;
use App\Services\FormDP\ProductionStatusTrackingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionStatusTrackingController extends Controller
{
    public function index(Request $request, ProductionStatusTrackingService $service)
    {
        $filters = $this->validatedFilters($request);

        if ($invalid = $this->assertDateRange($filters)) {
            return $invalid;
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(20, min(200, $perPage));

        return view('formdp.production-status.index', $service->getDashboardData(
            $filters,
            $perPage,
            max(1, (int) $request->query('page', 1))
        ));
    }

    public function detail(Request $request, ProductionStatusTrackingService $service)
    {
        $mfgNo = trim((string) $request->query('mfg_no', ''));
        abort_if($mfgNo === '', 404);

        return view('formdp.production-status.detail', $service->getDetailData(
            $mfgNo,
            $request->query('site'),
            $request->query('return_url'),
            $request->query('ord_id'),
            $request->query('so_number'),
            $request->only(['dp_qty', 'dp_sale_type', 'dp_status', 'delivery_type', 'ship_date', 'deadline'])
        ));
    }

    public function export(Request $request, ProductionStatusTrackingService $service)
    {
        $filters = $this->validatedFilters($request);

        if ($invalid = $this->assertDateRange($filters)) {
            return $invalid;
        }

        return $service->exportResponse($filters);
    }

    public function confirm(Request $request, DeliveryConfirmationService $service)
    {
        $validated = $request->validate([
            'mfg_no' => ['required', 'string', 'max:80'],
            'site' => ['nullable', 'string', 'max:10'],
            'so_number' => ['nullable', 'string', 'max:80'],
            'confirmation_status' => ['required', 'in:CONFIRM,POSTPONE'],
            'new_delivery_date' => ['nullable', 'date'],
            'original_ship_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $row = $service->save(
                $validated['mfg_no'],
                $validated['site'] ?? null,
                $validated['so_number'] ?? null,
                $validated['confirmation_status'],
                $validated['new_delivery_date'] ?? null,
                $validated['original_ship_date'] ?? null,
                $validated['remark'] ?? null
            );
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'ข้อมูลไม่ถูกต้อง',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'บันทึก Delivery Confirmation เรียบร้อย',
            'data' => [
                'mfg_no' => $row->mfg_no,
                'site' => $row->site,
                'confirmation_status' => $row->confirmation_status,
                'status_label' => \App\Models\FormDP\DeliveryConfirmation::statusLabel($row->confirmation_status),
                'badge_class' => \App\Models\FormDP\DeliveryConfirmation::statusBadgeClass($row->confirmation_status),
                'new_delivery_date' => optional($row->new_delivery_date)->format('Y-m-d'),
                'new_delivery_date_display' => optional($row->new_delivery_date)->format('d/m/Y'),
                'confirmed_at' => $row->confirmed_at?->format('Y-m-d H:i'),
                'confirmed_by_name' => $row->confirmed_by_name,
                'remark' => $row->remark,
            ],
        ]);
    }

    public function confirmBulk(Request $request, DeliveryConfirmationService $service)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.mfg_no' => ['required', 'string', 'max:80'],
            'items.*.site' => ['nullable', 'string', 'max:10'],
            'items.*.so_number' => ['nullable', 'string', 'max:80'],
            'items.*.original_ship_date' => ['nullable', 'date'],
        ]);

        $result = $service->saveBulkConfirm($validated['items']);

        return response()->json([
            'ok' => true,
            'message' => "บันทึก Confirm Delivery สำเร็จ {$result['saved']} รายการ"
                . ($result['skipped'] > 0 ? " (ข้าม {$result['skipped']} รายการที่บันทึกแล้ว)" : '')
                . ($result['errors'] > 0 ? " | ผิดพลาด {$result['errors']} รายการ" : ''),
            'data' => $result,
        ]);
    }

    public function cancelConfirm(Request $request, DeliveryConfirmationService $service)
    {
        $validated = $request->validate([
            'mfg_no' => ['required', 'string', 'max:80'],
            'site' => ['nullable', 'string', 'max:10'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $row = $service->cancel(
                $validated['mfg_no'],
                $validated['site'] ?? null,
                $validated['reason']
            );
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'ข้อมูลไม่ถูกต้อง',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'ยกเลิก Delivery Confirmation เรียบร้อย',
            'data' => [
                'confirmation_status' => $row->confirmation_status,
                'status_label' => \App\Models\FormDP\DeliveryConfirmation::statusLabel($row->confirmation_status),
                'badge_class' => \App\Models\FormDP\DeliveryConfirmation::statusBadgeClass($row->confirmation_status),
                'confirmed_at' => $row->confirmed_at?->format('Y-m-d H:i'),
                'confirmed_by_name' => $row->confirmed_by_name,
                'remark' => $row->remark,
            ],
        ]);
    }

    public function confirmHistory(Request $request, DeliveryConfirmationService $service)
    {
        $mfgNo = trim((string) $request->query('mfg_no', ''));
        $site = trim((string) $request->query('site', ''));
        abort_if($mfgNo === '', 404);

        $rows = $service->history($mfgNo, $site ?: null)->map(function ($r) {
            return [
                'confirmation_status' => $r->confirmation_status,
                'status_label' => \App\Models\FormDP\DeliveryConfirmation::statusLabel($r->confirmation_status),
                'badge_class' => \App\Models\FormDP\DeliveryConfirmation::statusBadgeClass($r->confirmation_status),
                'original_ship_date' => $r->original_ship_date ? Carbon::parse($r->original_ship_date)->format('d/m/Y') : '-',
                'new_delivery_date' => $r->new_delivery_date ? Carbon::parse($r->new_delivery_date)->format('d/m/Y') : '-',
                'remark' => $r->remark,
                'confirmed_at' => $r->confirmed_at ? Carbon::parse($r->confirmed_at)->format('d/m/Y H:i') : '-',
                'confirmed_by_name' => $r->confirmed_by_name ?: ($r->confirmed_by_login ?: '-'),
            ];
        });

        return response()->json([
            'ok' => true,
            'mfg_no' => $mfgNo,
            'site' => $site,
            'rows' => $rows,
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        if ($request->has('process_filter') && !is_array($request->input('process_filter'))) {
            $request->merge(['process_filter' => [$request->input('process_filter')]]);
        }

        $validated = $request->validate([
            'ship_from' => ['nullable', 'date'],
            'ship_to' => ['nullable', 'date'],
            'site' => ['nullable', 'in:WIRE,PLUS'],
            'keyword' => ['nullable', 'string', 'max:120'],
            'mfg' => ['nullable', 'string', 'max:80'],
            'so' => ['nullable', 'string', 'max:80'],
            'customer' => ['nullable', 'string', 'max:160'],
            'item' => ['nullable', 'string', 'max:160'],
            'delivery_type' => ['nullable', 'in:all,ALL,SO,ACID,SPECIAL'],
            'delivery_status' => ['nullable', 'in:NEW,ASSIGN,CLOSED,VOID,ALL'],
            'completion_filter' => ['nullable', 'in:all,open,completed'],
            'status_filter' => ['nullable', 'in:all,delayed,at_risk'],
            'movement_filter' => ['nullable', 'in:all,stale,not_started,moving,completed,no_route'],
            'risk_status' => ['nullable', 'in:HIGH,MEDIUM,NORMAL,NO_ROUTE'],
            'process_filter' => ['nullable', 'array', 'max:20'],
            'process_filter.*' => ['string', 'max:80'],
            'confirmation_filter' => ['nullable', 'in:all,pending,postpone'],
        ]);

        $completionFilter = strtolower(trim((string) ($validated['completion_filter'] ?? 'all'))) ?: 'all';
        $statusFilter = strtolower(trim((string) ($validated['status_filter'] ?? 'all'))) ?: 'all';

        if ($statusFilter !== 'all') {
            $completionFilter = 'all';
        }

        return [
            'ship_from' => $validated['ship_from'] ?? now('Asia/Bangkok')->toDateString(),
            'ship_to' => $validated['ship_to'] ?? now('Asia/Bangkok')->addDays(14)->toDateString(),
            'site' => strtoupper(trim((string) ($validated['site'] ?? ''))),
            'keyword' => trim((string) ($validated['keyword'] ?? '')),
            'mfg' => trim((string) ($validated['mfg'] ?? '')),
            'so' => trim((string) ($validated['so'] ?? '')),
            'customer' => trim((string) ($validated['customer'] ?? '')),
            'item' => trim((string) ($validated['item'] ?? '')),
            'delivery_type' => strtoupper(trim((string) ($validated['delivery_type'] ?? 'all'))) ?: 'ALL',
            'delivery_status' => strtoupper(trim((string) ($validated['delivery_status'] ?? 'NEW'))) ?: 'NEW',
            'completion_filter' => $completionFilter,
            'status_filter' => $statusFilter,
            'movement_filter' => strtolower(trim((string) ($validated['movement_filter'] ?? 'all'))) ?: 'all',
            'risk_status' => strtoupper(trim((string) ($validated['risk_status'] ?? ''))),
            'process_filter' => collect($validated['process_filter'] ?? [])
                ->map(fn($value) => trim((string) $value))
                ->filter()
                ->unique(fn($value) => mb_strtolower($value))
                ->values()
                ->all(),
            'confirmation_filter' => strtolower(trim((string) ($validated['confirmation_filter'] ?? 'all'))) ?: 'all',
        ];
    }

    private function assertDateRange(array $filters)
    {
        try {
            $from = Carbon::parse($filters['ship_from'])->startOfDay();
            $to = Carbon::parse($filters['ship_to'])->endOfDay();

            if ($from->gt($to)) {
                return back()->withErrors(['ship_to' => 'Ship To must be greater than or equal to Ship From.'])->withInput();
            }

            $hasSearch = ($filters['keyword'] ?? '') !== ''
                || ($filters['mfg'] ?? '') !== ''
                || ($filters['so'] ?? '') !== ''
                || ($filters['customer'] ?? '') !== ''
                || ($filters['item'] ?? '') !== '';

            if ($from->diffInDays($to) > 62 && !$hasSearch) {
                return back()->withErrors(['ship_to' => 'Please limit the date range to 62 days, or search by MFG/SO/customer.'])->withInput();
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['ship_to' => 'Invalid date range.'])->withInput();
        }

        return null;
    }
}
