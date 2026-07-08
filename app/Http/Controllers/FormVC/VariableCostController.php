<?php

namespace App\Http\Controllers\FormVC;

use App\Http\Controllers\Controller;
use App\Services\FormVC\VariableCostService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class VariableCostController extends Controller
{
    private const ROLE_DIVISION_GROUPS = [
        'VCA' => ['Admin'],
        'VCL' => ['Logistic'],
        'VCPD' => ['Production'],
        'VCP' => ['Purchase'],
        'VCS' => ['Sale'],
    ];

    public function index(Request $request)
    {
        return Redirect::route('variable-cost.summary', $request->query());
    }

    public function summary(Request $request, VariableCostService $service)
    {
        $data = $service->getSummaryData($this->filtersFromRequest($request));

        return view('formvc.summary', $this->withAllowedDivisionGroupOptions($data, $service, $request) + [
            'activePage' => 'summary',
        ]);
    }

    public function monthly(Request $request, VariableCostService $service)
    {
        $comparePrevious = (bool) $request->boolean('compare_previous');
        $data = $service->getMonthlyData($this->filtersFromRequest($request), $comparePrevious);

        return view('formvc.monthly', $this->withAllowedDivisionGroupOptions($data, $service, $request) + [
            'activePage' => 'monthly',
            'comparePrevious' => $comparePrevious,
        ]);
    }

    public function matrix(Request $request, VariableCostService $service)
    {
        $data = $service->getMatrixData($this->filtersFromRequest($request));

        return view('formvc.matrix', $this->withAllowedDivisionGroupOptions($data, $service, $request) + [
            'activePage' => 'matrix',
        ]);
    }

    public function accounts(Request $request, VariableCostService $service)
    {
        $data = $service->getAccountsData($this->filtersFromRequest($request));

        return view('formvc.accounts', $this->withAllowedDivisionGroupOptions($data, $service, $request) + [
            'activePage' => 'accounts',
        ]);
    }

    public function yearly(Request $request, VariableCostService $service)
    {
        $filters = $this->filtersFromRequest($request) + [
            'year' => $request->input('year'),
            'class' => $this->arrayInput($request, 'class'),
        ];
        $data = $service->getYearlyData($filters);

        return view('formvc.yearly', $this->withAllowedDivisionGroupOptions($data, $service, $request) + [
            'activePage' => 'yearly',
        ]);
    }

    public function details(Request $request, VariableCostService $service)
    {
        $filters = $service->normalizeFiltersPublic($this->filtersFromRequest($request));

        return view('formvc.details', [
            'filters' => $filters,
            'kpis' => [],
            'divisionGroupOptions' => $this->allowedDivisionGroupOptions($service, $request),
            'departmentOptions' => $this->allowedDepartmentOptions(
                $service->departmentOptionsPublic($filters['site']),
                $service,
                $request,
                $filters['site']
            ),
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
        return $this->applyDivisionGroupPermission($request, [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'site' => $request->input('site'),
            'division_group' => $this->arrayInput($request, 'division_group'),
            'division_department_mode' => $request->input('division_department_mode'),
            'department' => $this->arrayInput($request, 'department'),
            'account' => $this->arrayInput($request, 'account'),
            'invoice' => $request->input('invoice'),
            'notes' => $request->input('notes'),
        ]);
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

    public function storeTruckWeightLog(Request $request, VariableCostService $service)
    {
        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->storeTruckWeightLog(
                $this->filtersFromRequest($request),
                (float) $data['weight_kg'],
                $data['notes'] ?? null,
                $request->user()
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Unable to save truck weight. Please make sure the log table is created.');
        }

        return back()->with('success', 'Saved truck weight log.');
    }

    public function updateTruckWeightLog(Request $request, int $id, VariableCostService $service)
    {
        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $service->updateTruckWeightLog(
                $id,
                (float) $data['weight_kg'],
                $data['notes'] ?? null,
                $request->user()
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Unable to update truck weight log.');
        }

        if (!$updated) {
            return back()->with('error', 'Truck weight log not found.');
        }

        return back()->with('success', 'Updated truck weight log.');
    }

    public function destroyTruckWeightLog(Request $request, int $id, VariableCostService $service)
    {
        try {
            $deleted = $service->deleteTruckWeightLog($id);
        } catch (Throwable $e) {
            return back()->with('error', 'Unable to delete truck weight log.');
        }

        if (!$deleted) {
            return back()->with('error', 'Truck weight log not found.');
        }

        return back()->with('success', 'Deleted truck weight log.');
    }

    private function applyDivisionGroupPermission(Request $request, array $filters): array
    {
        $scope = $this->divisionScopeForUser($request);
        if ($scope === null) {
            return $filters;
        }

        $allowedGroups = $scope['groups'];

        $requestedGroups = collect((array) ($filters['division_group'] ?? []))
            ->map(fn($group) => $this->canonicalDivisionGroup((string) $group))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $selectedAllowed = array_values(array_intersect($requestedGroups, $allowedGroups));

        $filters['division_department_mode'] = 'AND';

        if ($scope['mode'] === 'exclude') {
            // role VC: เห็นทุกแผนกที่ไม่อยู่ในกลุ่มใดๆ (+ กลุ่มที่ได้รับสิทธิ์เพิ่ม)
            if (!empty($selectedAllowed)) {
                // ผู้ใช้เลือกเฉพาะกลุ่มที่ตนมีสิทธิ์ → จำกัดเฉพาะกลุ่มนั้น
                $filters['division_group'] = $selectedAllowed;
                $filters['division_scope'] = null;
                $filters['division_allowed_groups'] = [];
            } else {
                $filters['division_group'] = [];
                $filters['division_scope'] = 'exclude';
                $filters['division_allowed_groups'] = $allowedGroups;
            }

            return $filters;
        }

        // include mode (VCA/VCL/VCPD/VCP/VCS): จำกัดเฉพาะกลุ่มที่ได้รับสิทธิ์ (พฤติกรรมเดิม)
        $effective = empty($requestedGroups) ? $allowedGroups : $selectedAllowed;
        $filters['division_group'] = empty($effective) ? ['__NO_MATCH__'] : $effective;
        $filters['division_scope'] = null;
        $filters['division_allowed_groups'] = [];

        return $filters;
    }

    private function withAllowedDivisionGroupOptions(array $data, VariableCostService $service, Request $request): array
    {
        $data['divisionGroupOptions'] = $this->allowedDivisionGroupOptions($service, $request);
        $data['departmentOptions'] = $this->allowedDepartmentOptions(
            $data['departmentOptions'] ?? collect(),
            $service,
            $request,
            (string) ($data['filters']['site'] ?? $request->input('site') ?? 'ALL')
        );

        return $data;
    }

    private function allowedDivisionGroupOptions(VariableCostService $service, Request $request)
    {
        $options = $service->divisionGroupOptionsPublic();
        $scope = $this->divisionScopeForUser($request);
        if ($scope === null) {
            return $options;
        }

        $allowedLookup = array_flip($scope['groups']);

        // include และ exclude: dropdown แสดงเฉพาะกลุ่มที่ผู้ใช้มีสิทธิ์ (ว่างได้สำหรับ VC ล้วน)
        return $options
            ->filter(fn($group) => isset($allowedLookup[$this->canonicalDivisionGroup((string) $group)]))
            ->values();
    }

    private function allowedDepartmentOptions($options, VariableCostService $service, Request $request, string $site)
    {
        $options = collect($options);
        $scope = $this->divisionScopeForUser($request);
        if ($scope === null || $scope['mode'] !== 'exclude') {
            return $options->values();
        }

        // role VC: ซ่อนแผนกที่อยู่ในกลุ่มที่ผู้ใช้ไม่ได้รับสิทธิ์
        $allGroups = $service->divisionGroupOptionsPublic()->all();
        $excludeGroups = array_values(array_diff($allGroups, $scope['groups']));
        if (empty($excludeGroups)) {
            return $options->values();
        }

        $excludeLabels = array_flip($service->departmentLabelsForGroupsPublic($excludeGroups, $site));

        return $options
            ->filter(fn($option) => !isset($excludeLabels[(string) $option]))
            ->values();
    }

    /**
     * Permission scope ของผู้ใช้สำหรับ Form VC.
     *  - null                                  => ไม่จำกัด (เห็นทั้งหมด เช่น VCM)
     *  - ['mode' => 'include', 'groups' => []] => เห็นเฉพาะกลุ่มที่ระบุ (VCA/VCL/VCPD/VCP/VCS)
     *  - ['mode' => 'exclude', 'groups' => []] => เห็นทุกแผนกที่ไม่อยู่ในกลุ่ม + กลุ่มที่ระบุ (role VC)
     */
    private function divisionScopeForUser(Request $request): ?array
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $groups = [];
        foreach (self::ROLE_DIVISION_GROUPS as $role => $divisionGroups) {
            if ($user->hasRoleCode($role)) {
                $groups = array_merge($groups, $divisionGroups);
            }
        }
        $groups = array_values(array_unique($groups));

        if ($user->hasRoleCode('VC')) {
            return ['mode' => 'exclude', 'groups' => $groups];
        }

        if (empty($groups)) {
            return null; // เช่น VCM อย่างเดียว — คงสิทธิ์เดิม
        }

        return ['mode' => 'include', 'groups' => $groups];
    }

    private function canonicalDivisionGroup(string $group): ?string
    {
        $key = strtolower(trim($group));

        return [
            'admin' => 'Admin',
            'logistic' => 'Logistic',
            'logistics' => 'Logistic',
            'production' => 'Production',
            'purchase' => 'Purchase',
            'sale' => 'Sale',
            'sales' => 'Sale',
        ][$key] ?? null;
    }
}
