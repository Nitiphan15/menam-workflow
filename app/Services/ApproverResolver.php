<?php

namespace App\Services;

use App\Support\WorkflowDb;
use Illuminate\Support\Collection;

class ApproverResolver
{
    private const PO_DEPARTMENT_APPROVER_TABLE = 'po_department_approvers';

    /**
     * Return approver user ids for a workflow rule.
     * Supported source_type: ORIGINATOR, SUPERVISOR, DEPARTMENT_MANAGER, ROLE, USER, QUERY
     */
    public static function resolve(object $rule, object $wfForm, array $context, array $skipFlags): Collection
    {
        $type = strtoupper((string) $rule->source_type);
        $json = self::json($rule->condition_expr);
        $originatorId = (int) ($context['originator_id'] ?? $wfForm->request_by_user_id);
        $appCode = strtolower((string) ($wfForm->app_code ?? ''));
        $stepNo = (int) ($context['workflow_step_no'] ?? $wfForm->current_step_no ?? 0);
        $deptId = self::resolveDepartmentContext($rule, $wfForm, $context, $json);
        $excludeOriginator = (bool) ($json['exclude_originator'] ?? ($appCode === 'po' && $stepNo >= 2));

        if ($appCode === 'po'
            && $stepNo === 2
            && $type === 'SUPERVISOR'
            && self::isPurchaseDepartment($deptId, $appCode)
        ) {
            $excludeOriginator = (bool) ($json['exclude_originator'] ?? false);
        }

        switch ($type) {
            case 'ORIGINATOR':
                return collect([$originatorId]);

            case 'SUPERVISOR':
                if (!empty($skipFlags['sup'])) {
                    return collect();
                }

                if (self::isPoPurchaseApprovalStep($wfForm, $context)) {
                    return self::byDeptLevelOrAbove($deptId, 2, $excludeOriginator ? $originatorId : null, $appCode);
                }

                return self::byDeptLevel($deptId, 2, $excludeOriginator ? $originatorId : null, $appCode);

            case 'DEPARTMENT_MANAGER':
                return self::byDeptLevel($deptId, 3, $excludeOriginator ? $originatorId : null, $appCode);

            case 'ROLE':
                $roleId = (int) $rule->source_ref_id;

                if (isset($json['role_in'])) {
                    $users = self::byDeptRoleNames(
                        $deptId,
                        (array) $json['role_in'],
                        $excludeOriginator ? $originatorId : null,
                        $appCode,
                        (bool) ($json['include_children'] ?? false)
                    );

                    if ($users->isEmpty() && self::isPoDepartmentApprovalStep($wfForm, $context)) {
                        return self::byDeptHighestLevel($deptId, $excludeOriginator ? $originatorId : null, 3, $appCode);
                    }

                    return $users;
                }

                return self::byDeptRole($deptId, $roleId, $excludeOriginator ? $originatorId : null, $appCode);

            case 'USER':
                return collect([(int) $rule->source_ref_id])->filter();

            case 'QUERY':
                $queryKey = (string) ($json['q'] ?? '');
                if ($queryKey === 'dept:anyone' && $deptId > 0) {
                    foreach (self::departmentLineage($deptId, $appCode) as $candidateDeptId) {
                        $users = WorkflowDb::table($appCode, 'users')
                            ->where('department_id', $candidateDeptId)
                            ->where('is_active', 1)
                            ->pluck('id');

                        if ($excludeOriginator) {
                            $users = $users->reject(fn($id) => (int) $id === $originatorId)->values();
                        }

                        if ($users->isNotEmpty()) {
                            return $users;
                        }
                    }
                }

                return collect();

            default:
                return collect();
        }
    }

    /**
     * Resolve an explicit FormPO department approver before the shared
     * organization-role rules are evaluated. The nearest mapped department
     * wins, so an exact department mapping overrides a parent mapping.
     */
    public static function poDepartmentApprovers(object $wfForm, array $context): Collection
    {
        $appCode = strtolower((string) ($wfForm->app_code ?? ''));
        $stepNo = (int) ($context['workflow_step_no'] ?? $wfForm->current_step_no ?? 0);

        if ($appCode !== 'po' || $stepNo !== 3) {
            return collect();
        }

        $departmentId = (int) ($context['document_department_id'] ?? $context['department_id'] ?? 0);
        if ($departmentId <= 0) {
            return collect();
        }

        $connection = WorkflowDb::connection($appCode);
        if (!$connection->getSchemaBuilder()->hasTable(self::PO_DEPARTMENT_APPROVER_TABLE)) {
            return collect();
        }

        foreach (self::departmentLineage($departmentId, $appCode) as $candidateDepartmentId) {
            $approvers = WorkflowDb::table($appCode, self::PO_DEPARTMENT_APPROVER_TABLE . ' as pda')
                ->join('users as u', 'u.id', '=', 'pda.approver_user_id')
                ->where('pda.department_id', $candidateDepartmentId)
                ->where('pda.is_active', 1)
                ->where('u.is_active', 1)
                ->orderBy('pda.id')
                ->pluck('u.id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($approvers->isNotEmpty()) {
                return $approvers;
            }
        }

        return collect();
    }

