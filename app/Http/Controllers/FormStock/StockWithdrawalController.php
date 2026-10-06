<?php

namespace App\Http\Controllers\FormStock;

use App\Http\Controllers\Controller;
use App\Services\FormStock\StockWithdrawalService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockWithdrawalController extends Controller
{
    public function index(Request $request, StockWithdrawalService $service)
    {
        $filters = $request->validate([
            'site' => ['nullable', 'in:WIRE,PLUS'], 'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'in:ยังไม่เบิก,เบิกบางส่วน,เบิกครบแล้ว'],
            'risk' => ['nullable', 'in:GREEN,YELLOW,ORANGE,RED,GRAY'], 'keyword' => ['nullable', 'string', 'max:100'],
        ]);
        $filters += ['date_from' => now('Asia/Bangkok')->subDays(30)->toDateString(), 'date_to' => now('Asia/Bangkok')->addDays(60)->toDateString()];
        $rows = $service->report($filters);
        $summary = collect(['GREEN', 'YELLOW', 'ORANGE', 'RED', 'GRAY'])->mapWithKeys(fn($color) => [$color => $rows->where('risk_code', $color)->count()]);
        return view('formstock.index', compact('rows', 'filters', 'summary'));
    }

    public function export(Request $request, StockWithdrawalService $service): StreamedResponse
    {
        $rows = $service->report($request->only('site', 'date_from', 'date_to', 'status', 'risk', 'keyword'));
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Site', 'MFG', 'Part', 'Description', 'Due Date', 'Required', 'Issued', 'Remaining', 'Standard Days', 'Withdraw Date', 'Withdrawal Status', 'Risk']);
            foreach ($rows as $row) fputcsv($out, [$row['site'], $row['mfg'], $row['partnumber'], $row['description'], $row['due_date'], $row['required_qty'], $row['issued_qty'], $row['remaining_qty'], $row['standard_days'], $row['withdraw_date'], $row['withdrawal_status'], $row['risk_label']]);
            fclose($out);
        }, 'stock-withdrawal-'.now('Asia/Bangkok')->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
