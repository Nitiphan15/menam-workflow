<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Exports\FormOTD\InquiryByShipDateExport;
use App\Mail\DeliveryPlanMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;;

use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class DeliveryPlanInquiryController extends Controller
{
    private function conn()
    {
        return DB::connection('sqlsrv_menam');
    }

    public function export(Request $request)
    {
        return Excel::download(
            new InquiryByShipDateExport($request->all()),
            'Inquiry_By_ShipDate.xlsx'
        );
    }

    public function inquiry(Request $request)
    {
        [$shipFrom, $shipTo] = $this->resolveShipDateRange($request);

        $orderBy  = trim((string) $request->query('order_by', ''));
        $orderDir = strtolower(trim((string) $request->query('order_dir', '')));

        $status   = strtoupper(trim((string) $request->query('status', 'NEW')));
        $so       = trim((string) $request->query('so', ''));
        $customer = trim((string) $request->query('customer', ''));
        $shipto   = trim((string) $request->query('shipto', ''));
        $divsales = trim((string) $request->query('divsales', ''));
        $mode     = strtoupper(trim((string) $request->query('mode', '')));
        $searched = $request->has('searched');

        $hasKeyword = ($so !== '' || $customer !== '' || $shipto !== '' || $divsales !== '');

        if ($searched) {
            try {
                $from = Carbon::parse($shipFrom)->startOfDay();
                $to   = Carbon::parse($shipTo)->endOfDay();

                if (!$hasKeyword && $from->diffInDays($to) > 31) {
                    return back()
                        ->withInput()
                        ->withErrors(['ช่วงวันแทงส่งกว้างเกินไป (เกิน 31 วัน) กรุณาจำกัดช่วงวัน หรือใส่เงื่อนไขค้นหาเพิ่ม']);
                }
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['รูปแบบวันที่ไม่ถูกต้อง']);
            }
        }

        $assignSumSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('ord_id, SUM(ISNULL(assigned_weight, 0)) as assigned_weight_sum')
            ->groupBy('ord_id');

        $latestAssignSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('MAX(id) as latest_assign_id, ord_id')
            ->groupBy('ord_id');

        $truckListSub = $this->conn()
            ->table('delivery_plan_truck_assign as tx')
            ->leftJoin('delivery_plan_truck_master as tmx', 'tmx.id', '=', 'tx.truck_id')
            ->selectRaw("
                    tx.ord_id,
                    STRING_AGG(
                        LTRIM(RTRIM(
                            CASE
                                WHEN tx.truck_source = 'MANUAL' THEN ISNULL(tx.manual_plate_no, '')
                                ELSE ISNULL(tmx.plate_no, '')
                            END
                        )),
                        ', '
                    ) AS truck_plate_list
                ")
            ->groupBy('tx.ord_id');



        $q = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('users as u_create', 'u_create.id', '=', 'd.created_by')
            ->leftJoin('users as u_revise', 'u_revise.id', '=', 'd.revise_by')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->leftJoin('revision_master as rv', 'rv.revision_number', '=', 'd.revision_number')
            ->leftJoinSub($assignSumSub, 'tas', function ($join) {
                $join->on('tas.ord_id', '=', 'd.ord_id');
            })
            ->leftJoinSub($latestAssignSub, 'tal', function ($join) {
                $join->on('tal.ord_id', '=', 'd.ord_id');
            })
            ->leftJoinSub($truckListSub, 'tpl', function ($join) {
                $join->on('tpl.ord_id', '=', 'd.ord_id');
            })
            ->leftJoin('delivery_plan_truck_assign as ta', 'ta.id', '=', 'tal.latest_assign_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->select([
                'd.ord_id',
                'd.sales_id',

                DB::raw("
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) AS sales_name
            "),

                'd.customer_id',
                'c.customernumber',
                DB::raw("COALESCE(NULLIF(LTRIM(RTRIM(c.name)),''), '') AS customer_name"),
                'd.line_qty',
                'd.delivery_type',
                'd.part_number',
                'd.part_desc',
                'p.f1 as part_f1',
                'p.f2 as part_f2',
                'p.f3 as part_f3',
                'p.description as part_master_desc',
                'd.mfg_no',
                'd.sell_by_line',
                'd.qty',
                'd.address',
                'd.so_number',
                'd.status',
                'd.remark',
                'd.edit_remark',
                'd.remark_void',
                'd.ship_posted_at',
                'd.due_date',
                'd.due_date_remark',
                'd.tel',
                'd.revision_number',
                'd.created_at',
                'd.created_by',
                'd.attach_docs',
                'd.attach_docs_other',
                'd.window_at',
                'd.window_text',
                'tpl.truck_plate_list',
                'u_create.name as created_by_name',
                'u_revise.name as revise_by_name',

                DB::raw("
                CASE COALESCE(rv.color_code,'black')
                    WHEN 'sky' THEN 'deepskyblue'
                    ELSE COALESCE(rv.color_code,'black')
                END AS rev_color
            "),

                DB::raw('ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum'),
                DB::raw('ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum'),
                'tpl.truck_plate_list',
                'ta.id as truck_assign_id',
                'ta.truck_source',
                'ta.truck_id',
                'ta.manual_plate_no',
                'ta.manual_driver_name',
                'ta.manual_driver_phone',
                'ta.manual_max_load',
                'ta.manual_car_length',
                'ta.manual_remark',
                'ta.assigned_weight',
                'ta.ship_posted_at as truck_ship_posted_at',
                'ta.assigned_at',
                'ta.assigned_by',
                'ta.remark as truck_assign_remark',

                'tm.plate_no as truck_plate_no',
                'tm.driver_name as truck_driver_name',
                'tm.driver_phone as truck_driver_phone',
                'tm.max_load as truck_max_load',
                'tm.car_length as truck_car_length',
                'tm.remark as truck_master_remark',
            ]);

        try {
            $from = Carbon::parse($shipFrom)->startOfDay();
            $to   = Carbon::parse($shipTo)->endOfDay();

            $q->whereBetween('d.ship_posted_at', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $today = now()->startOfDay();
            $q->whereBetween('d.ship_posted_at', [
                $today->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ]);
        }

        if ($mode === 'SO') {
            $q->where('d.delivery_type', 'SO');
        } elseif ($mode === 'ACID') {
            $q->where('d.delivery_type', 'ACID');
        }

        if ($so !== '') {
            $q->where('d.so_number', 'like', "%{$so}%");
        }

        if ($customer !== '') {
            $q->where(function ($w) use ($customer) {
                $w->where('c.name', 'like', "%{$customer}%")
                    ->orWhere('c.customernumber', 'like', "%{$customer}%");
            });
        }

        if ($shipto !== '') {
            $q->where('d.address', 'like', "%{$shipto}%");
        }

        if ($divsales !== '') {
            $this->applyDivisionSalesFilter($q, $divsales);
        }

        if ($status !== 'ALL' && $status !== '') {
            $q->where('d.status', $status);
        }

        $dir = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'desc';

        $allowedOrder = [
            'so'             => 'd.so_number',
            'customer'       => 'c.name',
            'shipto'         => 'd.address',
            'qty'            => 'd.qty',
            'window_at'      => 'd.window_at',
            'ship_posted_at' => 'd.ship_posted_at',
            'revision'       => 'd.revision_number',
            'assigned_at'    => 'ta.assigned_at',
        ];

        if (isset($allowedOrder[$orderBy])) {
            $q->orderBy($allowedOrder[$orderBy], $dir);
        } else {
            $q->orderBy('d.sales_id')
                ->orderBy('c.name')
                ->orderBy('d.delivery_type')
                ->orderBy('d.part_number');
        }

        $rows = $q->paginate(100)->withQueryString();

        $soLines = collect($rows->items())
            ->groupBy(function ($r) {
                $so = (string) ($r->so_number ?? '');
                $shipDate = $this->dateOnly($r->ship_posted_at);
                return $so . '|' . $shipDate;
            })
            ->map(function ($items) {
                return $items->map(function ($r) {
                    $qtyTotal = (float) ($r->qty ?? 0);
                    $assigned = (float) ($r->assigned_weight_sum ?? 0);
                    $remaining = max(0, $qtyTotal - $assigned);

                    $sellByLine = (int) ($r->sell_by_line ?? 0) === 1;
                    $lineQty = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : null;

                    return [
                        'ord_id'         => (int) ($r->ord_id ?? 0),
                        'mfg_no'         => (string) ($r->mfg_no ?? ''),
                        'part'           => (string) ($r->part_number ?? ''),
                        'desc'           => (string) ($r->part_desc ?? ''),

                        // แยกให้ชัด
                        'sell_by_line'   => $sellByLine ? 1 : 0,
                        'line_qty'       => $lineQty,
                        'line_text'      => $sellByLine
                            ? ($lineQty !== null ? number_format($lineQty, 0) . ' เส้น' : 'ระบุเส้น')
                            : '-',

                        'shipto'         => trim((string) ($r->address ?? '')) ?: '-',

                        'qty_total'      => $qtyTotal,
                        'qty_assigned'   => $assigned,
                        'qty_remaining'  => $remaining,
                        'ship_date'      => $this->dateOnly($r->ship_posted_at),
                    ];
                })->values();
            });

        $parts = collect($rows->items())
            ->pluck('part_number')
            ->map(fn($x) => trim((string) $x))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $stockMap = [];
        if (!empty($parts)) {
            $stockRows = DB::connection('pgsqlw')
                ->table('parts')
                ->select(['partnumber', 'totalonhand', 'totalavail', 'onhand', 'avail'])
                ->whereIn('partnumber', $parts)
                ->get();

            foreach ($stockRows as $st) {
                $pn = trim((string) $st->partnumber);
                $stockMap[$pn] = [
                    'totalonhand' => (float) ($st->totalonhand ?? 0),
                    'totalavail'  => (float) ($st->totalavail ?? 0),
                    'onhand'      => (float) ($st->onhand ?? 0),
                    'avail'       => (float) ($st->avail ?? 0),
                ];
            }
        }

        foreach ($rows as $r) {
            $partNo = trim((string) ($r->part_number ?? ''));
            $stock = $stockMap[$partNo] ?? null;
            $r->stock_qty_rt = $stock['onhand'] ?? ($stock['totalonhand'] ?? 0);

            $totalQty = (float) ($r->qty ?? 0);
            $assignedSum = (float) ($r->assigned_weight_sum ?? 0);
            $remainingQty = max(0, $totalQty - $assignedSum);

            $r->assigned_weight_sum = $assignedSum;
            $r->remaining_assign_qty = $remainingQty;

            $truckPlate = '';
            if (($r->truck_source ?? '') === 'MANUAL') {
                $truckPlate = trim((string) ($r->manual_plate_no ?? ''));
            } else {
                $truckPlate = trim((string) ($r->truck_plate_no ?? ''));
            }
            $r->truck_plate_display = $truckPlate;

            $shipDateOnly = $this->safeCarbonDate($r->ship_posted_at);

            $r->can_pick_truck = $shipDateOnly
                ? now()->startOfDay()->lte($shipDateOnly->copy()->subDay())
                : false;

            $r->truck_current_load = null;
            $r->truck_remaining_capacity = null;
        }

        $groups = collect($rows->items())
            ->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))
            ->all();

        $docMap = $this->docMap();
        $defaultShipDate = collect($rows->items())
            ->map(fn($r) => $this->dateOnly($r->ship_posted_at))
            ->filter()
            ->first();

        $trucks = $this->getAssignableTrucksByShipDate($defaultShipDate);

        $divLabel = $this->divisionLabels();
        $divGroups = $this->buildDivisionGroups($groups);
        $revColorMap = $this->revisionColorMap();

        return view('formdp.inquiry', compact(
            'rows',
            'groups',
            'soLines',
            'docMap',
            'trucks',
            'divLabel',
            'divGroups',
            'revColorMap'
        ));
    }

    public function assignTruck(Request $request, $ordId = null)
    {
        $request->validate([
            'truck_pick_mode' => ['required', 'in:MASTER,MANUAL,MANUAL_TEMP'],
            'so_number'       => ['nullable', 'string', 'max:100'],
            'ship_posted_at'  => ['nullable', 'date'],

            'truck_id'        => ['nullable', 'integer'],

            'manual_plate_no'     => ['nullable', 'string', 'max:50'],
            'manual_driver_name'  => ['nullable', 'string', 'max:100'],
            'manual_driver_phone' => ['nullable', 'string', 'max:50'],
            'manual_max_load'     => ['nullable', 'numeric'],
            'manual_car_length'   => ['nullable', 'numeric'],
            'manual_remark'       => ['nullable', 'string', 'max:255'],

            'driver_staff_id'  => ['nullable', 'integer'],
            'helper1_staff_id' => ['nullable', 'integer'],

            'replace_mode' => ['nullable', 'in:1'],

            'ord_ids'   => ['required', 'array', 'min:1'],
            'ord_ids.*' => ['integer', 'distinct'],
        ], [
            'truck_pick_mode.required' => 'กรุณาเลือกประเภทรถ',
            'truck_pick_mode.in'       => 'ประเภทรถไม่ถูกต้อง',
            'ord_ids.required'         => 'กรุณาเลือก MFG ที่ต้องการขึ้นรถ',
            'ord_ids.min'              => 'กรุณาเลือก MFG อย่างน้อย 1 รายการ',
        ]);

        $replaceMode = (string) $request->input('replace_mode', '') === '1';

        $truckPickMode = strtoupper(trim((string) $request->input('truck_pick_mode')));
        $truckSource = in_array($truckPickMode, ['MANUAL', 'MANUAL_TEMP'], true)
            ? 'MANUAL'
            : 'MASTER';

        if ($truckSource === 'MASTER' && !$request->filled('truck_id')) {
            return back()->withErrors(['กรุณาเลือกรถในระบบ'])->withInput();
        }

        if (in_array($truckPickMode, ['MANUAL', 'MANUAL_TEMP'], true)) {
            $request->validate([
                'manual_plate_no'     => ['required', 'string', 'max:50'],
                'manual_driver_name'  => ['nullable', 'string', 'max:100'],
                'manual_driver_phone' => ['nullable', 'string', 'max:50'],
                'manual_max_load'     => ['nullable', 'numeric', 'gt:0'],
                'manual_car_length'   => ['nullable', 'numeric', 'gte:0'],
                'manual_remark'       => ['nullable', 'string', 'max:255'],
            ], [
                'manual_plate_no.required' => 'กรุณากรอกทะเบียนรถ',
                'manual_max_load.gt'       => 'Max Load ต้องมากกว่า 0',
            ]);
        }

        $conn = $this->conn();

        $savedRows = 0;
        $savedWeight = 0;
        $requestedWeight = 0;

        $conn->transaction(function () use (
            $conn,
            $request,
            $ordId,
            $truckSource,
            $replaceMode,
            &$savedRows,
            &$savedWeight,
            &$requestedWeight
        ) {
            $targetOrdId = (int) ($ordId ?: 0);

            $ordIds = collect($request->input('ord_ids', []))
                ->map(fn($x) => (int) $x)
                ->filter()
                ->unique()
                ->values();

            if ($ordIds->isEmpty() && $targetOrdId > 0) {
                $ordIds = collect([$targetOrdId]);
            }

            if ($ordIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['กรุณาเลือก MFG อย่างน้อย 1 รายการ'],
                ]);
            }

            $dpRows = $conn->table('delivery_plan_data')
                ->whereIn('ord_id', $ordIds->all())
                ->select([
                    'ord_id',
                    'so_number',
                    'ship_posted_at',
                    'status',
                    'qty',
                ])
                ->get();

            if ($dpRows->count() !== $ordIds->count()) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['พบ ord_id บางรายการไม่ถูกต้อง'],
                ]);
            }

            $shipSet = $dpRows->map(function ($r) {
                return $this->dateOnly($r->ship_posted_at);
            })->filter()->unique()->values();

            if ($shipSet->count() > 1) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['MFG ที่เลือกต้องมีวันที่ส่งสินค้าเดียวกัน'],
                ]);
            }

            $hasVoid = $dpRows->contains(fn($r) => strtoupper((string) ($r->status ?? '')) === 'VOID');
            if ($hasVoid) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่สามารถเลือกรถให้รายการ VOID ได้'],
                ]);
            }

            $hasClosed = $dpRows->contains(fn($r) => strtoupper((string) ($r->status ?? '')) === 'CLOSED');
            if ($hasClosed) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่สามารถเลือกรถให้รายการ CLOSED ได้'],
                ]);
            }

            $shipDateFromDb = (string) $shipSet->first();
            if ($shipDateFromDb === '') {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่พบวันที่ส่งสินค้าของรายการที่เลือก'],
                ]);
            }

            if ($replaceMode) {
                $conn->table('delivery_plan_truck_assign')
                    ->whereIn('ord_id', $ordIds->all())
                    ->delete();
            }

            $assignedSumRows = $conn->table('delivery_plan_truck_assign')
                ->whereIn('ord_id', $ordIds->all())
                ->selectRaw('ord_id, SUM(ISNULL(assigned_weight, 0)) as assigned_sum')
                ->groupBy('ord_id')
                ->get()
                ->keyBy('ord_id');

            $dpRowsByOrdId = $dpRows->keyBy('ord_id');

            $remainingByOrd = [];
            foreach ($ordIds as $oneOrdId) {
                $dp = $dpRowsByOrdId->get($oneOrdId);
                $qty = (float) ($dp->qty ?? 0);
                $assigned = (float) (($assignedSumRows->get($oneOrdId)->assigned_sum ?? 0));

                $remaining = $replaceMode
                    ? $qty
                    : max(0, $qty - $assigned);

                $remainingByOrd[$oneOrdId] = $remaining;
                $requestedWeight += $remaining;
            }

            if ($requestedWeight <= 0) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['รายการที่เลือกถูก assign ครบหมดแล้ว'],
                ]);
            }

            $manualPlateNo = trim((string) $request->input('manual_plate_no', ''));
            $existingManualTruck = null;
            $manualMaxLoadEffective = 0;
            $truckRemainingCapacity = 0;

            if ($truckSource === 'MASTER') {
                $truckId = (int) $request->input('truck_id');

                $conn->selectOne(
                    "SELECT id FROM delivery_plan_truck_master WITH (UPDLOCK, HOLDLOCK) WHERE id = ?",
                    [$truckId]
                );

                $truck = $conn->table('delivery_plan_truck_master')
                    ->where('id', $truckId)
                    ->where('status', 'ACTIVE')
                    ->first();

                if (!$truck) {
                    throw ValidationException::withMessages([
                        'truck_id' => ['ไม่พบรถในระบบ หรือรถไม่ active'],
                    ]);
                }

                $currentLoadRow = $conn->selectOne(
                    "
                SELECT ISNULL(SUM(ISNULL(assigned_weight, 0)), 0) AS current_load
                FROM delivery_plan_truck_assign WITH (UPDLOCK, HOLDLOCK)
                WHERE truck_source = 'MASTER'
                  AND truck_id = ?
                  AND CAST(ship_posted_at AS date) = ?
                ",
                    [$truckId, $shipDateFromDb]
                );

                $truckMaxLoad = (float) ($truck->max_load ?? 0);
                $currentLoad = (float) ($currentLoadRow->current_load ?? 0);
                $truckRemainingCapacity = max(0, $truckMaxLoad - $currentLoad);

                if ($truckRemainingCapacity <= 0) {
                    throw ValidationException::withMessages([
                        'truck_id' => ['รถคันนี้น้ำหนักเต็มแล้ว'],
                    ]);
                }
            } else {
                $existingManualTruck = $conn->table('delivery_plan_truck_assign')
                    ->where('truck_source', 'MANUAL')
                    ->where('manual_plate_no', $manualPlateNo)
                    ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDateFromDb])
                    ->orderByDesc('id')
                    ->first();

                $manualMaxLoadInput = (float) $request->input('manual_max_load', 0);

                $manualMaxLoadEffective = $manualMaxLoadInput > 0
                    ? $manualMaxLoadInput
                    : (float) ($existingManualTruck->manual_max_load ?? 0);

                if ($manualMaxLoadEffective <= 0) {
                    throw ValidationException::withMessages([
                        'manual_max_load' => ['กรุณากรอก Max Load ของรถนอกอย่างน้อยครั้งแรก'],
                    ]);
                }

                if ($existingManualTruck && $manualMaxLoadInput > 0) {
                    $oldMax = (float) ($existingManualTruck->manual_max_load ?? 0);
                    if ($oldMax > 0 && abs($manualMaxLoadInput - $oldMax) > 0.001) {
                        throw ValidationException::withMessages([
                            'manual_max_load' => ['ทะเบียนนี้มี Max Load เดิมอยู่แล้ว กรุณาใช้ค่าเดิม ' . number_format($oldMax, 3)],
                        ]);
                    }
                }

                $currentManualLoadRow = $conn->selectOne(
                    "
                SELECT ISNULL(SUM(ISNULL(assigned_weight, 0)), 0) AS current_load
                FROM delivery_plan_truck_assign WITH (UPDLOCK, HOLDLOCK)
                WHERE truck_source = 'MANUAL'
                  AND manual_plate_no = ?
                  AND CAST(ship_posted_at AS date) = ?
                ",
                    [$manualPlateNo, $shipDateFromDb]
                );

                $currentManualLoad = (float) ($currentManualLoadRow->current_load ?? 0);
                $truckRemainingCapacity = max(0, $manualMaxLoadEffective - $currentManualLoad);

                if ($truckRemainingCapacity <= 0) {
                    throw ValidationException::withMessages([
                        'manual_plate_no' => ['รถนอกคันนี้น้ำหนักเต็มแล้ว'],
                    ]);
                }
            }

            $staffSnapshot = $this->getTruckStaffSnapshotFromRequest($request);

            foreach ($ordIds as $oneOrdId) {
                $dp = $dpRowsByOrdId->get($oneOrdId);
                if (!$dp) {
                    continue;
                }

                $remainingOrdQty = (float) ($remainingByOrd[$oneOrdId] ?? 0);
                if ($remainingOrdQty <= 0) {
                    continue;
                }

                if ($truckRemainingCapacity <= 0) {
                    break;
                }

                $allocateWeight = min($remainingOrdQty, $truckRemainingCapacity);
                if ($allocateWeight <= 0) {
                    continue;
                }

                $payload = array_merge([
                    'ord_id'              => $oneOrdId,
                    'so_number'           => (string) ($dp->so_number ?? ''),
                    'truck_source'        => $truckSource,
                    'truck_id'            => $truckSource === 'MASTER' ? (int) $request->input('truck_id') : null,

                    'manual_plate_no'     => $truckSource === 'MANUAL' ? $manualPlateNo : null,
                    'manual_driver_name'  => $truckSource === 'MANUAL'
                        ? ($request->input('manual_driver_name') ?: ($existingManualTruck->manual_driver_name ?? null))
                        : null,
                    'manual_driver_phone' => $truckSource === 'MANUAL'
                        ? ($request->input('manual_driver_phone') ?: ($existingManualTruck->manual_driver_phone ?? null))
                        : null,
                    'manual_max_load'     => $truckSource === 'MANUAL' ? $manualMaxLoadEffective : null,
                    'manual_car_length'   => $truckSource === 'MANUAL'
                        ? ($request->input('manual_car_length') ?: ($existingManualTruck->manual_car_length ?? null))
                        : null,
                    'manual_remark'       => $truckSource === 'MANUAL'
                        ? ($request->input('manual_remark') ?: ($existingManualTruck->manual_remark ?? null))
                        : null,

                    'assigned_weight'     => $allocateWeight,
                    'ship_posted_at'      => $shipDateFromDb,
                    'assigned_at'         => now(),
                    'assigned_by'         => auth()->id(),
                ], $staffSnapshot);

                $conn->table('delivery_plan_truck_assign')->insert($payload);

                $conn->table('delivery_plan_data')
                    ->where('ord_id', $oneOrdId)
                    ->whereNotIn('status', ['VOID', 'CLOSED'])
                    ->update([
                        'status' => 'ASSIGN',
                    ]);

                $truckRemainingCapacity -= $allocateWeight;
                $savedRows++;
                $savedWeight += $allocateWeight;
            }

            if ($savedWeight <= 0) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่สามารถจัดน้ำหนักขึ้นรถได้ กรุณาตรวจสอบความจุรถและยอดคงเหลือ'],
                ]);
            }
        });

        $msg = $replaceMode ? 'เปลี่ยนรถสำเร็จ' : 'Assign truck สำเร็จ';

        if ($savedWeight < $requestedWeight) {
            $msg .= ' (บันทึกบางส่วน ' . number_format($savedWeight, 3) . ' / ' . number_format($requestedWeight, 3) . ' KG)';
        } else {
            $msg .= ' (' . number_format($savedWeight, 3) . ' KG)';
        }

        return redirect()
            ->to($this->resolveReturnUrl($request))
            ->with('success', $msg);
    }

    public function truckCapacity(Request $request)
    {
        $shipDate = trim((string) $request->query('ship_posted_at', ''));
        $rows = $this->getAssignableTrucksByShipDate($shipDate);

        return response()->json(
            $rows->map(function ($r) {
                return [
                    'row_key' => $r->row_key,
                    'truck_pick_type' => $r->truck_pick_type,
                    'truck_id' => $r->truck_id,
                    'manual_plate_no' => $r->manual_plate_no,
                    'plate_no' => $r->plate_no,
                    'driver_name' => $r->driver_name,
                    'driver_phone' => $r->driver_phone,
                    'max_load' => (float) $r->max_load,
                    'car_length' => $r->car_length,
                    'remark' => $r->remark,
                    'current_load' => (float) $r->current_load,
                    'remaining_capacity' => (float) $r->remaining_capacity,
                    'source_label' => $r->source_label,
                    'job_summary' => $r->job_summary ?? [],
                    'so_summary_text' => $r->so_summary_text ?? '',
                    'mfg_summary_text' => $r->mfg_summary_text ?? '',
                ];
            })->values()
        );
    }

    public function history(int $ordId)
    {
        try {
            $conn = $this->conn();

            $fields = [
                'ship_posted_at',
                'due_date',
                'window_at',
                'window_text',
                'delivery_type',
                'so_number',
                'customer_id',
                'sales_id',
                'part_number',
                'part_desc',
                'mfg_no',
                'qty',
                'stock_qty',
                'address',
                'sell_by_line',
                'tel',
                'remark',
                'attach_docs',
                'attach_docs_other',
                'edit_remark',
                'due_date_remark',
                'revise_by',
                'status',
                'remark_void',
            ];

            $labels = [
                'ship_posted_at'     => 'วันที่ส่งสินค้า',
                'due_date'           => 'วันส่งจริง',
                'window_at'          => 'ช่วงเวลารับส่ง (เวลา)',
                'window_text'        => 'ช่วงเวลารับส่ง',
                'delivery_type'      => 'ประเภทส่ง',
                'so_number'          => 'Sales Order',
                'customer_id'        => 'ลูกค้า',
                'sales_id'           => 'Sales',
                'part_number'        => 'Part No',
                'part_desc'          => 'Part Desc',
                'mfg_no'             => 'MFG No',
                'qty'                => 'จำนวน (KG)',
                'stock_qty'          => 'Stock FG',
                'address'            => 'สถานที่ส่ง',
                'sell_by_line'       => 'ขายแบบระบุเส้น',
                'tel'                => 'เบอร์โทร',
                'remark'             => 'หมายเหตุ',
                'attach_docs'        => 'เอกสารแนบ',
                'attach_docs_other'  => 'เอกสารอื่นๆ',
                'edit_remark'        => 'เหตุผลแก้ไข',
                'due_date_remark'    => 'สาเหตุเลื่อนส่ง',
                'revise_by'          => 'แก้ไขโดย',
                'status'             => 'สถานะ',
                'remark_void'        => 'เหตุผลยกเลิก',
            ];

            $docMap = $this->docMap();

            $all = $conn->table(DB::raw('dbo.delivery_plan_data FOR SYSTEM_TIME ALL AS x'))
                ->leftJoin('dbo.customer as c', 'c.id', '=', 'x.customer_id')
                ->leftJoin('dbo.employees as e', 'e.id', '=', 'x.sales_id')
                ->leftJoin('dbo.users as u_rev', 'u_rev.id', '=', 'x.revise_by')
                ->leftJoin('dbo.users as u_create', 'u_create.id', '=', 'x.created_by')
                ->leftJoin('dbo.revision_master as rv', 'rv.revision_number', '=', 'x.revision_number')
                ->where('x.ord_id', $ordId)
                ->orderByDesc(DB::raw('x.SysStartTime'))
                ->select(array_merge([
                    'x.ord_id',
                    'x.revision_number',
                    'x.revise_by',
                    'x.SysStartTime as sys_start',
                    'x.SysEndTime   as sys_end',

                    'c.name as customer_name',
                    'c.customernumber',

                    'e.login as sales_user_code',
                    'e.name  as sales_user_name',

                    'u_rev.name as revise_by_name',
                    'u_create.name as created_by_name',

                    'rv.color_code',
                ], array_map(fn($f) => "x.$f", $fields)))
                ->get()
                ->map(fn($x) => (array) $x)
                ->values()
                ->all();

            if (!$all) {
                return response()->json(['ok' => false, 'message' => 'ไม่พบข้อมูล ord_id นี้']);
            }

            $currentRow = collect($all)->first(function ($r) {
                $end = (string) ($r['sys_end'] ?? '');
                return str_starts_with($end, '9999-12-31');
            }) ?? $all[0];

            $curArr = $currentRow;

            $history = array_values(array_filter($all, function ($r) use ($currentRow) {
                return (string) ($r['sys_start'] ?? '') !== (string) ($currentRow['sys_start'] ?? '');
            }));

            $fmt = function (string $field, array $row) use ($docMap) {
                $v = $row[$field] ?? null;
                if ($v === null) {
                    return '';
                }
                if (is_string($v) && trim($v) === '') {
                    return '';
                }

                if ($field === 'customer_id') {
                    $name = trim((string) ($row['customer_name'] ?? ''));
                    $code = trim((string) ($row['customernumber'] ?? ''));
                    if ($name === '' && $code === '') {
                        return (string) $v;
                    }
                    return ($code !== '' ? "{$code} — " : '') . $name;
                }

                if ($field === 'sales_id') {
                    $name = trim((string) ($row['sales_user_name'] ?? ''));
                    $code = trim((string) ($row['sales_user_code'] ?? ''));
                    if ($name === '' && $code === '') {
                        return (string) $v;
                    }
                    return trim(($code !== '' ? "{$code} " : '') . $name);
                }

                if (in_array($field, ['ship_posted_at', 'due_date', 'window_at', 'sys_start', 'sys_end'], true)) {
                    try {
                        $dt = Carbon::parse($v);
                        if ($field === 'window_at') {
                            return $dt->format('Y-m-d H:i');
                        }
                        if ($field === 'due_date' || $field === 'ship_posted_at') {
                            return $dt->format('Y-m-d');
                        }
                        return $dt->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                        return (string) $v;
                    }
                }

                if ($field === 'sell_by_line') {
                    $b = (string) $v === '1' || strtolower((string) $v) === 'true';
                    return $b ? 'ใช่' : 'ไม่ใช่';
                }

                if ($field === 'delivery_type') {
                    $t = strtoupper(trim((string) $v));
                    return $t === 'ACID' ? 'ส่งกัดกรด' : 'ปกติ (SO)';
                }

                if ($field === 'status') {
                    return strtoupper(trim((string) $v));
                }

                if (in_array($field, ['qty', 'stock_qty'], true)) {
                    $n = is_numeric($v) ? (float) $v : null;
                    return $n === null ? (string) $v : number_format($n, 3);
                }

                if ($field === 'attach_docs') {
                    $codes = $this->normalizeDocCodes((string) $v);
                    if (!$codes) {
                        return '';
                    }
                    $names = array_map(fn($c) => $docMap[$c] ?? $c, $codes);
                    return implode(', ', $names);
                }

                return trim((string) $v);
            };

            $versionsDesc = array_map(function (array $h) use ($fields, $labels, $fmt, $curArr) {
                $diffCurrent = [];
                foreach ($fields as $f) {
                    $old = $fmt($f, $h);
                    $now = $fmt($f, $curArr);
                    if ($old !== $now) {
                        $diffCurrent[] = [
                            'field' => $f,
                            'label' => $labels[$f] ?? $f,
                            'from'  => $old,
                            'to'    => $now,
                        ];
                    }
                }
                $h['diff_current'] = $diffCurrent;
                $h['diff_step'] = [];
                return $h;
            }, $history);

            $timelineAsc = $all;
            usort($timelineAsc, fn($a, $b) => strcmp((string) $a['sys_start'], (string) $b['sys_start']));

            $stepMapOld = [];
            for ($i = 0; $i < count($timelineAsc) - 1; $i++) {
                $old = $timelineAsc[$i];
                $new = $timelineAsc[$i + 1];

                $diffStep = [];
                foreach ($fields as $f) {
                    $a = $fmt($f, $old);
                    $b = $fmt($f, $new);
                    if ($a !== $b) {
                        $diffStep[] = [
                            'field' => $f,
                            'label' => $labels[$f] ?? $f,
                            'from'  => $a,
                            'to'    => $b,
                        ];
                    }
                }

                $stepMapOld[(string) $old['sys_start']] = $diffStep;
            }

            foreach ($versionsDesc as &$v) {
                $k = (string) ($v['sys_start'] ?? '');
                $v['diff_step'] = $stepMapOld[$k] ?? [];
            }
            unset($v);

            return response()->json([
                'ok'               => true,
                'ord_id'           => (int) $ordId,
                'current_revision' => (int) ($curArr['revision_number'] ?? 0),
                'current'          => $curArr,
                'versions'         => $versionsDesc,
            ]);
        } catch (\Throwable $e) {
            Log::error('DeliveryPlanInquiryController@history failed', [
                'ord_id' => $ordId,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'เกิดข้อผิดพลาดในการดึงประวัติข้อมูล',
            ], 500);
        }
    }

    public function voidPlan(Request $request, $ordId)
    {
        $request->validate([
            'remark_void' => ['required', 'string', 'max:500'],
        ], [
            'remark_void.required' => 'กรุณากรอกเหตุผลยกเลิก',
        ]);

        $conn = $this->conn();

        $conn->transaction(function () use ($conn, $request, $ordId) {
            $row = $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->select(['ord_id', 'status', 'ship_posted_at'])
                ->first();

            if (!$row) {
                throw ValidationException::withMessages([
                    'ord_id' => ["ไม่พบรายการ ord_id={$ordId}"],
                ]);
            }

            if (strtoupper((string) ($row->status ?? '')) === 'VOID') {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ถูกยกเลิกไปแล้ว"],
                ]);
            }

            if (strtoupper((string) ($row->status ?? '')) === 'CLOSED') {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ปิดงานแล้ว ไม่สามารถยกเลิกได้"],
                ]);
            }

            $shipRaw = trim((string) ($row->ship_posted_at ?? ''));
            if ($shipRaw === '') {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ยกเลิกไม่ได้ เพราะไม่มีวันที่ส่งสินค้า"],
                ]);
            }

            try {
                $shipDt = Carbon::parse($shipRaw)->startOfDay();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ยกเลิกไม่ได้ เพราะรูปแบบวันที่ส่งสินค้าไม่ถูกต้อง"],
                ]);
            }

            $cutoff = $shipDt->copy()->subDays(3)->endOfDay();
            if (Carbon::now()->gt($cutoff)) {
                throw ValidationException::withMessages([
                    'ord_id' => [
                        "ยกเลิกไม่ได้: ต้องยกเลิกก่อนวันส่งสินค้าอย่างน้อย 3 วัน (กำหนดล่าสุด {$cutoff->format('d/m/Y H:i')})"
                    ],
                ]);
            }

            $userId = auth()->check() ? (int) auth()->id() : null;

            $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->update([
                    'status'          => 'VOID',
                    'remark_void'     => $request->input('remark_void'),
                    'revise_by'       => $userId,
                    'edit_remark'     => 'VOID: ' . trim((string) $request->input('remark_void')),
                    'revision_number' => DB::raw('ISNULL(revision_number,0) + 1'),
                ]);

            $conn->table('delivery_plan_truck_assign')
                ->where('ord_id', $ordId)
                ->delete();
        });

        return back()->with('success', "ยกเลิกรายการ ord_id={$ordId} เรียบร้อย");
    }

    public function historyDb(int $ord_id)
    {
        $sql = "
            WITH x AS (
                SELECT
                    ord_id, customer_id, sales_id, so_number, delivery_type,
                    part_number, part_desc, mfg_no,
                    qty, stock_qty, address,
                    due_date, window_at, window_text, ship_posted_at, due_date_remark,
                    attach_docs, attach_docs_other, tel, remark, edit_remark,
                    status, revision_number, revise_by, remark_void,
                    SysStartTime, SysEndTime
                FROM dbo.delivery_plan_data
                FOR SYSTEM_TIME ALL
                WHERE ord_id = ?
            )
            SELECT
                x.*,
                c.name as customer_name,
                c.customernumber,
                s.sales_name,
                s.sales_code,
                rv.color_code,
                u.user_code AS revise_user_code,
                u.name      AS revise_user_name
            FROM x
            LEFT JOIN dbo.customer        c  ON c.id = x.customer_id
            LEFT JOIN dbo.sales_master    s  ON s.sales_id = x.sales_id
            LEFT JOIN dbo.revision_master rv ON rv.revision_number = x.revision_number
            LEFT JOIN dbo.users           u  ON u.id = x.revise_by
            ORDER BY x.SysStartTime DESC;
        ";

        $rows = $this->conn()->select($sql, [$ord_id]);

        return response()->json([
            'ord_id' => $ord_id,
            'rows'   => array_map(fn($r) => (array) $r, $rows),
        ]);
    }

    private function divisionKeywordMap(): array
    {
        return [
            'ภควดี'     => 'D3',
            'ธนัชชา'    => 'D3',

            'ธัธลิญา'   => 'D5',
            'กัณญิกา'   => 'D5',

            'สุรศักดิ์' => 'D6',
            'คณัญญ์นิชา' => 'D6',

            'ศิรินภา'    => 'D7',
            'มนพัทธ์'    => 'D7',

            'สาธิต'      => 'D8',
            'สุธาสินี'   => 'D8',

            'วรเดชา'     => 'D9',
            'ลัดดาวัลย์' => 'D9',

            'ดิลก'       => 'D1',
            'ขวัญเรือน'  => 'D1',

            'ปรียาพรรณ' => 'D2',
            'นิตยา'      => 'D2',

            'กันยกร'     => 'PLN',
            'วางแผน'     => 'PLN',
        ];
    }

    private function divisionLabels(): array
    {
        return [
            'D1'  => 'D1 - ดิลก + ขวัญเรือน',
            'D2'  => 'D2 - ปรียาพรรณ + นิตยา',
            'D3'  => 'D3 - ภควดี + ธนัชชา',
            'D5'  => 'D5 - ธัธลิญา + กัณญิกา',
            'D6'  => 'D6 - สุรศักดิ์ + คณัญญ์นิชา',
            'D7'  => 'D7 - ศิรินภา + มนพัทธ์',
            'D8'  => 'D8 - สาธิต + สุธาสินี',
            'D9'  => 'D9 - วรเดชา + ลัดดาวัลย์',
            'PLN' => 'วางแผน - กันยกร',
        ];
    }

    private function divisionOrder(): array
    {
        return ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D8', 'D9', 'PLN'];
    }

    private function revisionColorMap(): array
    {
        return [
            0 => '#111827',
            1 => '#991b1b',
            2 => '#166534',
            3 => '#be185d',
            4 => '#1d4ed8',
            5 => '#0369a1',
        ];
    }

    protected function divisionSearchMap(): array
    {
        return [
            'D1'  => ['D1', 'ดิลก', 'ขวัญเรือน'],
            'D2'  => ['D2', 'ปรียาพรรณ', 'นิตยา'],
            'D3'  => ['D3', 'ภควดี', 'ธนัชชา'],
            'D5'  => ['D5', 'ธัธลิญา', 'กัณญิกา'],
            'D6'  => ['D6', 'สุรศักดิ์', 'คณัญญ์นิชา'],
            'D7'  => ['D7', 'ศิรินภา', 'มนพัทธ์'],
            'D8'  => ['D8', 'สาธิต', 'สุธาสินี'],
            'D9'  => ['D9', 'วรเดชา', 'ลัดดาวัลย์'],
            'PLN' => ['PLN', 'วางแผน', 'กันยกร'],
        ];
    }

    private function detectDivision(string $salesName): string
    {
        $salesName = trim($salesName);

        foreach ($this->divisionKeywordMap() as $needle => $div) {
            if ($needle !== '' && mb_stripos($salesName, $needle) !== false) {
                return $div;
            }
        }

        if (preg_match('/\bD[0-9]\b/i', $salesName, $m)) {
            return strtoupper($m[0]);
        }

        return $salesName !== '' ? $salesName : 'UNKNOWN';
    }

    protected function expandDivisionSearchTokens(string $keyword): array
    {
        $tokens = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        $map = $this->divisionSearchMap();

        $expanded = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $matched = false;

            foreach ($map as $groupKeywords) {
                if (collect($groupKeywords)->contains(fn($v) => mb_strtolower($v) === mb_strtolower($token))) {
                    $expanded[] = $groupKeywords;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $expanded[] = [$token];
            }
        }

        return $expanded;
    }

    private function buildDivisionGroups(array $groups): array
    {
        $divGroups = [];

        foreach ($groups as $salesName => $items) {
            $div = $this->detectDivision((string) $salesName);

            foreach ($items ?? [] as $r) {
                $mode = strtoupper(trim((string) ($r->delivery_type ?? '')));
                $key = $mode === 'ACID' ? 'PLN' : $div;
                $divGroups[$key] = $divGroups[$key] ?? [];
                $divGroups[$key][] = $r;
            }
        }

        $order = $this->divisionOrder();

        uksort($divGroups, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib ?: strcmp((string) $a, (string) $b);
        });

        return $divGroups;
    }

    protected function applyDivisionSalesFilter($q, string $divsales): void
    {
        $groups = $this->expandDivisionSearchTokens($divsales);

        if (empty($groups)) {
            return;
        }

        $q->where(function ($outer) use ($groups) {
            foreach ($groups as $group) {
                $outer->where(function ($w) use ($group) {
                    foreach ($group as $keyword) {
                        $kw = '%' . $keyword . '%';

                        $w->orWhere('s.sales_name', 'like', $kw)
                            ->orWhere('s.sales_code', 'like', $kw)
                            ->orWhere('e.login', 'like', $kw)
                            ->orWhere('e.name', 'like', $kw);
                    }
                });
            }
        });
    }

    private function resolveShipDateRange(Request $request): array
    {
        $shipFrom = trim((string) $request->query('ship_from', ''));
        $shipTo   = trim((string) $request->query('ship_to', ''));

        if ($shipFrom === '' && $shipTo === '') {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        $from = $this->normalizeDateInput($shipFrom);
        $to   = $this->normalizeDateInput($shipTo);

        if ($from === null || $to === null) {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        return [$from, $to];
    }

    private function normalizeDateInput(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeCarbonDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dateOnly($value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function docMap(): array
    {
        return $this->conn()
            ->table('attach_docs_master')
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [
                strtoupper(trim((string) $code)) => trim((string) $name),
            ])
            ->all();
    }

    private function normalizeDocCodes(?string $value): array
    {
        return array_values(array_filter(array_map(
            fn($x) => strtoupper(trim((string) $x)),
            explode(',', (string) $value)
        )));
    }

    private function resolveReturnUrl(Request $request): string
    {
        $fallback = route('dp.inquiry');
        $returnUrl = trim((string) $request->input('return_url', ''));

        if ($returnUrl === '') {
            return $fallback;
        }

        $allowedBase = rtrim(route('dp.inquiry'), '/');
        $appUrl = rtrim((string) config('app.url'), '/');

        if (str_starts_with($returnUrl, $allowedBase)) {
            return $returnUrl;
        }

        if ($appUrl !== '' && str_starts_with($returnUrl, $appUrl)) {
            $path = parse_url($returnUrl, PHP_URL_PATH) ?? '';
            $allowedPath = parse_url(route('dp.inquiry'), PHP_URL_PATH) ?? '';

            if ($path !== '' && str_starts_with($path, $allowedPath)) {
                return $returnUrl;
            }
        }

        return $fallback;
    }

    public function sendPlanMail(Request $request)
    {
        if (!auth()->check() || !auth()->user()->can('DPEMAIL')) {
            abort(403);
        }
        $validated = $request->validate([
            'ship_posted_at'  => ['required', 'date'],
            'revision_number' => ['required', 'integer', 'min:0'],
            'remark'          => ['nullable', 'string', 'max:500'],
            'force_send'      => ['nullable', 'in:0,1'],
        ], [
            'ship_posted_at.required'  => 'กรุณาระบุวันที่ส่งสินค้า',
            'revision_number.required' => 'กรุณาระบุ revision',
        ]);

        $shipDate  = Carbon::parse($validated['ship_posted_at'])->toDateString();
        $revision  = (int) $validated['revision_number'];
        $forceSend = (string) ($validated['force_send'] ?? '0') === '1';

        $to = collect(config('mail.delivery_plan.to', []))
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        $cc = collect(config('mail.delivery_plan.cc', []))
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        if (empty($to)) {
            return back()->withErrors([
                'mail' => 'ยังไม่ได้ตั้งค่า DP_MAIL_TO ใน .env หรือรูปแบบอีเมลไม่ถูกต้อง'
            ])->withInput();
        }

        $rows = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->selectRaw("
                d.ord_id,
                d.so_number,
                d.part_number,
                c.name as customer_name,
                d.part_desc,
                d.mfg_no,
                d.qty,
                d.remark,
                d.address,
                d.delivery_type,
                d.sell_by_line,
                d.line_qty,
                d.ship_posted_at,
                d.revision_number,
                p.onhand as stock_qty,
                p.f3 as part_type,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) AS sales_name,
                d.edit_remark,
                d.attach_docs,
                d.attach_docs_other
            ")
            ->whereRaw("CAST(d.ship_posted_at AS date) = ?", [$shipDate])
            ->where('d.revision_number', $revision)
            ->whereRaw("ISNULL(d.status,'') NOT IN ('VOID','CANCEL')")
            ->orderBy('d.so_number')
            ->orderBy('d.part_desc')
            ->get();


        if ($rows->isEmpty()) {
            return back()->withErrors([
                'mail' => "ไม่พบข้อมูลสำหรับวันที่ {$shipDate} และ revision {$revision}"
            ])->withInput();
        }

        $docMaster = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code');

        $mfgNos = $rows
            ->pluck('mfg_no')
            ->filter()
            ->flatMap(function ($mfg) {
                return collect(explode(',', (string) $mfg))
                    ->map(fn($x) => trim($x))
                    ->filter();
            })
            ->unique()
            ->values();

        $packageMap = collect();

        $mfgNos = collect($rows)
            ->pluck('mfg_no')
            ->filter()
            ->flatMap(function ($mfg) {
                return collect(preg_split('/\s*,\s*/', (string) $mfg))
                    ->map(function ($x) {
                        $x = trim((string) $x);
                        return ltrim($x, '+'); // กันกรณีมี + นำหน้า
                    })
                    ->filter();
            })
            ->unique()
            ->values();

        $packageMap = collect();

        if ($mfgNos->isNotEmpty()) {
            $packageMap = DB::connection('pgsqlmfgw')
                ->table('workorder')
                ->select('workordernumber', 'fcat')
                ->whereIn('workordernumber', $mfgNos->all())
                ->get()
                ->pluck('fcat', 'workordernumber');
        }

        $resolvePackage = function (?string $mfgNo) use ($packageMap) {
            if (blank($mfgNo)) {
                return '-';
            }

            $packs = collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(function ($x) {
                    $x = trim((string) $x);
                    return ltrim($x, '+');
                })
                ->filter()
                ->map(fn($wo) => trim((string) ($packageMap[$wo] ?? '')))
                ->filter()
                ->unique()
                ->values();

            return $packs->isNotEmpty() ? $packs->implode(', ') : '-';
        };

        $normalizeMfgDisplay = function (?string $mfgNo) {
            if (blank($mfgNo)) {
                return '-';
            }

            return collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(function ($x) {
                    $x = trim((string) $x);
                    return ltrim($x, '+');
                })
                ->filter()
                ->unique()
                ->implode(', ');
        };

        $u = auth()->user();

        $mailRows = $rows->values()->map(function ($r, $index) {
            return [
                'no'        => $index + 1,
                'so_number' => $r->so_number,
                'customer'  => $r->customer_name,
                'part_desc' => $r->part_desc,
                'mfg_no'    => $r->mfg_no,
                'qty'       => $r->qty,
                'remark'    => $r->remark,
                'address'   => $r->address,
            ];
        })->all();

        $groups = $rows
            ->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))
            ->all();

        $divLabel  = $this->divisionLabels();
        $divGroups = $this->buildDivisionGroups($groups);

        $pdfRows = [];
        $itemNo = 0;

        $resolveAttachDocs = function ($attachDocs, $attachDocsOther) use ($docMaster) {
            $codes = collect(preg_split('/\s*,\s*/', (string) $attachDocs))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->unique();

            $names = $codes->map(function ($code) use ($docMaster) {
                return $docMaster[$code] ?? $code; // ถ้าไม่มีใน master ใช้ code เดิม
            });

            $other = trim((string) ($attachDocsOther ?? ''));

            if ($other !== '') {
                $names->push('อื่นๆ: ' . $other);
            }

            $names = $names->unique()->values();

            return $names->isNotEmpty() ? $names->implode(', ') : '-';
        };

        foreach ($divGroups as $divCode => $items) {
            $groupTitle = $divLabel[$divCode] ?? $divCode;

            $pdfRows[] = [
                'row_type'   => 'group',
                'group_name' => $groupTitle,
            ];

            foreach ($items as $r) {
                $itemNo++;

                $pdfRows[] = [
                    'row_type'        => 'item',
                    'item_no'         => $itemNo,
                    'revision_number' => (int) ($r->revision_number ?? 0),
                    'customer'        => $r->customer_name ?? '-',
                    'package'         => $resolvePackage($r->mfg_no),
                    'type'            => filled($r->part_type ?? null) ? $r->part_type : '-',
                    'size_length'     => $r->part_desc ?? '-',
                    'mfg_no'          => $normalizeMfgDisplay($r->mfg_no),
                    'pieces'          => ((int) ($r->sell_by_line ?? 0) === 1 && is_numeric($r->line_qty ?? null) && (float) $r->line_qty > 0)
                        ? 'ระบุเส้น : ' . number_format((float) $r->line_qty, 0)
                        : '-',
                    'sales_qty'       => (float) ($r->qty ?? 0),
                    'stock_qty'       => (float) ($r->stock_qty ?? 0),
                    'production_qty'  => (float) ($r->qty ?? 0),
                    'logistics_qty'   => 0,
                    'delivery_place'  => $r->address ?? '-',
                    'oe_no'           => $r->so_number ?? '-',
                    'problem_note'    => $revision > 0
                        ? ($r->edit_remark ?? '-')
                        : ($r->remark ?? '-'),
                    'attach_docs'     => $resolveAttachDocs(
                        $r->attach_docs ?? null,
                        $r->attach_docs_other ?? null
                    ),
                ];
            }
        }

        $shipDateText = Carbon::parse($shipDate)->format('d/m/Y');
        $shipDateTitleText = Carbon::parse($shipDate)->locale('th')->translatedFormat('j F Y');

        $mailTypeText = match (true) {
            $revision === 0 => 'แจ้งแผนส่งมอบ',
            $revision === 1 => 'แจ้งแก้ไขแผนส่งมอบ Revision 1',
            default         => "แจ้งปรับปรุงแผนส่งมอบ Revision {$revision}",
        };

        $mailTypeCode = match (true) {
            $revision === 0 => 'INITIAL',
            $revision === 1 => 'REVISION',
            default         => 'MANUAL',
        };

        $revisionBadgeText = $revision === 0 ? 'แจกแล้ว' : 'เพิ่มเติม ' . $revision;
        $revisionBadgeTime = now()->format('H:i A');

        $subject = "[Delivery Plan] {$mailTypeText} วันที่ {$shipDateTitleText}";

        $existingLog = $this->conn()
            ->table('delivery_plan_mail_logs')
            ->whereRaw("CAST(ship_posted_at AS date) = ?", [$shipDate])
            ->where('revision_number', $revision)
            ->exists();

        if ($existingLog && !$forceSend) {
            return back()->withErrors([
                'mail' => "เคยส่งเมลวันที่ {$shipDate} revision {$revision} แล้ว หากต้องการส่งซ้ำกรุณาติ๊ก Force Send"
            ])->withInput();
        }

        $inquiryUrl = url('/dp/inquiry') . '?' . http_build_query([
            'ship_from'  => $shipDate,
            'ship_to'  => $shipDate,
            'revision_number' => $revision,
        ]);

        $mailData = [
            'subject' => $subject,
            'shipDateText' => $shipDateTitleText,
            'shipDateFile' => now()->format('Ymd_His'),
            'mailTypeText' => $mailTypeText,
            'total'        => count($mailRows),
            'rows'         => $mailRows,
            'inquiryUrl'   => $inquiryUrl,
            'mailRemark'   => $validated['remark'] ?? null,
        ];

        $pdfData = [
            'shipDateText'       => $shipDateText,
            'shipDateFile'       => now()->format('Ymd_His'),
            'monthText'          => Carbon::parse($shipDate)->locale('th')->translatedFormat('F Y'),
            'targetText'         => '900 ตัน/เดือน',
            'preparedBy'         => '....................',
            'productionConfirm'  => '....................',
            'docReceiver'        => '....................',
            'footerCode'         => 'MK-03',
            'sumSalesQty'        => collect($pdfRows)->where('row_type', 'item')->sum('sales_qty'),
            'sumStockQty'        => collect($pdfRows)->where('row_type', 'item')->sum('stock_qty'),
            'sumProductionQty'   => collect($pdfRows)->where('row_type', 'item')->sum('production_qty'),
            'sumLogisticsQty'    => collect($pdfRows)->where('row_type', 'item')->sum('logistics_qty'),
            'pdfRows'            => $pdfRows,
            'totalBodyRows'      => count($pdfRows) + 1,
            'revisionBadgeText'  => $revisionBadgeText,
            'revisionBadgeTime'  => $revisionBadgeTime,
        ];

        $mail = new DeliveryPlanMail($mailData, $pdfData);

        try {
            $mailer = Mail::to($to);

            if (!empty($cc)) {
                $mailer->cc($cc);
            }

            $mailer->send($mail);

            $this->conn()->table('delivery_plan_mail_logs')->insert([
                'ship_posted_at'  => $shipDate,
                'revision_number' => $revision,
                'mail_type'       => $mailTypeCode,
                'to_emails'       => implode(',', $to),
                'cc_emails'       => !empty($cc) ? implode(',', $cc) : null,
                'total_items'     => count($mailRows),
                'sent_at'         => now(),
                'sent_by'         => $u->id ?? null,
                'sent_by_name'    => $u->name ?? $u->email ?? null,
                'subject'         => $subject,
                'remark'          => $validated['remark'] ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            return back()->with('success', "ส่งเมลเรียบร้อยแล้ว ({$shipDate} / revision {$revision})");
        } catch (\Throwable $e) {
            report($e);
            //dd($e);
            return back()->withErrors([
                'mail' => 'ส่งเมลไม่สำเร็จ กรุณาตรวจสอบการตั้งค่าเมลหรือสอบถามผู้ดูแลระบบ'

            ])->withInput();
        }
    }


    public function loadingBoard(Request $request)
    {
        $today = now()->toDateString();

        $latestAssignSub = $this->conn()->table('delivery_plan_truck_assign as a1')
            ->selectRaw('MAX(a1.id) as id, a1.ord_id')
            ->groupBy('a1.ord_id');

        $rows = $this->conn()->table('delivery_plan_data as dp')
            ->leftJoinSub($latestAssignSub, 'la', function ($join) {
                $join->on('la.ord_id', '=', 'dp.ord_id');
            })
            ->leftJoin('delivery_plan_truck_assign as ta', 'ta.id', '=', 'la.id')
            ->leftJoin('delivery_plan_truck_master as mt', 'mt.id', '=', 'ta.truck_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw('p.partnumber COLLATE DATABASE_DEFAULT'),
                    '=',
                    DB::raw('dp.part_number COLLATE DATABASE_DEFAULT')
                );
            })
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->selectRaw("
            dp.ord_id,
            UPPER(ISNULL(dp.status, 'NEW')) as status,
            dp.ship_posted_at,
            dp.window_at,
            dp.so_number,
            dp.part_number,
            dp.part_desc,
            dp.qty,
            dp.remark,
            dp.address,
            dp.delivery_type,
            dp.attach_docs,
            dp.attach_docs_other,

            c.name as customer_name,
            ISNULL(p.onhand, 0) as stock_fg,
            ISNULL(ta.assigned_weight, 0) as assigned_weight,

            ta.truck_source,
            ta.truck_id,
            ta.manual_plate_no,
            ta.manual_driver_name,
            ta.manual_driver_phone,
            ta.driver_staff_id,
            ta.driver_name,
            ta.driver_phone,
            ta.helper1_staff_id,
            ta.helper1_name,
            ta.helper2_staff_id,
            ta.helper2_name,
            ta.helper3_staff_id,
            ta.helper3_name,

            ta.remark as truck_remark,
            CASE
                WHEN ta.truck_source = 'MANUAL' AND ISNULL(ta.manual_plate_no, '') <> ''
                    THEN ta.manual_plate_no
                WHEN ta.truck_source = 'MASTER' AND ISNULL(mt.plate_no, '') <> ''
                    THEN mt.plate_no
                WHEN ta.truck_source = 'MASTER' AND ta.truck_id IS NOT NULL
                    THEN CONCAT('TRUCK #', ta.truck_id)
                ELSE NULL
            END as truck_label
        ")
            ->whereDate('dp.ship_posted_at', $today)
            ->whereIn(DB::raw("UPPER(ISNULL(dp.status, 'NEW'))"), ['NEW', 'ASSIGN'])
            ->orderByRaw("
            CASE
                WHEN UPPER(ISNULL(dp.status, 'NEW')) = 'ASSIGN' THEN 0
                WHEN UPPER(ISNULL(dp.status, 'NEW')) = 'NEW' THEN 1
                ELSE 9
            END
        ")
            ->orderBy('dp.ship_posted_at', 'asc')
            ->orderBy('dp.window_text', 'asc')
            ->orderBy('dp.so_number', 'asc')
            ->get();

        $totalCount   = $rows->count();
        $assignCount  = $rows->where('status', 'ASSIGN')->count();
        $newCount     = $rows->where('status', 'NEW')->count();
        $totalQty     = $rows->sum(fn($r) => (float) ($r->qty ?? 0));
        $totalWeight  = $rows->sum(function ($r) {
            $assigned = (float) ($r->assigned_weight ?? 0);
            return $assigned > 0 ? $assigned : (float) ($r->qty ?? 0);
        });

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->toArray();

        return view('formdp.loading-board', [
            'rows'        => $rows,
            'today'       => $today,
            'totalCount'  => $totalCount,
            'assignCount' => $assignCount,
            'newCount'    => $newCount,
            'totalQty'    => $totalQty,
            'totalWeight' => $totalWeight,
            'attachDocMap' => $attachDocMap,
        ]);
    }

    private function getAssignableTrucksByShipDate(?string $shipDate): \Illuminate\Support\Collection
    {
        $shipDate = trim((string) $shipDate);
        if ($shipDate === '') {
            return collect();
        }

        $masterRows = $this->conn()
            ->table('delivery_plan_truck_master as tm')
            ->where('tm.status', 'ACTIVE')
            ->orderBy('tm.plate_no')
            ->get()
            ->map(function ($t) {
                return (object) [
                    'row_key' => 'MASTER:' . $t->id,
                    'truck_pick_type' => 'MASTER',
                    'truck_id' => (int) $t->id,
                    'manual_plate_no' => null,
                    'plate_no' => trim((string) ($t->plate_no ?? '')),
                    'driver_name' => trim((string) ($t->driver_name ?? '')),
                    'driver_phone' => trim((string) ($t->driver_phone ?? '')),
                    'max_load' => (float) ($t->max_load ?? 0),
                    'car_length' => $t->car_length,
                    'remark' => trim((string) ($t->remark ?? '')),
                    'current_load' => 0,
                    'remaining_capacity' => (float) ($t->max_load ?? 0),
                    'source_label' => 'ในระบบ',
                    'job_summary' => [],
                    'so_summary_text' => '',
                    'mfg_summary_text' => '',
                ];
            });

        $masterLoadRows = collect($this->conn()->select(
            "
            SELECT
                truck_id,
                ISNULL(SUM(ISNULL(assigned_weight, 0)), 0) AS current_load
            FROM delivery_plan_truck_assign
            WHERE truck_source = 'MASTER'
            AND truck_id IS NOT NULL
            AND CAST(ship_posted_at AS date) = ?
            GROUP BY truck_id
            ",
            [$shipDate]
        ))->keyBy('truck_id');

        $manualRows = collect($this->conn()->select(
            "
            SELECT
                manual_plate_no,
                MAX(manual_driver_name) AS driver_name,
                MAX(manual_driver_phone) AS driver_phone,
                MAX(manual_max_load) AS max_load,
                MAX(manual_car_length) AS car_length,
                MAX(manual_remark) AS remark,
                ISNULL(SUM(ISNULL(assigned_weight, 0)), 0) AS current_load
            FROM delivery_plan_truck_assign
            WHERE truck_source = 'MANUAL'
            AND manual_plate_no IS NOT NULL
            AND LTRIM(RTRIM(manual_plate_no)) <> ''
            AND CAST(ship_posted_at AS date) = ?
            GROUP BY manual_plate_no
            ",
            [$shipDate]
        ))->map(function ($r) {
            $maxLoad = (float) ($r->max_load ?? 0);
            $currentLoad = (float) ($r->current_load ?? 0);

            return (object) [
                'row_key' => 'MANUAL:' . trim((string) $r->manual_plate_no),
                'truck_pick_type' => 'MANUAL_TEMP',
                'truck_id' => null,
                'manual_plate_no' => trim((string) $r->manual_plate_no),
                'plate_no' => trim((string) $r->manual_plate_no),
                'driver_name' => trim((string) ($r->driver_name ?? '')),
                'driver_phone' => trim((string) ($r->driver_phone ?? '')),
                'max_load' => $maxLoad,
                'car_length' => $r->car_length,
                'remark' => trim((string) ($r->remark ?? '')),
                'current_load' => $currentLoad,
                'remaining_capacity' => max(0, $maxLoad - $currentLoad),
                'source_label' => 'รถนอกวันนี้',
                'job_summary' => [],
                'so_summary_text' => '',
                'mfg_summary_text' => '',
            ];
        })->filter(fn($r) => (float) $r->remaining_capacity > 0)->values();

        $usageRows = collect($this->conn()->select(
            "
            SELECT
                CASE
                    WHEN ta.truck_source = 'MASTER' AND ta.truck_id IS NOT NULL
                        THEN 'MASTER:' + CAST(ta.truck_id AS varchar(50))
                    WHEN ta.truck_source = 'MANUAL' AND ta.manual_plate_no IS NOT NULL
                        THEN 'MANUAL:' + LTRIM(RTRIM(ta.manual_plate_no))
                    ELSE ''
                END AS row_key,
                dp.so_number,
                dp.mfg_no,
                dp.address,
                ISNULL(c.name, '-') AS customer_name,
                ISNULL(wo.brand, '-') AS job_type,
                SUM(ISNULL(ta.assigned_weight, 0)) AS assigned_weight
            FROM delivery_plan_truck_assign ta
            INNER JOIN delivery_plan_data dp
                ON dp.ord_id = ta.ord_id
            LEFT JOIN customer c
                ON c.id = dp.customer_id
            LEFT JOIN workorder wo
                ON LTRIM(RTRIM(wo.workordernumber)) COLLATE DATABASE_DEFAULT
                = LTRIM(RTRIM(dp.mfg_no)) COLLATE DATABASE_DEFAULT
            WHERE CAST(ta.ship_posted_at AS date) = ?
            GROUP BY
                CASE
                    WHEN ta.truck_source = 'MASTER' AND ta.truck_id IS NOT NULL
                        THEN 'MASTER:' + CAST(ta.truck_id AS varchar(50))
                    WHEN ta.truck_source = 'MANUAL' AND ta.manual_plate_no IS NOT NULL
                        THEN 'MANUAL:' + LTRIM(RTRIM(ta.manual_plate_no))
                    ELSE ''
                END,
                dp.so_number,
                dp.mfg_no,
                dp.address,
                c.name,
                wo.brand
            ",
            [$shipDate]
        ))->filter(fn($r) => trim((string) ($r->row_key ?? '')) !== '');

        $summaryMap = $usageRows->groupBy('row_key')->map(function ($rows) {
            $rows = collect($rows);

            return [
                'job_summary' => $rows->map(function ($r) {
                    return [
                        'so_number' => trim((string) ($r->so_number ?? '')),
                        'mfg_no' => trim((string) ($r->mfg_no ?? '')),
                        'address' => trim((string) ($r->address ?? '')),
                        'customer_name' => trim((string) ($r->customer_name ?? '')) ?: '-',
                        'job_type' => trim((string) ($r->job_type ?? '')) ?: '-',
                        'assigned_weight' => (float) ($r->assigned_weight ?? 0),
                    ];
                })->values()->all(),

                'so_summary_text' => $rows->pluck('so_number')
                    ->map(fn($x) => trim((string) $x))
                    ->filter()
                    ->unique()
                    ->implode(', '),

                'mfg_summary_text' => $rows->pluck('mfg_no')
                    ->map(fn($x) => trim((string) $x))
                    ->filter()
                    ->unique()
                    ->implode(', '),
            ];
        });

        $masterRows = $masterRows->map(function ($r) use ($masterLoadRows, $summaryMap) {
            $load = (float) ($masterLoadRows->get($r->truck_id)->current_load ?? 0);
            $summary = $summaryMap->get($r->row_key, []);

            $r->current_load = $load;
            $r->remaining_capacity = max(0, (float) $r->max_load - $load);
            $r->job_summary = $summary['job_summary'] ?? [];
            $r->so_summary_text = $summary['so_summary_text'] ?? '';
            $r->mfg_summary_text = $summary['mfg_summary_text'] ?? '';

            return $r;
        });

        $manualRows = $manualRows->map(function ($r) use ($summaryMap) {
            $summary = $summaryMap->get($r->row_key, []);

            $r->job_summary = $summary['job_summary'] ?? [];
            $r->so_summary_text = $summary['so_summary_text'] ?? '';
            $r->mfg_summary_text = $summary['mfg_summary_text'] ?? '';

            return $r;
        });

        return $masterRows
            ->concat($manualRows)
            ->sortBy([
                ['truck_pick_type', 'asc'],
                ['plate_no', 'asc'],
            ])
            ->values();
    }

    public function truckBoard(Request $request)
    {
        $shipDate = trim((string) $request->query('ship_date', now()->toDateString()));

        try {
            $shipDate = Carbon::parse($shipDate)->toDateString();
        } catch (\Throwable $e) {
            $shipDate = now()->toDateString();
        }

        $latestAssignSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('MAX(id) as latest_assign_id, ord_id')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->groupBy('ord_id');

        $assignSumSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('ord_id, SUM(ISNULL(assigned_weight,0)) as assigned_weight_sum')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->groupBy('ord_id');

        $rows = $this->conn()
            ->table('delivery_plan_data as dp')
            ->leftJoinSub($latestAssignSub, 'la', function ($join) {
                $join->on('la.ord_id', '=', 'dp.ord_id');
            })
            ->leftJoinSub($assignSumSub, 'tas', function ($join) {
                $join->on('tas.ord_id', '=', 'dp.ord_id');
            })
            ->leftJoin('delivery_plan_truck_assign as ta', 'ta.id', '=', 'la.latest_assign_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("dp.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->selectRaw("
            dp.ord_id,
            dp.ship_posted_at,
            dp.window_at,
            dp.window_text,
            dp.so_number,
            dp.delivery_type,
            dp.part_number,
            dp.part_desc,
            p.f1 as part_f1,
            p.f2 as part_f2,
            p.f3 as part_f3,
            p.description as part_master_desc,
            dp.mfg_no,
            dp.qty,
            dp.line_qty,
            dp.sell_by_line,
            dp.address,
            dp.status,
            dp.remark,
            dp.edit_remark,
            dp.attach_docs,
            dp.attach_docs_other,
            dp.revision_number,

            c.name as customer_name,

            COALESCE(
                NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
            ) AS sales_name,

            ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum,

            ta.id as truck_assign_id,
            ta.truck_source,
            ta.truck_id,
            ta.manual_plate_no,
            ta.manual_driver_name,
            ta.manual_driver_phone,
            ta.manual_max_load,
            ta.manual_car_length,
            ta.manual_remark,
            ta.assigned_weight,
            ta.assigned_at,

            ta.driver_staff_id,
            ta.driver_name,
            ta.driver_phone,
            ta.helper1_staff_id,
            ta.helper1_name,
            ta.helper2_staff_id,
            ta.helper2_name,
            ta.helper3_staff_id,
            ta.helper3_name,

            tm.plate_no as master_plate_no,
            tm.driver_name as master_driver_name,
            tm.driver_phone as master_driver_phone,
            tm.max_load as master_max_load,
            tm.car_length as master_car_length,
            tm.remark as master_remark
        ")
            ->whereRaw("CAST(dp.ship_posted_at AS date) = ?", [$shipDate])
            ->whereIn(DB::raw("UPPER(ISNULL(dp.status, 'NEW'))"), ['NEW', 'ASSIGN'])
            ->orderByRaw("
            CASE 
                WHEN ta.id IS NULL THEN 1
                ELSE 0
            END
        ")
            ->orderByRaw("
            CASE 
                WHEN ta.truck_source = 'MANUAL' THEN ISNULL(ta.manual_plate_no, '')
                ELSE ISNULL(tm.plate_no, '')
            END
        ")
            ->orderBy('dp.window_at')
            ->orderBy('dp.so_number')
            ->get();

        $allMfgNos = [];

        foreach ($rows as $r) {
            $tokens = collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->values()
                ->all();

            foreach ($tokens as $mfg) {
                $allMfgNos[$mfg] = $mfg;
            }
        }

        $typeMap = [];
        if (!empty($allMfgNos)) {
            $workorders = DB::connection('pgsqlmfgw')
                ->table('workorder')
                ->select(['workordernumber', 'brand'])
                ->whereIn('workordernumber', array_values($allMfgNos))
                ->get();

            foreach ($workorders as $wo) {
                $typeMap[trim((string) $wo->workordernumber)] = trim((string) ($wo->brand ?? ''));
            }
        }

        $partNumbers = $rows->pluck('part_number')
            ->map(fn($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $stockMap = [];
        if (!empty($partNumbers)) {
            $stockRows = DB::connection('pgsqlw')
                ->table('parts')
                ->select(['partnumber', 'onhand', 'totalonhand'])
                ->whereIn('partnumber', $partNumbers)
                ->get();

            foreach ($stockRows as $st) {
                $stockMap[trim((string) $st->partnumber)] = (float) ($st->onhand ?? $st->totalonhand ?? 0);
            }
        }

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)])
            ->toArray();

        $normalizeDocText = function ($attachDocs, $attachDocsOther) use ($attachDocMap) {
            $codes = collect(explode(',', (string) $attachDocs))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->unique()
                ->values();

            $names = $codes->map(fn($code) => $attachDocMap[$code] ?? $code)->values();

            $other = trim((string) $attachDocsOther);
            if ($other !== '') {
                $names->push('อื่นๆ: ' . $other);
            }

            return $names->filter()->unique()->implode(', ');
        };

        $rows = $rows->map(function ($r) use ($stockMap, $normalizeDocText, $typeMap) {
            $isManual = strtoupper(trim((string) ($r->truck_source ?? ''))) === 'MANUAL';

            $plateNo = $isManual
                ? trim((string) ($r->manual_plate_no ?? ''))
                : trim((string) ($r->master_plate_no ?? ''));

            $driverName = trim((string) ($r->driver_name ?? ''));
            if ($driverName === '') {
                $driverName = $isManual
                    ? trim((string) ($r->manual_driver_name ?? ''))
                    : trim((string) ($r->master_driver_name ?? ''));
            }

            $driverPhone = trim((string) ($r->driver_phone ?? ''));
            if ($driverPhone === '') {
                $driverPhone = $isManual
                    ? trim((string) ($r->manual_driver_phone ?? ''))
                    : trim((string) ($r->master_driver_phone ?? ''));
            }

            $maxLoad = $isManual
                ? (float) ($r->manual_max_load ?? 0)
                : (float) ($r->master_max_load ?? 0);

            $carLength = $isManual ? $r->manual_car_length : $r->master_car_length;

            $truckRemark = $isManual
                ? trim((string) ($r->manual_remark ?? ''))
                : trim((string) ($r->master_remark ?? ''));

            $mfgTokens = collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->values();

            $r->type_display = $mfgTokens
                ->map(fn($mfg) => $typeMap[$mfg] ?? null)
                ->filter()
                ->unique()
                ->implode(', ');

            if ($r->type_display === '') {
                $r->type_display = '-';
            }

            $r->line_qty_display = ((int) ($r->sell_by_line ?? 0) === 1)
                ? (float) ($r->line_qty ?? 0)
                : null;

            $r->truck_plate_display = $plateNo !== '' ? $plateNo : 'ยังไม่ขึ้นรถ';
            $r->truck_group_key = $plateNo !== '' ? $plateNo : '__UNASSIGNED__';
            $r->truck_driver_name = $driverName;
            $r->truck_driver_phone = $driverPhone;
            $r->helper1_name_display = trim((string) ($r->helper1_name ?? ''));
            $r->helper2_name_display = trim((string) ($r->helper2_name ?? ''));
            $r->helper3_name_display = trim((string) ($r->helper3_name ?? ''));
            $r->truck_max_load = $maxLoad;
            $r->truck_car_length = $carLength;
            $r->truck_remark_display = $truckRemark;
            $r->stock_fg = $stockMap[trim((string) ($r->part_number ?? ''))] ?? 0;
            $r->attach_docs_text = $normalizeDocText($r->attach_docs ?? null, $r->attach_docs_other ?? null);

            $qty = (float) ($r->qty ?? 0);
            $assignedSum = (float) ($r->assigned_weight_sum ?? 0);
            $r->display_weight = $assignedSum > 0 ? $assignedSum : $qty;

            return $r;
        });

        $truckGroups = $rows->groupBy('truck_group_key')->map(function ($items, $key) {
            $first = $items->first();

            $totalQty = $items->sum(fn($r) => (float) ($r->qty ?? 0));
            $totalAssigned = $items->sum(fn($r) => (float) ($r->display_weight ?? 0));
            $maxLoad = (float) ($first->truck_max_load ?? 0);

            $helperNames = collect([
                $items->pluck('helper1_name_display')->filter()->first(),
                $items->pluck('helper2_name_display')->filter()->first(),
                $items->pluck('helper3_name_display')->filter()->first(),
            ])->filter()->implode(', ');

            return (object) [
                'truck_key'       => $key,
                'truck_label'     => $first->truck_plate_display ?? 'ยังไม่ขึ้นรถ',
                'driver_name'     => $first->truck_driver_name ?? '',
                'driver_phone'    => $first->truck_driver_phone ?? '',
                'helper_names'    => $helperNames,
                'max_load'        => $maxLoad,
                'car_length'      => $first->truck_car_length ?? null,
                'remark'          => $first->truck_remark_display ?? '',
                'item_count'      => $items->count(),
                'total_qty'       => $totalQty,
                'total_assigned'  => $totalAssigned,
                'remaining_load'  => $maxLoad > 0 ? max(0, $maxLoad - $totalAssigned) : null,
                'rows'            => $items->values(),
                'is_unassigned'   => $key === '__UNASSIGNED__',
            ];
        });

        $truckGroups = $truckGroups
            ->sortBy([
                ['is_unassigned', 'asc'],
                ['truck_label', 'asc'],
            ])
            ->values();

        $summary = [
            'ship_date'        => $shipDate,
            'truck_count'      => $truckGroups->where('is_unassigned', false)->count(),
            'unassigned_count' => $truckGroups->where('is_unassigned', true)->sum('item_count'),
            'item_count'       => $rows->count(),
            'assign_count'     => $rows->where('truck_group_key', '!=', '__UNASSIGNED__')->count(),
            'new_count'        => $rows->where('truck_group_key', '__UNASSIGNED__')->count(),
            'total_qty'        => $rows->sum(fn($r) => (float) ($r->qty ?? 0)),
            'total_weight'     => $rows->sum(fn($r) => (float) ($r->display_weight ?? 0)),
        ];

        return view('formdp.truck-board', compact(
            'shipDate',
            'summary',
            'truckGroups'
        ));
    }

    private function normalizeMaterial($f3): ?string
    {
        if ($f3 === null) {
            return null;
        }

        $value = strtoupper(trim((string) $f3));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', '', $value);

        if (in_array($value, ['0', '0.0', '0.00', '0.000', 'NULL', '-'], true)) {
            return null;
        }

        $value = str_replace('STAINLESS', '', $value);
        $value = str_replace('SUS', '', $value);
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function calcLineWeightKg(
        ?int $lineQty,
        $f1,
        $f2,
        $f3,
        ?string $description = null,
        ?string $partNumber = null
    ): ?float {
        $qty = (int) ($lineQty ?? 0);
        $dia = is_numeric($f1) ? (float) $f1 : 0.0;
        $lengthMm = is_numeric($f2) ? (float) $f2 : 0.0;
        $material = $this->normalizeMaterial($f3);

        $desc = strtoupper(trim((string) ($description ?? '')));
        $pn   = strtoupper(trim((string) ($partNumber ?? '')));

        if ($qty <= 0 || $dia <= 0 || $lengthMm <= 0 || $material === null) {
            return null;
        }

        // คิดเฉพาะ Bar / NCR
        $isBarLike =
            str_contains($desc, 'BAR') ||
            str_contains($desc, 'NCR') ||
            str_starts_with($pn, 'FI') ||
            str_starts_with($pn, 'FB') ||
            str_starts_with($pn, 'NC') ||
            str_starts_with($pn, 'FJ');

        if (!$isBarLike) {
            return null;
        }

        // กัน shape ที่ไม่ใช่เส้นกลม
        $blockedWords = [
            'ANGLE',
            'PIPE',
            'BOX',
            'PLATE',
            'RECTANGULAR',
            'SQUARE',
            'FLAT BAR',
            'TRIANGLE',
            'OVAL',
            'HOUSE SHAPE',
            'WEDGE',
            'RUBBER',
            'FRAME',
        ];

        foreach ($blockedWords as $word) {
            if (str_contains($desc, $word)) {
                return null;
            }
        }

        $lengthM = $lengthMm / 1000;

        return $qty * (($dia * $dia * 3.14 * 0.00793 * $lengthM) / 4);
    }

    private function getTruckUsageSummaryByShipDate(string $shipDate): array
    {
        $rows = $this->conn()
            ->table('delivery_plan_truck_assign as ta')
            ->join('delivery_plan_data as dp', 'dp.ord_id', '=', 'ta.ord_id')
            ->leftJoin('workorder as wo', function ($join) {
                $join->on(
                    DB::raw('wo.workordernumber COLLATE DATABASE_DEFAULT'),
                    '=',
                    DB::raw('dp.mfg_no COLLATE DATABASE_DEFAULT')
                );
            })
            ->whereRaw('CAST(ta.ship_posted_at AS date) = ?', [$shipDate])
            ->selectRaw("
            ta.truck_source,
            ta.truck_id,
            ta.manual_plate_no,
            ISNULL(dp.customer_name, '-') as customer_name,
            ISNULL(wo.brand, '-') as job_type,
            ISNULL(dp.so_number, '-') as so_number,
            ISNULL(dp.mfg_no, '-') as mfg_no,
            SUM(ISNULL(ta.assigned_weight,0)) as assigned_weight
        ")
            ->groupBy(
                'ta.truck_source',
                'ta.truck_id',
                'ta.manual_plate_no',
                'dp.customer_name',
                'wo.brand',
                'dp.so_number',
                'dp.mfg_no'
            )
            ->get();

        $map = [];

        foreach ($rows as $r) {
            $key = strtoupper((string)$r->truck_source) === 'MANUAL'
                ? 'MANUAL:' . trim((string)$r->manual_plate_no)
                : 'MASTER:' . (int)$r->truck_id;

            $map[$key]['job_summary'][] = [
                'customer_name' => (string)$r->customer_name,
                'job_type' => (string)$r->job_type,
                'assigned_weight' => (float)$r->assigned_weight,
            ];

            $map[$key]['so_set'][(string)$r->so_number] = true;
            $map[$key]['mfg_set'][(string)$r->mfg_no] = true;
        }

        foreach ($map as $key => $item) {
            $map[$key]['so_summary_text'] = implode(', ', array_keys($item['so_set'] ?? []));
            $map[$key]['mfg_summary_text'] = implode(', ', array_keys($item['mfg_set'] ?? []));
        }

        return $map;
    }

    private function fullStaffName(object $r): string
    {
        return trim(collect([
            $r->prefix_name ?? null,
            $r->first_name ?? null,
            $r->last_name ?? null,
        ])->filter(fn($x) => trim((string) $x) !== '')->implode(' '));
    }

    private function getTruckStaffMasterOptions(): array
    {
        $rows = $this->conn()
            ->table('delivery_plan_truck_staff_master')
            ->where('is_active', 1)
            ->orderBy('role_type')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $drivers = [];
        $helpers = [];

        foreach ($rows as $r) {
            $item = [
                'id' => (int) $r->id,
                'emp_code' => trim((string) ($r->emp_code ?? '')),
                'name' => $this->fullStaffName($r),
                'phone' => trim((string) ($r->phone ?? '')),
                'position_name' => trim((string) ($r->position_name ?? '')),
                'department_name' => trim((string) ($r->department_name ?? '')),
            ];

            if (strtoupper(trim((string) $r->role_type)) === 'DRIVER') {
                $drivers[] = $item;
            } else {
                $helpers[] = $item;
            }
        }

        return [
            'drivers' => $drivers,
            'helpers' => $helpers,
        ];
    }

    private function getTruckStaffDefaultsData(?int $truckId, ?string $manualPlateNo, ?string $shipDate): array
    {
        $truckId = (int) ($truckId ?? 0);
        $manualPlateNo = trim((string) $manualPlateNo);
        $shipDate = trim((string) $shipDate);

        $driver = null;
        $helpers = [null, null, null];

        if ($truckId > 0) {
            $rows = $this->conn()
                ->table('delivery_plan_truck_staff_map as m')
                ->join('delivery_plan_truck_staff_master as s', 's.id', '=', 'm.staff_id')
                ->where('m.truck_id', $truckId)
                ->where('m.is_active', 1)
                ->where('s.is_active', 1)
                ->orderBy('m.assign_role')
                ->orderBy('m.seq_no')
                ->get([
                    'm.assign_role',
                    'm.seq_no',
                    's.id',
                    's.emp_code',
                    's.prefix_name',
                    's.first_name',
                    's.last_name',
                    's.phone',
                ]);

            foreach ($rows as $r) {
                $item = [
                    'id' => (int) $r->id,
                    'name' => $this->fullStaffName($r),
                    'phone' => trim((string) ($r->phone ?? '')),
                ];

                if (strtoupper(trim((string) $r->assign_role)) === 'DRIVER') {
                    $driver = $item;
                } else {
                    $seq = max(1, (int) ($r->seq_no ?? 1));
                    if ($seq >= 1 && $seq <= 3) {
                        $helpers[$seq - 1] = $item;
                    }
                }
            }
        }

        if ($truckId <= 0 && $manualPlateNo !== '' && $shipDate !== '') {
            $last = $this->conn()
                ->table('delivery_plan_truck_assign')
                ->where('truck_source', 'MANUAL')
                ->where('manual_plate_no', $manualPlateNo)
                ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
                ->orderByDesc('id')
                ->first([
                    'driver_staff_id',
                    'driver_name',
                    'driver_phone',
                    'helper1_staff_id',
                    'helper1_name',
                    'helper2_staff_id',
                    'helper2_name',
                    'helper3_staff_id',
                    'helper3_name',
                ]);

            if ($last) {
                if (!empty($last->driver_name) || !empty($last->driver_staff_id)) {
                    $driver = [
                        'id' => $last->driver_staff_id ? (int) $last->driver_staff_id : null,
                        'name' => trim((string) ($last->driver_name ?? '')),
                        'phone' => trim((string) ($last->driver_phone ?? '')),
                    ];
                }

                $helpers = [
                    [
                        'id' => $last->helper1_staff_id ? (int) $last->helper1_staff_id : null,
                        'name' => trim((string) ($last->helper1_name ?? '')),
                    ],
                    [
                        'id' => $last->helper2_staff_id ? (int) $last->helper2_staff_id : null,
                        'name' => trim((string) ($last->helper2_name ?? '')),
                    ],
                    [
                        'id' => $last->helper3_staff_id ? (int) $last->helper3_staff_id : null,
                        'name' => trim((string) ($last->helper3_name ?? '')),
                    ],
                ];
            }
        }

        return [
            'driver' => $driver,
            'helpers' => $helpers,
        ];
    }

    private function getTruckStaffSnapshotFromRequest(Request $request): array
    {
        $driverId  = (int) $request->input('driver_staff_id', 0);
        $helper1Id = (int) $request->input('helper1_staff_id', 0);
        $helper2Id = 0;
        $helper3Id = 0;

        $ids = collect([$driverId, $helper1Id, $helper2Id, $helper3Id])
            ->filter(fn($x) => (int) $x > 0)
            ->unique()
            ->values();

        $staffMap = $ids->isEmpty()
            ? collect()
            : $this->conn()
            ->table('delivery_plan_truck_staff_master')
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        $driver = $staffMap->get($driverId);
        $helper1 = $staffMap->get($helper1Id);
        $helper2 = $staffMap->get($helper2Id);
        $helper3 = $staffMap->get($helper3Id);

        return [
            'driver_staff_id' => $driver ? (int) $driver->id : null,
            'driver_name' => $driver ? $this->fullStaffName($driver) : null,
            'driver_phone' => $driver ? trim((string) ($driver->phone ?? '')) : null,

            'helper1_staff_id' => $helper1 ? (int) $helper1->id : null,
            'helper1_name' => $helper1 ? $this->fullStaffName($helper1) : null,

            'helper2_staff_id' => $helper2 ? (int) $helper2->id : null,
            'helper2_name' => $helper2 ? $this->fullStaffName($helper2) : null,

            'helper3_staff_id' => $helper3 ? (int) $helper3->id : null,
            'helper3_name' => $helper3 ? $this->fullStaffName($helper3) : null,
        ];
    }

    public function truckStaffOptions(Request $request)
    {
        return response()->json($this->getTruckStaffMasterOptions());
    }

    public function truckStaffDefaults(Request $request)
    {
        $truckId = (int) $request->query('truck_id', 0);
        $manualPlateNo = trim((string) $request->query('manual_plate_no', ''));
        $shipDate = trim((string) $request->query('ship_posted_at', ''));

        return response()->json(
            $this->getTruckStaffDefaultsData($truckId ?: null, $manualPlateNo, $shipDate)
        );
    }
}
