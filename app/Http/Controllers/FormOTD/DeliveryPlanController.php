<?php

namespace App\Http\Controllers\FormOTD;

use App\Http\Controllers\Controller;
use App\Models\FormOTD\DeliveryPlanLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliveryPlanController extends Controller
{


    public function calendar(Request $req)
    {
        $ym = $req->query('ym'); // 2026-01
        $month = $ym ? Carbon::createFromFormat('Y-m', $ym) : now();

        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        $counts = DeliveryPlanLine::query()
            ->selectRaw('plan_date, COUNT(*) as c')
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->where('active', 1)
            ->groupBy('plan_date')
            ->pluck('c', 'plan_date')
            ->toArray();

        $days = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $d = $cursor->toDateString();
            $days[] = [
                'date'  => $d,
                'day'   => (int) $cursor->format('j'),
                'count' => $counts[$d] ?? 0,
                'dow'   => (int) $cursor->dayOfWeekIso,
            ];
            $cursor->addDay();
        }

        return view('formotd.calendar', compact('month', 'days'));
    }

    public function day(string $date, Request $req)
    {
        $day = Carbon::parse($date)->startOfDay();

        $rows = DeliveryPlanLine::query()
            ->whereDate('plan_date', $day->toDateString())
            ->orderByDesc('id')
            ->get();

        return view('formotd.day', compact('day', 'rows'));
    }

    public function store(string $date, Request $req)
    {
        $day = Carbon::parse($date)->toDateString();

        $data = $req->validate([
            'customer_name'  => 'required|string|max:255',
            'package_name'   => 'nullable|string|max:120',
            'product_type'   => 'nullable|string|max:120',
            'size_length'    => 'nullable|string|max:120',
            'mfg_no'         => 'nullable|string|max:255',

            'qty_marketing'  => 'nullable|numeric',
            'qty_inventory'  => 'nullable|numeric',
            'qty_production' => 'nullable|numeric',
            'qty_delivery'   => 'nullable|numeric',

            'address'        => 'nullable|string|max:255',
            'sales_order_no' => 'nullable|string|max:50',
            'tel'            => 'nullable|string|max:50',

            'logistic'       => 'nullable|string|max:255',
            'remark_problem' => 'nullable|string',
        ]);

        $data['plan_date']    = $day;
        $data['active']       = 1;
        $data['revise_count'] = 0;
        $data['row_version']  = 0;
        $data['created_by']   = Auth::id();

        DeliveryPlanLine::create($data);

        // แนะนำ: ให้ route name ชัดเจนว่าเป็น formotd หรือ dp (เลือกอย่างใดอย่างหนึ่ง)
        return redirect()->route('dp.day', ['date' => $day])->with('ok', 'Added.');
    }

    public function edit($id)
    {
        $row = DeliveryPlanLine::findOrFail($id);
        return view('formotd.edit', compact('row'));
    }

    public function update($id, Request $req)
    {
        $data = $req->validate([
            'customer_name'  => 'required|string|max:255',
            'package_name'   => 'nullable|string|max:120',
            'product_type'   => 'nullable|string|max:120',
            'size_length'    => 'nullable|string|max:120',
            'mfg_no'         => 'nullable|string|max:255',

            'qty_marketing'  => 'nullable|numeric',
            'qty_inventory'  => 'nullable|numeric',
            'qty_production' => 'nullable|numeric',
            'qty_delivery'   => 'nullable|numeric',

            'address'        => 'nullable|string|max:255',
            'sales_order_no' => 'nullable|string|max:50',
            'tel'            => 'nullable|string|max:50',

            'logistic'       => 'nullable|string|max:255',
            'remark_problem' => 'nullable|string',

            'row_version'    => 'required|integer|min:0',
        ]);

        $row = DeliveryPlanLine::findOrFail($id);

        $expectedVersion = (int) $data['row_version'];
        unset($data['row_version']);

        $data['updated_by'] = Auth::id();

        // IMPORTANT: ใช้ Eloquent query => จะใช้ connection sqlsrv ตาม Model แน่นอน
        $updated = DeliveryPlanLine::query()
            ->where('id', $row->id)
            ->where('row_version', $expectedVersion)
            ->update(array_merge($data, [
                'row_version'  => DB::raw('row_version + 1'),
                'revise_count' => DB::raw('revise_count + 1'),
                'updated_at'   => now(),
            ]));

        if ($updated === 0) {
            return back()
                ->withInput()
                ->withErrors(['conflict' => 'มีคนแก้ข้อมูลนี้ไปแล้ว กรุณารีเฟรช แล้วลองใหม่']);
        }

        return redirect()->route('dp.day', ['date' => $row->plan_date->toDateString()])
            ->with('ok', 'Updated.');
    }

    public function toggleActive($id)
    {
        // toggle ก็ทำแบบ lock ได้เช่นกัน แต่แบบนี้โอเคก่อน
        $row = DeliveryPlanLine::findOrFail($id);
        $row->active = !$row->active;
        $row->updated_by = Auth::id();
        $row->save();

        return back()->with('ok', 'Updated status.');
    }

    public function seedDemo()
    {
        $data = [
            // ===== 1 Jan =====
            [
                'plan_date' => '2026-01-02',
                'customer_name' => 'ABC Automotive',
                'product_type' => '304(SB)',
                'size_length' => '6.00*2000',
                'mfg_no' => 'MFG-240102-A',
                'qty_delivery' => 3200,
                'sales_order_no' => 'SO-260102-01',
                'logistic' => 'Truck 6W',
                'remark_problem' => null,
            ],
            [
                'plan_date' => '2026-01-02',
                'customer_name' => 'XYZ Steel',
                'product_type' => '825',
                'size_length' => '8.00*6000',
                'mfg_no' => 'MFG-240102-B',
                'qty_delivery' => 1800,
                'sales_order_no' => 'SO-260102-02',
                'logistic' => 'Truck 10W',
                'remark_problem' => 'Waiting packaging',
            ],

            // ===== 3 Jan =====
            [
                'plan_date' => '2026-01-03',
                'customer_name' => 'Thai Summit',
                'product_type' => 'SWRCH18A',
                'size_length' => '7.00*3000',
                'mfg_no' => 'MFG-240103-A',
                'qty_delivery' => 2500,
                'sales_order_no' => 'SO-260103-01',
                'logistic' => 'Outsource Truck',
                'remark_problem' => null,
            ],

            // ===== 5 Jan =====
            [
                'plan_date' => '2026-01-05',
                'customer_name' => 'Apex Logistics',
                'product_type' => '304',
                'size_length' => '5.50*2000',
                'mfg_no' => 'MFG-240105-A',
                'qty_delivery' => 1200,
                'sales_order_no' => 'SO-260105-01',
                'logistic' => 'Truck 4W',
                'remark_problem' => 'Customer request morning delivery',
            ],
            [
                'plan_date' => '2026-01-05',
                'customer_name' => 'Menam Wire',
                'product_type' => '825',
                'size_length' => '9.00*6000',
                'mfg_no' => 'MFG-240105-B',
                'qty_delivery' => 4100,
                'sales_order_no' => 'SO-260105-02',
                'logistic' => 'Truck 10W',
                'remark_problem' => null,
            ],
        ];

        $all = [];
        $id = 1;

        foreach ($data as $r) {
            $r['id'] = $id++;
            $r['package_name'] = null;
            $r['address'] = 'Bangkok / Rayong';
            $r['tel'] = '02-xxx-xxxx';
            $r['revise_count'] = rand(0, 2);
            $r['active'] = true;
            $r['created_at'] = now()->toDateTimeString();

            $all[] = $r;
        }

        session(['dp_demo_lines' => $all]);

        return redirect()->route('demo.dp.calendar')
            ->with('ok', 'Demo data seeded.');
    }
}
