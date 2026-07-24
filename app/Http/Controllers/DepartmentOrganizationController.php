<?php

namespace App\Http\Controllers;

use App\Support\SqlServerDb;
use Illuminate\Http\Request;

class DepartmentOrganizationController extends Controller
{
    public function index(Request $request)
    {
        $departments = SqlServerDb::table('departments')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'site_code', 'cost_center']);

        $requestedDepartmentId = (int) $request->query('department_id', 0);
        $selectedDepartment = $departments->firstWhere('id', $requestedDepartmentId)
            ?? $departments->first();

        $roles = collect();
        $membersByRole = collect();
        $unassignedMembers = collect();
        $levels = collect();
        $employeeCount = 0;
        $vacantPositionCount = 0;

        if ($selectedDepartment) {
            $departmentId = (int) $selectedDepartment->id;
            $today = now()->toDateString();

            $roles = SqlServerDb::table('department_roles')
                ->where('department_id', $departmentId)
                ->where('is_active', 1)
                ->orderByRaw('TRY_CONVERT(int, level_no) DESC')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'level_no'])
                ->map(function ($role) {
                    $role->level_no = (int) ($role->level_no ?? 0);
                    return $role;
                });

            $assignedMembers = SqlServerDb::table('department_role_users as dru')
                ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
                ->join('users as u', 'u.id', '=', 'dru.user_id')
                ->leftJoin('users as supervisor', 'supervisor.id', '=', 'u.supervisor_user_id')
                ->where('u.department_id', $departmentId)
                ->where('u.is_active', 1)
                ->where('dr.department_id', $departmentId)
                ->where('dr.is_active', 1)
                ->where('dru.is_primary', 1)
                ->where('dru.start_date', '<=', $today)
                ->where(function ($query) use ($today) {
                    $query->whereNull('dru.end_date')
                        ->orWhere('dru.end_date', '>=', $today);
                })
                ->orderByDesc('dru.start_date')
                ->orderBy('u.name')
                ->get([
                    'u.id',
                    'u.user_code',
                    'u.name',
                    'u.email',
                    'u.supervisor_user_id',
                    'dr.id as role_id',
                    'dr.code as role_code',
                    'dr.name as role_name',
                    'dr.level_no',
                    'supervisor.name as supervisor_name',
                ])
                ->unique('id')
                ->values();

            $membersByRole = $assignedMembers->groupBy(fn ($member) => (int) $member->role_id);
            $assignedUserIds = $assignedMembers->pluck('id')->map(fn ($id) => (int) $id)->all();

            $unassignedMembers = SqlServerDb::table('users')
                ->where('department_id', $departmentId)
                ->where('is_active', 1)
                ->when(
                    $assignedUserIds !== [],
                    fn ($query) => $query->whereNotIn('id', $assignedUserIds)
                )
                ->orderBy('name')
                ->get(['id', 'user_code', 'name', 'email']);

            $levels = $roles
                ->groupBy(fn ($role) => (int) $role->level_no)
                ->sortKeysDesc();

            $employeeCount = $assignedMembers->count() + $unassignedMembers->count();
            $vacantPositionCount = $roles
                ->filter(fn ($role) => $membersByRole->get((int) $role->id, collect())->isEmpty())
                ->count();
        }

        return view('organization.index', [
            'departments' => $departments,
            'selectedDepartment' => $selectedDepartment,
            'roles' => $roles,
            'membersByRole' => $membersByRole,
            'unassignedMembers' => $unassignedMembers,
            'levels' => $levels,
            'employeeCount' => $employeeCount,
            'vacantPositionCount' => $vacantPositionCount,
            'levelPresentation' => [
                4 => ['label' => 'ผู้บริหารระดับสูง', 'class' => 'executive'],
                3 => ['label' => 'ผู้จัดการ', 'class' => 'manager'],
                2 => ['label' => 'หัวหน้างาน', 'class' => 'supervisor'],
                1 => ['label' => 'พนักงานและเจ้าหน้าที่', 'class' => 'staff'],
                0 => ['label' => 'ระดับอื่น ๆ', 'class' => 'other'],
            ],
        ]);
    }
}
