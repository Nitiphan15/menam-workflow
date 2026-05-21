<?php

namespace App\Http\Controllers\FormVC;

use App\Http\Controllers\Controller;
use App\Services\FormVC\VariableCostService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class VariableCostController extends Controller
{
    public function index(Request $request)
    {
        return Redirect::route('variable-cost.summary', $request->query());
    }

    public function summary(Request $request, VariableCostService $service)
    {
        return view('formvc.summary', $service->getSummaryData($this->filtersFromRequest($request)) + [
            'activePage' => 'summary',
        ]);
    }

    public function monthly(Request $request, VariableCostService $service)
    {
        $comparePrevious = (bool) $request->boolean('compare_previous');
        return view('formvc.monthly', $service->getMonthlyData($this->filtersFromRequest($request), $comparePrevious) + [
            'activePage' => 'monthly',
            'comparePrevious' => $comparePrevious,
        ]);
    }

    public function matrix(Request $request, VariableCostService $service)
    {
        return view('formvc.matrix', $service->getMatrixData($this->filtersFromRequest($request)) + [
            'activePage' => 'matrix',
        ]);
    }

    public function accounts(Request $request, VariableCostService $service)
    {
        return view('formvc.accounts', $service->getAccountsData($this->filtersFromRequest($request)) + [
            'activePage' => 'accounts',
        ]);
    }

    public function yearly(Request $request, VariableCostService $service)
    {
        return view('formvc.yearly', $service->getYearlyData([
            'year' => $request->input('year'),
            'site' => $request->input('site'),
            'class' => $this->arrayInput($request, 'class'),
            'account' => $request->input('account'),
        ]) + [
            'activePage' => 'yearly',
        ]);
    }

    public function details(Request $request, VariableCostService $service)
    {
        $filters = $service->normalizeFiltersPublic($this->filtersFromRequest($request));

        return view('formvc.details', [
            'filters' => $filters,
            'kpis' => [],
            'departmentOptions' => $service->departmentOptionsPublic($filters['site']),
            'accountOptions' => collect(),
            'activePage' => 'details',
        ]);
    }

    private function render(Request $request, VariableCostService $service, string $page)
    {
        $filters = $this->filtersFromRequest($request);

        return view("formvc.{$page}", $service->getData($filters) + [
            'activePage' => $page,
        ]);
    }

    private function filtersFromRequest(Request $request): array
    {
        return [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'site' => $request->input('site'),
            'department' => $this->arrayInput($request, 'department'),
            'account' => $this->arrayInput($request, 'account'),
            'invoice' => $request->input('invoice'),
            'notes' => $request->input('notes'),
        ];
    }

    private function arrayInput(Request $request, string $key): array
    {
        $raw = $request->input($key, []);
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn($v) => trim((string) $v),
            $raw
        ), fn($v) => $v !== ''));
    }

    private function shouldGuardDetail(array $filters): bool
    {
        if (
            !empty($filters['department'])
            || !empty($filters['account'])
            || trim((string) ($filters['invoice'] ?? '')) !== ''
        ) {
            return false;
        }

        try {
            $from = Carbon::parse($filters['date_from'] ?? now()->startOfMonth());
            $to = Carbon::parse($filters['date_to'] ?? now());
        } catch (\Throwable $e) {
            return false;
        }

        return $from->diffInDays($to) > 92;
    }

    public function export(Request $request, VariableCostService $service)
    {
        $filters = $this->filtersFromRequest($request);
        $downloadToken = (string) $request->query('vc_download_token', '');

        if ($request->query('page') === 'monthly') {
            return $service->exportMonthly($filters, $downloadToken);
        }

        return $service->export($filters, $downloadToken);
    }
}
