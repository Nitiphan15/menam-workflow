<?php

namespace App\Http\Controllers\FormMLA;

use App\Http\Controllers\Controller;
use App\Services\FormMLA\MachineLoadService;
use App\Services\FormMLA\ProductionPlanDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MachineLoadController extends Controller
{
    public function dashboard(Request $request, MachineLoadService $service)
    {
        $filters = $this->validatedFilters($request);

        if ($invalid = $this->assertDateRange($filters)) {
            return $invalid;
        }

        return view('formmla.dashboard', $service->getDashboardData($filters));
    }

    public function inquiry(Request $request, MachineLoadService $service)
    {
        $filters = $this->validatedFilters($request);

        if ($invalid = $this->assertDateRange($filters)) {
            return $invalid;
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(20, min(500, $perPage));

        return view('formmla.inquiry', $service->getInquiryData($filters, $perPage, (int) $request->input('page', 1)));
    }

    public function planDashboard(Request $request, ProductionPlanDashboardService $service)
    {
        $filters = $this->validatedPlanFilters($request);

        if ($invalid = $this->assertPlanDateRange($filters)) {
            return $invalid;
        }

        return view('formmla.plan-dashboard', $service->getDashboardData($filters));
    }

    public function export(Request $request, MachineLoadService $service)
    {
        $filters = $this->validatedFilters($request);

        if ($invalid = $this->assertDateRange($filters)) {
            return $invalid;
        }

        return $service->exportResponse($filters);
    }

    private function assertDateRange(array $filters)
    {
        try {
            if (Carbon::parse($filters['date_from'])->gt(Carbon::parse($filters['date_to']))) {
                return back()->withErrors(['date_to' => 'Date To must be greater than or equal to Date From.'])->withInput();
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['date_to' => 'Invalid date range.'])->withInput();
        }

        return null;
    }

    public function settings(MachineLoadService $service)
    {
        return view('formmla.settings', $service->getSettingsData());
    }

    public function storeWorkCenter(Request $request, MachineLoadService $service)
    {
        try {
            $service->saveWorkCenter($this->validatedWorkCenter($request));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return redirect()->route('machine-load.settings')->with('error', 'Cannot save machine setting: ' . $e->getMessage());
        }

        return redirect()->route('machine-load.settings')->with('success', 'Machine capacity setting saved.');
    }

    public function updateWorkCenter(Request $request, int $id, MachineLoadService $service)
    {
        try {
            $service->saveWorkCenter($this->validatedWorkCenter($request), $id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return redirect()->route('machine-load.settings')->with('error', 'Cannot update machine setting: ' . $e->getMessage());
        }

        return redirect()->route('machine-load.settings')->with('success', 'Machine capacity setting updated.');
    }

    public function destroyWorkCenter(int $id, MachineLoadService $service)
    {
        try {
            $service->deleteWorkCenter($id);
        } catch (\Throwable $e) {
            return redirect()->route('machine-load.settings')->with('error', 'Cannot delete machine setting: ' . $e->getMessage());
        }

        return redirect()->route('machine-load.settings')->with('success', 'Machine capacity setting deleted.');
    }

    public function storeHoliday(Request $request, MachineLoadService $service)
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'source_site' => ['nullable', 'in:ALL,WIRE,PLUS'],
        ]);
        $validated['source_site'] = strtoupper($validated['source_site'] ?? 'ALL');

        try {
            $service->saveHoliday($validated);
        } catch (\Throwable $e) {
            return redirect()->route('machine-load.settings')->with('error', 'Cannot save holiday: ' . $e->getMessage());
        }

        return redirect()->route('machine-load.settings')->with('success', 'Holiday saved.');
    }

    public function destroyHoliday(int $id, MachineLoadService $service)
    {
        try {
            $service->deleteHoliday($id);
        } catch (\Throwable $e) {
            return redirect()->route('machine-load.settings')->with('error', 'Cannot delete holiday: ' . $e->getMessage());
        }

        return redirect()->route('machine-load.settings')->with('success', 'Holiday deleted.');
    }

    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'date_type' => ['nullable', 'in:dateopen,reqdate'],
            'site' => ['nullable', 'in:WIRE,PLUS'],
            'source' => ['nullable', 'array'],
            'source.*' => ['in:WIRE,PLUS'],
            'workcenter_ids' => ['nullable', 'array'],
            'workcenter_ids.*' => ['integer', 'min:1'],
            'machine_ids' => ['nullable', 'array'],
            'machine_ids.*' => ['integer', 'min:1'],
            'bucket' => ['nullable', 'in:week,month'],
            'status' => ['nullable', 'in:open,closed,all'],
            'quick_filter' => ['nullable', 'in:all,load_80,load_100,due_risk'],
            'start_at' => ['nullable', 'date'],
        ]);

        $source = $validated['source'] ?? [];
        if (empty($source) && !empty($validated['site'])) {
            $source = [$validated['site']];
        }

        $workcenterIds = $validated['workcenter_ids'] ?? [];

        return [
            'date_from' => $validated['date_from'] ?? now()->subDays(30)->toDateString(),
            'date_to' => $validated['date_to'] ?? now()->addDays(60)->toDateString(),
            'date_type' => $validated['date_type'] ?? 'dateopen',
            'site' => $validated['site'] ?? '',
            'source' => array_values(array_unique($source)),
            'workcenter_ids' => array_values(array_unique(array_map('intval', $workcenterIds))),
            'machine_ids' => array_values(array_unique(array_map('intval', $validated['machine_ids'] ?? []))),
            'bucket' => $validated['bucket'] ?? 'week',
            'status' => $validated['status'] ?? 'open',
            'quick_filter' => $validated['quick_filter'] ?? 'all',
            'start_at' => $validated['start_at'] ?? now()->setTime(8, 0)->format('Y-m-d H:i'),
        ];
    }

    private function validatedPlanFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'site' => ['nullable', 'in:WIRE,PLUS'],
            'source' => ['nullable', 'array'],
            'source.*' => ['in:WIRE,PLUS'],
            'transnumber' => ['nullable', 'string', 'max:60'],
            'workcenter_ids' => ['nullable', 'array'],
            'workcenter_ids.*' => ['integer', 'min:1'],
            'machine_ids' => ['nullable', 'array'],
            'machine_ids.*' => ['integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $source = $validated['source'] ?? [];
        if (empty($source) && !empty($validated['site'])) {
            $source = [$validated['site']];
        }

        return [
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
            'site' => $validated['site'] ?? '',
            'source' => array_values(array_unique($source)),
            'transnumber' => $validated['transnumber'] ?? '',
            'workcenter_ids' => array_values(array_unique(array_map('intval', $validated['workcenter_ids'] ?? []))),
            'machine_ids' => array_values(array_unique(array_map('intval', $validated['machine_ids'] ?? []))),
            'q' => $validated['q'] ?? '',
        ];
    }

    private function assertPlanDateRange(array $filters)
    {
        if (empty($filters['date_from']) || empty($filters['date_to'])) {
            return null;
        }

        try {
            $from = Carbon::parse($filters['date_from']);
            $to = Carbon::parse($filters['date_to']);

            if ($from->gt($to)) {
                return back()->withErrors(['date_to' => 'Date To must be greater than or equal to Date From.'])->withInput();
            }
            if ($from->diffInDays($to) > 180) {
                return back()->withErrors(['date_to' => 'Plan dashboard date range must be 180 days or less.'])->withInput();
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['date_to' => 'Invalid date range.'])->withInput();
        }

        return null;
    }

    private function validatedWorkCenter(Request $request): array
    {
        $validated = $request->validate([
            'source_site' => ['required', 'in:WIRE,PLUS'],
            'workcenter_id' => ['required', 'string', 'max:50'],
            'workmachine_id' => ['nullable', 'string', 'max:50'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'capacity_per_hour' => ['nullable', 'numeric', 'min:0'],
            'work_hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'work_days_per_week' => ['required', 'integer', 'min:1', 'max:7'],
            'cycle_time_minutes' => ['nullable', 'numeric', 'min:0'],
            'setup_time_minutes' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        [$workCenterSite, $workCenterId] = $this->parseMasterId($validated['workcenter_id']);
        if ($workCenterId <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'workcenter_id' => 'Please select a valid Work Center.',
            ]);
        }
        if ($workCenterSite !== null) {
            $validated['source_site'] = $workCenterSite;
        }
        $validated['workcenter_id'] = $workCenterId;

        if (!empty($validated['workmachine_id'])) {
            [$machineSite, $machineId] = $this->parseMasterId($validated['workmachine_id']);
            if ($machineId <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'workmachine_id' => 'Please select a valid Machine.',
                ]);
            }
            if ($machineSite !== null && $machineSite !== $validated['source_site']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'workmachine_id' => 'Machine source must match Work Center source.',
                ]);
            }
            $validated['workmachine_id'] = $machineId;
        }

        return $validated;
    }

    private function parseMasterId(string $value): array
    {
        $value = trim($value);
        if (str_contains($value, '|')) {
            [$site, $id] = array_pad(explode('|', $value, 2), 2, '');
            $site = strtoupper(trim($site));
            return [in_array($site, ['WIRE', 'PLUS'], true) ? $site : null, (int) $id];
        }

        return [null, (int) $value];
    }
}
