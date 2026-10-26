<?php

namespace App\Http\Controllers\FormAccounting;

use App\Exports\FormAccounting\InvoiceMfgProfitExport;
use App\Http\Controllers\Controller;
use App\Services\FormAccounting\InvoiceMfgProfitService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class InvoiceMfgProfitController extends Controller
{
    private const FILTERS = ['date_from','date_to','site','invoice','sales_order','mfg','customer','partnumber'];

    public function index(Request $request, InvoiceMfgProfitService $service)
    {
        return view('formaccounting.invoice_mfg_profit', $service->inquiry($request->only(self::FILTERS)));
    }

    public function export(Request $request, InvoiceMfgProfitService $service)
    {
        $data = $service->exportData($request->only(self::FILTERS));
        return Excel::download(new InvoiceMfgProfitExport($data), 'invoice-mfg-profit-'.now()->format('Ymd-His').'.xlsx');
    }
}
