<?php

namespace App\Http\Controllers\FormPA;

use App\Http\Controllers\Controller;
use App\Models\FormPA\PaPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PeriodController extends Controller
{
    public function index(Request $request)
    {
        $periods = PaPeriod::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                $kw = '%' . $request->q . '%';
                $q->where(function ($w) use ($kw) {
                    $w->where('code', 'like', $kw)->orWhere('name', 'like', $kw);
                });
            })
            ->orderByDesc('start_date')
            ->paginate(15)
            ->withQueryString();

        return view('formpa.periods.index', compact('periods'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'       => ['required', 'string', 'max:20', 'unique:pa_periods,code'],
            'name'       => ['required', 'string', 'max:200'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'is_active'  => ['nullable'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $start = Carbon::parse($data['start_date']);
        $data['year_no'] = (int) $start->year;


        DB::transaction(function () use ($data) {
            if ($data['is_active']) {
                PaPeriod::query()->update(['is_active' => 0]);
            }
            PaPeriod::create($data);
        });

        return back()->with('ok', 'เพิ่มรอบประเมินแล้ว');
    }

    public function update(Request $request, int $id)
    {
        $period = PaPeriod::findOrFail($id);

        $data = $request->validate([
            'code'       => ['sometimes', 'string', 'max:20', Rule::unique('pa_periods', 'code')->ignore($id)],
            'name'       => ['required', 'string', 'max:200'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'is_active'  => ['nullable'],
        ]);

        DB::transaction(function () use ($period, $data, $request) {
            $period->fill($data)->save();

            if ($request->boolean('is_active')) {
                PaPeriod::query()->where('id', '!=', $period->id)->update(['is_active' => 0]);
                $period->update(['is_active' => 1]);
            }
        });

        return back()->with('ok', 'อัปเดตรอบประเมินแล้ว');
    }

    public function activate(int $id)
    {
        DB::transaction(function () use ($id) {
            PaPeriod::query()->update(['is_active' => 0]);
            PaPeriod::where('id', $id)->update(['is_active' => 1]);
        });

        return back()->with('ok', 'ตั้งรอบที่ใช้งานสำเร็จ');
    }

    public function destroy(int $id)
    {
        // กันลบถ้ามีข้อมูลอ้างอิง
        if (DB::table('pa_data')->where('period_id', $id)->exists()) {
            return back()->with('err', 'ลบไม่ได้: มีข้อมูลประเมินอ้างอิงรอบนี้อยู่');
        }

        PaPeriod::where('id', $id)->delete();
        return back()->with('ok', 'ลบรอบประเมินแล้ว');
    }
}