    private static function byDeptLevel(int $deptId, int $levelNo, ?int $excludeUserId = null, ?string $appCode = null): Collection
    {

        foreach (self::departmentLineage($deptId, $appCode) as $candidateDeptId) {
            $users = self::queryDeptRoleUsers($candidateDeptId, $excludeUserId, $appCode)
                ->where('dr.level_no', $levelNo)
                ->orderByDesc('dru.is_primary')
                ->orderByDesc('dru.start_date')
                ->pluck('u.id');
            //dd($users);
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        return collect();
    }

    private static function byDeptHighestLevel(int $deptId, ?int $excludeUserId = null, int $minLevelNo = 1, ?string $appCode = null): Collection
    {
        foreach (self::departmentLineage($deptId, $appCode) as $candidateDeptId) {
            $rows = self::queryDeptRoleUsers($candidateDeptId, $excludeUserId, $appCode)
                ->where('dr.level_no', '>=', $minLevelNo)
                ->orderByDesc('dr.level_no')
                ->orderByDesc('dru.is_primary')
                ->orderByDesc('dru.start_date')
                ->get(['u.id', 'dr.level_no']);

            if ($rows->isEmpty()) {
                continue;
            }

            $maxLevel = (int) $rows->max('level_no');

            return $rows
                ->filter(fn ($row) => (int) $row->level_no === $maxLevel)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        return collect();
    }

    private static function byDeptLevelOrAbove(int $deptId, int $minLevelNo, ?int $excludeUserId = null, ?string $appCode = null): Collection
    {
        foreach (self::departmentLineage($deptId, $appCode) as $candidateDeptId) {
            $rows = self::queryDeptRoleUsers($candidateDeptId, $excludeUserId, $appCode)
                ->where('dr.level_no', '>=', $minLevelNo)
                ->orderBy('dr.level_no')
                ->orderByDesc('dru.is_primary')
                ->orderByDesc('dru.start_date')
                ->get(['u.id', 'dr.level_no']);

            if ($rows->isEmpty()) {
                continue;
            }

            $nearestLevel = (int) $rows->min('level_no');

            return $rows
                ->filter(fn ($row) => (int) $row->level_no === $nearestLevel)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        return collect();
    }

    private static function isPoDepartmentApprovalStep(object $wfForm, array $context): bool
    {
        return strtolower((string) ($wfForm->app_code ?? '')) === 'po'
            && (int) ($context['workflow_step_no'] ?? $wfForm->current_step_no ?? 0) === 3;
    }

    private static function isPoPurchaseApprovalStep(object $wfForm, array $context): bool
    {
        return strtolower((string) ($wfForm->app_code ?? '')) === 'po'
            && (int) ($context['workflow_step_no'] ?? $wfForm->current_step_no ?? 0) === 2;
    }

    private static function isPurchaseDepartment(int $deptId, ?string $appCode = null): bool
    {
        foreach (self::departmentLineage($deptId, $appCode) as $candidateDeptId) {
            $department = WorkflowDb::table($appCode, 'departments')
                ->where('id', $candidateDeptId)
                ->first(['name', 'code']);

            if (!$department) {
                continue;
            }

            $text = strtolower(trim((string) ($department->name ?? '') . ' ' . (string) ($department->code ?? '')));
            $code = strtolower(trim((string) ($department->code ?? '')));

            if (str_contains($text, 'purchase')
                || str_contains($text, 'purchasing')
                || str_contains($text, 'จัดซื้อ')
                || in_array($code, ['pur', 'purch', 'purchase'], true)
                || str_starts_with($code, 'pur')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function resolveDepartmentContext(object $rule, object $wfForm, array $context, array $json): int
    {
        if (!(int) ($rule->department_scoped ?? 0)) {
            return (int) ($json['department_id'] ?? 0);
        }

        $contextKey = (string) ($json['department_context'] ?? '');
        if ($contextKey !== '' && isset($context[$contextKey])) {
            return (int) $context[$contextKey];
        }

        $appCode = strtolower((string) ($wfForm->app_code ?? ''));
        $stepNo = (int) ($context['workflow_step_no'] ?? $wfForm->current_step_no ?? 0);
        $sourceType = strtoupper((string) ($rule->source_type ?? ''));

        if ($appCode === 'po' && $stepNo === 2 && $sourceType === 'SUPERVISOR') {
            return (int) ($context['submitter_department_id'] ?? $context['purchase_department_id'] ?? $context['department_id'] ?? 0);
        }

        if ($appCode === 'po' && $stepNo === 3) {
            return (int) ($context['document_department_id'] ?? $context['department_id'] ?? 0);
        }

        if ($sourceType === 'ROLE' && isset($json['role_in']) && (int) ($rule->source_ref_id ?? 0) > 0) {
            return (int) $rule->source_ref_id;
        }

        return (int) ($context['department_id'] ?? 0);
    }

    private static function byDeptRole(int $deptId, int $roleId, ?int $excludeUserId = null, ?string $appCode = null): Collection
    {
        if ($roleId <= 0) {
            return collect();
        }

        foreach (self::departmentLineage($deptId, $appCode) as $candidateDeptId) {
            $users = self::queryDeptRoleUsers($candidateDeptId, $excludeUserId, $appCode)
                ->where('dr.id', $roleId)
                ->orderByDesc('dru.is_primary')
                ->orderByDesc('dru.start_date')
                ->pluck('u.id');

            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        return collect();
    }

    private static function byDeptRoleNames(
        int $deptId,
        array $roleNames,
        ?int $excludeUserId = null,
        ?string $appCode = null,
        bool $includeChildren = false
    ): Collection
    {
        $roleNames = collect($roleNames)
            ->map(fn($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();

        if (empty($roleNames)) {
            return collect();
        }

        foreach (self::departmentSearchOrder($deptId, $appCode, $includeChildren) as $candidateDeptId) {
            $users = self::queryDeptRoleUsers($candidateDeptId, $excludeUserId, $appCode)
                ->whereIn('dr.name', $roleNames)
                ->orderByDesc('dru.is_primary')
                ->orderByDesc('dru.start_date')
                ->pluck('u.id');
            //dd($users);
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        return collect();
    }

    private static function departmentSearchOrder(int $deptId, ?string $appCode = null, bool $includeChildren = false): array
    {
        if (!$includeChildren) {
            return self::departmentLineage($deptId, $appCode);
        }

        return collect([$deptId])
            ->merge(self::departmentDescendants($deptId, $appCode))
            ->merge(self::departmentLineage($deptId, $appCode))
            ->unique()
            ->values()
            ->all();
    }

    private static function queryDeptRoleUsers(int $deptId, ?int $excludeUserId = null, ?string $appCode = null)
    {
        $today = now()->toDateString();

        $query = WorkflowDb::table($appCode, 'department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users as u', 'u.id', '=', 'dru.user_id')
            ->where('dr.department_id', $deptId)
            ->where('dr.is_active', 1)
            ->whereDate('dru.start_date', '<=', $today)
            ->where(function ($where) use ($today) {
                $where->whereNull('dru.end_date')->orWhere('dru.end_date', '>=', $today);
            })
            ->where('u.is_active', 1);
        //dd($query);
        if ($excludeUserId) {
            $query->where('u.id', '!=', $excludeUserId);
        }

        return $query;
    }

    private static function departmentLineage(int $deptId, ?string $appCode = null): array
    {
        if ($deptId <= 0) {
            return [];
        }

        $lineage = [];
        $visited = [];
        $currentId = $deptId;

        while ($currentId > 0 && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $lineage[] = $currentId;

            $currentId = (int) (WorkflowDb::table($appCode, 'departments')
                ->where('id', $currentId)
                ->value('parent_id') ?? 0);
        }

        return $lineage;
    }

    private static function departmentDescendants(int $deptId, ?string $appCode = null): array
    {
        if ($deptId <= 0) {
            return [];
        }

        $descendants = [];
        $queue = [$deptId];
        $visited = [$deptId => true];

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $childIds = WorkflowDb::table($appCode, 'departments')
                ->where('parent_id', $currentId)
                ->pluck('id');

            foreach ($childIds as $childId) {
                $childId = (int) $childId;
                if ($childId <= 0 || isset($visited[$childId])) {
                    continue;
                }

                $visited[$childId] = true;
                $descendants[] = $childId;
                $queue[] = $childId;
            }
        }

        return $descendants;
    }

    private static function json($str): array
    {
        if (!$str) {
            return [];
        }

        try {
            return (array) json_decode($str, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
