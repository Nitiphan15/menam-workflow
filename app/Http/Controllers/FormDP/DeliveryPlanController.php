<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Support\FormDP\PieceSalePolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeliveryPlanController extends Controller
{
    private const SALES8_USER_IDS = [50, 51];

    private function conn()
    {
        return DB::connection('sqlsrv_menam');
    }

    private function isSales8User(?int $userId = null): bool
    {
        $uid = $userId ?? (auth()->check() ? (int) auth()->id() : 0);
        return in_array($uid, self::SALES8_USER_IDS, true);
    }

    /* =========================================================
     * LOOKUPS
     * ========================================================= */
    public function customerLookup(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) return response()->json(['items' => []]);

        $qLike = '%' . $q . '%';

        // customer + employees (saleperson_id)
        $rows = $this->conn()->table('customer as c')
            ->leftJoin('employees as e', 'e.id', '=', 'c.saleperson_id')
            ->selectRaw("
                TOP 20
                c.id,
                c.customernumber,
                c.name,
                c.saleperson_id as sales_id,
                e.login as sales_login,
                e.name  as sales_name
            ")
            ->where(function ($w) use ($qLike) {
                $w->where('c.customernumber', 'like', $qLike)
                    ->orWhere('c.name', 'like', $qLike);
            })
            ->orderBy('c.name')
            ->get();

        $items = $rows->map(function ($r) {
            $login = trim((string)($r->sales_login ?? ''));
            $name  = trim((string)($r->sales_name ?? ''));

            $salesText = $this->salesDisplayText($login, $name);

            return [
                'id'         => (int)$r->id,
                'text'       => trim((string)($r->customernumber ?? '') . ' — ' . (string)($r->name ?? '')),
                'sales_id'   => (int)($r->sales_id ?? 0),
                'sales_text' => $salesText,
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    // Sales autocomplete จาก employees
    public function salesLookup(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 1) return response()->json(['items' => []]);

        $qLike = '%' . $q . '%';
        $qUp   = mb_strtoupper($q);

        $rows = $this->conn()->table('employees as e')
            ->selectRaw("
                TOP 20
                e.id,
                e.login,
                e.name
            ")
            ->where(function ($w) use ($qLike, $qUp) {
                $w->whereRaw("CAST(e.id AS NVARCHAR(20)) LIKE ?", [$qLike])
                    ->orWhereRaw("UPPER(ISNULL(e.login,'')) LIKE ?", ['%' . $qUp . '%'])
                    ->orWhereRaw("UPPER(ISNULL(e.name,'')) LIKE ?",  ['%' . $qUp . '%']);
            })
            ->orderBy('e.id')
            ->get();

        $items = $rows->map(function ($r) {
            $id    = (int)($r->id ?? 0);
            $login = trim((string)($r->login ?? ''));
            $name  = trim((string)($r->name ?? ''));

            $nameTh = $this->mapSalesThai($login, $name);
            $text   = trim(($login ? "{$login} " : '') . ($nameTh ?: $name));

            return [
                'id'            => $id,
                'login'         => $login,
                'sales_name'    => $name,
                'sales_name_th' => $nameTh,
                'text'          => $text,
            ];
        })->filter(fn($x) => $x['id'] > 0)->values();

        return response()->json(['items' => $items]);
    }

    /* =========================================================
     * INDEX
     * ========================================================= */
    public function index(Request $request, string $date = null)
    {
        $date = $date ?: $request->query('date');
        $day  = $date ? Carbon::parse($date) : Carbon::now();
        $day  = $day->startOfDay();

        $editId = trim((string)$request->query('edit', ''));
        $isEdit = ($editId !== '');
        $isSales8User = $this->isSales8User();

        $header = [
            'window_time'       => '17:00',
            'ship_posted_date'  => $day->toDateString(),
            'due_date'          => $day->toDateString(),
            'delay_reason'      => '',
            'tel'               => '',
            'remark'            => '',
            'attach_docs'       => [],
            'attach_docs_other' => '',
        ];

        $lines = [];
        $editMailSentRevisions = [];

        if ($isEdit) {
            $row = $this->conn()
                ->table('delivery_plan_data as d')
                ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
                ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
                ->leftJoin('parts as p', function ($join) {
                    $join->on(
                        DB::raw('p.partnumber COLLATE DATABASE_DEFAULT'),
                        '=',
                        DB::raw('d.part_number COLLATE DATABASE_DEFAULT')
                    );
                })
                ->where('d.ord_id', $editId)
                ->select([
                    'd.*',
                    'c.customernumber',
                    'c.name as cm_name',
                    'e.login as emp_login',
                    'e.name  as emp_name',
                    'p.id as parts_id',
                    'p.ref_unit',
                    'p.ref_unit_qty',
                ])
                ->selectRaw('COALESCE(c.f3, c.f4) as cm_address')
                ->first();

            if (!$row) return back()->withErrors(["ไม่พบรายการ ord_id={$editId}"]);



            $day = Carbon::parse($row->due_date)->startOfDay();

            $shipDate = $row->ship_posted_at ? Carbon::parse($row->ship_posted_at)->toDateString() : $day->toDateString();
            $winTime  = $row->window_at ? Carbon::parse($row->window_at)->format('H:i') : '08:00';

            // งานที่ถูกจัดรถแล้ว → แก้ไขไม่ได้ (กันเข้าหน้าแก้ไขผ่าน URL ตรง ๆ)
            if ($this->isDispatchAssignedForOrd((string) $row->ord_id)) {
                $soText = trim((string) ($row->so_number ?? '')) !== '' ? 'SO ' . trim((string) $row->so_number) : 'งานนี้';
                return redirect()
                    ->route('dp.inquiry')
                    ->with('error', "{$soText} ถูกจัดรถหรือกำหนดเป็นงานพิเศษแล้ว กรุณาติดต่อ Logistics เพื่อยกเลิกการจัดส่งก่อนแก้ไข");
            }

            $editMailSentRevisions = $this->sentRevisionNumbersForShipDate($shipDate);

            $header = [
                'due_date'          => Carbon::parse($row->due_date)->toDateString(),
                'ship_posted_date'  => $shipDate,
                'window_time'       => $winTime,
                'tel'               => (string)($row->tel ?? ''),
                'remark'            => (string)($row->remark ?? ''),
                'delay_reason'      => (string)($row->due_date_remark ?? ''),
                'attach_docs'       => $row->attach_docs ? array_values(array_filter(array_map('trim', explode(',', (string)$row->attach_docs)))) : [],
                'attach_docs_other' => (string)($row->attach_docs_other ?? ''),
            ];

            $login = trim((string)($row->emp_login ?? ''));
            $name  = trim((string)($row->emp_name ?? ''));
            $thai  = $this->mapSalesThai($login, $name);
            $salesText = $this->salesDisplayText($login, $name);

            $partNoForCalc = strtoupper(trim((string)($row->part_number ?? '')));
            $refUnit = strtoupper(trim((string)($row->ref_unit ?? '')));
            $refUnitQty = $this->toNumberOrNull($row->ref_unit_qty ?? null);

            $kgPerLine = null;

            if (
                $refUnit === '03' &&
                str_ends_with($partNoForCalc, 'E') &&
                $refUnitQty !== null &&
                $refUnitQty > 0
            ) {
                $kgPerLine = $refUnitQty;
            }

            $editQtyKg = $this->toNumberOrNull($row->qty ?? null);
            $editLineQty = $this->toNumberOrNull($row->line_qty ?? null);
            $isPieceSale = $isSales8User || PieceSalePolicy::appliesTo($row->part_number ?? null);
            if (!$isPieceSale && $editQtyKg !== null && $editQtyKg <= 0) {
                $editQtyKg = null;
            }

            if ($editQtyKg === null && $editLineQty !== null && $editLineQty > 0 && $kgPerLine !== null) {
                $editQtyKg = $editLineQty * $kgPerLine;
            }

            if ($editQtyKg === null && !in_array(strtoupper((string) ($row->delivery_type ?? 'SO')), ['ACID', 'SPECIAL'], true)) {
                $mfgNoForQty = trim((string) ($row->mfg_no ?? ''));

                if ($mfgNoForQty !== '' && !str_contains($mfgNoForQty, ',')) {
                    $mfgQty = $this->toNumberOrNull(
                        $this->conn()
                            ->table('workorder')
                            ->where('workordernumber', $mfgNoForQty)
                            ->value('qty')
                    );

                    if ($mfgQty !== null && $mfgQty > 0) {
                        $editQtyKg = $mfgQty;
                    }
                }

                if ($editQtyKg === null) {
                    $soQty = $this->toNumberOrNull(
                        $this->conn()
                            ->table('saleorder')
                            ->where('ordnumber', trim((string) ($row->so_number ?? '')))
                            ->where('parts_id', (int) ($row->parts_id ?? 0))
                            ->value('ordered_qty')
                    );

                    if ($soQty !== null && $soQty > 0) {
                        $editQtyKg = $soQty;
                    }
                }
            }

            $lines = [[
                'id'                => (string)$row->ord_id,
                'mode'              => (string)($row->delivery_type ?? 'SO'),
                'so_number'         => (string)($row->so_number ?? ''),
                'customer_id'       => (string)($row->customer_id ?? ''),
                'customer_name'     => trim((string) ($row->customer_name ?? '')) !== ''
                    ? trim((string) $row->customer_name)
                    : trim((string)($row->customernumber ?? '') . ' — ' . (string)($row->cm_name ?? '')),
                'sales_id'          => (string)($row->sales_id ?? ''),
                'sales_text'        => $salesText,
                'parts_id'          => (int)($row->parts_id ?? 0),
                'part_no'           => (string)($row->part_number ?? ''),
                'part_desc'         => (string)($row->part_desc ?? ''),
                'mfg_no'            => (string)($row->mfg_no ?? ''),
                'qty_kg'            => $editQtyKg === null ? '' : (string)$editQtyKg,
                'stock_fg'          => (string)($row->stock_qty ?? ''),
                'delivery_location' => (string)($row->address ?? ''),
                'sell_by_line'      => (int)($row->sell_by_line ?? 0),
                'sell_by_line_qty'  => (string)($row->line_qty ?? ''),
                'revision_number'   => (int)($row->revision_number ?? 0),
                'kg_per_line' => $kgPerLine,
                'edit_remark'       => '',
            ]];
        }

        $rows = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('revision_master as r', 'r.revision_number', '=', 'd.revision_number')
            ->whereDate('d.due_date', '=', $day->toDateString())
            ->orderByDesc('d.ord_id')
            ->select([
                'd.*',
                'c.customernumber',
                'c.name as cm_name',
                'e.login as emp_login',
                'e.name  as emp_name',
                'r.color_code',
            ])
            ->selectRaw('COALESCE(c.f3, c.f4) as cm_address')
            ->get()->map(fn($x) => (array)$x)->values()->all();

        $attachMasters = $this->conn()
            ->table('attach_docs_master')->where('active', 1)->orderBy('id')
            ->get()->map(fn($x) => (array)$x)->values()->all();

        $piecePartNumbers = PieceSalePolicy::PART_NUMBERS;

        return view('formdp.index', compact('day', 'rows', 'lines', 'header', 'attachMasters', 'isEdit', 'editMailSentRevisions', 'piecePartNumbers'));
    }

    /* =========================================================
     * STORE
     * ========================================================= */
    public function store(Request $request)
    {
        $request->validate([
            'due_date'            => ['required', 'date'],
            'ship_posted_date'    => ['required', 'date'],
            'window_time'         => ['required', 'date_format:H:i'],
            'window_text'         => ['nullable', 'string', 'max:50'],
            'mfg_no'              => ['nullable', 'string'],
            'lines'               => ['array'],

            'lines.*.qty_kg'      => ['nullable', 'numeric'],
            'lines.*.stock_fg'    => ['nullable', 'numeric'],
            'lines.*.sales_id'    => ['nullable'],
            'lines.*.edit_remark' => ['nullable', 'string'],
            'lines.*.revision_number' => ['nullable', 'integer', 'min:0'],
        ]);

        $userLogin = auth()->check()
            ? (auth()->user()->login ?? auth()->user()->id ?? auth()->user()->name ?? 'system')
            : 'system';
        $isSales8User = $this->isSales8User();
        $firstLine = $request->input('lines.0', []);
        $isPieceSale = $isSales8User || PieceSalePolicy::appliesTo($firstLine['part_no'] ?? null);


        $rules = [
            'due_date'         => ['required', 'date'],
            'ship_posted_date' => ['required', 'date'],
            'window_time'      => ['required', 'date_format:H:i'],
            'window_text'      => ['nullable', 'string', 'max:50'],

            'lines'               => ['required', 'array', 'min:1'],
            'lines.*.mode'        => ['required', 'in:SO,ACID,SPECIAL'],
            'lines.*.qty_kg'      => ['required', 'numeric', 'gt:0'],
            'lines.*.stock_fg'    => ['nullable', 'numeric', 'min:0'],

            // ตัวนี้ถ้าเป็น SO ค่อย required (เช็คใน after)
            'lines.*.sales_id'    => ['nullable'],
            'lines.*.customer_id' => ['nullable'],
            'lines.*.customer_name' => ['nullable', 'string', 'max:255'],

            'lines.*.part_no'      => ['nullable', 'string', 'max:60'],
            'lines.*.part_desc'   => ['nullable', 'string', 'max:255'],
            'lines.*.delivery_location' => ['required', 'string', 'max:500'],
            'lines.*.edit_remark' => ['nullable', 'string', 'max:500'],
            'lines.*.revision_number' => ['nullable', 'integer', 'min:0'],

            'lines.*.sell_by_line'     => ['nullable', 'in:0,1'],
            'lines.*.sell_by_line_qty' => ['nullable', 'integer', 'min:1'],
        ];

        if ($isPieceSale) {
            $rules['lines.*.qty_kg'] = ['nullable', 'numeric', 'min:0'];
            $rules['lines.*.sell_by_line_qty'] = ['required', 'integer', 'min:1'];
        }

        $messages = [
            'due_date.required' => 'กรุณาเลือกวันที่กำหนดส่ง (Due date)',
            'ship_posted_date.required' => 'กรุณาเลือกวันที่กำหนดส่งสินค้า',
            'window_time.required' => 'กรุณาเลือกเวลาในช่วงรับสินค้า',
            'window_time.date_format' => 'รูปแบบเวลาไม่ถูกต้อง (HH:MM)',

            'lines.required' => 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ',
            'lines.*.mode.required' => 'กรุณาเลือกโหมด (SO/ACID/Special)',
            'lines.*.mode.in' => 'โหมดไม่ถูกต้อง',
            'lines.*.qty_kg.required' => 'กรุณากรอกจำนวน (kg) ',
            'lines.*.qty_kg.gt' => 'จำนวน (kg) ต้องมากกว่า 0',
            'lines.*.part_desc.max' => 'Part Description ยาวเกิน 255 ตัวอักษร',
            'lines.*.delivery_location.required' => 'กรุณาระบุสถานที่ส่ง',
            'lines.*.delivery_location.max'      => 'สถานที่ส่งยาวเกิน 500 ตัวอักษร',
            'lines.*.sell_by_line.in' => 'ค่าขายแบบระบุเส้นไม่ถูกต้อง',
            'lines.*.sell_by_line_qty.min' => 'Qty ระบุเส้นต้องมากกว่า 0',
        ];

        $deliveryMode = strtoupper(trim((string)($firstLine['mode'] ?? 'SO')));
        $deliveryMode = in_array($deliveryMode, ['SO', 'ACID', 'SPECIAL'], true) ? $deliveryMode : 'SO';

        $sellByLineInput = (string)($firstLine['sell_by_line'] ?? '0');
        $isSellByLine = ($sellByLineInput === '1' || strtolower($sellByLineInput) === 'true');
        if ($isPieceSale) {
            $isSellByLine = true;
        }

        $qtyKgInput = $this->toNumberOrNull($firstLine['qty_kg'] ?? null);
        $lineQtyInput = $this->toNumberOrNull($firstLine['sell_by_line_qty'] ?? null);

        if (
            !$isPieceSale &&
            trim((string)($firstLine['id'] ?? '')) !== '' &&
            ($qtyKgInput === null || $qtyKgInput <= 0)
        ) {
            $fallbackQtyKg = $this->resolveEditQtyKg($firstLine);
            if ($fallbackQtyKg !== null && $fallbackQtyKg > 0) {
                $firstLine['qty_kg'] = (string)$fallbackQtyKg;
                $request->merge([
                    'lines' => array_replace($request->input('lines', []), [0 => $firstLine]),
                ]);
                $qtyKgInput = $fallbackQtyKg;
            }
        }

        // ถ้าขายแบบระบุเส้น ให้ใช้ Qty ระบุเส้นไปเทียบกับ SO/MFG qty
        // ถ้าไม่ใช่ระบุเส้น ค่อยใช้ KG
        $qtyInputForMax = $isSellByLine
            ? (float)($lineQtyInput ?? 0)
            : (float)($qtyKgInput ?? 0);

        $ordnumber = trim((string) ($firstLine['so_number'] ?? ''));
        $mfgNo     = trim((string) ($firstLine['mfg_no'] ?? ''));
        $partsID   = (int) ($firstLine['parts_id'] ?? 0);

        $maxAllowed = null;
        $isManualMfg = $request->boolean('is_manual_mfg');

        // โหมดที่ไม่ผูก Sales Order (กรอก Part เอง): ACID = ส่งกัดกรด, SPECIAL = งานพิเศษ
        $isNonSoMode = in_array($deliveryMode, ['ACID', 'SPECIAL'], true);

        if (!$isNonSoMode && $mfgNo !== '' && !$isManualMfg) {
            $mfg = $this->conn()->table('workorder')
                ->where('workordernumber', $mfgNo)
                ->first();

            if (!$mfg) {
                return back()
                    ->withInput()
                    ->withErrors(['lines.0.mfg_no' => 'ไม่พบ MFG No']);
            }

            $maxAllowed = (float) ($mfg->qty ?? 0);
        } elseif (!$isNonSoMode) {
            $so = $this->conn()->table('saleorder')
                ->where('ordnumber', $ordnumber)
                ->where('parts_id', $partsID)
                ->first();

            if (!$so) {
                return back()
                    ->withInput()
                    ->withErrors(['lines.0.so_number' => 'ไม่พบ Sales Order']);
            }

            $maxAllowed = (float) ($so->ordered_qty ?? 0);
        }

        if ($maxAllowed !== null && $qtyInputForMax > $maxAllowed) {
            $maxErrorField = $isSellByLine
                ? 'lines.0.sell_by_line_qty'
                : 'lines.0.qty_kg';

            $maxErrorText = $isSellByLine
                ? 'Qty ' . ($isPieceSale ? 'ชิ้น' : 'ระบุเส้น') . ' ห้ามเกิน ' . number_format((float)$maxAllowed, 0) . ' ' . ($isPieceSale ? 'ชิ้น' : 'เส้น')
                : 'จำนวน KG ห้ามเกิน ' . number_format((float)$maxAllowed, 3) . ' KG';

            return back()
                ->withInput()
                ->withErrors([
                    $maxErrorField => $maxErrorText,
                ]);
        }
        $validator = Validator::make($request->all(), $rules, $messages);
        // เงื่อนไขตามโหมด: ACID ต้องกรอก part+part_desc เอง / SO ต้องมี sales_id (หรือ SO number แล้วแต่ระบบ)
        $validator->after(function ($v) use ($request) {
            $lines = $request->input('lines', []);
            foreach ($lines as $i => $line) {
                $mode = strtoupper((string)($line['mode'] ?? 'SO'));

                // ACID (ส่งกัดกรด) และ SPECIAL (งานพิเศษ) ต้องกรอก Part เอง (ไม่ผูก SO)
                if (in_array($mode, ['ACID', 'SPECIAL'], true)) {
                    $modeLabel = $mode === 'SPECIAL' ? 'งานพิเศษ' : 'ACID';
                    if (blank($line['part_no'] ?? null)) {
                        $v->errors()->add("lines.$i.part_no", "โหมด {$modeLabel} ต้องกรอก Part");
                    }
                    if (blank($line['part_desc'] ?? null)) {
                        $v->errors()->add("lines.$i.part_desc", "โหมด {$modeLabel} ต้องกรอก Part Description");
                    }
                }

                if ($mode === 'SPECIAL' && blank($line['customer_name'] ?? null)) {
                    $v->errors()->add("lines.$i.customer_name", 'โหมดงานพิเศษต้องกรอกชื่อลูกค้า/หน่วยงาน');
                }

                if ($mode === 'SO') {
                    if (blank($line['sales_id'] ?? null)) {
                        $v->errors()->add("lines.$i.sales_id", 'โหมด SO ต้องเลือก Sales');
                    }
                }
            }
        });
        $validator->validate();

        $erpDue = Carbon::parse($request->input('due_date'))->startOfDay();

        $shipPostedDate = Carbon::parse($request->input('ship_posted_date'))->toDateString(); // YYYY-mm-dd
        $windowTime     = (string)$request->input('window_time'); // HH:ii

        // เก็บลงคอลัมน์เดิม (datetime)
        $shipPostedAt = Carbon::parse($shipPostedDate . ' 00:00:00');
        $windowAt     = Carbon::parse($erpDue->toDateString() . ' ' . $windowTime . ':00');

        $windowText = trim((string)$request->input('window_text', ''));
        $windowText = $windowText === '' ? null : $windowText;

        // attachments (header)
        $docs = $request->input('attach_docs', []);
        $docs = is_array($docs) ? $docs : [$docs];
        $docs = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $docs))));
        $docs = array_filter($docs, fn($x) => $x !== '' && strtolower($x) !== 'array');
        $docs = array_values(array_unique($docs));

        $allowed = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('code')
            ->map(fn($x) => trim((string)$x))
            ->filter()
            ->values()
            ->all();

        $docs = array_values(array_filter($docs, fn($x) => in_array($x, $allowed, true)));

        $docsOtherText = trim((string)$request->input('attach_docs_other', ''));

        $OTHER_CODE = 'OTHER';
        if ($docsOtherText !== '' && !in_array($OTHER_CODE, $docs, true)) $docs[] = $OTHER_CODE;

        if (in_array($OTHER_CODE, $docs, true)) {
            $request->validate(['attach_docs_other' => ['required', 'string', 'max:255']]);
        }

        $attachDocs = implode(',', $docs);
        $hasDoc = ($attachDocs !== '' || $docsOtherText !== '') ? 1 : 0;

        $delayReason = trim((string)$request->input('delay_reason', ''));
        $tel         = trim((string)$request->input('tel', ''));
        $remark      = trim((string)$request->input('remark', ''));

        $userId = auth()->check() ? (int)auth()->id() : null;

        $lines = $request->input('lines', []);
        if (!is_array($lines)) $lines = [];

        $this->conn()->beginTransaction();

        try {
            foreach ($lines as $ln) {
                if (!is_array($ln)) continue;

                $ordId    = trim((string)($ln['id'] ?? ''));

                $qty      = trim((string)($ln['qty_kg'] ?? ''));
                $part     = trim((string)($ln['part_no'] ?? ''));
                $custId   = trim((string)($ln['customer_id'] ?? ''));
                $custName = trim((string)($ln['customer_name'] ?? ''));
                $deliveryType = strtoupper(trim((string)($ln['mode'] ?? 'SO')));
                $deliveryType = in_array($deliveryType, ['SO', 'ACID', 'SPECIAL'], true) ? $deliveryType : 'SO';

                // skip empty line (create เท่านั้น)
                if ($ordId === '' && $qty === '' && $part === '' && $custId === '' && $custName === '') continue;

                if ($custId === '') {
                    $custId = $this->findCustomerIdByName($custName);
                }
                if ($custId === '' && $deliveryType !== 'SPECIAL') {
                    throw new \RuntimeException("ไม่พบ Customer ใน master: {$custName}");
                }

                $manualCustomerName = $deliveryType === 'SPECIAL' && $custId === ''
                    ? $custName
                    : null;

                $sellByLine = (string)($ln['sell_by_line'] ?? '0');
                $sellByLine = ($sellByLine === '1' || strtolower($sellByLine) === 'true') ? 1 : 0;

                // sales_id: manual ได้ / หรือไม่กรอกให้ดึงจาก customer.saleperson_id
                $salesIdInput = trim((string)($ln['sales_id'] ?? ''));
                if ($salesIdInput === '' && $custId !== '') {
                    $salesIdInput = (string)$this->conn()->table('customer')->where('id', $custId)->value('saleperson_id');
                }
                $salesIdInput = trim((string)$salesIdInput);
                $salesId = $salesIdInput === '' ? null : (int)$salesIdInput;

                // งานพิเศษ (Special): ผู้รับผิดชอบเป็น Export (ไม่ผูก Sales จริง)
                // กลุ่ม EXPORT ในหน้า Inquiry มาจาก delivery_type='SPECIAL' จึงเคลียร์ sales_id
                if ($deliveryType === 'SPECIAL') {
                    $salesId = null;
                }

                $soNo = trim((string)($ln['so_number'] ?? ''));

                $sellByLine = (string)($ln['sell_by_line'] ?? '0');
                $sellByLine = ($sellByLine === '1' || strtolower($sellByLine) === 'true') ? 1 : 0;
                $isPieceSale = $isSales8User || PieceSalePolicy::appliesTo($ln['part_no'] ?? null);
                if ($isPieceSale) {
                    $sellByLine = 1;
                }

                $sellByLineQty = $this->toNumberOrNull($ln['sell_by_line_qty'] ?? null);
                if ($sellByLine !== 1) {
                    $sellByLineQty = null;
                }

                $qtyKg = $this->toNumberOrNull($ln['qty_kg'] ?? null);

                if ($isPieceSale) {
                    if ($sellByLineQty === null || $sellByLineQty <= 0) {
                        throw new \RuntimeException('กรุณากรอกจำนวนชิ้น');
                    }

                    $qtyKg = 0;
                } elseif ($sellByLine === 1 && $sellByLineQty !== null) {
                    $calcQtyKg = $this->calcKgFromLineQty(
                        (int)($ln['parts_id'] ?? 0),
                        (float)$sellByLineQty
                    );

                    if ($calcQtyKg === null) {
                        throw new \RuntimeException('ไม่สามารถคำนวณ KG จากจำนวนเส้นได้ กรุณาตรวจสอบ Part / ref_unit / ref_unit_qty');
                    }

                    $qtyKg = $calcQtyKg;
                }

                if (in_array($deliveryType, ['ACID', 'SPECIAL'], true)) $soNo = '';

                $payload = [
                    // header
                    'due_date'           => $erpDue,
                    'window_at'          => $windowAt,
                    'window_text'        => $windowText,
                    'ship_posted_at'     => $shipPostedAt,
                    'due_date_remark'    => $delayReason,
                    'tel'                => $tel,
                    'remark'             => $remark,
                    'attach_docs'        => $attachDocs,
                    'attach_docs_other'  => $docsOtherText,
                    'has_doc'            => $hasDoc,

                    // line
                    'delivery_type'      => $deliveryType,
                    'so_number'          => $soNo,
                    'customer_id'        => $custId === '' ? null : $custId,
                    'customer_name'      => $manualCustomerName,
                    'sales_id'           => $salesId,

                    'part_number'        => trim((string)($ln['part_no'] ?? '')),
                    'part_desc'          => trim((string)($ln['part_desc'] ?? '')),
                    'mfg_no'             => trim((string)($ln['mfg_no'] ?? '')),
                    'qty'                => $qtyKg,
                    'stock_qty'          => $this->toNumberOrNull($ln['stock_fg'] ?? null),
                    'address'            => trim((string)($ln['delivery_location'] ?? '')),
                    'sell_by_line'       => $sellByLine,
                    'line_qty'   => $sellByLineQty,
                ];

                foreach ($payload as $k => $v) {
                    if (is_array($v)) throw new \RuntimeException("PAYLOAD[$k] is array: " . json_encode($v));
                }

                if ($ordId !== '') {
                    $row = $this->conn()->table('delivery_plan_data')
                        ->select([
                            'ord_id',
                            'ship_posted_at',
                            'window_at',
                            'window_text',
                            'due_date_remark',
                            'tel',
                            'remark',
                            'attach_docs',
                            'attach_docs_other',
                            'has_doc',
                            'delivery_type',
                            'so_number',
                            'customer_id',
                            'customer_name',
                            'sales_id',
                            'part_number',
                            'part_desc',
                            'mfg_no',
                            'qty',
                            'stock_qty',
                            'address',
                            'sell_by_line',
                            'line_qty',
                            'revision_number',
                        ])
                        ->where('ord_id', $ordId)
                        ->first();

                    if (!$row) throw new \RuntimeException("ไม่พบรายการ ord_id={$ordId}");

                    // งานที่ถูกจัดรถแล้ว → ห้ามแก้ไข (กันยอดในแผนกับยอดจัดรถไม่ตรงกัน)
                    if ($this->isDispatchAssignedForOrd($ordId)) {
                        $soText = trim((string) ($row->so_number ?? '')) !== '' ? 'SO ' . trim((string) $row->so_number) : 'งานนี้';
                        throw new \RuntimeException(
                            "{$soText} ถูกจัดรถหรือกำหนดเป็นงานพิเศษแล้ว กรุณาติดต่อ Logistics เพื่อยกเลิกการจัดส่งก่อนแก้ไข"
                        );
                    }

                    $editRemark = trim((string)($ln['edit_remark'] ?? ''));
                    if ($editRemark === '') throw new \RuntimeException("กรุณากรอก Edit Remark ตอนแก้ไข");

                    $payload['edit_remark'] = $editRemark;
                    $revisionInput = $ln['revision_number'] ?? null;
                    if ($revisionInput === null || $revisionInput === '') {
                        throw new \RuntimeException("กรุณาระบุ Revision ตอนแก้ไข");
                    }

                    $payload['revision_number'] = (int) $revisionInput;
                    $newShipDate = $shipPostedAt->toDateString();
                    $sentRevisions = $this->sentRevisionNumbersForShipDate($newShipDate);

                    if (!empty($sentRevisions)) {
                        $requestedRevision = (int) $payload['revision_number'];
                        $sentRevisionText = implode(', ', $sentRevisions);

                        if (in_array($requestedRevision, $sentRevisions, true)) {
                            throw new \RuntimeException(
                                "วันที่ส่งสินค้า {$newShipDate} revision {$requestedRevision} เคยส่งเมลแล้ว กรุณาใช้ Revision ใหม่ที่ยังไม่เคยส่งเมล (ที่เคยส่งแล้ว: {$sentRevisionText})"
                            );
                        }
                    }

                    // due_date ห้ามแก้ไขตอน update
                    unset($payload['due_date']);

                    // อนุญาตให้แก้ ship_posted_at แล้ว (เอา unset ออก)
                    // unset($payload['ship_posted_at']);

                    $payload['revise_by'] = $userId;

                    $this->conn()->table('delivery_plan_data')
                        ->where('ord_id', $ordId)
                        ->update($payload);
                } else {
                    // create เหมือนเดิม...

                    $revisionInput = $ln['revision_number'] ?? null;

                    if ($revisionInput === null || $revisionInput === '') {
                        throw new \RuntimeException("กรุณาระบุ Revision ตอนแก้ไข");
                    }

                    $payload['status']          = 'NEW';
                    $payload['revision_number'] = 0;
                    $payload['created_at']      = DB::raw('GETDATE()');
                    $payload['created_by']      = $userLogin;
                    $payload['revision_number'] = (int) $revisionInput;
                    //dd($ln, $revisionInput, $payload['revision_number'], $payload);
                    $this->conn()->table('delivery_plan_data')->insert($payload);
                }
            }

            $this->conn()->commit();
        } catch (\Throwable $e) {
            $this->conn()->rollBack();
            return back()->withErrors([$e->getMessage()])->withInput();
        }

        $redirect = redirect()->back()->with('ok', 'บันทึกสำเร็จ')->with('reset_form', 1);
        $continuePayload = $this->buildContinueSameSoPayload($request);
        if ($continuePayload !== null) {
            $redirect->with('dp_continue_same_so', $continuePayload);
        }

        return $redirect;
    }

    /* =========================================================
     * DUPLICATE CHECK (SO + MFG)
     * ========================================================= */
    public function duplicateCheck(Request $request)
    {
        $soNumber   = trim((string) $request->query('so_number', ''));
        $mfgNo      = trim((string) $request->query('mfg_no', ''));
        $excludeOrd = trim((string) $request->query('exclude_ord_id', ''));

        if ($soNumber === '' && $mfgNo === '') {
            return response()->json(['items' => []]);
        }

        $q = $this->conn()->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->whereRaw("UPPER(ISNULL(d.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')")
            ->select([
                'd.ord_id',
                'd.so_number',
                'd.mfg_no',
                'd.part_number',
                'd.part_desc',
                'd.qty',
                'd.line_qty',
                'd.sell_by_line',
                'd.due_date',
                'd.ship_posted_at',
                'd.status',
                'd.revision_number',
                'd.customer_id',
                'c.customernumber',
                'c.name as customer_name',
                'e.login as sales_login',
                'e.name as sales_name',
            ]);

        if ($soNumber !== '') $q->where('d.so_number', $soNumber);
        if ($mfgNo !== '')    $q->where('d.mfg_no', $mfgNo);
        if ($excludeOrd !== '') $q->where('d.ord_id', '!=', $excludeOrd);

        $rows = $q->orderByDesc('d.ord_id')->limit(20)->get();

        $items = $rows->map(function ($r) {
            return [
                'ord_id'          => (int) $r->ord_id,
                'so_number'       => (string) ($r->so_number ?? ''),
                'mfg_no'          => (string) ($r->mfg_no ?? ''),
                'part_number'     => (string) ($r->part_number ?? ''),
                'part_desc'       => (string) ($r->part_desc ?? ''),
                'qty'             => (float) ($r->qty ?? 0),
                'line_qty'        => $r->line_qty !== null ? (float) $r->line_qty : null,
                'sell_by_line'    => (int) ($r->sell_by_line ?? 0),
                'due_date'        => $r->due_date ? Carbon::parse($r->due_date)->toDateString() : null,
                'ship_date'       => $r->ship_posted_at ? Carbon::parse($r->ship_posted_at)->toDateString() : null,
                'status'          => (string) ($r->status ?? ''),
                'revision_number' => (int) ($r->revision_number ?? 0),
                'customer'        => trim((string) ($r->customernumber ?? '') . ' — ' . (string) ($r->customer_name ?? '')),
                'sales'           => $this->salesDisplayText((string) ($r->sales_login ?? ''), (string) ($r->sales_name ?? '')),
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    /* =========================================================
     * SO LOOKUP
     * ========================================================= */
    public function soLookup(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) return response()->json(['items' => []]);

        $qLike = $q . '%';

        $rows = $this->conn()->table('saleorder as so')
            ->leftJoin('parts as p', 'p.id', '=', 'so.parts_id')
            ->leftJoin('customer as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('employees as e', 'e.id', '=', 'so.salesperson_id')
            ->selectRaw("
                so.ordnumber,
                so.parts_id,
                so.ordered_qty,
                p.partnumber,
                p.description,
                COALESCE(p.totalonhand, 0) as stock_qty,

                so.customer_id,
                c.name,
                c.customernumber,

                so.salesperson_id as sales_id,
                e.login as sales_login,
                e.name  as sales_name
            ")
            ->where('so.ordnumber', 'like', $qLike)
            ->orderBy('so.ordnumber')
            ->orderBy('p.partnumber')
            ->limit(30)
            ->get();

        $items = $rows->map(function ($r) {
            $login = trim((string)($r->sales_login ?? ''));
            $name  = trim((string)($r->sales_name ?? ''));
            $thai  = $this->mapSalesThai($login, $name);

            return [
                'ordnumber'      => (string)$r->ordnumber,
                'parts_id'       => (int)($r->parts_id ?? 0),
                'ordered_qty'    => (float)($r->ordered_qty ?? 0),
                'part_number'    => (string)($r->partnumber ?? ''),
                'part_desc'      => (string)($r->description ?? ''),


                'customer_id'    => (string)($r->customer_id ?? ''),
                'customer_name'  => (string)($r->name ?? ''),
                'customernumber' => (string)($r->customernumber ?? ''),

                'sales_id'       => (string)($r->sales_id ?? ''),
                'sales_code'     => $login,
                'sales_name'     => $name,
                'sales_name_th'  => $thai,
                'sales_text'     => $this->salesDisplayText($login, $name),
            ];
        });

        return response()->json(['items' => $items]);
    }

    public function soLines(Request $request)
    {
        $ord = trim((string)$request->query('ordnumber', ''));
        if ($ord === '') return response()->json(['items' => []]);

        $rows = $this->conn()->table('saleorder as so')
            ->leftJoin('parts as p', 'p.id', '=', 'so.parts_id')
            ->leftJoin('customer as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('employees as e', 'e.id', '=', 'so.salesperson_id')
            ->selectRaw("
                so.ordnumber,
                so.parts_id,
                so.ordered_qty,
                p.partnumber,
                p.description,
                COALESCE(p.totalonhand, 0) as stock_qty,

                so.customer_id,
                c.name,
                c.customernumber,

                so.salesperson_id as sales_id,
                e.login as sales_login,
                e.name  as sales_name
            ")
            ->where('so.ordnumber', '=', $ord)
            ->orderBy('p.partnumber')
            ->get();

        $items = $rows->map(function ($r) {
            $login = trim((string)($r->sales_login ?? ''));
            $name  = trim((string)($r->sales_name ?? ''));
            $salesText = $this->salesDisplayText($login, $name);

            return [
                'ordnumber'      => (string)$r->ordnumber,
                'parts_id'       => (int)($r->parts_id ?? 0),
                'ordered_qty'    => (float)($r->ordered_qty ?? 0),
                'part_number'    => (string)($r->partnumber ?? ''),
                'part_desc'      => (string)($r->description ?? ''),
                'stock_qty'      => (float)($r->stock_qty ?? 0),

                'customer_id'    => (string)($r->customer_id ?? ''),
                'customer_name'  => (string)($r->name ?? ''),
                'customernumber' => (string)($r->customernumber ?? ''),

                'sales_id'       => (string)($r->sales_id ?? ''),
                'sales_code'     => $login,
                'sales_name'     => $name,
                'sales_name_th'  => $thai,
                'sales_text'     => $salesText,
            ];
        });

        return response()->json(['items' => $items]);
    }

    /* =========================================================
     * MFG + PART LOOKUP
     * ========================================================= */
    public function mfgLookup(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $customerId = (int)$request->query('customer_id', 0);
        $partsId    = (int)$request->query('parts_id', 0);
        $ordnumber  = trim((string)$request->query('ordnumber', ''));

        $qUp   = mb_strtoupper($q);
        $qLike = $qUp . '%';

        $qb = $this->conn()->table('workorder as wo')
            ->selectRaw("
            TOP 20
            wo.workordernumber,
            wo.ordnumber,
            wo.customer_id,
            wo.parts_id,
            wo.qty,
            wo.lastupdate
        ")
            ->whereRaw("UPPER(wo.workordernumber) LIKE ?", [$qLike]);

        // ถ้าต้องการให้ lookup MFG ตาม SO ที่เลือก
        if ($ordnumber !== '') {
            $qb->where('wo.ordnumber', $ordnumber);
        }

        // เปิดใช้เพิ่มได้ ถ้าต้องการบีบให้ตรง customer / part ด้วย
        if ($customerId > 0) {
            $qb->where('wo.customer_id', $customerId);
        }

        if ($partsId > 0) {
            $qb->where('wo.parts_id', $partsId);
        }

        $rows = $qb->orderByDesc('wo.lastupdate')
            ->orderBy('wo.workordernumber')
            ->get();

        $items = $rows->map(function ($r) {
            $mfg = trim((string)($r->workordernumber ?? ''));
            $ord = trim((string)($r->ordnumber ?? ''));

            return [
                'mfg_no'      => $mfg,
                'ordnumber'   => $ord,
                'customer_id' => (int)($r->customer_id ?? 0),
                'parts_id'    => (int)($r->parts_id ?? 0),
                'qty'         => (float)($r->qty ?? 0),
                'lastupdate'  => (string)($r->lastupdate ?? ''),
                'text'        => trim($mfg . ($ord !== '' ? " — {$ord}" : '')),
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    public function partLookup(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) return response()->json(['items' => []]);

        $qLike = '%' . $q . '%';

        $rows = $this->conn()->table('parts as p')
            ->selectRaw("
            TOP 20
            p.id,
            p.partnumber,
            p.description as part_desc,
            p.ref_unit,
            p.ref_unit_qty,
            p.f1,
            p.f2,
            p.f3
        ")
            ->where(function ($w) use ($qLike) {
                $w->where('p.partnumber', 'like', $qLike)
                    ->orWhere('p.description', 'like', $qLike);
            })
            ->orderBy('p.partnumber')
            ->get();

        $items = $rows->map(function ($r) {
            $partNo     = strtoupper(trim((string)($r->partnumber ?? '')));
            $refUnit    = strtoupper(trim((string)($r->ref_unit ?? '')));
            $refUnitQty = $this->toNumberOrNull($r->ref_unit_qty ?? null);

            $kgPerLine = null;
            if (
                $refUnit === '03' &&
                str_ends_with($partNo, 'E') &&
                $refUnitQty !== null &&
                $refUnitQty > 0
            ) {
                $kgPerLine = $refUnitQty;
            }

            return [
                'id'           => (int)($r->id ?? 0),
                'part_no'      => (string)($r->partnumber ?? ''),
                'part_desc'    => (string)($r->part_desc ?? ''),
                'ref_unit'     => (string)($r->ref_unit ?? ''),
                'ref_unit_qty' => $refUnitQty,
                'f1'           => $this->toNumberOrNull($r->f1 ?? null),
                'f2'           => $this->toNumberOrNull($r->f2 ?? null),
                'f3'           => (string)($r->f3 ?? ''),
                'kg_per_line'  => $kgPerLine,
                'text'         => trim((string)($r->partnumber ?? '') . ' — ' . (string)($r->part_desc ?? '')),
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    /* =========================================================
     * HELPERS
     * ========================================================= */
    private function calcKgFromLineQty(?int $partsId, ?float $lineQty): ?float
    {
        if (!$partsId || !$lineQty || $lineQty <= 0) {
            return null;
        }

        $p = $this->conn()->table('parts')
            ->select('id', 'partnumber', 'ref_unit', 'ref_unit_qty', 'f1', 'f2', 'f3')
            ->where('id', $partsId)
            ->first();

        if (!$p) {
            return null;
        }

        $partNo     = strtoupper(trim((string)($p->partnumber ?? '')));
        $refUnit    = strtoupper(trim((string)($p->ref_unit ?? '')));
        $kgPerLine  = $this->toNumberOrNull($p->ref_unit_qty ?? null);

        if ($refUnit !== '03') {
            return null;
        }

        if (!str_ends_with($partNo, 'E')) {
            return null;
        }

        if ($kgPerLine === null || $kgPerLine <= 0) {
            return null;
        }

        return round($lineQty * $kgPerLine, 3);
    }

    private function resolveEditQtyKg(array $line): ?float
    {
        $sellByLine = (string)($line['sell_by_line'] ?? '0');
        $isSellByLine = ($sellByLine === '1' || strtolower($sellByLine) === 'true');
        $lineQty = $this->toNumberOrNull($line['sell_by_line_qty'] ?? null);
        $partsId = (int)($line['parts_id'] ?? 0);

        if ($isSellByLine && $lineQty !== null && $lineQty > 0) {
            $qtyFromLine = $this->calcKgFromLineQty($partsId, (float)$lineQty);
            if ($qtyFromLine !== null && $qtyFromLine > 0) {
                return $qtyFromLine;
            }
        }

        $mfgNo = trim((string)($line['mfg_no'] ?? ''));
        if ($mfgNo !== '' && !str_contains($mfgNo, ',')) {
            $qtyFromMfg = $this->toNumberOrNull(
                $this->conn()
                    ->table('workorder')
                    ->where('workordernumber', $mfgNo)
                    ->value('qty')
            );

            if ($qtyFromMfg !== null && $qtyFromMfg > 0) {
                return $qtyFromMfg;
            }
        }

        $soNo = trim((string)($line['so_number'] ?? ''));
        if ($soNo === '') {
            return null;
        }

        $soQuery = $this->conn()->table('saleorder as so')
            ->leftJoin('parts as p', 'p.id', '=', 'so.parts_id')
            ->where('so.ordnumber', $soNo);

        if ($partsId > 0) {
            $soQuery->where('so.parts_id', $partsId);
        } else {
            $partNo = trim((string)($line['part_no'] ?? ''));
            if ($partNo !== '') {
                $soQuery->whereRaw(
                    'p.partnumber COLLATE DATABASE_DEFAULT = ?',
                    [$partNo]
                );
            }
        }

        return $this->toNumberOrNull($soQuery->value('so.ordered_qty'));
    }

    private function sentRevisionNumbersForShipDate(?string $shipDate): array
    {
        if (!$shipDate) {
            return [];
        }

        return $this->conn()
            ->table('delivery_plan_mail_logs')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->whereNotNull('sent_at')
            ->pluck('revision_number')
            ->map(fn($revision) => (int) $revision)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * ล็อกการแก้ไขทันทีเมื่องานถูกจัดรถจริง (มี assigned_weight)
     * หรือมีช่องทางพิเศษที่ยังเปิดอยู่ใน delivery_plan_special_dispatch
     * เพื่อป้องกันยอดในแผนกับยอดจัดรถไม่ตรงกัน
     */
    private function isDispatchAssignedForOrd(string $ordId): bool
    {
        $ordId = trim($ordId);
        if ($ordId === '') {
            return false;
        }

        $hasTruckAssignment = (float) $this->conn()
            ->table('delivery_plan_truck_assign')
            ->where('ord_id', $ordId)
            ->selectRaw('ISNULL(SUM(ISNULL(assigned_weight, 0)), 0) as s')
            ->value('s') > 0;

        if ($hasTruckAssignment) {
            return true;
        }

        return $this->conn()
            ->table('delivery_plan_special_dispatch')
            ->where('ord_id', $ordId)
            ->where('status', 'OPEN')
            ->whereRaw("UPPER(ISNULL(dispatch_type, '')) <> 'POSTPONED'")
            ->exists();
    }

    private function buildContinueSameSoPayload(Request $request): ?array
    {
        $line = $request->input('lines.0', []);
        if (!is_array($line)) {
            return null;
        }

        if (trim((string) ($line['id'] ?? '')) !== '') {
            return null;
        }

        $mode = strtoupper(trim((string) ($line['mode'] ?? 'SO')));
        $soNo = trim((string) ($line['so_number'] ?? ''));
        if ($mode !== 'SO' || $soNo === '') {
            return null;
        }

        $mfgCount = $this->conn()->table('workorder')
            ->where('ordnumber', $soNo)
            ->whereNotNull('workordernumber')
            ->distinct()
            ->count('workordernumber');

        if ($mfgCount <= 1 && !$request->boolean('continue_same_so')) {
            return null;
        }

        $payload = $request->except(['_token', 'continue_same_so']);
        unset(
            $payload['lines'][0]['id'],
            $payload['lines'][0]['mfg_no'],
            $payload['lines'][0]['qty_kg'],
            $payload['lines'][0]['sell_by_line_qty'],
            $payload['lines'][0]['auto_qty_kg']
        );
        $payload['is_manual_mfg'] = '1';

        $salesId = (int) ($payload['lines'][0]['sales_id'] ?? 0);
        if ($salesId > 0) {
            $sales = $this->conn()->table('employees')
                ->select('login', 'name')
                ->where('id', $salesId)
                ->first();

            if ($sales) {
                $payload['lines'][0]['sales_text'] = $this->salesDisplayText(
                    $sales->login ?? '',
                    $sales->name ?? ''
                );
            }
        }

        return [
            'so_number' => $soNo,
            'mfg_count' => $mfgCount,
            'requested' => $request->boolean('continue_same_so'),
            'form' => $payload,
        ];
    }

    private function toNumberOrNull($v)
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = str_replace(',', '', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    private function findCustomerIdByName(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        // "CODE — NAME"
        if (str_contains($text, '—')) {
            $parts = array_map('trim', explode('—', $text, 2));
            $code = $parts[0] ?? '';
            $name = $parts[1] ?? '';
            if ($code !== '') {
                $id = $this->conn()->table('customer')->where('customernumber', $code)->value('id');
                if ($id) return (string)$id;
            }
            $text = $name ?: $text;
        }

        $id = $this->conn()->table('customer')->where('name', $text)->value('id');
        return $id ? (string)$id : '';
    }

    /**
     * กันเคส "id NOT NULL แต่ไม่ใช่ identity"
     * ใช้ UPDLOCK/HOLDLOCK กันชนกันตอนมีหลายคน insert พร้อมกัน
     */
    private function nextIntId(string $table, string $col = 'id'): int
    {
        $row = $this->conn()->selectOne("
            SELECT ISNULL(MAX([$col]), 0) + 1 AS next_id
            FROM [$table] WITH (UPDLOCK, HOLDLOCK)
        ");
        return (int)($row->next_id ?? 1);
    }

    private function salesThaiMap(): array
    {
        return [
            'export sales 00' => 'คุณดิลก สอนแจ้ง',
            'export sales 01' => 'คุณดิลก สอนแจ้ง',
            'export sales 02' => 'คุณปรียาพรรณ ทิพหา',
            'sales person 03' => 'คุณภควดี เรืองเชื้อเหมือน',
            'sales person 04' => 'คุณวิลาวัณย์ สิงหวิบูลย์',
            'sales person 05' => 'คุณธัธลิญา พงษ์ศิริ',
            'sales person 06' => 'คุณสุรศักดิ์ เลียงมงคลการ',
            'sales person 07' => 'คุณศิรินภา สุวรรณมา',
            'sales person 08' => 'คุณสาธิต หมื่นนรินทร์',
            'sales person 09' => 'คุณวรเดชา วัธนกุล',
            'sales person09'  => 'คุณวรเดชา วัธนกุล',
        ];
    }

    /**
     * FIX: เดิมคุณใช้ $name เป็น key ทำให้ map ไม่ติด
     * ใช้ login เป็น key ก่อน แล้วค่อย fallback เป็นชื่อจาก employees
     */
    private function mapSalesThai(?string $login, ?string $name): string
    {
        $loginKey = trim(mb_strtolower((string)$login));
        $nameKey = trim(mb_strtolower((string)$name));
        $map = $this->salesThaiMap();

        if ($loginKey !== '' && isset($map[$loginKey])) {
            return (string)$map[$loginKey];
        }

        if (preg_match('/^export\s*(\d+)$/', $loginKey, $matches)) {
            $exportNameKey = 'export sales ' . str_pad((string) ((int) $matches[1]), 2, '0', STR_PAD_LEFT);
            if (isset($map[$exportNameKey])) {
                return (string)$map[$exportNameKey];
            }
        }

        if ($nameKey !== '' && isset($map[$nameKey])) {
            return (string)$map[$nameKey];
        }

        $name = trim((string)$name);
        return $name !== '' ? $name : '';
    }

    private function salesDisplayText(?string $login, ?string $name): string
    {
        $login = trim((string)$login);
        $mappedName = $this->mapSalesThai($login, $name);

        if ($login !== '' && $mappedName !== '') {
            return $login . ' : ' . $mappedName;
        }

        return $mappedName;
    }
}
