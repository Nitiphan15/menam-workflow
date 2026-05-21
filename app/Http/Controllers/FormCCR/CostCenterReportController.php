<?php

namespace App\Http\Controllers\FormCCR;

use App\Http\Controllers\Controller;
use App\Services\FormVC\CostCenterReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;

class CostCenterReportController extends Controller
{
    public function index(Request $request)
    {
        return Redirect::route('cost-center.summary', $request->query());
    }

    public function summary(Request $request, CostCenterReportService $service)
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $site = strtoupper((string) $request->input('site', 'ALL'));
        $classnumber = trim((string) $request->input('classnumber', ''));

        $filters = [
            'site' => $site,
            'date_from' => "{$year}-01-01",
            'date_to' => ($year + 1) . '-01-01',
            'classnumber' => $classnumber,
        ];

        $rows = $service->validationTotals($filters);

        $kpis = [
            'total_amount' => (float) $rows->sum('ccalloc_total'),
            'class_count' => $rows->pluck('classnumber')->filter()->unique()->count(),
            'account_count' => $rows->pluck('account_no')->filter()->unique()->count(),
            'allocation_rows' => (int) $rows->sum('allocation_rows'),
        ];

        $byClass = $rows
            ->groupBy(fn($row) => $row->classnumber . '|' . $row->class_group)
            ->map(function (Collection $group) {
                $first = $group->first();
                return (object) [
                    'classnumber' => $first->classnumber,
                    'class_group' => $first->class_group,
                    'total' => (float) $group->sum('ccalloc_total'),
                    'allocation_rows' => (int) $group->sum('allocation_rows'),
                    'accounts' => $group->sortByDesc('ccalloc_total')->values(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $monthly = $service->monthlyTotals($filters);
        $monthLabels = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        $sitesInData = $monthly->pluck('site')->unique()->sort()->values();

        $monthlyDatasets = $sitesInData->map(function (string $siteName) use ($monthly) {
            $byMonth = $monthly->where('site', $siteName)->keyBy('month');
            $data = [];
            for ($m = 1; $m <= 12; $m++) {
                $data[] = round((float) ($byMonth[$m]->total ?? 0), 2);
            }
            return ['site' => $siteName, 'data' => $data];
        })->values();

        $monthlyTotalLine = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthlyTotalLine[] = round((float) $monthly->where('month', $m)->sum('total'), 2);
        }

        $topAccounts = $service->topAccountTotals($filters, 10);

        $chart = [
            'monthly' => [
                'labels' => $monthLabels,
                'total' => $monthlyTotalLine,
                'sites' => $monthlyDatasets,
            ],
            'topAccounts' => [
                'labels' => $topAccounts->pluck('account_label')->values(),
                'data' => $topAccounts->pluck('total')->map(fn($v) => round((float) $v, 2))->values(),
            ],
        ];

        $classOptions = $byClass
            ->map(fn($c) => (object) ['value' => $c->classnumber, 'label' => $c->class_group])
            ->values();

        return view('formccr.summary', [
            'activePage' => 'summary',
            'year' => $year,
            'site' => $site,
            'classnumber' => $classnumber,
            'filters' => $filters,
            'kpis' => $kpis,
            'byClass' => $byClass,
            'chart' => $chart,
            'classOptions' => $classOptions,
        ]);
    }

    public function detail(Request $request, CostCenterReportService $service)
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $site = strtoupper((string) $request->input('site', 'ALL'));
        $classnumber = trim((string) $request->input('classnumber', ''));

        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo = $request->input('date_to') ?: ($year + 1) . '-01-01';

        $filters = [
            'site' => $site,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'classnumber' => $classnumber,
        ];

        $rows = $classnumber !== '' ? $service->reportRows($filters) : collect();
        $needClassPrompt = $classnumber === '';

        $classOptionsList = collect();
        if ($needClassPrompt) {
            $classOptionsList = $service
                ->validationTotals($filters)
                ->groupBy(fn($r) => $r->classnumber . '|' . $r->class_group)
                ->map(fn(Collection $g) => (object) [
                    'value' => $g->first()->classnumber,
                    'label' => $g->first()->class_group,
                ])
                ->values();
        }

        $kpis = [
            'total_amount' => (float) $rows->sum('line_amount'),
            'line_count' => $rows->count(),
            'invoice_count' => $rows->pluck('invoice_no')->filter()->unique()->count(),
            'account_count' => $rows->pluck('account_no')->filter()->unique()->count(),
        ];

        return view('formccr.detail', [
            'activePage' => 'detail',
            'year' => $year,
            'site' => $site,
            'classnumber' => $classnumber,
            'filters' => $filters,
            'rows' => $rows,
            'needClassPrompt' => $needClassPrompt,
            'classOptionsList' => $classOptionsList,
            'kpis' => $kpis,
        ]);
    }
}
