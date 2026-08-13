<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use App\Mail\FcDivisionClosedMail;
use App\Mail\FcDivisionNeedsApprovalMail;
use App\Mail\FcDivisionRejectedMail;
use App\Services\WorkflowEngine;
use App\Support\WorkflowDb;
use App\Support\FormFcPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ForecastRmDivisionController extends Controller
{
    /**
     * Temporary Sales Forecast trial horizon.
     *
     * The database columns are still named forecast_6m, division_forecast_6m,
     * and approval_forecast_6m. During this trial they intentionally store the
     * 4-month total. Rename/migrate those legacy columns only after the trial
     * horizon is confirmed.
     */
    private const FORECAST_HORIZON_MONTHS = FormFcPeriod::MONTHS;

    private string $fcConn = 'sqlsrv_menam';
    private string $divisionSubmissionTable = 'fc_rm_division_forecast_submissions';
    private string $divisionApprovalTable = 'fc_rm_division_forecast_approval';

    private function bumpForecastIndexCacheVersion(): void
    {
        Cache::forever('forecast_rm:index:version', (int) Cache::get('forecast_rm:index:version', 1) + 1);
    }

    private function fcDivisionMailItem(?object $submission, ?object $workflow = null, array $extra = []): array
    {
        $salesCode = strtoupper((string) ($submission->sales_code ?? $extra['sales_code'] ?? ''));
        $baseMonth = (string) ($submission->forecast_base_month ?? $extra['forecast_base_month'] ?? now('Asia/Bangkok')->startOfMonth()->toDateString());
        $monthText = $baseMonth !== ''
            ? Carbon::parse($baseMonth)->format('m/Y')
            : '-';

        return array_merge([
            'form_no' => (string) ($submission->form_no ?? $workflow->form_no ?? '-'),
            'division' => $this->divisionLabels[$salesCode] ?? $salesCode ?: '-',
            'sales_code' => $salesCode,
            'month' => $monthText,
            'status' => (string) ($workflow->form_status ?? $submission->status ?? '-'),
            'step' => (string) ($workflow->current_step_no ?? '-'),
            'view_url' => route('fc.division', ['division' => $salesCode]),
            'approval_url' => route('fc.division.approval', ['division' => $salesCode]),
        ], $extra);
    }

    private function workflowUserById(?int $userId): ?object
    {
        $userId = (int) ($userId ?? 0);
        if ($userId <= 0) {
            return null;
        }

        return WorkflowDb::table('fc', 'users')
            ->where('id', $userId)
            ->first(['id', 'name', 'email']);
    }

    private function notifyFcPendingApprovers(?object $submission, ?object $workflow = null): void
    {
        $wfId = (int) ($submission->wf_form_id ?? $workflow->id ?? 0);
        if ($wfId <= 0) {
            return;
        }

        $workflow = $workflow ?: WorkflowDb::findForm('fc', $wfId);
        $item = $this->fcDivisionMailItem($submission, $workflow);

        $pendingRows = WorkflowDb::table('fc', 'wf_form_authorizes as wa')
            ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
            ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
            ->where('wa.wf_form_id', $wfId)
            ->where('wa.status', 'PENDING')
            ->whereColumn('wa.step_no', 'wf.current_step_no')
            ->whereNotNull('u.email')
            ->get(['u.email', 'u.name']);

        $pendingRows
            ->groupBy('email')
            ->each(function ($rows, $email) use ($item) {
                if (blank($email)) {
                    return;
                }

                Mail::to($email)->send(new FcDivisionNeedsApprovalMail(
                    approverName: (string) ($rows->first()->name ?? $email),
                    items: [$item],
                ));
            });
    }

    private function notifyFcOwnerClosed(?object $submission, ?object $workflow = null): void
    {
        $owner = $this->workflowUserById((int) ($submission->submitted_by ?? $workflow->request_by_user_id ?? 0));
        if (!$owner || blank($owner->email ?? null)) {
            return;
        }

        $workflow = $workflow ?: $this->workflowForSubmission($submission);

        Mail::to($owner->email)->send(new FcDivisionClosedMail(
            recipientName: (string) ($owner->name ?: $owner->email),
            item: $this->fcDivisionMailItem($submission, $workflow),
        ));
    }

    private function notifyFcOwnerRejected(?object $submission, ?object $workflow = null, string $reason = ''): void
    {
        $owner = $this->workflowUserById((int) ($submission->submitted_by ?? $workflow->request_by_user_id ?? 0));
        if (!$owner || blank($owner->email ?? null)) {
            return;
        }

        $workflow = $workflow ?: $this->workflowForSubmission($submission);

        Mail::to($owner->email)->send(new FcDivisionRejectedMail(
            recipientName: (string) ($owner->name ?: $owner->email),
            item: $this->fcDivisionMailItem($submission, $workflow, [
                'reject_reason' => $reason,
            ]),
        ));
    }

    private array $salespersonDivisionMap = [
        1506 => 'D1',
        1507 => 'D2',
        1431 => 'D3',
        1433 => 'D5',
        1434 => 'D6',
        1435 => 'D7',
        1436 => 'D8',
        478468285 => 'D9',
        528615586 => 'D9',
    ];

    private function userOr403()
    {
        $u = auth()->user();
        /*if (!$u && !app()->environment('local')) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }*/
        //return $u;

        if (!$u) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }
        return $u;
    }

    private function isSuperAdmin(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $u = auth()->user();
        if (!$u) return false;

        return (int) ($u->superadmin ?? 0) === 1;
    }

    private function allSalesCodes(): array
    {
        return ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D9'];
    }

    private function isPlanningDivisionViewer(): bool
    {
        $user = auth()->user();

        return $user
            && method_exists($user, 'hasRoleCode')
            && $user->hasRoleCode('FC_PLN');
    }

    private function planningDivisionCodes(): array
    {
        return ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9'];
    }

    private function requestedPlanningDivisions(Request $request): array
    {
        $requested = $request->query('divisions', $request->query('division', []));

        $selected = collect(is_array($requested) ? $requested : [$requested])
            ->map(fn($division) => $this->normalizeSalesCode((string) $division))
            ->filter(fn($division) => $division && in_array($division, $this->planningDivisionCodes(), true))
            ->unique()
            ->values()
            ->all();

        return $selected ?: $this->planningDivisionCodes();
    }

    private function normalizeSalesCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        $code = str_replace(['.', ' '], '', $code);

        if (preg_match('/^D([1-9])$/', $code, $m)) {
            return 'D' . $m[1];
        }

        return null;
    }

    private array $divisionLabels = [
        'D1' => 'D1',
        'D2' => 'D2',
        'D3' => 'D3',
        'D5' => 'D5',
        'D6' => 'D6',
        'D7' => 'D7',
        'D9' => 'D9',
    ];

    private array $customerCodeDivisionOverrides = [
        'DCW-003' => 'D9',
        'DEA-001' => 'D9',
        'DTK-025' => 'D9',
    ];

    private function availableSalesCodesForUser(): array
    {
        $u = auth()->user();
        if (!$u) {
            return [];
        }

        $allowed = [];
        foreach ($this->allSalesCodes() as $code) {
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                $allowed[] = $code;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function resolveUserSalesCode(): ?string
    {
        $allowed = $this->availableSalesCodesForUser();
        return $allowed[0] ?? null;
    }

    private function availableForecastDivisions(): array
    {
        if (app()->environment('local')) {
            return ['D1'];
        }

        $u = auth()->user();
        if (!$u) {
            return [];
        }

        $allowed = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $i) {
            $code = 'D' . $i;
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                $allowed[] = $code;
            }
        }

        return $allowed;
    }

    private function currentForecastDivision(Request $request): ?string
    {
        $allowed = $this->availableForecastDivisions();
        if (empty($allowed)) {
            return null;
        }

        $requested = strtoupper(trim((string) $request->query('division', $request->input('division', ''))));

        if ($requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0];
    }

    private function hasMultiForecastDivisions(): bool
    {
        return count($this->availableForecastDivisions()) > 1;
    }

    /*private function requestedOrResolvedSalesCode(Request $request): ?string
    {
        $requested = $this->normalizeSalesCode(
            $request->query('sales_code', $request->input('sales_code'))
        );

        if ($requested) {
            return $requested;
        }

        return $this->resolveUserSalesCode();
    }*/

    private function requestedOrResolvedSalesCode(Request $request): ?string
    {
        $requested = $this->normalizeSalesCode(
            $request->query(
                'division',
                $request->input(
                    'division',
                    $request->query('sales_code', $request->input('sales_code'))
                )
            )
        );

        $allowed = $this->availableSalesCodesForUser();

        if ($requested && ($this->isSuperAdmin() || in_array($requested, $allowed, true))) {
            return $requested;
        }

        return $allowed[0] ?? null;
    }

    private function canUseSalesCode(string $salesCode): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $u = auth()->user();
        if (!$u) {
            return false;
        }

        return method_exists($u, 'hasRoleCode') && $u->hasRoleCode($salesCode);
    }

    private function canApproveAllDivisions(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $u = auth()->user();

        return $u && method_exists($u, 'hasRoleCode') && $u->hasRoleCode('FCAPPROVE');
    }

    private function canAccessApprovalSalesCode(string $salesCode, string $forecastBaseMonth): bool
    {
        return in_array($salesCode, $this->availableApprovalSalesCodesForUser($forecastBaseMonth), true);
    }

    private function canApproveDivisionForecast(): bool
    {
        if ($this->canApproveAllDivisions()) {
            return true;
        }

        return count($this->availableSalesCodesForUser()) > 0
            || count($this->pendingApprovalSalesCodesForUser(now('Asia/Bangkok')->startOfMonth()->toDateString())) > 0;
    }

    private function pendingApprovalSalesCodesForUser(string $forecastBaseMonth): array
    {
        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0 || !$this->submissionTableAvailable()) {
            return [];
        }

        return WorkflowDb::table('fc', 'wf_form_authorizes as wa')
            ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
            ->join($this->divisionSubmissionTable . ' as sub', 'sub.wf_form_id', '=', 'wf.id')
            ->where('wa.approver_user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('wa.status')->orWhere('wa.status', 'PENDING');
            })
            ->whereDate('sub.forecast_base_month', $forecastBaseMonth)
            ->pluck('sub.sales_code')
            ->map(fn($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function availableApprovalSalesCodesForUser(string $forecastBaseMonth): array
    {
        if ($this->canApproveAllDivisions()) {
            return $this->allSalesCodes();
        }

        return collect($this->availableSalesCodesForUser())
            ->merge($this->pendingApprovalSalesCodesForUser($forecastBaseMonth))
            ->map(fn($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function requestedOrResolvedApprovalSalesCode(Request $request, string $forecastBaseMonth): ?string
    {
        $requested = $this->normalizeSalesCode(
            $request->query(
                'division',
                $request->input(
                    'division',
                    $request->query('sales_code', $request->input('sales_code'))
                )
            )
        );

        $allowed = $this->availableApprovalSalesCodesForUser($forecastBaseMonth);

        if ($requested && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0] ?? null;
    }

    private function divisionForecastSubmitted(string $salesCode, string $forecastBaseMonth): bool
    {
        if ($this->submissionTableAvailable()) {
            return DB::connection($this->fcConn)
                ->table($this->divisionSubmissionTable)
                ->where('sales_code', $salesCode)
                ->whereDate('forecast_base_month', $forecastBaseMonth)
                ->whereIn('status', ['SUBMITTED', 'PENDING_APPROVAL', 'APPROVED'])
                ->exists();
        }

        return DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast')
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', $forecastBaseMonth)
            ->exists();
    }

    private function submissionTableAvailable(): bool
    {
        try {
            DB::connection($this->fcConn)
                ->table($this->divisionSubmissionTable)
                ->limit(1)
                ->get(['id']);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function approvalTableAvailable(): bool
    {
        try {
            DB::connection($this->fcConn)
                ->table($this->divisionApprovalTable)
                ->limit(1)
                ->get(['id']);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function approvalKFactorColumnAvailable(): bool
    {
        try {
            return (bool) DB::connection($this->fcConn)
                ->selectOne(
                    "SELECT 1 AS ok
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$this->divisionApprovalTable, 'approval_k_factor']
                );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function fetchApprovalRows(string $salesCode, string $forecastBaseMonth, array $keys)
    {
        if (empty($keys) || !$this->approvalTableAvailable()) {
            return collect();
        }

        $pairs = collect($keys)
            ->map(function ($key) {
                [$customerId, $fgPartnumber] = array_pad(explode('|', (string) $key, 2), 2, '');

                return [
                    'customer_id' => (int) $customerId,
                    'fg_partnumber' => strtoupper(trim((string) $fgPartnumber)),
                ];
            })
            ->filter(fn($r) => $r['customer_id'] > 0 && $r['fg_partnumber'] !== '')
            ->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        return $pairs
            ->chunk(900)
            ->flatMap(function ($chunk) use ($salesCode, $forecastBaseMonth) {
                return DB::connection($this->fcConn)
                    ->table($this->divisionApprovalTable)
                    ->where('sales_code', $salesCode)
                    ->whereDate('forecast_base_month', $forecastBaseMonth)
                    ->where(function ($q) use ($chunk) {
                        foreach ($chunk as $pair) {
                            $q->orWhere(function ($sub) use ($pair) {
                                $sub->where('customer_id', $pair['customer_id'])
                                    ->where('fg_partnumber', $pair['fg_partnumber']);
                            });
                        }
                    })
                    ->get();
            })
            ->keyBy(fn($r) => ((int) ($r->customer_id ?? 0)) . '|' . strtoupper(trim((string) ($r->fg_partnumber ?? ''))));
    }

    private function currentSubmission(string $salesCode, string $forecastBaseMonth): ?object
    {
        if (!$this->submissionTableAvailable()) {
            return null;
        }

        return DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', $forecastBaseMonth)
            ->whereIn('status', ['SUBMITTED', 'PENDING_APPROVAL', 'APPROVED'])
            ->orderByDesc('id')
            ->first();
    }

    private function anySubmission(string $salesCode, string $forecastBaseMonth): ?object
    {
        if (!$this->submissionTableAvailable()) {
            return null;
        }

        return DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', $forecastBaseMonth)
            ->orderByDesc('id')
            ->first();
    }

    private function hasSubmittedForecastData(string $salesCode, string $forecastBaseMonth): bool
    {
        if ($this->submissionTableAvailable()) {
            return (bool) $this->currentSubmission($salesCode, $forecastBaseMonth);
        }

        return DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast')
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', $forecastBaseMonth)
            ->exists();
    }

    private function workflowForSubmission(?object $submission): ?object
    {
        $wfId = (int) ($submission->wf_form_id ?? 0);
        if ($wfId <= 0) {
            return null;
        }

        return WorkflowDb::findForm('fc', $wfId);
    }

    private function submissionStatusFromWorkflow(?object $workflow): string
    {
        return $this->workflowIsApproved($workflow)
            ? 'APPROVED'
            : 'PENDING_APPROVAL';
    }

    private function workflowIsApproved(?object $workflow): bool
    {
        $workflowStatus = strtoupper((string) ($workflow->form_status ?? ''));

        return in_array($workflowStatus, [WorkflowEngine::ST_APPROVED, WorkflowEngine::ST_CLOSED, 'CLOSED'], true);
    }

    private function canApproveSubmission(?object $submission): bool
    {
        $wfId = (int) ($submission->wf_form_id ?? 0);
        $userId = (int) (auth()->id() ?? 0);

        return $wfId > 0 && $userId > 0 && WorkflowDb::canApprove('fc', $wfId, $userId);
    }

    private function createSubmissionWorkflow(string $salesCode, string $forecastBaseMonth, $user): ?int
    {
        if (!$this->submissionTableAvailable()) {
            return null;
        }

        $existing = $this->currentSubmission($salesCode, $forecastBaseMonth);
        if ($existing) {
            return (int) ($existing->wf_form_id ?? 0) ?: null;
        }

        $formNo = 'FC-' . strtoupper($salesCode) . '-' . Carbon::parse($forecastBaseMonth)->format('Ym');
        $departmentId = (int) ($user->department_id ?? 0);
        $existingAny = $this->anySubmission($salesCode, $forecastBaseMonth);

        if ($existingAny) {
            $submissionId = (int) $existingAny->id;
            DB::connection($this->fcConn)
                ->table($this->divisionSubmissionTable)
                ->where('id', $submissionId)
                ->update([
                    'form_no' => $formNo,
                    'wf_form_id' => null,
                    'department_id' => $departmentId > 0 ? $departmentId : null,
                    'status' => 'SUBMITTED',
                    'submitted_at' => now(),
                    'submitted_by' => $user->id ?? null,
                    'approved_at' => null,
                    'approved_by' => null,
                    'rejected_at' => null,
                    'rejected_by' => null,
                    'reject_reason' => null,
                    'updated_at' => now(),
                    'updated_by' => $user->id ?? null,
                ]);
        } else {
            $submissionId = (int) DB::connection($this->fcConn)
                ->table($this->divisionSubmissionTable)
                ->insertGetId([
                    'sales_code' => $salesCode,
                    'forecast_base_month' => $forecastBaseMonth,
                    'form_no' => $formNo,
                    'department_id' => $departmentId > 0 ? $departmentId : null,
                    'status' => 'SUBMITTED',
                    'submitted_at' => now(),
                    'submitted_by' => $user->id ?? null,
                    'created_at' => now(),
                    'created_by' => $user->id ?? null,
                    'updated_at' => now(),
                    'updated_by' => $user->id ?? null,
                ]);
        }

        $wfId = WorkflowEngine::submit(
            appCode: 'fc',
            refType: 'FORMFC_DIVISION_FORECAST',
            refId: $submissionId,
            options: [
                'form_no' => $formNo,
                'request_by_user_id' => (int) ($user->id ?? auth()->id()),
                'initial_step_no' => 2,
                'context' => [
                    'department_id' => $departmentId,
                    'sales_code' => $salesCode,
                    'forecast_base_month' => $forecastBaseMonth,
                ],
            ]
        );

        $workflow = WorkflowDb::findForm('fc', $wfId);

        DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('id', $submissionId)
            ->update([
                'wf_form_id' => $wfId,
                'form_no' => (string) ($workflow->form_no ?? $formNo),
                'status' => $this->submissionStatusFromWorkflow($workflow),
                'updated_at' => now(),
                'updated_by' => $user->id ?? null,
            ]);

        return $wfId;
    }

    private function divisionSalespersonIds(string $division): array
    {
        return collect($this->salespersonDivisionMap)
            ->filter(fn($d) => $d === strtoupper($division))
            ->keys()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    private function customerCodeOverridesForDivision(string $division): array
    {
        $division = strtoupper($division);

        return collect($this->customerCodeDivisionOverrides)
            ->filter(fn($owner) => strtoupper((string) $owner) === $division)
            ->keys()
            ->map(fn($code) => strtoupper(trim((string) $code)))
            ->values()
            ->all();
    }

    private function customerCodeOverridesForOtherDivisions(string $division): array
    {
        $division = strtoupper($division);

        return collect($this->customerCodeDivisionOverrides)
            ->filter(fn($owner) => strtoupper((string) $owner) !== $division)
            ->keys()
            ->map(fn($code) => strtoupper(trim((string) $code)))
            ->values()
            ->all();
    }

    private function buildCustomerDivisionOverrideSql(string $salesCode, string $customerAlias = 'c'): array
    {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);
        $salespersonPlaceholders = implode(',', array_fill(0, count($salespersonIds), '?'));
        $ownCustomerCodes = $this->customerCodeOverridesForDivision($salesCode);
        $otherCustomerCodes = $this->customerCodeOverridesForOtherDivisions($salesCode);

        $sql = "AND ({$customerAlias}.saleperson_id IN ({$salespersonPlaceholders})";
        $bindings = $salespersonIds;

        if (!empty($ownCustomerCodes)) {
            $sql .= ' OR UPPER(TRIM(COALESCE(' . $customerAlias . '.customernumber, \'\'))) IN (' . implode(',', array_fill(0, count($ownCustomerCodes), '?')) . ')';
            $bindings = array_merge($bindings, $ownCustomerCodes);
        }

        $sql .= ') ';

        if (!empty($otherCustomerCodes)) {
            $sql .= 'AND UPPER(TRIM(COALESCE(' . $customerAlias . '.customernumber, \'\'))) NOT IN (' . implode(',', array_fill(0, count($otherCustomerCodes), '?')) . ') ';
            $bindings = array_merge($bindings, $otherCustomerCodes);
        }

        return [$sql, $bindings];
    }

    private function parseRmKeywords(string $text): array
    {
        return collect(preg_split('/[\s,|;]+/', strtoupper(trim($text))))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function fetchDivisionPartMasterConfig(string $salesCode)
    {
        return DB::connection($this->fcConn)
            ->table('fc_rm_division_part_master')
            ->where('sales_code', $salesCode)
            ->get()
            ->keyBy(fn($r) => strtoupper(trim((string) $r->rm_partnumber)));
    }

    private function fetchSupplierOptions()
    {
        return DB::connection($this->fcConn)
            ->table('fc_supplier_master')
            ->where('is_active', 1)
            ->orderBy('supplier_short_name')
            ->get(['supplier_code', 'supplier_short_name'])
            ->map(fn($r) => [
                'supplier_code' => (string) $r->supplier_code,
                'supplier_short_name' => (string) ($r->supplier_short_name ?? ''),
                'label' => (string) ($r->supplier_short_name ?? ''),
            ])
            ->values();
    }

    private function parseMultiKeywords($text): array
    {
        $items = is_array($text) ? $text : [$text];

        return collect($items)
            ->flatMap(function ($v) {
                return preg_split('/[\s,|;]+/', strtoupper(trim((string) $v))) ?: [];
            })
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Customer filter ห้ามแตกคำด้วย space เพราะชื่อบริษัทไทยมีช่องว่างเยอะ
     * ถ้าแตกเป็นคำ เช่น "ไทย" / "สำนักงานใหญ่" จะดึงลูกค้าอื่นติดมาด้วย
     * ให้แยกหลายลูกค้าด้วย comma / pipe / semicolon เท่านั้น
     */
    private function parseCustomerFilterTerms($text): array
    {
        $items = is_array($text) ? $text : [$text];

        return collect($items)
            ->flatMap(function ($v) {
                $v = trim((string) $v);
                if ($v === '') return [];
                return preg_split('/[,|;]+/', $v) ?: [];
            })
            ->map(function ($v) {
                $v = trim((string) $v);
                // autocomplete เดิมอาจเป็น "CODE - Customer Name" ให้เหลือเฉพาะชื่อ
                if (preg_match('/^.+?\s+-\s+(.+)$/u', $v, $m)) {
                    $v = trim($m[1]);
                }
                return strtoupper($v);
            })
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeFilterValues($value, bool $upper = false): array
    {
        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->flatMap(function ($v) {
                $v = trim((string) $v);
                if ($v === '') return [];
                return preg_split('/[,|;]+/', $v) ?: [];
            })
            ->map(function ($v) use ($upper) {
                $v = trim((string) $v);
                if (preg_match('/^.+?\s+-\s+(.+)$/u', $v, $m)) {
                    $v = trim($m[1]);
                }
                return $upper ? strtoupper($v) : $v;
            })
            ->filter(fn($v) => $v !== '')
            ->unique(fn($v) => strtoupper($v))
            ->values()
            ->all();
    }

    private function fetchSupplierMapByRmParts(array $rmParts)
    {
        $rmParts = collect($rmParts)
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($rmParts)) {
            return collect();
        }

        return DB::connection($this->fcConn)
            ->table('fc_item_supplier_map as ism')
            ->join('parts as p', 'p.id', '=', 'ism.part_id')
            ->join('fc_supplier_master as sm', 'sm.id', '=', 'ism.supplier_id')
            ->whereIn(DB::raw('UPPER(LTRIM(RTRIM(p.partnumber)))'), $rmParts)
            ->where('sm.is_active', 1)
            ->orderBy('sm.supplier_short_name')
            ->get([
                DB::raw('UPPER(LTRIM(RTRIM(p.partnumber))) as rm_partnumber'),
                'sm.supplier_code',
                'sm.supplier_short_name',
            ])
            ->groupBy('rm_partnumber')
            ->map(function ($rows) {
                $first = collect($rows)->first();

                return [
                    'supplier_code' => (string) ($first->supplier_code ?? ''),
                    'supplier_short_name' => (string) ($first->supplier_short_name ?? ''),
                ];
            });
    }

    private function customerFgOwnerKey($customerName, $fgPartnumber): string
    {
        return strtoupper(trim((string) $customerName)) . '|' . strtoupper(trim((string) $fgPartnumber));
    }

    private function rowCustomerFgOwnerKey(array $row): string
    {
        return $this->customerFgOwnerKey($row['customer_name'] ?? '', $row['fg_partnumber'] ?? '');
    }

    private function rowCustomerFgDedupKey(array $row): string
    {
        return $this->rowCustomerFgOwnerKey($row);
    }

    private function shouldDisplayManualRow(array $row): bool
    {
        if ((float) ($row['avg6'] ?? 0) > 0) {
            return false;
        }

        $rmPart = strtoupper(trim((string) ($row['rm_partnumber'] ?? '')));
        $hasSavedManual = (int) ($row['manual_forecast_saved'] ?? 0) === 1
            || (float) ($row['manual_forecast_1m'] ?? 0) > 0;

        return $rmPart !== '' || $hasSavedManual;
    }

    private function findOverrideCustomerCodeForName(?string $customerName): ?string
    {
        $customerName = trim((string) $customerName);
        if ($customerName === '') {
            return null;
        }

        $codes = array_keys($this->customerCodeDivisionOverrides);

        foreach (['pgsqlw', 'pgsqlp'] as $conn) {
            $code = DB::connection($conn)
                ->table('customer')
                ->whereIn(DB::raw("UPPER(TRIM(COALESCE(customernumber, '')))"), $codes)
                ->whereRaw("UPPER(TRIM(COALESCE(name, ''))) = ?", [strtoupper($customerName)])
                ->value('customernumber');

            if ($code) {
                return strtoupper(trim((string) $code));
            }
        }

        return null;
    }

    private function fetchLatestSavedCustomerFgOwners(string $forecastBaseMonth)
    {
        $fromMonth = Carbon::parse($forecastBaseMonth)->subMonths(12)->startOfMonth()->toDateString();

        return DB::connection($this->fcConn)
            ->table('fc_rm_division_part_setting')
            ->whereDate('forecast_base_month', '<=', $forecastBaseMonth)
            ->whereDate('forecast_base_month', '>=', $fromMonth)
            ->whereNotNull('customer_name')
            ->whereRaw("LTRIM(RTRIM(COALESCE(customer_name, ''))) <> ''")
            ->whereNotNull('fg_partnumber')
            ->whereRaw("LTRIM(RTRIM(COALESCE(fg_partnumber, ''))) <> ''")
            ->whereNotNull('manual_forecast_1m')
            ->where('manual_forecast_1m', '>', 0)
            ->orderByDesc('forecast_base_month')
            ->orderByDesc('updated_at')
            ->get(['sales_code', 'customer_name', 'fg_partnumber'])
            ->groupBy(fn($row) => $this->customerFgOwnerKey($row->customer_name ?? '', $row->fg_partnumber ?? ''))
            ->map(function ($rows) {
                $row = collect($rows)->first();
                return strtoupper(trim((string) ($row->sales_code ?? '')));
            });
    }

    private function applySavedOwnerOverride($rows, $ownerByCustomerFg, string $salesCode)
    {
        return collect($rows)
            ->filter(function ($row) use ($ownerByCustomerFg, $salesCode) {
                $owner = $ownerByCustomerFg->get($this->rowCustomerFgOwnerKey((array) $row));

                return $owner === null || $owner === strtoupper($salesCode);
            })
            ->values();
    }

    private function fetchHistoryForSavedCustomerFgRows($targetRows, Carbon $start, Carbon $end, array $historyYm, string $companyMode = 'ALL')
    {
        $targets = collect($targetRows)
            ->map(function ($row) {
                $row = (array) $row;
                $customerName = trim((string) ($row['customer_name'] ?? ''));
                $fgPartnumber = strtoupper(trim((string) ($row['fg_partnumber'] ?? '')));

                if ($customerName === '' || $fgPartnumber === '') {
                    return null;
                }

                return [
                    'key' => $this->customerFgOwnerKey($customerName, $fgPartnumber),
                    'customer_code' => $this->findOverrideCustomerCodeForName($customerName),
                    'customer_id' => (int) ($row['customer_id'] ?? 0),
                    'customer_name' => $customerName,
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => (string) ($row['fg_description'] ?? ''),
                    'rm_partnumber' => strtoupper(trim((string) ($row['rm_partnumber'] ?? ''))),
                ];
            })
            ->filter()
            ->unique('key')
            ->values();

        if ($targets->isEmpty()) {
            return collect();
        }

        $targetByKey = $targets->keyBy('key');

        $fetch = function (string $conn) use ($targets, $start, $end) {
            $rows = collect();

            foreach ($targets->chunk(50) as $chunk) {
                $pairSql = [];
                $pairBindings = [];

                foreach ($chunk as $target) {
                    if (!empty($target['customer_code'])) {
                        $pairSql[] = "(UPPER(TRIM(COALESCE(c.customernumber, ''))) = ? AND UPPER(TRIM(p_fg.partnumber)) = ?)";
                        $pairBindings[] = strtoupper($target['customer_code']);
                    } else {
                        $pairSql[] = "(UPPER(TRIM(COALESCE(c.name, ''))) = ? AND UPPER(TRIM(p_fg.partnumber)) = ?)";
                        $pairBindings[] = strtoupper($target['customer_name']);
                    }
                    $pairBindings[] = strtoupper($target['fg_partnumber']);
                }

                if (empty($pairSql)) {
                    continue;
                }

                $sql = '
                    SELECT
                        COALESCE(c.id, 0) AS customer_id,
                        COALESCE(c.name, \'-\') AS customer_name,
                        UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                        p_fg.description AS fg_description,
                        UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) AS rm_partnumber,
                        DATE_TRUNC(\'month\', sales.transdate)::date AS month,
                        SUM(ROUND(COALESCE(sales.qty, 0), 2)) AS qty_sum
                    FROM (
                        SELECT ar.customer_id, inv.parts_id, ar.transdate, COALESCE(inv.qty, 0) AS qty
                        FROM invoice inv
                        JOIN ar ON ar.id = inv.trans_id
                        UNION ALL
                        SELECT ar.customer_id, inv.parts_id, ret.transdate, COALESCE(rei.qty, 0) AS qty
                        FROM returnitems rei
                        JOIN return ret ON ret.id = rei.trans_id
                        JOIN invoice inv ON inv.id = rei.invoice_id
                        JOIN ar ON ar.id = inv.trans_id
                        WHERE ret.hasitems = TRUE
                    ) sales
                    JOIN customer c ON c.id = sales.customer_id
                    LEFT JOIN parts p_fg ON p_fg.id = sales.parts_id
                    WHERE sales.transdate >= ?
                    AND sales.transdate <= ?
                    AND p_fg.partnumber IS NOT NULL
                    AND (' . implode(' OR ', $pairSql) . ')
                    GROUP BY
                        COALESCE(c.id, 0),
                        COALESCE(c.name, \'-\'),
                        UPPER(TRIM(p_fg.partnumber)),
                        p_fg.description,
                        UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))),
                        DATE_TRUNC(\'month\', sales.transdate)::date
                    ORDER BY customer_name, fg_partnumber, month
                ';

                $bindings = array_merge(
                    [$start->toDateString(), $end->toDateString()],
                    $pairBindings
                );

                $rows = $rows->merge(
                    collect(DB::connection($conn)->select($sql, $bindings))
                        ->map(fn($r) => (array) $r)
                );
            }

            return $rows;
        };

        $historyRows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $historyRows = $historyRows->merge($fetch('pgsqlw')->map(function ($r) {
                $r['company'] = 'WIRE';
                return $r;
            }));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $historyRows = $historyRows->merge($fetch('pgsqlp')->map(function ($r) {
                $r['company'] = 'PLUS';
                return $r;
            }));
        }

        return $historyRows
            ->groupBy(fn($row) => $this->rowCustomerFgOwnerKey($row))
            ->map(function ($rows, $key) use ($historyYm, $targetByKey) {
                $first = collect($rows)->first();
                $target = $targetByKey->get($key, []);

                $monthMap = [];
                $monthMapByCo = ['WIRE' => [], 'PLUS' => []];

                foreach ($rows as $row) {
                    $ym = Carbon::parse($row['month'])->format('Y-m');
                    $qty = (float) ($row['qty_sum'] ?? 0);
                    $monthMap[$ym] = ($monthMap[$ym] ?? 0) + $qty;
                    $co = strtoupper((string) ($row['company'] ?? 'WIRE'));
                    if (!isset($monthMapByCo[$co])) $monthMapByCo[$co] = [];
                    $monthMapByCo[$co][$ym] = ($monthMapByCo[$co][$ym] ?? 0) + $qty;
                }

                $filled = collect($historyYm)->map(fn($ym) => (float) ($monthMap[$ym] ?? 0));
                $fgPartnumber = strtoupper(trim((string) ($target['fg_partnumber'] ?? $first['fg_partnumber'] ?? '')));

                // f4 (RM) อาจว่างฝั่งหนึ่ง (เช่น WIRE) แต่มีอีกฝั่ง (PLUS) ใน group เดียวกัน
                // จึงต้องเลือก rm ตัวแรกที่ "ไม่ว่าง" จากทุกแถว ไม่ใช่ยึดแถวแรกเฉย ๆ
                $rmFromRows = (string) (collect($rows)
                    ->map(fn($r) => strtoupper(trim((string) ($r['rm_partnumber'] ?? ''))))
                    ->first(fn($v) => $v !== '') ?? '');

                return [
                    'row_key' => ((int) ($target['customer_id'] ?? 0)) . '|' . $fgPartnumber,
                    'customer_id' => (int) ($target['customer_id'] ?? $first['customer_id'] ?? 0),
                    'customer_name' => (string) ($target['customer_name'] ?? $first['customer_name'] ?? '-'),
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => (string) (($target['fg_description'] ?? '') !== ''
                        ? $target['fg_description']
                        : ($first['fg_description'] ?? '')),
                    'rm_partnumber' => (string) (($target['rm_partnumber'] ?? '') !== ''
                        ? $target['rm_partnumber']
                        : $rmFromRows),
                    'avg6' => round((float) $filled->avg(), 2),
                    'history_detail' => collect($historyYm)->map(fn($ym) => [
                        'ym' => $ym,
                        'qty' => (float) ($monthMap[$ym] ?? 0),
                        'qty_wire' => (float) ($monthMapByCo['WIRE'][$ym] ?? 0),
                        'qty_plus' => (float) ($monthMapByCo['PLUS'][$ym] ?? 0),
                    ])->values()->all(),
                ];
            });
    }

    private function fetchFgHistoryGrouped(
        string $salesCode,
        array $rmLikes,
        array $customerNameLikes,
        Carbon $start,
        Carbon $end,
        string $companyMode = 'ALL',
        array $customerIds = []
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }
        [$customerDivisionSql, $customerDivisionBindings] = $this->buildCustomerDivisionOverrideSql($salesCode);

        $fetch = function (string $conn) use ($rmLikes, $customerNameLikes, $customerIds, $start, $end, $customerDivisionSql, $customerDivisionBindings) {
            $rmSql = '';
            $rmBindings = [];
            if (!empty($rmLikes)) {
                $rmSql .= ' AND (';
                foreach ($rmLikes as $i => $kw) {
                    if ($i > 0) $rmSql .= ' OR ';
                    $rmSql .= 'UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) LIKE ?';
                    $rmBindings[] = $kw . '%';
                }
                $rmSql .= ') ';
            }

            $custNameSql = '';
            $custNameBindings = [];
            if (!empty($customerNameLikes)) {
                $custNameSql .= ' AND (';
                foreach ($customerNameLikes as $i => $kw) {
                    if ($i > 0) $custNameSql .= ' OR ';
                    $custNameSql .= "(UPPER(TRIM(COALESCE(c.name, ''))) LIKE ? OR UPPER(TRIM(COALESCE(c.customernumber, ''))) LIKE ?)";
                    $custNameBindings[] = '%' . $kw . '%';
                    $custNameBindings[] = '%' . $kw . '%';
                }
                $custNameSql .= ') ';
            }

            $custIdSql = '';
            $custIdBindings = [];
            if (!empty($customerIds)) {
                $custIdSql = ' AND c.id IN (' . implode(',', array_fill(0, count($customerIds), '?')) . ') ';
                $custIdBindings = array_values(array_map('intval', $customerIds));
            }

            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0) AS customer_id,
                    COALESCE(c.name, '-') AS customer_name,
                    UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                    p_fg.description AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, '')))) AS rm_partnumber,
                    DATE_TRUNC('month', sales.transdate)::date AS month,
                    SUM(ROUND(COALESCE(sales.qty, 0), 2)) AS qty_sum
                FROM (
                    SELECT ar.customer_id, inv.parts_id, ar.transdate, COALESCE(inv.qty, 0) AS qty
                    FROM invoice inv
                    JOIN ar ON ar.id = inv.trans_id
                    UNION ALL
                    SELECT ar.customer_id, inv.parts_id, ret.transdate, COALESCE(rei.qty, 0) AS qty
                    FROM returnitems rei
                    JOIN return ret ON ret.id = rei.trans_id
                    JOIN invoice inv ON inv.id = rei.invoice_id
                    JOIN ar ON ar.id = inv.trans_id
                    WHERE ret.hasitems = TRUE
                ) sales
                JOIN customer c ON c.id = sales.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = sales.parts_id
                WHERE sales.transdate >= ?
                AND sales.transdate <= ?
                {$customerDivisionSql}
                {$custIdSql}
                AND p_fg.partnumber IS NOT NULL
                {$rmSql}
                {$custNameSql}
                GROUP BY
                    COALESCE(c.id, 0),
                    COALESCE(c.name, '-'),
                    UPPER(TRIM(p_fg.partnumber)),
                    p_fg.description,
                    UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, '')))),
                    DATE_TRUNC('month', sales.transdate)::date
                ORDER BY customer_name, fg_partnumber, month
                SQL;

            $bindings = array_merge(
                [$start->toDateString(), $end->toDateString()],
                $customerDivisionBindings,
                $custIdBindings,
                $rmBindings,
                $custNameBindings
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw')->map(function ($r) {
                $r['company'] = 'WIRE';
                return $r;
            }));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp')->map(function ($r) {
                $r['company'] = 'PLUS';
                return $r;
            }));
        }

        return $rows;
    }

    private function fetchLatestManualSeedRows(string $salesCode, string $forecastBaseMonth)
    {
        $settingRows = DB::connection($this->fcConn)
            ->table('fc_rm_division_part_setting')
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', '<=', $forecastBaseMonth)
            ->whereDate(
                'forecast_base_month',
                '>=',
                Carbon::parse($forecastBaseMonth)->subMonths(12)->startOfMonth()->toDateString()
            )
            ->whereNotNull('customer_id')
            ->where('customer_id', '>', 0)
            ->whereNotNull('fg_partnumber')
            ->whereRaw("LTRIM(RTRIM(COALESCE(fg_partnumber, ''))) <> ''")
            ->where(function ($q) {
                $q->where('is_selected', 1)
                    ->orWhereNotNull('manual_forecast_1m');
            })
            ->orderByDesc('forecast_base_month')
            ->orderByDesc('updated_at')
            ->get([
                'forecast_base_month',
                'customer_id',
                'customer_name',
                'fg_partnumber',
                'fg_description',
                'is_selected',
                'k_factor',
                'manual_forecast_1m',
                'row_remark',
                'supplier_code',
                'supplier_name',
            ])
            ->map(function ($r) {
                $fgPartnumber = strtoupper(trim((string) ($r->fg_partnumber ?? '')));
                $customerId = (int) ($r->customer_id ?? 0);

                return [
                    'row_key' => $customerId . '|' . $fgPartnumber,
                    'forecast_base_month' => (string) ($r->forecast_base_month ?? ''),
                    'customer_id' => $customerId,
                    'customer_name' => (string) ($r->customer_name ?? '-'),
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => (string) ($r->fg_description ?? ''),
                    'rm_partnumber' => '',
                    'is_selected' => (int) ($r->is_selected ?? 0),
                    'k_factor' => $r->k_factor !== null ? round((float) $r->k_factor, 1) : null,
                    'manual_forecast_1m' => $r->manual_forecast_1m !== null
                        ? round((float) $r->manual_forecast_1m, 2)
                        : null,
                    'row_remark' => (string) ($r->row_remark ?? ''),
                    'supplier_code' => (string) ($r->supplier_code ?? ''),
                    'supplier_name' => (string) ($r->supplier_name ?? ''),
                ];
            });

        $forecastRows = DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast')
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', '<=', $forecastBaseMonth)
            ->whereDate(
                'forecast_base_month',
                '>=',
                Carbon::parse($forecastBaseMonth)->subMonths(12)->startOfMonth()->toDateString()
            )
            ->whereNotNull('customer_id')
            ->where('customer_id', '>', 0)
            ->whereNotNull('fg_partnumber')
            ->whereRaw("LTRIM(RTRIM(COALESCE(fg_partnumber, ''))) <> ''")
            ->where(function ($q) {
                $q->where('manual_forecast_1m', '>', 0)
                    ->orWhere(function ($sub) {
                        $sub->where('source_type', 'like', 'MANUAL%')
                            ->where('forecast_qty', '>', 0);
                    });
            })
            ->orderByDesc('forecast_base_month')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get([
                'forecast_base_month',
                'customer_id',
                'customer_name',
                'fg_partnumber',
                'fg_description',
                'rm_partnumber',
                'k_factor',
                'manual_forecast_1m',
                'row_remark',
                'supplier_code',
                'supplier_name',
            ])
            ->map(function ($r) {
                $fgPartnumber = strtoupper(trim((string) ($r->fg_partnumber ?? '')));
                $customerId = (int) ($r->customer_id ?? 0);

                return [
                    'row_key' => $customerId . '|' . $fgPartnumber,
                    'forecast_base_month' => (string) ($r->forecast_base_month ?? ''),
                    'customer_id' => $customerId,
                    'customer_name' => (string) ($r->customer_name ?? '-'),
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => (string) ($r->fg_description ?? ''),
                    'rm_partnumber' => strtoupper(trim((string) ($r->rm_partnumber ?? ''))),
                    'is_selected' => 1,
                    'k_factor' => $r->k_factor !== null ? round((float) $r->k_factor, 1) : null,
                    'manual_forecast_1m' => $r->manual_forecast_1m !== null
                        ? round((float) $r->manual_forecast_1m, 2)
                        : null,
                    'row_remark' => (string) ($r->row_remark ?? ''),
                    'supplier_code' => (string) ($r->supplier_code ?? ''),
                    'supplier_name' => (string) ($r->supplier_name ?? ''),
                ];
            });

        return $forecastRows
            ->concat($settingRows)
            ->groupBy('row_key')
            ->map(function ($rows) {
                $rows = collect($rows);
                $first = $rows->first();

                foreach (['rm_partnumber', 'fg_description', 'row_remark', 'supplier_code', 'supplier_name'] as $key) {
                    if (trim((string) ($first[$key] ?? '')) !== '') {
                        continue;
                    }

                    $fallback = $rows->first(fn($row) => trim((string) ($row[$key] ?? '')) !== '');
                    if ($fallback) {
                        $first[$key] = $fallback[$key];
                    }
                }

                return $first;
            });
    }

    private function fetchFgPartDetails(array $fgParts, string $companyMode = 'ALL')
    {
        $fgParts = collect($fgParts)
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($fgParts)) {
            return collect();
        }

        $fetch = function (string $conn) use ($fgParts) {
            return DB::connection($conn)
                ->table('parts')
                ->whereIn(DB::raw('UPPER(TRIM(partnumber))'), $fgParts)
                ->get([
                    DB::raw('UPPER(TRIM(partnumber)) as fg_partnumber'),
                    'description as fg_description',
                    DB::raw("UPPER(LTRIM(RTRIM(COALESCE(f4, '')))) as rm_partnumber"),
                ])
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->map(function ($group) {
                $first = collect($group)->first();

                return [
                    'fg_description' => (string) ($first['fg_description'] ?? ''),
                    'rm_partnumber' => (string) ($first['rm_partnumber'] ?? ''),
                ];
            });
    }

    private function fetchOlderCustomerFgCandidates(
        string $salesCode,
        array $rmLikes,
        array $customerNameLikes,
        Carbon $start,
        Carbon $end,
        string $companyMode = 'ALL',
        array $customerIds = []
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }
        [$customerDivisionSql, $customerDivisionBindings] = $this->buildCustomerDivisionOverrideSql($salesCode);

        $fetch = function (string $conn) use ($rmLikes, $customerNameLikes, $customerIds, $start, $end, $customerDivisionSql, $customerDivisionBindings) {
            $rmSql = '';
            $rmBindings = [];
            if (!empty($rmLikes)) {
                $rmSql .= ' AND (';
                foreach ($rmLikes as $i => $kw) {
                    if ($i > 0) {
                        $rmSql .= ' OR ';
                    }
                    $rmSql .= 'UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) LIKE ?';
                    $rmBindings[] = $kw . '%';
                }
                $rmSql .= ') ';
            }

            $custSql = '';
            $custBindings = [];
            if (!empty($customerNameLikes)) {
                $custSql .= ' AND (';
                foreach ($customerNameLikes as $i => $kw) {
                    if ($i > 0) {
                        $custSql .= ' OR ';
                    }
                    $custSql .= "(UPPER(TRIM(COALESCE(c.name, ''))) LIKE ? OR UPPER(TRIM(COALESCE(c.customernumber, ''))) LIKE ?)";
                    $custBindings[] = '%' . $kw . '%';
                    $custBindings[] = '%' . $kw . '%';
                }
                $custSql .= ') ';
            }

            $custIdSql = '';
            $custIdBindings = [];
            if (!empty($customerIds)) {
                $custIdSql = ' AND c.id IN (' . implode(',', array_fill(0, count($customerIds), '?')) . ') ';
                $custIdBindings = array_values(array_map('intval', $customerIds));
            }

            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0) AS customer_id,
                    COALESCE(c.name, '-') AS customer_name,
                    UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                    MAX(p_fg.description) AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(MAX(p_fg.f4), '')))) AS rm_partnumber
                FROM (
                    SELECT ar.customer_id, inv.parts_id, ar.transdate
                    FROM invoice inv
                    JOIN ar ON ar.id = inv.trans_id
                    UNION ALL
                    SELECT ar.customer_id, inv.parts_id, ret.transdate
                    FROM returnitems rei
                    JOIN return ret ON ret.id = rei.trans_id
                    JOIN invoice inv ON inv.id = rei.invoice_id
                    JOIN ar ON ar.id = inv.trans_id
                    WHERE ret.hasitems = TRUE
                ) sales
                JOIN customer c ON c.id = sales.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = sales.parts_id
                WHERE sales.transdate >= ?
                AND sales.transdate <= ?
                {$customerDivisionSql}
                {$custIdSql}
                AND p_fg.partnumber IS NOT NULL
                {$rmSql}
                {$custSql}
                GROUP BY
                    COALESCE(c.id, 0),
                    COALESCE(c.name, '-'),
                    UPPER(TRIM(p_fg.partnumber))
                ORDER BY customer_name, fg_partnumber
                SQL;

            $bindings = array_merge(
                [$start->toDateString(), $end->toDateString()],
                $customerDivisionBindings,
                $custIdBindings,
                $rmBindings,
                $custBindings
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows
            ->groupBy(fn($r) => ($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? ''))
            ->map(fn($g) => collect($g)->first())
            ->values();
    }

    private function fetchDivisionCustomerFgCatalog(
        string $salesCode,
        array $rmLikes,
        array $customerNameLikes,
        string $companyMode = 'ALL',
        array $customerIds = []
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }
        [$customerDivisionSql, $customerDivisionBindings] = $this->buildCustomerDivisionOverrideSql($salesCode);

        $fetch = function (string $conn) use ($rmLikes, $customerNameLikes, $customerIds, $customerDivisionSql, $customerDivisionBindings) {
            $rmSql = '';
            $rmBindings = [];
            if (!empty($rmLikes)) {
                $rmSql .= ' AND (';
                foreach ($rmLikes as $i => $kw) {
                    if ($i > 0) {
                        $rmSql .= ' OR ';
                    }
                    $rmSql .= 'UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) LIKE ?';
                    $rmBindings[] = $kw . '%';
                }
                $rmSql .= ') ';
            }

            $custSql = '';
            $custBindings = [];
            if (!empty($customerNameLikes)) {
                $custSql .= ' AND (';
                foreach ($customerNameLikes as $i => $kw) {
                    if ($i > 0) {
                        $custSql .= ' OR ';
                    }
                    $custSql .= "(UPPER(TRIM(COALESCE(c.name, ''))) LIKE ? OR UPPER(TRIM(COALESCE(c.customernumber, ''))) LIKE ?)";
                    $custBindings[] = '%' . $kw . '%';
                    $custBindings[] = '%' . $kw . '%';
                }
                $custSql .= ') ';
            }

            $custIdSql = '';
            $custIdBindings = [];
            if (!empty($customerIds)) {
                $custIdSql = ' AND c.id IN (' . implode(',', array_fill(0, count($customerIds), '?')) . ') ';
                $custIdBindings = array_values(array_map('intval', $customerIds));
            }

            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0) AS customer_id,
                    COALESCE(c.name, '-') AS customer_name,
                    UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                    MAX(p_fg.description) AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(MAX(p_fg.f4), '')))) AS rm_partnumber
                FROM workorder wo
                JOIN customer c ON c.id = wo.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = wo.parts_id
                WHERE 1 = 1
                {$customerDivisionSql}
                {$custIdSql}
                AND p_fg.partnumber IS NOT NULL
                AND LTRIM(RTRIM(COALESCE(p_fg.f4, ''))) <> ''
                {$rmSql}
                {$custSql}
                GROUP BY
                    COALESCE(c.id, 0),
                    COALESCE(c.name, '-'),
                    UPPER(TRIM(p_fg.partnumber))
                ORDER BY customer_name, fg_partnumber
                SQL;

            $bindings = array_merge(
                $customerDivisionBindings,
                $custIdBindings,
                $rmBindings,
                $custBindings
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows
            ->groupBy(fn($r) => ($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? ''))
            ->map(fn($g) => collect($g)->first())
            ->values();
    }

    public function customerLookup(Request $request)
    {
        $this->userOr403();

        $salesCode = $this->requestedOrResolvedSalesCode($request);
        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            return response()->json(['items' => []]);
        }

        $q = trim((string) $request->query('q', ''));
        $mode = trim((string) $request->query('mode', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $salespersonIds = $this->divisionSalespersonIds($salesCode);
        if (empty($salespersonIds)) {
            return response()->json(['items' => []]);
        }
        $ownCustomerCodes = $this->customerCodeOverridesForDivision($salesCode);
        $otherCustomerCodes = $this->customerCodeOverridesForOtherDivisions($salesCode);

        $fetch = function (string $conn) use ($q, $salespersonIds, $ownCustomerCodes, $otherCustomerCodes) {
            return DB::connection($conn)
                ->table('customer')
                ->select('id', 'name', 'customernumber')
                ->where(function ($w) use ($salespersonIds, $ownCustomerCodes) {
                    $w->whereIn('saleperson_id', $salespersonIds);
                    if (!empty($ownCustomerCodes)) {
                        $w->orWhereIn(DB::raw("UPPER(TRIM(COALESCE(customernumber, '')))"), $ownCustomerCodes);
                    }
                })
                ->when(!empty($otherCustomerCodes), function ($w) use ($otherCustomerCodes) {
                    $w->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(customernumber, '')))"), $otherCustomerCodes);
                })
                ->where(function ($w) use ($q) {
                    $w->whereRaw("UPPER(TRIM(COALESCE(name, ''))) LIKE ?", ['%' . strtoupper($q) . '%'])
                        ->orWhereRaw("UPPER(TRIM(COALESCE(customernumber, ''))) LIKE ?", ['%' . strtoupper($q) . '%'])
                        ->orWhereRaw("CAST(id AS TEXT) LIKE ?", ['%' . $q . '%']);
                })
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->map(fn($r) => [
                    'id' => (int) ($r->id ?? 0),
                    'value' => (string) ($r->name ?? ''),
                    'text' => trim((string) ($r->customernumber ?? '') . ' - ' . (string) ($r->name ?? '')),
                    'customer_name' => (string) ($r->name ?? ''),
                    'customernumber' => (string) ($r->customernumber ?? ''),
                ]);
        };

        $items = collect()
            ->merge($fetch('pgsqlw'))
            ->merge($fetch('pgsqlp'));

        $items = $mode === 'filter'
            ? $items->unique(fn($r) => strtoupper(trim((string) ($r['customer_name'] ?? ''))))
            : $items->unique('id');

        $items = $items->values()->take(20);

        return response()->json(['items' => $items]);
    }

    public function partLookup(Request $request)
    {
        $this->userOr403();

        $q = trim((string) $request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $fetch = function (string $conn) use ($q) {
            return DB::connection($conn)
                ->table('parts')
                ->where(function ($w) use ($q) {
                    $w->whereRaw("UPPER(TRIM(partnumber)) LIKE ?", [strtoupper($q) . '%'])
                        ->orWhereRaw("UPPER(TRIM(COALESCE(description, ''))) LIKE ?", ['%' . strtoupper($q) . '%']);
                })
                ->orderBy('partnumber')
                ->limit(20)
                ->get([
                    DB::raw('UPPER(TRIM(partnumber)) as fg_partnumber'),
                    'description as fg_description',
                    DB::raw("UPPER(LTRIM(RTRIM(COALESCE(f4, '')))) as rm_partnumber"),
                ])
                ->map(fn($r) => [
                    'value' => (string) ($r->fg_partnumber ?? ''),
                    'fg_partnumber' => (string) ($r->fg_partnumber ?? ''),
                    'fg_description' => (string) ($r->fg_description ?? ''),
                    'rm_partnumber' => (string) ($r->rm_partnumber ?? ''),
                    'text' => trim((string) ($r->fg_partnumber ?? '') . ' - ' . (string) ($r->fg_description ?? '')),
                ]);
        };

        $items = collect()
            ->merge($fetch('pgsqlw'))
            ->merge($fetch('pgsqlp'))
            ->unique('fg_partnumber')
            ->values()
            ->take(20);

        return response()->json(['items' => $items]);
    }

    private function fetchFgPartSettings(string $salesCode, string $forecastBaseMonth, array $keys)
    {
        if (empty($keys)) return collect();

        $pairs = collect($keys)->map(function ($k) {
            [$customerId, $fgPartnumber] = explode('|', $k, 2);
            return [
                'customer_id' => (int) $customerId,
                'fg_partnumber' => (string) $fgPartnumber,
            ];
        })->values();

        $rows = collect();

        foreach ($pairs->chunk(500) as $chunk) {
            $rows = $rows->merge(
                DB::connection($this->fcConn)
                    ->table('fc_rm_division_part_setting')
                    ->where('sales_code', $salesCode)
                    ->where('forecast_base_month', $forecastBaseMonth)
                    ->where(function ($q) use ($chunk) {
                        foreach ($chunk as $p) {
                            $q->orWhere(function ($sub) use ($p) {
                                $sub->where('customer_id', $p['customer_id'])
                                    ->where('fg_partnumber', $p['fg_partnumber']);
                            });
                        }
                    })
                    ->get()
            );
        }

        return $rows->keyBy(fn($r) => (int) $r->customer_id . '|' . (string) $r->fg_partnumber);
    }

    private function fetchSavedForecastHistory(string $salesCode, string $forecastBaseMonth, array $keys)
    {
        if (empty($keys)) return collect();

        $pairs = collect($keys)->map(function ($k) {
            [$customerId, $fgPartnumber] = explode('|', $k, 2);
            return [
                'customer_id' => (int) $customerId,
                'fg_partnumber' => (string) $fgPartnumber,
            ];
        })->values();

        $rows = collect();

        foreach ($pairs->chunk(500) as $chunk) {
            $rows = $rows->merge(
                DB::connection($this->fcConn)
                    ->table('fc_rm_division_forecast')
                    ->select([
                        'forecast_base_month',
                        'customer_id',
                        'customer_name',
                        'fg_partnumber',
                        'k_factor',
                        'forecast_1m',
                        'forecast_6m',
                        'updated_at',
                        'created_at',
                    ])
                    ->where('sales_code', $salesCode)
                    ->where('forecast_base_month', $forecastBaseMonth)
                    ->where(function ($q) use ($chunk) {
                        foreach ($chunk as $p) {
                            $q->orWhere(function ($sub) use ($p) {
                                $sub->where('customer_id', $p['customer_id'])
                                    ->where('fg_partnumber', $p['fg_partnumber']);
                            });
                        }
                    })
                    ->get()
            );
        }

        return $rows
            ->keyBy(fn($r) => (int) $r->customer_id . '|' . (string) $r->fg_partnumber)
            ->map(function ($r) {
                return [[
                    'base_month'  => Carbon::parse($r->forecast_base_month)->format('Y-m'),
                    'k_factor'    => (float) ($r->k_factor ?? 0),
                    'forecast_1m' => (float) ($r->forecast_1m ?? 0),
                    'forecast_6m' => round((float) ($r->forecast_1m ?? 0) * self::FORECAST_HORIZON_MONTHS, 2),
                    'saved_at'    => Carbon::parse($r->updated_at ?? $r->created_at)->format('Y-m-d H:i:s'),
                ]];
            });
    }

    private function fetchSalesOrderSummaryByFg(array $fgParts, string $companyMode = 'ALL')
    {
        $fgParts = collect($fgParts)
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($fgParts)) {
            return collect();
        }

        $fetch = function (string $conn) use ($fgParts) {
            $ob = DB::connection($conn)->table('orderitems as oi')
                ->join('oe', 'oi.trans_id', '=', 'oe.id')
                ->leftJoin('oedm', 'oe.id', '=', 'oedm.ord_id')
                ->leftJoin('dm', 'oedm.dm_id', '=', 'dm.id')
                ->leftJoin('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
                ->leftJoin('dmitems as dmi', function ($join) {
                    $join->on('dmpredm.dmitems_id', '=', 'dmi.id')
                        ->on('dmi.parts_id', '=', 'oi.parts_id');
                })
                ->join('customer as cus', 'oe.customer_id', '=', 'cus.id')
                ->leftJoin('workorder as wo', 'oe.ordnumber', '=', 'wo.workordernumber')
                ->whereNotNull('oe.ordnumber')
                ->where('oe.shipped_or_received', false)
                ->where('oe.cancelled', false)
                ->where('oe.invoiced', false)
                ->groupBy([
                    'oe.ordnumber',
                    'oi.parts_id',
                    'oe.customer_id',
                    'cus.saleperson_id',
                    'oe.shipped_or_received',
                    'oe.invoiced',
                    'oi.qty',
                    'oe.transdate',
                    'oi.reqdate',
                    'wo.reqdate'
                ])
                ->selectRaw("
                MAX(oe.custponumber) AS po,
                oe.ordnumber AS ordnumber,
                oi.parts_id AS parts_id,
                oi.qty AS ordered_qty,
                oe.transdate AS order_date,
                oe.customer_id AS customer_id,
                cus.saleperson_id AS saleperson_id,
                COALESCE(oi.reqdate, wo.reqdate) AS due_date,
                oe.shipped_or_received,
                oe.invoiced,
                SUM(dmi.qty) AS shipped_qty
            ");

            $sb = DB::connection($conn)->table('invoice as inv')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(inv.qty) AS shipped_qty,
                MAX(ar.transdate) AS last_invoice_date
            ");

            $rb = DB::connection($conn)->table('returnitems as rei')
                ->join('return as ret', 'rei.trans_id', '=', 'ret.id')
                ->join('invoice as inv', 'inv.id', '=', 'rei.invoice_id')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->where('ret.hasitems', true)
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(CASE WHEN ret.hasitems = TRUE THEN COALESCE(rei.qty,0) ELSE 0 END) AS return_qty
            ");

            return collect(
                DB::connection($conn)->query()
                    ->fromSub($ob, 'ob')
                    ->leftJoinSub($sb, 'sb', function ($j) {
                        $j->on('sb.ordnumber', '=', 'ob.ordnumber')
                            ->on('sb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoinSub($rb, 'rb', function ($j) {
                        $j->on('rb.ordnumber', '=', 'ob.ordnumber')
                            ->on('rb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoin('parts as p', 'p.id', '=', 'ob.parts_id')
                    ->whereIn(DB::raw('UPPER(TRIM(p.partnumber))'), $fgParts)
                    ->where('ob.shipped_or_received', false)
                    ->where('ob.invoiced', false)
                    ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0)) > 0")
                    ->selectRaw("
                    UPPER(TRIM(p.partnumber)) AS fg_partnumber,
                    (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty
                ")
                    ->get()
            )->map(fn($r) => (array) $r);
        };

        $rows = $fetch('pgsqlw');

        return $rows
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->map(fn($g) => round((float) collect($g)->sum('backorder_qty'), 2));
    }

    public function soDetail(Request $request)
    {
        $this->userOr403();

        $fgPart = strtoupper(trim((string) $request->query('fg_partnumber', '')));

        if ($fgPart === '') {
            return response()->json([]);
        }

        $fetch = function (string $conn, string $companyLabel) use ($fgPart) {
            $ob = DB::connection($conn)->table('orderitems as oi')
                ->join('oe', 'oi.trans_id', '=', 'oe.id')
                ->leftJoin('oedm', 'oe.id', '=', 'oedm.ord_id')
                ->leftJoin('dm', 'oedm.dm_id', '=', 'dm.id')
                ->leftJoin('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
                ->leftJoin('dmitems as dmi', function ($join) {
                    $join->on('dmpredm.dmitems_id', '=', 'dmi.id')
                        ->on('dmi.parts_id', '=', 'oi.parts_id');
                })
                ->join('customer as cus', 'oe.customer_id', '=', 'cus.id')
                ->leftJoin('workorder as wo', 'oe.ordnumber', '=', 'wo.workordernumber')
                ->whereNotNull('oe.ordnumber')
                ->where('oe.shipped_or_received', false)
                ->where('oe.cancelled', false)
                ->where('oe.invoiced', false)
                ->groupBy([
                    'oe.ordnumber',
                    'oi.parts_id',
                    'oe.customer_id',
                    'oe.shipped_or_received',
                    'oe.invoiced',
                    'oi.qty',
                    'oe.transdate',
                    'oi.reqdate',
                    'wo.reqdate',
                    'cus.name'
                ])
                ->selectRaw("
                MAX(oe.custponumber) AS po,
                oe.ordnumber AS ordnumber,
                oi.parts_id AS parts_id,
                oi.qty AS ordered_qty,
                oe.transdate AS order_date,
                oe.customer_id AS customer_id,
                COALESCE(oi.reqdate, wo.reqdate) AS due_date,
                oe.shipped_or_received,
                oe.invoiced,
                SUM(dmi.qty) AS shipped_qty,
                cus.name AS customer_name
            ");

            $sb = DB::connection($conn)->table('invoice as inv')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(inv.qty) AS shipped_qty
            ");

            $rb = DB::connection($conn)->table('returnitems as rei')
                ->join('return as ret', 'rei.trans_id', '=', 'ret.id')
                ->join('invoice as inv', 'inv.id', '=', 'rei.invoice_id')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->where('ret.hasitems', true)
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(CASE WHEN ret.hasitems = TRUE THEN COALESCE(rei.qty,0) ELSE 0 END) AS return_qty
            ");

            return collect(
                DB::connection($conn)->query()
                    ->fromSub($ob, 'ob')
                    ->leftJoinSub($sb, 'sb', function ($j) {
                        $j->on('sb.ordnumber', '=', 'ob.ordnumber')
                            ->on('sb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoinSub($rb, 'rb', function ($j) {
                        $j->on('rb.ordnumber', '=', 'ob.ordnumber')
                            ->on('rb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoin('parts as p', 'p.id', '=', 'ob.parts_id')
                    ->whereRaw('UPPER(TRIM(p.partnumber)) = ?', [$fgPart])
                    ->where('ob.shipped_or_received', false)
                    ->where('ob.invoiced', false)
                    ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0)) > 0")
                    ->orderBy('ob.order_date')
                    ->selectRaw("
                    UPPER(TRIM(p.partnumber)) AS fg_partnumber,
                    p.description,
                    ob.po,
                    ob.ordnumber,
                    ob.order_date,
                    ob.due_date,
                    ob.customer_name,
                    (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty,
                    ? AS company
                ", [$companyLabel])
                    ->get()
            )->map(function ($r) {
                $r = (array) $r;
                $r['backorder_qty'] = max((float) ($r['backorder_qty'] ?? 0), 0);
                return $r;
            })->filter(fn($r) => (float) $r['backorder_qty'] > 0);
        };

        $out = $fetch('pgsqlw', 'MENAM WIRE')
            ->sortBy('order_date')
            ->values();

        return response()->json($out);
    }

    public function index(Request $request)
    {
        if ($this->isPlanningDivisionViewer()) {
            return $this->renderPlanningDivisionOverview($request);
        }

        return $this->renderForecastPage($request, false);
    }

    private function renderPlanningDivisionOverview(Request $request)
    {
        $this->userOr403();

        $baseMonth = now('Asia/Bangkok')->startOfMonth();
        $forecastBaseMonth = $baseMonth->toDateString();
        $selectedDivisions = $this->requestedPlanningDivisions($request);
        $search = trim((string) $request->query('q', ''));
        $futureMonths = collect(range(1, self::FORECAST_HORIZON_MONTHS))
            ->map(fn($offset) => (clone $baseMonth)->addMonths($offset));
        $futureYm = $futureMonths->map(fn($month) => $month->format('Y-m'))->all();
        $futureLabels = $futureMonths->map(fn($month) => $month->format('M-y'))->all();

        $forecastRows = DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast')
            ->whereDate('forecast_base_month', $forecastBaseMonth)
            ->whereIn('sales_code', $selectedDivisions)
            ->where('is_selected', 1)
            ->orderBy('sales_code')
            ->orderBy('customer_name')
            ->orderBy('fg_partnumber')
            ->orderBy('forecast_month')
            ->get();

        $approvalRows = collect();
        if ($this->approvalTableAvailable()) {
            $approvalRows = DB::connection($this->fcConn)
                ->table($this->divisionApprovalTable)
                ->whereDate('forecast_base_month', $forecastBaseMonth)
                ->whereIn('sales_code', $selectedDivisions)
                ->get()
                ->keyBy(fn($row) => strtoupper(trim((string) $row->sales_code))
                    . '|' . (int) $row->customer_id
                    . '|' . strtoupper(trim((string) $row->fg_partnumber)));
        }

        $rows = $forecastRows
            ->groupBy(fn($row) => strtoupper(trim((string) $row->sales_code))
                . '|' . (int) $row->customer_id
                . '|' . strtoupper(trim((string) $row->fg_partnumber)))
            ->map(function ($group, $key) use ($futureYm, $approvalRows) {
                $first = $group->first();
                $forecastByMonth = array_fill_keys($futureYm, 0.0);

                foreach ($group as $item) {
                    try {
                        $ym = Carbon::parse($item->forecast_month)->format('Y-m');
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if (array_key_exists($ym, $forecastByMonth)) {
                        $forecastByMonth[$ym] += (float) ($item->forecast_qty ?? 0);
                    }
                }

                $approval = $approvalRows->get($key);

                return [
                    'sales_code' => strtoupper(trim((string) $first->sales_code)),
                    'customer_id' => (int) $first->customer_id,
                    'customer_name' => (string) ($first->customer_name ?? '-'),
                    'fg_partnumber' => strtoupper(trim((string) $first->fg_partnumber)),
                    'fg_description' => (string) ($first->fg_description ?? ''),
                    'rm_partnumber' => strtoupper(trim((string) ($first->rm_partnumber ?? ''))),
                    'history_avg6' => (float) ($first->history_avg6 ?? 0),
                    'k_factor' => (float) ($first->k_factor ?? 0),
                    'supplier_code' => (string) ($first->supplier_code ?? ''),
                    'supplier_name' => (string) ($first->supplier_name ?? ''),
                    'row_remark' => (string) ($first->row_remark ?? ''),
                    'forecast_by_month' => $forecastByMonth,
                    'approval_forecast_1m' => $approval ? (float) $approval->approval_forecast_1m : null,
                    'approval_forecast_6m' => $approval
                        ? round((float) ($approval->approval_forecast_1m ?? 0) * self::FORECAST_HORIZON_MONTHS, 2)
                        : null,
                    'approval_remark' => $approval ? (string) ($approval->approval_remark ?? '') : '',
                ];
            })
            ->values();

        if ($search !== '') {
            $needle = mb_strtoupper($search);
            $rows = $rows->filter(function (array $row) use ($needle) {
                $haystack = mb_strtoupper(implode(' ', [
                    $row['sales_code'],
                    $row['customer_id'],
                    $row['customer_name'],
                    $row['fg_partnumber'],
                    $row['fg_description'],
                    $row['rm_partnumber'],
                    $row['supplier_code'],
                    $row['supplier_name'],
                    $row['row_remark'],
                ]));

                return str_contains($haystack, $needle);
            })->values();
        }

        $submissionStatuses = collect($selectedDivisions)->mapWithKeys(fn($division) => [$division => null]);
        if ($this->submissionTableAvailable()) {
            $submissionStatuses = $submissionStatuses->merge(
                DB::connection($this->fcConn)
                    ->table($this->divisionSubmissionTable)
                    ->whereDate('forecast_base_month', $forecastBaseMonth)
                    ->whereIn('sales_code', $selectedDivisions)
                    ->pluck('status', 'sales_code')
                    ->mapWithKeys(fn($status, $division) => [strtoupper((string) $division) => strtoupper((string) $status)])
            );
        }

        $firstForecastMonth = $futureYm[0] ?? null;
        $kpi = [
            'items' => $rows->count(),
            'forecast_1m_sum' => (float) $rows->sum(fn($row) => $firstForecastMonth
                ? ($row['forecast_by_month'][$firstForecastMonth] ?? 0)
                : 0),
            'approved_1m_sum' => (float) $rows->sum(fn($row) => $row['approval_forecast_1m']
                ?? ($firstForecastMonth ? ($row['forecast_by_month'][$firstForecastMonth] ?? 0) : 0)),
        ];

        $perPage = 100;
        $page = max(1, (int) $request->query('page', 1));
        $paginatedRows = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('formfc.division.forecast_planning', [
            'rows' => $paginatedRows,
            'allDivisions' => $this->planningDivisionCodes(),
            'selectedDivisions' => $selectedDivisions,
            'divisionLabels' => $this->divisionLabels,
            'submissionStatuses' => $submissionStatuses,
            'forecastBaseMonth' => $forecastBaseMonth,
            'futureYm' => $futureYm,
            'futureLabels' => $futureLabels,
            'forecastHorizonMonths' => self::FORECAST_HORIZON_MONTHS,
            'search' => $search,
            'kpi' => $kpi,
        ]);
    }

    private function documentListMonth(Request $request): string
    {
        try {
            return Carbon::parse(
                (string) $request->query('month', now('Asia/Bangkok')->startOfMonth()->toDateString())
            )->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now('Asia/Bangkok')->startOfMonth()->toDateString();
        }
    }

    private function normalizeDocumentStatus(?string $status): string
    {
        $status = strtoupper(trim((string) $status));

        return match ($status) {
            WorkflowEngine::ST_CLOSED, 'CLOSED' => 'CLOSED',
            WorkflowEngine::ST_REQUESTED => 'PENDING_APPROVAL',
            default => $status,
        };
    }

    private function documentStatusClass(string $status): string
    {
        return match ($this->normalizeDocumentStatus($status)) {
            'PENDING_APPROVAL', 'SUBMITTED' => 'status-pending',
            'APPROVED', 'CLOSED' => 'status-approved',
            'REJECTED' => 'status-rejected',
            default => 'status-other',
        };
    }

    private function divisionDocumentAllowedSalesCodes(): array
    {
        if ($this->canApproveAllDivisions()) {
            return $this->allSalesCodes();
        }

        return $this->availableSalesCodesForUser();
    }

    private function divisionDocumentsQuery(Request $request)
    {
        $month = $this->documentListMonth($request);
        $allowed = $this->divisionDocumentAllowedSalesCodes();
        $selectedDivision = $this->normalizeSalesCode((string) $request->query('division', ''));
        $status = strtoupper(trim((string) $request->query('status', 'ALL')));
        $qText = trim((string) $request->query('q', ''));

        $query = DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable . ' as sub')
            ->leftJoin('wf_forms as wf', 'wf.id', '=', 'sub.wf_form_id')
            ->leftJoin('workflows as wfm', 'wfm.code', '=', 'wf.app_code')
            ->leftJoin('workflow_steps as wfs', function ($join) {
                $join->on('wfs.workflow_id', '=', 'wfm.id')
                    ->on('wfs.step_no', '=', 'wf.current_step_no')
                    ->where('wfs.is_active', 1);
            })
            ->leftJoin('users as requester', 'requester.id', '=', 'sub.submitted_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'sub.approved_by')
            ->whereDate('sub.forecast_base_month', $month)
            ->whereIn('sub.sales_code', $allowed)
            ->select([
                'sub.*',
                'wf.form_no as wf_form_no',
                'wf.form_status as wf_status',
                'wf.current_step_no as wf_current_step',
                'wfs.name as wf_current_step_name',
                'requester.name as requester_name',
                'approver.name as approver_name',
            ]);

        if ($selectedDivision && in_array($selectedDivision, $allowed, true)) {
            $query->where('sub.sales_code', $selectedDivision);
        }

        if ($status !== '' && $status !== 'ALL') {
            if ($status === 'CLOSED') {
                $query->where(function ($where) {
                    $where->where('sub.status', 'APPROVED')
                        ->orWhereIn('wf.form_status', [WorkflowEngine::ST_CLOSED, 'CLOSED']);
                });
            } else {
                $query->where('sub.status', $status);
            }
        }

        if ($qText !== '') {
            $query->where(function ($where) use ($qText) {
                $where->where('sub.sales_code', 'like', "%{$qText}%")
                    ->orWhere('sub.form_no', 'like', "%{$qText}%")
                    ->orWhere('requester.name', 'like', "%{$qText}%");
            });
        }

        return $query;
    }

    public function documents(Request $request)
    {
        $this->userOr403();

        $month = $this->documentListMonth($request);
        $allowed = $this->divisionDocumentAllowedSalesCodes();

        if (empty($allowed)) {
            abort(403, 'ไม่มีสิทธิ์ดูรายการเอกสาร Sales Forecast');
        }

        if (!$this->submissionTableAvailable()) {
            return view('formfc.division.documents', [
                'rows' => collect(),
                'q' => trim((string) $request->query('q', '')),
                'status' => strtoupper(trim((string) $request->query('status', 'ALL'))),
                'month' => $month,
                'division' => $this->normalizeSalesCode((string) $request->query('division', '')),
                'allowedDivisions' => $allowed,
                'divisionLabels' => $this->divisionLabels,
                'tableMissing' => true,
                'canApproveDivisionForecast' => $this->canApproveDivisionForecast(),
            ]);
        }

        $rows = $this->divisionDocumentsQuery($request)
            ->orderByDesc('sub.forecast_base_month')
            ->orderByDesc('sub.submitted_at')
            ->paginate(20)
            ->withQueryString();

        $rows->getCollection()->transform(function ($row) {
            $status = $this->normalizeDocumentStatus((string) ($row->wf_status ?? $row->status ?? ''));
            $row->display_status = $status ?: $this->normalizeDocumentStatus((string) ($row->status ?? ''));
            $row->status_class = $this->documentStatusClass($row->display_status);
            $row->can_open_current_month = Carbon::parse($row->forecast_base_month)->isSameMonth(now('Asia/Bangkok'));
            $row->can_approve = (int) ($row->wf_form_id ?? 0) > 0
                && WorkflowDb::canApprove('fc', (int) $row->wf_form_id, (int) auth()->id());

            return $row;
        });

        return view('formfc.division.documents', [
            'rows' => $rows,
            'q' => trim((string) $request->query('q', '')),
            'status' => strtoupper(trim((string) $request->query('status', 'ALL'))),
            'month' => $month,
            'division' => $this->normalizeSalesCode((string) $request->query('division', '')),
            'allowedDivisions' => $allowed,
            'divisionLabels' => $this->divisionLabels,
            'tableMissing' => false,
            'canApproveDivisionForecast' => $this->canApproveDivisionForecast(),
        ]);
    }

    public function exportDocuments(Request $request)
    {
        $this->userOr403();

        $allowed = $this->divisionDocumentAllowedSalesCodes();
        if (empty($allowed)) {
            abort(403, 'ไม่มีสิทธิ์ export รายการเอกสาร Sales Forecast');
        }

        if (!$this->submissionTableAvailable()) {
            return back()->with('error', 'ยังไม่พบ table fc_rm_division_forecast_submissions');
        }

        $rows = $this->divisionDocumentsQuery($request)
            ->orderByDesc('sub.forecast_base_month')
            ->orderByDesc('sub.submitted_at')
            ->limit(5000)
            ->get();

        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmtDate = static fn($value) => $value ? Carbon::parse($value)->format('d/m/Y H:i') : '';
        $fmtMonth = static fn($value) => $value ? Carbon::parse($value)->format('m/Y') : '';

        $html = '<html><head><meta charset="UTF-8"></head><body>';
        $html .= '<table border="1">';
        $html .= '<thead><tr>';
        foreach ([
            'Form No',
            'Division',
            'Month',
            'Status',
            'Workflow Step',
            'Requester',
            'Submitted At',
            'Approved By',
            'Approved At',
            'Rejected At',
            'Reject Reason',
        ] as $heading) {
            $html .= '<th>' . $esc($heading) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $status = $this->normalizeDocumentStatus((string) ($row->wf_status ?? $row->status ?? ''));
            $status = $status ?: $this->normalizeDocumentStatus((string) ($row->status ?? ''));

            $html .= '<tr>';
            $html .= '<td>' . $esc($row->form_no ?? $row->wf_form_no ?? '') . '</td>';
            $html .= '<td>' . $esc($this->divisionLabels[$row->sales_code] ?? $row->sales_code ?? '') . '</td>';
            $html .= '<td>' . $esc($fmtMonth($row->forecast_base_month ?? null)) . '</td>';
            $html .= '<td>' . $esc($status) . '</td>';
            $html .= '<td>' . $esc($row->wf_current_step_name ?? ('Step ' . ($row->wf_current_step ?? ''))) . '</td>';
            $html .= '<td>' . $esc($row->requester_name ?? '') . '</td>';
            $html .= '<td>' . $esc($fmtDate($row->submitted_at ?? null)) . '</td>';
            $html .= '<td>' . $esc($row->approver_name ?? '') . '</td>';
            $html .= '<td>' . $esc($fmtDate($row->approved_at ?? null)) . '</td>';
            $html .= '<td>' . $esc($fmtDate($row->rejected_at ?? null)) . '</td>';
            $html .= '<td>' . $esc($row->reject_reason ?? '') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        $filename = 'fc_division_documents_' . now('Asia/Bangkok')->format('Ymd_His') . '.xls';

        return response("\xEF\xBB\xBF" . $html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function approvalList(Request $request)
    {
        $this->userOr403();

        if (!$this->canApproveDivisionForecast()) {
            abort(403, 'ไม่มีสิทธิ์ดูรายการ approve Division Forecast');
        }

        if (!$this->submissionTableAvailable()) {
            return view('formfc.division.approval_list', [
                'rows' => collect(),
                'q' => trim((string) $request->query('q', '')),
                'status' => trim((string) $request->query('status', '')),
                'month' => now('Asia/Bangkok')->startOfMonth()->toDateString(),
                'divisionLabels' => $this->divisionLabels,
                'tableMissing' => true,
            ]);
        }

        $month = Carbon::parse(
            (string) $request->query('month', now('Asia/Bangkok')->startOfMonth()->toDateString())
        )->startOfMonth()->toDateString();

        $allowed = $this->availableApprovalSalesCodesForUser($month);
        if (empty($allowed)) {
            abort(403, 'ไม่มีรายการ Division Forecast ที่สามารถ approve ได้');
        }

        $qText = trim((string) $request->query('q', ''));
        $status = strtoupper(trim((string) $request->query('status', 'PENDING_APPROVAL')));

        $query = DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable . ' as sub')
            ->leftJoin('wf_forms as wf', 'wf.id', '=', 'sub.wf_form_id')
            ->leftJoin('workflows as wfm', 'wfm.code', '=', 'wf.app_code')
            ->leftJoin('workflow_steps as wfs', function ($join) {
                $join->on('wfs.workflow_id', '=', 'wfm.id')
                    ->on('wfs.step_no', '=', 'wf.current_step_no')
                    ->where('wfs.is_active', 1);
            })
            ->leftJoin('users as requester', 'requester.id', '=', 'sub.submitted_by')
            ->whereDate('sub.forecast_base_month', $month)
            ->whereIn('sub.sales_code', $allowed)
            ->select([
                'sub.*',
                'wf.form_no as wf_form_no',
                'wf.form_status as wf_status',
                'wf.current_step_no as wf_current_step',
                'wfs.name as wf_current_step_name',
                'requester.name as requester_name',
            ]);

        if ($status !== '' && $status !== 'ALL') {
            $query->where('sub.status', $status);
        }

        if ($qText !== '') {
            $query->where(function ($where) use ($qText) {
                $where->where('sub.sales_code', 'like', "%{$qText}%")
                    ->orWhere('sub.form_no', 'like', "%{$qText}%")
                    ->orWhere('requester.name', 'like', "%{$qText}%");
            });
        }

        $rows = $query
            ->orderByRaw("CASE WHEN sub.status = 'PENDING_APPROVAL' THEN 0 WHEN sub.status = 'SUBMITTED' THEN 1 ELSE 2 END")
            ->orderByDesc('sub.submitted_at')
            ->paginate(15)
            ->withQueryString();

        $rows->getCollection()->transform(function ($row) {
            $wfId = (int) ($row->wf_form_id ?? 0);
            $row->can_approve = $wfId > 0 && WorkflowDb::canApprove('fc', $wfId, (int) auth()->id());
            return $row;
        });

        return view('formfc.division.approval_list', [
            'rows' => $rows,
            'q' => $qText,
            'status' => $status,
            'month' => $month,
            'divisionLabels' => $this->divisionLabels,
            'tableMissing' => false,
        ]);
    }

    public function approval(Request $request)
    {
        if (!$this->canApproveDivisionForecast()) {
            abort(403, 'ไม่มีสิทธิ์ approve Division Forecast');
        }

        return $this->renderForecastPage($request, true);
    }

    private function renderForecastPage(Request $request, bool $isApprovalMode = false)
    {
        $this->userOr403();

        $baseMonth = now('Asia/Bangkok')->startOfMonth();
        $forecastBaseMonth = $baseMonth->toDateString();

        $salesCode = $isApprovalMode
            ? $this->requestedOrResolvedApprovalSalesCode($request, $forecastBaseMonth)
            : $this->requestedOrResolvedSalesCode($request);

        $canViewSalesCode = $isApprovalMode
            ? ($salesCode && in_array($salesCode, $this->availableApprovalSalesCodesForUser($forecastBaseMonth), true))
            : ($salesCode && $this->canUseSalesCode($salesCode));

        if (!$salesCode || !$canViewSalesCode) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึง division นี้');
        }

        $rmLike = strtoupper(trim((string) $request->query('sku', '')));
        $selectedFgParts = $this->normalizeFilterValues($request->query('fg', []), true);
        $selectedCustomerNames = $this->normalizeFilterValues($request->query('customer_name', []), false);
        $fgLike = implode(',', $selectedFgParts);
        $customerNameText = implode(',', $selectedCustomerNames);
        $customerId = trim((string) $request->query('customer_id', ''));
        // ไม่ใช้ customer_id ไป filter ERP ตรง ๆ เพราะ WIRE/PLUS อาจมี customer id คนละตัว
        // ให้ filter ด้วยชื่อแบบ phrase แทน จะรวม WIRE+PLUS ของลูกค้าเดียวกันได้ และไม่แตกคำมั่ว
        $customerIds = [];
        $selectedKRaw = trim((string) $request->query('k_factor', ''));
        $selectedK = is_numeric($selectedKRaw) ? round((float) $selectedKRaw, 1) : 0.0;
        $companyMode = 'ALL';

        $rmKeywords = $this->parseMultiKeywords($rmLike);
        $fgKeywords = $this->parseMultiKeywords($selectedFgParts);
        $customerNameKeywords = $this->parseCustomerFilterTerms($selectedCustomerNames);

        $savedOwnerByCustomerFg = $this->fetchLatestSavedCustomerFgOwners($forecastBaseMonth);

        $defaultK = (float) optional(
            DB::connection($this->fcConn)
                ->table('fc_rm_division_setting')
                ->where('sales_code', $salesCode)
                ->first()
        )->default_k_factor;

        if ($defaultK <= 0) {
            $defaultK = 1.0;
        }
        // selectedK คือค่าที่ user พิมพ์ในช่อง K Factor Default เท่านั้น
        // ค่า 0 = ไม่ override row ใด ๆ ตอนกด Filter/โหลดข้อมูล
        if ($selectedK < 0) {
            $selectedK = 0.0;
        }
        $rowDefaultK = $selectedK > 0 ? $selectedK : $defaultK;

        $historyMonths = collect(range(0, FormFcPeriod::MONTHS - 1))
            ->map(fn($i) => (clone $baseMonth)->subMonths($i))
            ->reverse()
            ->values();

        $historyYm = $historyMonths->map(fn($d) => $d->format('Y-m'))->all();
        $historyLabels = $historyMonths->map(fn($d) => $d->format('M-y'))->all();
        $futureMonths = collect(range(1, self::FORECAST_HORIZON_MONTHS))
            ->map(fn($i) => (clone $baseMonth)->addMonths($i));
        $futureYm = $futureMonths->map(fn($d) => $d->format('Y-m'))->all();
        $futureLabels = $futureMonths->map(fn($d) => $d->format('M-y'))->all();

        $raw = $this->fetchFgHistoryGrouped(
            $salesCode,
            $rmKeywords,
            $customerNameKeywords,
            (clone $baseMonth)->subMonths(FormFcPeriod::MONTHS - 1)->startOfMonth(),
            (clone $baseMonth)->endOfMonth(),
            $companyMode,
            $customerIds
        );

        $grouped = $raw
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['customer_name'] ?? ''))) . '|' . strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->map(function ($rows, $key) use ($historyYm) {
                $first = collect($rows)->first();
                // เลือก customer_id ตัวแทน: เอาที่ไม่ใช่ 0 ตัวแรก (เพื่อใช้ key DB เดิม)
                $canonicalCustomerId = (int) (collect($rows)->pluck('customer_id')->filter()->first() ?? 0);

                $monthMap = [];
                $monthMapByCo = ['WIRE' => [], 'PLUS' => []];

                foreach ($rows as $r) {
                    $ym = Carbon::parse($r['month'])->format('Y-m');
                    $qty = (float) ($r['qty_sum'] ?? 0);
                    $monthMap[$ym] = ($monthMap[$ym] ?? 0) + $qty;
                    $co = strtoupper((string) ($r['company'] ?? 'WIRE'));
                    if (!isset($monthMapByCo[$co])) $monthMapByCo[$co] = [];
                    $monthMapByCo[$co][$ym] = ($monthMapByCo[$co][$ym] ?? 0) + $qty;
                }

                $filled = collect($historyYm)->map(fn($ym) => (float) ($monthMap[$ym] ?? 0));
                $avg6 = round((float) $filled->avg(), 2);

                return [
                    'row_key' => $canonicalCustomerId . '|' . strtoupper(trim((string) ($first['fg_partnumber'] ?? ''))),
                    'customer_id' => $canonicalCustomerId,
                    'customer_name' => (string) ($first['customer_name'] ?? '-'),
                    'fg_partnumber' => (string) ($first['fg_partnumber'] ?? ''),
                    'fg_description' => (string) ($first['fg_description'] ?? ''),
                    'rm_partnumber' => (string) ($first['rm_partnumber'] ?? ''),
                    'avg6' => $avg6,
                    'history_detail' => collect($historyYm)->map(fn($ym) => [
                        'ym' => $ym,
                        'qty' => (float) ($monthMap[$ym] ?? 0),
                        'qty_wire' => (float) ($monthMapByCo['WIRE'][$ym] ?? 0),
                        'qty_plus' => (float) ($monthMapByCo['PLUS'][$ym] ?? 0),
                    ])->values()->all(),
                ];
            })
            ->values();

        // FG filter เป็นตัวเสริม ไม่กระทบ flow หลัก RM -> FG
        $grouped = $this->applySavedOwnerOverride($grouped, $savedOwnerByCustomerFg, $salesCode);

        if (!empty($fgKeywords)) {
            $grouped = $grouped->filter(function ($r) use ($fgKeywords) {
                $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));

                foreach ($fgKeywords as $kw) {
                    if (str_contains($fg, $kw)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $existingNameFgKeys = $grouped
            ->map(fn($r) => strtoupper(trim((string) ($r['customer_name'] ?? ''))) . '|' . strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->all();

        // map: UPPER(name) -> canonical customer_id (จาก grouped หลัก)
        $nameToCanonicalId = $grouped
            ->mapWithKeys(fn($r) => [strtoupper(trim((string) ($r['customer_name'] ?? ''))) => (int) ($r['customer_id'] ?? 0)])
            ->all();

        $olderCandidateRows = $this->fetchOlderCustomerFgCandidates(
            $salesCode,
            $rmKeywords,
            $customerNameKeywords,
            (clone $baseMonth)->subMonths(24)->startOfMonth(),
            (clone $baseMonth)->subMonths(7)->endOfMonth(),
            $companyMode,
            $customerIds
        );
        $catalogCandidateRows = $this->fetchDivisionCustomerFgCatalog(
            $salesCode,
            $rmKeywords,
            $customerNameKeywords,
            $companyMode,
            $customerIds
        );

        $seedCandidateRows = $olderCandidateRows->concat($catalogCandidateRows)
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['customer_name'] ?? ''))) . '|' . strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->map(fn($g) => collect($g)->first())
            ->values();

        // กรอง FG keywords สำหรับ seed candidates ด้วย (bug เดิม: filter ไม่ทำงานกับ rows ที่เสริมเข้ามา)
        if (!empty($fgKeywords)) {
            $seedCandidateRows = $seedCandidateRows->filter(function ($r) use ($fgKeywords) {
                $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
                foreach ($fgKeywords as $kw) {
                    if (str_contains($fg, $kw)) return true;
                }
                return false;
            })->values();
        }

        $seedCandidateRows = $this->applySavedOwnerOverride($seedCandidateRows, $savedOwnerByCustomerFg, $salesCode);

        if ($seedCandidateRows->isNotEmpty()) {
            $grouped = $grouped->concat(
                $seedCandidateRows
                    ->reject(function ($r) use ($existingNameFgKeys) {
                        $key = strtoupper(trim((string) ($r['customer_name'] ?? ''))) . '|' . strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
                        return in_array($key, $existingNameFgKeys, true);
                    })
                    ->map(function ($r) use ($historyYm, $nameToCanonicalId) {
                        $nameUp = strtoupper(trim((string) ($r['customer_name'] ?? '')));
                        $canonicalId = $nameToCanonicalId[$nameUp] ?? (int) ($r['customer_id'] ?? 0);
                        return [
                            'row_key' => (string) ($canonicalId . '|' . strtoupper(trim((string) ($r['fg_partnumber'] ?? '')))),
                            'customer_id' => $canonicalId,
                            'customer_name' => (string) ($r['customer_name'] ?? '-'),
                            'fg_partnumber' => (string) ($r['fg_partnumber'] ?? ''),
                            'fg_description' => (string) ($r['fg_description'] ?? ''),
                            'rm_partnumber' => (string) ($r['rm_partnumber'] ?? ''),
                            'avg6' => 0.00,
                            'history_detail' => collect($historyYm)->map(fn($ym) => [
                                'ym' => $ym,
                                'qty' => 0.00,
                                'qty_wire' => 0.00,
                                'qty_plus' => 0.00,
                            ])->values()->all(),
                        ];
                    })
            )->values();
        }

        $manualSeedRows = $this->fetchLatestManualSeedRows($salesCode, $forecastBaseMonth);
        $groupedKeys = $grouped->pluck('row_key')->map(fn($key) => (string) $key)->all();

        $missingManualSeeds = $manualSeedRows
            ->reject(fn($seed, $rowKey) => in_array((string) $rowKey, $groupedKeys, true));

        if (!empty($fgKeywords)) {
            $missingManualSeeds = $missingManualSeeds->filter(function ($seed) use ($fgKeywords) {
                $fg = strtoupper(trim((string) ($seed['fg_partnumber'] ?? '')));

                foreach ($fgKeywords as $kw) {
                    if (str_contains($fg, $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if (!empty($customerIds)) {
            $missingManualSeeds = $missingManualSeeds->filter(function ($seed) use ($customerIds) {
                return in_array((int) ($seed['customer_id'] ?? 0), $customerIds, true);
            });
        } elseif (!empty($customerNameKeywords)) {
            $missingManualSeeds = $missingManualSeeds->filter(function ($seed) use ($customerNameKeywords) {
                $customerName = strtoupper(trim((string) ($seed['customer_name'] ?? '')));

                foreach ($customerNameKeywords as $kw) {
                    if (str_contains($customerName, $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $manualFgDetails = $this->fetchFgPartDetails(
            $missingManualSeeds->pluck('fg_partnumber')->filter()->unique()->values()->all(),
            $companyMode
        );
        $manualSeedHistory = $this->fetchHistoryForSavedCustomerFgRows(
            $missingManualSeeds,
            (clone $baseMonth)->subMonths(FormFcPeriod::MONTHS - 1)->startOfMonth(),
            (clone $baseMonth)->endOfMonth(),
            $historyYm,
            $companyMode
        );

        if ($missingManualSeeds->isNotEmpty()) {
            $grouped = $grouped->concat(
                $missingManualSeeds->map(function ($seed) use ($historyYm, $manualFgDetails, $manualSeedHistory) {
                    $fgPartnumber = strtoupper(trim((string) ($seed['fg_partnumber'] ?? '')));
                    $fgDetail = $manualFgDetails->get($fgPartnumber, []);
                    $rmPartnumber = strtoupper(trim((string) ($seed['rm_partnumber'] ?? '')));
                    if ($rmPartnumber === '') {
                        $rmPartnumber = strtoupper(trim((string) ($fgDetail['rm_partnumber'] ?? '')));
                    }
                    $history = $manualSeedHistory->get($this->rowCustomerFgOwnerKey((array) $seed));
                    $historyDetail = $history
                        ? (array) ($history['history_detail'] ?? [])
                        : collect($historyYm)->map(fn($ym) => [
                            'ym' => $ym,
                            'qty' => 0.00,
                            'qty_wire' => 0.00,
                            'qty_plus' => 0.00,
                        ])->values()->all();

                    return [
                        'row_key' => (string) ($seed['row_key'] ?? ''),
                        'customer_id' => (int) ($seed['customer_id'] ?? 0),
                        'customer_name' => (string) ($seed['customer_name'] ?? '-'),
                        'fg_partnumber' => $fgPartnumber,
                        'fg_description' => (string) (($seed['fg_description'] ?? '') !== ''
                            ? $seed['fg_description']
                            : ($fgDetail['fg_description'] ?? '')),
                        'rm_partnumber' => $rmPartnumber,
                        'avg6' => $history ? (float) ($history['avg6'] ?? 0) : 0.00,
                        'history_detail' => $historyDetail,
                        'seed_is_selected' => (int) ($seed['is_selected'] ?? 0),
                        'seed_k_factor' => $seed['k_factor'] ?? null,
                        'seed_manual_forecast_1m' => $seed['manual_forecast_1m'] ?? null,
                        'seed_supplier_code' => (string) ($seed['supplier_code'] ?? ''),
                        'seed_supplier_name' => (string) ($seed['supplier_name'] ?? ''),
                        'seed_row_remark' => (string) ($seed['row_remark'] ?? ''),
                    ];
                })
            )->values();
        }

        $grouped = $grouped
            ->sortByDesc(function ($row) {
                $row = (array) $row;
                return ((float) ($row['avg6'] ?? 0) > 0 ? 100 : 0)
                    + ((int) ($row['seed_is_selected'] ?? $row['is_selected'] ?? 0) === 1 ? 10 : 0)
                    + ((float) ($row['seed_manual_forecast_1m'] ?? $row['manual_forecast_1m'] ?? 0) > 0 ? 1 : 0);
            })
            ->unique(fn($row) => $this->rowCustomerFgDedupKey((array) $row))
            ->values();

        $keys = $grouped->pluck('row_key')->all();

        $settings = $this->fetchFgPartSettings($salesCode, $forecastBaseMonth, $keys);
        $savedHistory = $this->fetchSavedForecastHistory($salesCode, $forecastBaseMonth, $keys);
        $approvalRows = $this->fetchApprovalRows($salesCode, $forecastBaseMonth, $keys);
        $divisionMasterConfig = $this->fetchDivisionPartMasterConfig($salesCode);

        $rmParts = $grouped->pluck('rm_partnumber')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $rmSupplierMap = $this->fetchSupplierMapByRmParts($rmParts);

        $hasKInput = $request->filled('k_factor');

        $rows = $grouped->map(function ($r) use (
            $settings,
            $selectedK,
            $rowDefaultK,
            $savedHistory,
            $divisionMasterConfig,
            $rmSupplierMap,
            $hasKInput,
            $approvalRows
        ) {
            $setting = $settings->get($r['row_key']);

            $rm = strtoupper(trim((string) ($r['rm_partnumber'] ?? '')));
            $cfg = $divisionMasterConfig->get($rm);

            $defaultSelected = array_key_exists('seed_is_selected', $r)
                ? (int) ($r['seed_is_selected'] ?? 0) === 1
                : (int) ($cfg->is_forecast ?? 0) === 1;

            $isSelected = $setting
                ? ((int) ($setting->is_selected ?? 0) === 1)
                : $defaultSelected;

            $savedRowK = $setting && $setting->k_factor !== null
                ? (float) $setting->k_factor
                : (isset($r['seed_k_factor']) && $r['seed_k_factor'] !== null ? (float) $r['seed_k_factor'] : null);

            // สำคัญ: ถ้า row นี้เคย save K รายแถวแล้ว ให้ใช้ค่าที่ save ไว้ก่อนเสมอ
            // ไม่ให้ K Factor Default จาก query string เช่น 2.0 ไป override ค่า 2100.0 / 3000.0 ที่บันทึกไว้
            // K Default ใช้เฉพาะ row ใหม่ที่ยังไม่เคยมี setting เท่านั้น
            $kUsed = $savedRowK !== null ? $savedRowK : $rowDefaultK;

            $defaultSupplier = $rmSupplierMap->get($rm, [
                'supplier_code' => '',
                'supplier_short_name' => '',
            ]);

            $manualSaved = $setting && $setting->manual_forecast_1m !== null
                ? (float) $setting->manual_forecast_1m
                : (isset($r['seed_manual_forecast_1m']) && $r['seed_manual_forecast_1m'] !== null
                    ? (float) $r['seed_manual_forecast_1m']
                    : null);

            $r['is_selected'] = $isSelected ? 1 : 0;
            $r['row_k_factor'] = $kUsed;
            $r['k_used'] = $kUsed;
            $r['save_history'] = $savedHistory->get($r['row_key'], []);
            $r['manual_forecast_saved'] = $manualSaved !== null ? 1 : 0;
            $r['row_remark'] = $setting
                ? (string) ($setting->row_remark ?? '')
                : (string) ($r['seed_row_remark'] ?? '');

            $r['supplier_code'] = $setting && !empty($setting->supplier_code)
                ? (string) $setting->supplier_code
                : (string) (($r['seed_supplier_code'] ?? '') !== '' ? $r['seed_supplier_code'] : ($defaultSupplier['supplier_code'] ?? ''));

            $r['supplier_name'] = $setting && !empty($setting->supplier_name)
                ? (string) $setting->supplier_name
                : (string) (($r['seed_supplier_name'] ?? '') !== '' ? $r['seed_supplier_name'] : ($defaultSupplier['supplier_short_name'] ?? ''));

            $calcForecast1m = $r['is_selected']
                ? round((float) $r['avg6'] * $kUsed, 2)
                : 0.00;

            // ช่อง input ให้มีค่าไว้แก้ได้
            // ถ้าเคย save manual จริง ค่อยใช้ manual นั้น
            $r['manual_forecast_1m'] = $manualSaved !== null ? $manualSaved : $calcForecast1m;

            $r['forecast_1m'] = $r['is_selected']
                ? round((float) $r['manual_forecast_1m'], 2)
                : 0.00;

            $r['forecast_6m'] = $r['is_selected']
                ? round((float) $r['forecast_1m'] * self::FORECAST_HORIZON_MONTHS, 2)
                : 0.00;

            $approval = $approvalRows->get($r['row_key']);
            $r['approval_forecast_1m'] = $approval ? round((float) ($approval->approval_forecast_1m ?? 0), 2) : null;
            $r['approval_forecast_6m'] = $approval
                ? round((float) ($approval->approval_forecast_1m ?? 0) * self::FORECAST_HORIZON_MONTHS, 2)
                : null;
            $r['approval_k_factor'] = $approval && isset($approval->approval_k_factor)
                ? round((float) $approval->approval_k_factor, 1)
                : null;
            $r['approval_remark'] = $approval ? (string) ($approval->approval_remark ?? '') : '';

            return $r;
        })->values();

        $fgParts = $rows->pluck('fg_partnumber')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $soMap = $this->fetchSalesOrderSummaryByFg($fgParts, $companyMode);

        $rows = $rows->map(function ($r) use ($soMap) {
            $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
            $r['sales_order_qty'] = (float) ($soMap->get($fg, 0) ?? 0);
            return $r;
        })->values();

        $rowsWithHistoryKeys = $rows
            ->filter(fn($r) => (float) ($r['avg6'] ?? 0) > 0)
            ->map(fn($r) => $this->rowCustomerFgDedupKey((array) $r))
            ->all();

        $manualOnlyRows = $rows
            ->filter(fn($r) => $this->shouldDisplayManualRow((array) $r))
            ->reject(fn($r) => in_array($this->rowCustomerFgDedupKey((array) $r), $rowsWithHistoryKeys, true))
            ->groupBy(fn($r) => $this->rowCustomerFgDedupKey((array) $r))
            ->map(function ($group) {
                return collect($group)
                    ->sortByDesc(function ($r) {
                        $manual = (float) ($r['manual_forecast_1m'] ?? 0);
                        $hasSupplier = !empty($r['supplier_code']) ? 1 : 0;
                        return ($manual > 0 ? 1000000 : 0) + $hasSupplier;
                    })
                    ->first();
            })
            ->values();
        $displayRows = $isApprovalMode
            ? $rows->filter(fn($r) => (int) ($r['is_selected'] ?? 0) === 1
                && ((float) ($r['avg6'] ?? 0) > 0 || (float) ($r['forecast_1m'] ?? 0) > 0))->values()
            : $rows->filter(fn($r) => (float) ($r['avg6'] ?? 0) > 0)->values();

        $kpi = [
            'items' => $displayRows->count(),
            'avg6_sum' => (float) $displayRows->sum('avg6'),
            'forecast_1m_sum' => (float) $displayRows->sum('forecast_1m'),
            'forecast_6m_sum' => (float) $displayRows->sum('forecast_6m'),
        ];

        $allowedDivisions = $this->availableSalesCodesForUser();
        if ($isApprovalMode && $this->canApproveDivisionForecast()) {
            $allowedDivisions = $this->availableApprovalSalesCodesForUser($forecastBaseMonth);
        }
        $showDivisionDropdown = count($allowedDivisions) > 1;
        $isSubmitted = $this->hasSubmittedForecastData($salesCode, $forecastBaseMonth);
        $submission = $this->currentSubmission($salesCode, $forecastBaseMonth);
        $latestSubmission = $this->anySubmission($salesCode, $forecastBaseMonth);
        $rejectedSubmission = strtoupper((string) ($latestSubmission->status ?? '')) === 'REJECTED'
            ? $latestSubmission
            : null;
        $visibleWorkflowSubmission = $submission ?: $rejectedSubmission;
        $workflow = $this->workflowForSubmission($visibleWorkflowSubmission);
        $canApproveCurrentSubmission = $submission ? $this->canApproveSubmission($submission) : false;
        $workflowHistory = $workflow
            ? WorkflowDb::historyWithActors('fc', (int) $workflow->id)
            : collect();

        return view('formfc.division.forecast', [
            'salesCode' => $salesCode,
            'rmLike' => $rmLike,
            'fgLike' => $fgLike,
            'selectedFgParts' => $selectedFgParts,
            'customerNameText' => $customerNameText,
            'selectedCustomerNames' => $selectedCustomerNames,
            'customerId' => $customerId,
            'selectedK' => $selectedK,
            'historyYm' => $historyYm,
            'historyLabels' => $historyLabels,
            'futureYm' => $futureYm,
            'futureLabels' => $futureLabels,
            'forecastHorizonMonths' => self::FORECAST_HORIZON_MONTHS,
            'rows' => $displayRows,
            'manualOnlyRows' => $manualOnlyRows,
            'kpi' => $kpi,
            'suppliers' => $this->fetchSupplierOptions(),
            'allowedDivisions' => $allowedDivisions,
            'showDivisionDropdown' => $showDivisionDropdown,
            'divisionLabels' => $this->divisionLabels,
            'isSubmitted' => $isSubmitted,
            'isApprovalMode' => $isApprovalMode,
            'canApproveDivisionForecast' => $this->canApproveDivisionForecast(),
            'approvalTableAvailable' => $this->approvalTableAvailable(),
            'submissionTableAvailable' => $this->submissionTableAvailable(),
            'submission' => $submission,
            'latestSubmission' => $latestSubmission,
            'rejectedSubmission' => $rejectedSubmission,
            'workflow' => $workflow,
            'workflowHistory' => $workflowHistory,
            'canApproveCurrentSubmission' => $canApproveCurrentSubmission,
        ]);
    }
    private function createForecastBatch(array $data): int
    {
        return (int) DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast_batch')
            ->insertGetId($data);
    }

    private function sortRowsForSqlServer(array $rows): array
    {
        return array_map(function ($row) {
            ksort($row);
            return $row;
        }, $rows);
    }

    private function upsertRowsByChunk(string $table, array $rows, array $uniqueBy, array $updateColumns, int $maxParameters = 2000): void
    {
        if (empty($rows)) {
            return;
        }

        $rows = $this->sortRowsForSqlServer($rows);
        $columnCount = max(1, count($rows[0]));

        // SQL Server upsert uses MERGE; keep a safe buffer below the 2100-parameter limit.
        $chunkSize = max(1, (int) floor($maxParameters / ($columnCount * 2)));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection($this->fcConn)
                ->table($table)
                ->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }

    public function generateFgForecast(Request $request)
    {
        $u = $this->userOr403();

        // รองรับ submit แบบ JSON payload (เลี่ยง php max_input_vars เมื่อมีหลายร้อยแถว)
        $payloadJson = (string) $request->input('payload', '');
        if ($payloadJson !== '') {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded)) {
                foreach (['sales_code', 'division', 'customer_id', 'customer_name', 'k_factor', 'submit_action'] as $scalarKey) {
                    if (array_key_exists($scalarKey, $decoded)) {
                        $request->merge([$scalarKey => $decoded[$scalarKey]]);
                    }
                }
                foreach (['forecast_flag', 'row_k_factor', 'row_meta', 'manual_forecast_1m', 'row_remark', 'supplier_code', 'supplier_name_manual'] as $arrKey) {
                    if (isset($decoded[$arrKey]) && is_array($decoded[$arrKey])) {
                        $request->merge([$arrKey => $decoded[$arrKey]]);
                    }
                }
            }
        }

        $validated = $request->validate([
            'sales_code' => ['required', 'string'],
            'customer_id' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string'],
            'k_factor' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'forecast_flag' => ['nullable', 'array'],
            'row_k_factor' => ['nullable', 'array'],
            'row_meta' => ['nullable', 'array'],
            'manual_forecast_1m' => ['nullable', 'array'],
            'row_remark' => ['nullable', 'array'],
            'supplier_code' => ['nullable', 'array'],
            'supplier_name_manual' => ['nullable', 'array'],
            'payload' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'in:save_draft,submit_approval'],
        ], [
            'k_factor.regex' => 'K Factor Default ต้องเป็นตัวเลข เช่น 1, 1.5, 2.05',
        ]);

        foreach ((array) $request->input('row_k_factor', []) as $rk) {
            if ($rk !== null && $rk !== '' && !preg_match('/^\d+(\.\d+)?$/', (string) $rk)) {
                return back()->with('error', 'K รายแถวต้องเป็นตัวเลข เช่น 1.5')->withInput();
            }
        }

        $salesCode = $this->normalizeSalesCode($validated['sales_code']);
        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์บันทึก division นี้');
        }

        $companyMode = 'ALL';
        $defaultK = round((float) $validated['k_factor'], 1);
        $customerId = trim((string) ($validated['customer_id'] ?? ''));
        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();
        $submitAction = (string) ($validated['submit_action'] ?? 'submit_approval');
        $shouldSubmitApproval = $submitAction === 'submit_approval';

        if ($this->divisionForecastSubmitted($salesCode, $baseMonth)) {
            return back()->with('error', 'Division นี้ submit Forecast ของเดือนนี้แล้ว ห้ามบันทึกทับอีก กรุณาให้หัวหน้าแก้ผ่านหน้า Approval')->withInput();
        }

        $manualRows = $request->input('manual_forecast_1m', []);
        $rowRemarks = $request->input('row_remark', []);
        $supplierCodes = $request->input('supplier_code', []);
        $supplierNamesManual = $request->input('supplier_name_manual', []);
        $suppliers = $this->fetchSupplierOptions()->keyBy('supplier_code');
        $suppliersByName = $this->fetchSupplierOptions()->keyBy(fn($s) => strtoupper(trim((string) ($s['supplier_short_name'] ?? ''))));
        $forecastFlag = $request->input('forecast_flag', []);
        $rowKFactor = $request->input('row_k_factor', []);
        $rowMeta = $request->input('row_meta', []);

        $settingRows = [];
        $snapshotRows = [];
        $historyRows = [];

        foreach ($rowMeta as $rowKey => $metaJson) {
            $meta = json_decode((string) $metaJson, true);
            if (!is_array($meta)) continue;

            $custId = (int) ($meta['customer_id'] ?? 0);
            $custName = (string) ($meta['customer_name'] ?? '-');
            $fgPartnumber = strtoupper(trim((string) ($meta['fg_partnumber'] ?? '')));
            $fgDesc = (string) ($meta['fg_description'] ?? '');
            $rmPart = strtoupper(trim((string) ($meta['rm_partnumber'] ?? '')));
            $avg6 = round((float) ($meta['avg6'] ?? 0), 2);
            $salesOrderQty = round((float) ($meta['sales_order_qty'] ?? 0), 2);

            $manual1m = isset($manualRows[$rowKey]) && $manualRows[$rowKey] !== ''
                ? round((float) $manualRows[$rowKey], 2)
                : null;

            $rowRemark = trim((string) ($rowRemarks[$rowKey] ?? ''));
            $supplierCode = trim((string) ($supplierCodes[$rowKey] ?? ''));
            $supplierManualName = trim((string) ($supplierNamesManual[$rowKey] ?? ''));
            $supplier = $supplierCode !== '' ? $suppliers->get($supplierCode) : null;
            $supplierName = null;

            if ($supplier) {
                $supplierName = (string) ($supplier['supplier_short_name'] ?? '');
            } elseif ($supplierManualName !== '') {
                $matched = $suppliersByName->get(strtoupper($supplierManualName));
                if ($matched) {
                    $supplierCode = (string) ($matched['supplier_code'] ?? '');
                    $supplierName = (string) ($matched['supplier_short_name'] ?? $supplierManualName);
                } else {
                    $supplierCode = '';
                    $supplierName = $supplierManualName;
                }
            }

            if ($fgPartnumber === '') continue;

            $isSelected = isset($forecastFlag[$rowKey]) && (string) $forecastFlag[$rowKey] === '1';

            $kUsed = isset($rowKFactor[$rowKey]) && is_numeric($rowKFactor[$rowKey])
                ? round((float) $rowKFactor[$rowKey], 1)
                : $defaultK;

            $manualInput = isset($manualRows[$rowKey]) && $manualRows[$rowKey] !== ''
                ? round((float) $manualRows[$rowKey], 2)
                : null;

            $autoForecast1m = round($avg6 * $kUsed, 2);

            // manual เฉพาะตอนค่าที่ส่งมา "ต่าง" จาก auto ของ K รอบนี้
            $isManual = $manualInput !== null && abs($manualInput - $autoForecast1m) > 0.0001;

            $forecast1m = $isSelected
                ? ($isManual ? $manualInput : $autoForecast1m)
                : 0.00;

            $forecast6m = $isSelected ? round($forecast1m * self::FORECAST_HORIZON_MONTHS, 2) : 0;

            $settingRows[] = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => $custId,
                'customer_name' => $custName,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => $fgDesc,
                'is_selected' => $isSelected ? 1 : 0,
                'k_factor' => $kUsed,
                'manual_forecast_1m' => $isManual ? $manualInput : null,
                'row_remark' => $rowRemark !== '' ? $rowRemark : null,
                'supplier_code' => $supplierCode !== '' ? $supplierCode : null,
                'supplier_name' => $supplierName,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            if ($isSelected) {
                $common = [
                    'sales_code' => $salesCode,
                    'forecast_base_month' => $baseMonth,
                    'company_mode' => $companyMode,
                    'customer_id' => $custId,
                    'customer_name' => $custName !== '' ? $custName : ($customerName !== '' ? $customerName : null),
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => $fgDesc,
                    'rm_partnumber' => $rmPart !== '' ? $rmPart : null,
                    'history_avg6' => $avg6,
                    'k_factor' => $kUsed,
                    'forecast_month' => $baseMonth,
                    'forecast_qty' => $forecast1m,
                    'forecast_1m' => $forecast1m,
                    'forecast_6m' => $forecast6m,
                    'manual_forecast_1m' => $isManual ? $manualInput : null,
                    'source_type' => $isManual ? 'MANUAL_1M' : 'AUTO_K',
                    'is_selected' => 1,
                    'row_remark' => $rowRemark !== '' ? $rowRemark : null,
                    'supplier_code' => $supplierCode !== '' ? $supplierCode : null,
                    'supplier_name' => $supplierName,
                    'sales_order_qty' => $salesOrderQty,
                    'created_at' => now(),
                    'created_by' => $u->id ?? null,
                ];

                $snapshotRows[] = $common + [
                    'updated_at' => now(),
                    'updated_by' => $u->id ?? null,
                ];

                $historyRows[] = $common;
            }
        }

        if (empty($settingRows)) {
            return back()->with('error', 'ไม่พบข้อมูลสำหรับบันทึก');
        }

        $settingRows = $this->uniqueForecastRowsByKey(
            $settingRows,
            ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber']
        );
        $snapshotRows = $this->uniqueForecastRowsByKey(
            $snapshotRows,
            ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber', 'forecast_month']
        );
        $historyRows = $this->uniqueForecastRowsByKey(
            $historyRows,
            ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber', 'forecast_month']
        );

        DB::connection($this->fcConn)->transaction(function () use (
            $salesCode,
            $baseMonth,
            $companyMode,
            $defaultK,
            $settingRows,
            $snapshotRows,
            $historyRows,
            $customerId,
            $customerName,
            $u
        ) {
            $this->upsertRowsByChunk(
                'fc_rm_division_part_setting',
                $settingRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
                [
                    'customer_name',
                    'fg_description',
                    'is_selected',
                    'k_factor',
                    'manual_forecast_1m',
                    'row_remark',
                    'supplier_code',
                    'supplier_name',
                    'updated_at',
                    'updated_by'
                ]
            );

            // batch เป็น header ของการ save 1 ครั้ง
            // ถ้าไม่ได้ filter customer แต่รายการที่บันทึกทั้งหมดเป็นลูกค้าคนเดียวกัน ให้เก็บ customer_id/name ให้ด้วย
            $selectedCustomerIds = collect($snapshotRows)
                ->pluck('customer_id')
                ->filter(fn($v) => (int) $v > 0)
                ->map(fn($v) => (int) $v)
                ->unique()
                ->values();

            $selectedCustomerNames = collect($snapshotRows)
                ->pluck('customer_name')
                ->filter(fn($v) => trim((string) $v) !== '')
                ->map(fn($v) => trim((string) $v))
                ->unique()
                ->values();

            $batchCustomerId = $customerId !== ''
                ? (int) $customerId
                : ($selectedCustomerIds->count() === 1 ? (int) $selectedCustomerIds->first() : null);

            $batchCustomerName = $customerName !== ''
                ? $customerName
                : ($selectedCustomerNames->count() === 1 ? (string) $selectedCustomerNames->first() : null);

            $batchId = $this->createForecastBatch([
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => $companyMode,
                'customer_id' => $batchCustomerId,
                'customer_name' => $batchCustomerName,
                // ตัวนี้เป็น K default ของรอบ save ไม่ใช่ K ราย row
                // K ราย row อยู่ที่ fc_rm_division_forecast.k_factor และ fc_rm_division_part_setting.k_factor
                'default_k_factor' => $defaultK,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ]);

            DB::connection($this->fcConn)
                ->table('fc_rm_division_forecast')
                ->where('sales_code', $salesCode)
                ->where('forecast_base_month', $baseMonth)
                ->when($customerId !== '', fn($q) => $q->where('customer_id', $customerId))
                ->delete();

            // สำคัญมาก: จัดลำดับ key ให้ตรงกันก่อน insert/upsert
            $this->upsertRowsByChunk(
                'fc_rm_division_part_setting',
                $settingRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
                [
                    'customer_name',
                    'fg_description',
                    'is_selected',
                    'k_factor',
                    'manual_forecast_1m',
                    'row_remark',
                    'supplier_code',
                    'supplier_name',
                    'updated_at',
                    'updated_by'
                ]
            );

            $snapshotRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                return $this->normalizeForecastRowForSqlServer($r);
            }, $snapshotRows);

            $historyRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                $r = $this->normalizeForecastRowForSqlServer($r);

                unset($r['forecast_1m'], $r['forecast_6m'], $r['updated_at'], $r['updated_by']);

                return $r;
            }, $historyRows);

            if (!empty($snapshotRows)) {
                $this->upsertRowsByChunk(
                    'fc_rm_division_forecast',
                    $snapshotRows,
                    ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber', 'forecast_month'],
                    [
                        'batch_id',
                        'company_mode',
                        'customer_name',
                        'fg_description',
                        'rm_partnumber',
                        'history_avg6',
                        'k_factor',
                        'forecast_qty',
                        'forecast_1m',
                        'forecast_6m',
                        'manual_forecast_1m',
                        'source_type',
                        'is_selected',
                        'row_remark',
                        'supplier_code',
                        'supplier_name',
                        'sales_order_qty',
                        'updated_at',
                        'updated_by',
                    ]
                );
            }

            if (!empty($historyRows)) {
                $this->insertRowsByChunk('fc_rm_division_forecast_item_history', $historyRows, 50);
            }

            DB::connection($this->fcConn)
                ->table('fc_rm_division_setting')
                ->updateOrInsert(
                    ['sales_code' => $salesCode],
                    [
                        'default_k_factor' => $defaultK,
                        'updated_at' => now(),
                        'updated_by' => $u->id ?? null,
                    ]
                );
        });

        $this->bumpForecastIndexCacheVersion();

        if ($shouldSubmitApproval) {
            $wfId = $this->createSubmissionWorkflow($salesCode, $baseMonth, $u);
            if ($wfId) {
                $submission = $this->currentSubmission($salesCode, $baseMonth);
                $workflow = WorkflowDb::findForm('fc', $wfId);
                $this->notifyFcPendingApprovers($submission, $workflow);
            }

            return back()->with('success', 'บันทึก Forecast และส่งให้หัวหน้ายืนยันเรียบร้อยแล้ว');
        }

        return back()->with('success', 'บันทึก Forecast Draft เรียบร้อยแล้ว ยังไม่ส่งเข้า workflow');
    }

    public function saveManual(Request $request)
    {
        $u = $this->userOr403();

        $salesCode = $this->normalizeSalesCode(
            (string) $request->input('sales_code', $request->input('division', ''))
        );

        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์บันทึก division นี้');
        }

        $rows = $request->input('manual_rows', []);
        $metaRows = $request->input('manual_meta', []);
        if (!is_array($rows) || empty($rows)) {
            return back()->with('error', 'ไม่มีข้อมูล Manual Forecast สำหรับบันทึก');
        }

        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();
        if ($this->divisionForecastSubmitted($salesCode, $baseMonth)) {
            return back()->with('error', 'Division นี้ submit Forecast ของเดือนนี้แล้ว ห้ามบันทึก Manual ทับอีก กรุณาให้หัวหน้าแก้ผ่านหน้า Approval')->withInput();
        }

        $settingRows = [];
        $snapshotRows = [];
        $historyRows = [];
        $submittedManualKeys = [];

        foreach ($rows as $rowKey => $qty) {
            $rowKey = (string) $rowKey;
            if ($rowKey === '') {
                continue;
            }

            $meta = json_decode((string) ($metaRows[$rowKey] ?? ''), true);
            if (!is_array($meta)) {
                continue;
            }

            $customerId = (int) ($meta['customer_id'] ?? 0);
            $customerName = trim((string) ($meta['customer_name'] ?? ''));
            $fgPartnumber = strtoupper(trim((string) ($meta['fg_partnumber'] ?? '')));
            $fgDescription = trim((string) ($meta['fg_description'] ?? ''));
            $rmPartnumber = strtoupper(trim((string) ($meta['rm_partnumber'] ?? '')));

            if ($customerId <= 0 || $fgPartnumber === '') {
                continue;
            }

            if ($qty === '' || $qty === null) {
                continue;
            }

            if (!is_numeric($qty)) {
                return back()->with('error', "ค่า Manual ของ {$fgPartnumber} ต้องเป็นตัวเลข")->withInput();
            }

            $qty = round((float) $qty, 2);
            if ($qty < 0) {
                return back()->with('error', "ค่า Manual ของ {$fgPartnumber} ต้องไม่ติดลบ")->withInput();
            }
            if ($qty <= 0) {
                continue;
            }

            $manualKey = $customerId . '|' . $fgPartnumber;
            if (isset($submittedManualKeys[$manualKey])) {
                $label = ($customerName !== '' ? $customerName : ('Customer #' . $customerId)) . ' / ' . $fgPartnumber;
                return back()->with('error', "Manual Forecast ซ้ำ customer + FG Part: {$label}")->withInput();
            }
            $submittedManualKeys[$manualKey] = true;

            $settingRows[] = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => $customerId,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => $fgDescription !== '' ? $fgDescription : null,
                'is_selected' => 1,
                'k_factor' => 0,
                'manual_forecast_1m' => $qty,
                'row_remark' => null,
                'supplier_code' => null,
                'supplier_name' => null,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            $common = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => $customerId,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => $fgDescription !== '' ? $fgDescription : null,
                'rm_partnumber' => $rmPartnumber !== '' ? $rmPartnumber : null,
                'history_avg6' => 0,
                'k_factor' => 0,
                'manual_forecast_1m' => $qty,
                'row_remark' => null,
                'supplier_code' => null,
                'supplier_name' => null,
                'sales_order_qty' => null,
                'forecast_month' => $baseMonth,
                'forecast_qty' => $qty,
                'forecast_1m' => $qty,
                'forecast_6m' => round($qty * self::FORECAST_HORIZON_MONTHS, 2),
                'source_type' => 'MANUAL',
                'is_selected' => 1,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ];

            $snapshotRows[] = $common + [
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            $historyRows[] = $common;
        }

        $preferManualValue = function (array $rows): array {
            $byKey = [];

            foreach ($rows as $row) {
                $key = ((int) ($row['customer_id'] ?? 0)) . '|' . strtoupper(trim((string) ($row['fg_partnumber'] ?? '')));
                $existing = $byKey[$key] ?? null;
                $qty = (float) ($row['manual_forecast_1m'] ?? $row['forecast_qty'] ?? 0);
                $existingQty = (float) ($existing['manual_forecast_1m'] ?? $existing['forecast_qty'] ?? 0);

                if (!$existing || ($existingQty <= 0 && $qty > 0) || $existingQty === $qty) {
                    $byKey[$key] = $row;
                }
            }

            return array_values($byKey);
        };

        $settingRows = $preferManualValue($settingRows);
        $snapshotRows = $preferManualValue($snapshotRows);
        $historyRows = $preferManualValue($historyRows);

        if (empty($snapshotRows)) {
            return back()->with('error', 'ไม่มีข้อมูล Manual Forecast ที่กรอกไว้')->withInput();
        }

        DB::connection($this->fcConn)->transaction(function () use ($salesCode, $baseMonth, $settingRows, $snapshotRows, $historyRows, $u) {
            $this->upsertRowsByChunk(
                'fc_rm_division_part_setting',
                $settingRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
                ['customer_name', 'fg_description', 'is_selected', 'k_factor', 'manual_forecast_1m', 'row_remark', 'supplier_code', 'supplier_name', 'updated_at', 'updated_by']
            );

            $batchId = $this->createForecastBatch([
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => null,
                'customer_name' => null,
                'default_k_factor' => 0,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ]);

            $snapshotRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                return $this->normalizeForecastRowForSqlServer($r);
            }, $snapshotRows);

            $historyRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                $r = $this->normalizeForecastRowForSqlServer($r);
                unset($r['forecast_1m'], $r['forecast_6m'], $r['updated_at'], $r['updated_by']);
                return $r;
            }, $historyRows);

            $this->upsertRowsByChunk(
                'fc_rm_division_forecast',
                $snapshotRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber', 'forecast_month'],
                ['forecast_qty', 'manual_forecast_1m', 'forecast_1m', 'forecast_6m', 'row_remark', 'source_type', 'updated_at', 'updated_by', 'batch_id']
            );

            $this->insertRowsByChunk('fc_rm_division_forecast_item_history', $historyRows, 50);
        });
        $this->bumpForecastIndexCacheVersion();

        return back()->with('success', 'บันทึก Manual Forecast เรียบร้อยแล้ว');
    }

    public function saveApproval(Request $request)
    {
        $u = $this->userOr403();

        if (!$this->canApproveDivisionForecast()) {
            abort(403, 'ไม่มีสิทธิ์ approve Division Forecast');
        }

        if (!$this->approvalTableAvailable()) {
            return back()->with('error', 'ยังไม่พบ table ' . $this->divisionApprovalTable . ' กรุณารัน SQL ใน database/sql/create_fc_division_forecast_approval.sql ก่อน')->withInput();
        }

        $salesCode = $this->normalizeSalesCode(
            (string) $request->input('sales_code', $request->input('division', ''))
        );
        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();

        if (!$salesCode || !$this->canAccessApprovalSalesCode($salesCode, $baseMonth)) {
            abort(403, 'ไม่มีสิทธิ์ approve division นี้');
        }

        if (!$this->divisionForecastSubmitted($salesCode, $baseMonth)) {
            return back()->with('error', 'Division นี้ยังไม่ได้ submit Forecast ของเดือนนี้ จึงยัง approve ไม่ได้')->withInput();
        }

        $submission = $this->currentSubmission($salesCode, $baseMonth);
        if (!$submission || !$this->canApproveSubmission($submission)) {
            abort(403, 'You are not allowed to approve this Division Forecast step');
        }

        $rows = $request->input('approval_forecast_1m', []);
        $remarks = $request->input('approval_remark', []);
        $rowKFactors = $request->input('row_k_factor', []);
        $metaRows = $request->input('row_meta', []);
        $canStoreApprovalKFactor = $this->approvalKFactorColumnAvailable();

        if (!is_array($rows) || empty($rows)) {
            return back()->with('error', 'ไม่พบข้อมูล Approval Forecast สำหรับบันทึก')->withInput();
        }

        $approvalRows = [];
        foreach ($rows as $rowKey => $qty) {
            $rowKey = (string) $rowKey;
            $meta = json_decode((string) ($metaRows[$rowKey] ?? ''), true);
            if (!is_array($meta) || $qty === '' || $qty === null) {
                continue;
            }

            if (!is_numeric($qty)) {
                return back()->with('error', 'ค่า Approval Forecast ต้องเป็นตัวเลข')->withInput();
            }

            $qty = round((float) $qty, 2);
            if ($qty < 0) {
                return back()->with('error', 'ค่า Approval Forecast ต้องไม่ติดลบ')->withInput();
            }

            $approvalKFactor = isset($rowKFactors[$rowKey]) && $rowKFactors[$rowKey] !== ''
                ? $rowKFactors[$rowKey]
                : null;
            if ($approvalKFactor !== null && !is_numeric($approvalKFactor)) {
                return back()->with('error', 'ค่า K ที่ใช้ตอน approve ต้องเป็นตัวเลข')->withInput();
            }
            $approvalKFactor = $approvalKFactor !== null ? round((float) $approvalKFactor, 1) : null;

            $customerId = (int) ($meta['customer_id'] ?? 0);
            $fgPartnumber = strtoupper(trim((string) ($meta['fg_partnumber'] ?? '')));
            if ($customerId <= 0 || $fgPartnumber === '') {
                continue;
            }

            $approvalRows[] = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => $customerId,
                'customer_name' => trim((string) ($meta['customer_name'] ?? '')) ?: null,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => trim((string) ($meta['fg_description'] ?? '')) ?: null,
                'rm_partnumber' => strtoupper(trim((string) ($meta['rm_partnumber'] ?? ''))) ?: null,
                'division_forecast_1m' => isset($meta['division_forecast_1m']) ? round((float) $meta['division_forecast_1m'], 2) : null,
                'division_forecast_6m' => isset($meta['division_forecast_6m']) ? round((float) $meta['division_forecast_6m'], 2) : null,
                'approval_forecast_1m' => $qty,
                'approval_forecast_6m' => round($qty * self::FORECAST_HORIZON_MONTHS, 2),
                'approval_remark' => trim((string) ($remarks[$rowKey] ?? '')) ?: null,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ];

            if ($canStoreApprovalKFactor) {
                $approvalRows[array_key_last($approvalRows)]['approval_k_factor'] = $approvalKFactor;
            }
        }

        if (empty($approvalRows)) {
            return back()->with('error', 'ไม่มีรายการ Approval Forecast ที่บันทึกได้')->withInput();
        }

        $approvalUpdateColumns = [
            'customer_name',
            'fg_description',
            'rm_partnumber',
            'division_forecast_1m',
            'division_forecast_6m',
            'approval_forecast_1m',
            'approval_forecast_6m',
            'approval_remark',
            'updated_at',
            'updated_by',
        ];

        if ($canStoreApprovalKFactor) {
            $approvalUpdateColumns[] = 'approval_k_factor';
        }

        $this->upsertRowsByChunk(
            $this->divisionApprovalTable,
            $approvalRows,
            ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
            $approvalUpdateColumns
        );

        $wfId = (int) $submission->wf_form_id;
        WorkflowEngine::approve($wfId, (int) ($u->id ?? auth()->id()), $request->input('comment'), 'fc');

        $workflow = WorkflowDb::findForm('fc', $wfId);
        $workflowIsApproved = $this->workflowIsApproved($workflow);

        DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('id', (int) $submission->id)
            ->update([
                'status' => $this->submissionStatusFromWorkflow($workflow),
                'approved_at' => $workflowIsApproved ? now() : null,
                'approved_by' => $workflowIsApproved ? ($u->id ?? null) : null,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ]);
        $this->bumpForecastIndexCacheVersion();

        $updatedSubmission = DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('id', (int) $submission->id)
            ->first();

        if ($workflowIsApproved) {
            $this->notifyFcOwnerClosed($updatedSubmission, $workflow);
        } else {
            $this->notifyFcPendingApprovers($updatedSubmission, $workflow);
        }

        return redirect()
            ->route('fc.division.approval', ['division' => $salesCode])
            ->with('success', 'บันทึกและ approve Forecast เรียบร้อยแล้ว');
    }

    public function rejectApproval(Request $request)
    {
        $u = $this->userOr403();

        if (!$this->canApproveDivisionForecast()) {
            abort(403, 'ไม่มีสิทธิ์ reject Division Forecast');
        }

        $validated = $request->validate([
            'sales_code' => ['required', 'string'],
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        $salesCode = $this->normalizeSalesCode($validated['sales_code']);
        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();
        if (!$salesCode || !$this->canAccessApprovalSalesCode($salesCode, $baseMonth)) {
            abort(403, 'ไม่มีสิทธิ์ reject division นี้');
        }

        $submission = $this->currentSubmission($salesCode, $baseMonth);

        if (!$submission || !$this->canApproveSubmission($submission)) {
            abort(403, 'You are not allowed to reject this Division Forecast step');
        }

        WorkflowEngine::reject((int) $submission->wf_form_id, (int) ($u->id ?? auth()->id()), $validated['comment'], 'fc');

        DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('id', (int) $submission->id)
            ->update([
                'status' => 'REJECTED',
                'rejected_at' => now(),
                'rejected_by' => $u->id ?? null,
                'reject_reason' => $validated['comment'],
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ]);
        $this->bumpForecastIndexCacheVersion();

        $updatedSubmission = DB::connection($this->fcConn)
            ->table($this->divisionSubmissionTable)
            ->where('id', (int) $submission->id)
            ->first();
        $workflow = WorkflowDb::findForm('fc', (int) $submission->wf_form_id);
        $this->notifyFcOwnerRejected($updatedSubmission, $workflow, $validated['comment']);

        return redirect()
            ->route('fc.division.approval', ['division' => $salesCode])
            ->with('success', 'Reject Forecast เรียบร้อยแล้ว');
    }

    private function normalizeForecastRowForSqlServer(array $r): array
    {
        return [
            'batch_id'            => isset($r['batch_id']) ? (int) $r['batch_id'] : null,
            'company_mode'        => isset($r['company_mode']) ? (string) $r['company_mode'] : null,
            'created_at'          => $r['created_at'] ?? now(),
            'created_by'          => isset($r['created_by']) ? (int) $r['created_by'] : null,
            'customer_id'         => isset($r['customer_id']) && $r['customer_id'] !== '' ? (int) $r['customer_id'] : null,
            'customer_name'       => isset($r['customer_name']) ? (string) $r['customer_name'] : null,
            'fg_description'      => isset($r['fg_description']) ? (string) $r['fg_description'] : null,
            'fg_partnumber'       => isset($r['fg_partnumber']) ? (string) $r['fg_partnumber'] : null,
            'forecast_1m'         => isset($r['forecast_1m']) ? round((float) $r['forecast_1m'], 2) : null,
            'forecast_6m'         => isset($r['forecast_1m'])
                ? round((float) $r['forecast_1m'] * FormFcPeriod::MONTHS, 2)
                : null,
            'forecast_base_month' => $r['forecast_base_month'] ?? null,
            'forecast_month'      => $r['forecast_month'] ?? null,
            'forecast_qty'        => isset($r['forecast_qty']) ? round((float) $r['forecast_qty'], 2) : null,
            'history_avg6'        => isset($r['history_avg6']) ? round((float) $r['history_avg6'], 2) : null,
            'is_selected'         => !empty($r['is_selected']) ? 1 : 0,
            'k_factor'            => isset($r['k_factor']) ? round((float) $r['k_factor'], 1) : null,
            'manual_forecast_1m'  => isset($r['manual_forecast_1m']) ? round((float) $r['manual_forecast_1m'], 2) : null,
            'rm_partnumber'       => isset($r['rm_partnumber']) && $r['rm_partnumber'] !== '' ? (string) $r['rm_partnumber'] : null,
            'row_remark'          => isset($r['row_remark']) && $r['row_remark'] !== '' ? (string) $r['row_remark'] : null,
            'sales_code'          => isset($r['sales_code']) ? (string) $r['sales_code'] : null,
            'sales_order_qty'     => isset($r['sales_order_qty']) ? round((float) $r['sales_order_qty'], 2) : null,
            'source_type'         => isset($r['source_type']) ? (string) $r['source_type'] : null,
            'supplier_code'       => isset($r['supplier_code']) && $r['supplier_code'] !== '' ? (string) $r['supplier_code'] : null,
            'supplier_name'       => isset($r['supplier_name']) && $r['supplier_name'] !== '' ? (string) $r['supplier_name'] : null,
            'updated_at'          => $r['updated_at'] ?? now(),
            'updated_by'          => isset($r['updated_by']) ? (int) $r['updated_by'] : null,
        ];
    }

    private function uniqueForecastRowsByKey(array $rows, array $columns): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $key = collect($columns)
                ->map(fn($column) => strtoupper(trim((string) ($row[$column] ?? ''))))
                ->implode('|');

            $unique[$key] = $row;
        }

        return array_values($unique);
    }

    private function insertRowsByChunk(string $table, array $rows, int $chunkSize = 50): void
    {
        $rows = array_map(function ($row) {
            ksort($row);
            return $row;
        }, $rows);

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection($this->fcConn)
                ->table($table)
                ->insert($chunk);
        }
    }
}
