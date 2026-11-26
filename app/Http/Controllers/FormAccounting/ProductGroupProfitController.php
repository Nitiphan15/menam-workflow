<?php

namespace App\Http\Controllers\FormAccounting;

use App\Exports\FormAccounting\ProductGroupProfitExport;
use App\Http\Controllers\Controller;
use App\Services\FormAccounting\ProductGroupProfitService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProductGroupProfitController extends Controller
{
    private const FILTERS = ['date_from','date_to','sales_site','production_site','product_group','invoice','partnumber','issues_only'];

    public function index(Request $request, ProductGroupProfitService $service)
    {
        return view('formaccounting.product_group_profit', $service->inquiry($request->only(self::FILTERS)));
    }

    public function export(Request $request, ProductGroupProfitService $service)
    {
        return Excel::download(
            new ProductGroupProfitExport($service->exportData($request->only(self::FILTERS))),
            'product-group-profit-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
