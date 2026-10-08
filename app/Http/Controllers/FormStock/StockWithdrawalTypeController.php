<?php

namespace App\Http\Controllers\FormStock;

use App\Http\Controllers\Controller;
use App\Models\FormStock\StockWithdrawalType;
use Illuminate\Http\Request;

class StockWithdrawalTypeController extends Controller
{
    public function index() { return view('formstock.types.index', ['types' => StockWithdrawalType::query()->orderBy('code')->orderByDesc('effective_from')->get()]); }

    public function store(Request $request)
    {
        $data = $request->validate(['code' => ['required','alpha_dash','max:30'], 'name' => ['required','string','max:100'], 'lead_time_days' => ['required','integer','min:0','max:365'], 'effective_from' => ['required','date'], 'effective_to' => ['nullable','date','after_or_equal:effective_from'], 'is_active' => ['required','boolean'], 'remark' => ['nullable','string','max:500']]);
        $data['code'] = strtoupper($data['code']); $data['created_by'] = auth()->id(); $data['updated_by'] = auth()->id();
        StockWithdrawalType::create($data);
        return back()->with('success', 'เพิ่มประเภทการเบิกแล้ว');
    }
}
