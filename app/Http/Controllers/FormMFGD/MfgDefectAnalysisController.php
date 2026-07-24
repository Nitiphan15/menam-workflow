<?php

namespace App\Http\Controllers\FormMFGD;

use App\Exports\FormMFGD\MfgDefectAnalysisExport;
use App\Http\Controllers\Controller;
use App\Services\FormMFGD\MfgDefectAnalysisService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;

class MfgDefectAnalysisController extends Controller
{
    public function index(Request $request, MfgDefectAnalysisService $service)
    {
        $filters = $this->filters($request);

        $report = $service->report($filters);
        $rows = $report['rows'];
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = $filters['per_page'];
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('formmfgd.index', [
            'rows' => $paginator,
            'filters' => $filters,
            'summary' => $report['summary'],
            'prefixSummary' => $report['prefix_summary'],
            'dataErrors' => $report['errors'],
        ]);
    }

    public function export(Request $request, MfgDefectAnalysisService $service)
    {
        $filters = $this->filters($request);
        $report = $service->report($filters);

        if ($report['errors'] !== []) {
            return back()->with('warning', implode(' | ', $report['errors']));
        }

        $period = $filters['all_dates']
            ? 'all'
            : (($filters['date_from'] ?: 'start') . '_to_' . ($filters['date_to'] ?: 'today'));
        $fileName = 'MFG_Defect_' . $period . '_' . now('Asia/Bangkok')->format('Ymd_His') . '.xlsx';

        return Excel::download(new MfgDefectAnalysisExport($report['rows']), $fileName);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'mfg' => 'nullable|string|max:50',
            'prefix' => 'nullable|string|max:100',
            'min_output_qty' => 'nullable|numeric|min:0',
            'sort_by' => 'nullable|in:defect_pct,defect_qty',
            'site' => 'nullable|in:all,wire,plus',
            'all_dates' => 'nullable|boolean',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);

        $allDates = (bool) ($validated['all_dates'] ?? false);
        $hasExplicitDate = $request->filled('date_from') || $request->filled('date_to');
        $prefixes = collect(preg_split('/[\s,]+/', strtoupper((string) ($validated['prefix'] ?? ''))))
            ->map(fn($prefix) => ltrim(trim((string) $prefix), '+'))
            ->filter(fn($prefix) => $prefix !== '')
            ->unique()
            ->values();

        return [
            'date_from' => $allDates
                ? null
                : ($validated['date_from'] ?? ($hasExplicitDate ? null : Carbon::now()->startOfMonth()->toDateString())),
            'date_to' => $allDates
                ? null
                : ($validated['date_to'] ?? ($hasExplicitDate ? null : Carbon::now()->toDateString())),
            'mfg' => strtoupper(trim((string) ($validated['mfg'] ?? ''))),
            'prefix' => $prefixes->implode(','),
            'prefixes' => $prefixes->all(),
            'min_output_qty' => (float) ($validated['min_output_qty'] ?? 0),
            'sort_by' => $validated['sort_by'] ?? 'defect_pct',
            'site' => $validated['site'] ?? 'all',
            'all_dates' => $allDates,
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];
    }
}
