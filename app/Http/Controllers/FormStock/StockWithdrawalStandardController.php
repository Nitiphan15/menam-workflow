<?php

namespace App\Http\Controllers\FormStock;

use App\Http\Controllers\Controller;
use App\Models\FormStock\StockWithdrawalStandard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockWithdrawalStandardController extends Controller
{
    public function index(Request $request)
    {
        $query = StockWithdrawalStandard::query()->orderBy('site')->orderBy('partnumber')->orderByDesc('effective_from');
        if ($request->filled('site')) $query->where('site', (string) $request->string('site'));
        if ($request->filled('keyword')) {
            $keyword = '%'.strtoupper(trim($request->string('keyword'))).'%';
            $query->where(fn($q) => $q->whereRaw('UPPER(partnumber) LIKE ?', [$keyword])->orWhereRaw('UPPER(description) LIKE ?', [$keyword]));
        }
        return view('formstock.master', ['standards' => $query->paginate(100)->withQueryString()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['partnumber'] = strtoupper(trim($data['partnumber']));
        $data['created_by'] = auth()->id(); $data['updated_by'] = auth()->id();
        StockWithdrawalStandard::create($data);
        return back()->with('success', 'เพิ่ม Standard Part แล้ว');
    }

    public function update(Request $request, StockWithdrawalStandard $standard)
    {
        $data = $this->validated($request);
        $data['partnumber'] = strtoupper(trim($data['partnumber']));
        $newEffectiveFrom = \Carbon\Carbon::parse($data['effective_from'])->startOfDay();
        if ($newEffectiveFrom->lte($standard->effective_from->copy()->startOfDay())) {
            throw ValidationException::withMessages(['effective_from' => 'Revision ใหม่ต้องมีวันที่เริ่มใช้หลัง Version เดิม']);
        }

        DB::connection($standard->getConnectionName())->transaction(function () use ($standard, $data, $newEffectiveFrom) {
            $standard->update([
                'effective_to' => $newEffectiveFrom->copy()->subDay()->toDateString(),
                'is_active' => false,
                'updated_by' => auth()->id(),
            ]);
            $data['created_by'] = auth()->id();
            $data['updated_by'] = auth()->id();
            StockWithdrawalStandard::create($data);
        });

        return back()->with('success', 'สร้าง Revision ใหม่และเก็บ Version เดิมแล้ว');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'site' => ['required', Rule::in(['WIRE', 'PLUS', 'ALL'])], 'partnumber' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'], 'standard_days' => ['required', 'integer', 'min:0', 'max:365'],
            'warning_days' => ['required', 'integer', 'min:0', 'max:90'], 'day_type' => ['required', Rule::in(['WORKING'])],
            'responsible_name' => ['nullable', 'string', 'max:150'], 'responsible_email' => ['nullable', 'email', 'max:255'],
            'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'], 'remark' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
