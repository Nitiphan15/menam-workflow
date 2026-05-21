<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use App\Models\Users\Department;
use Illuminate\Http\Request;
use App\Support\SqlServerDb;

class UserPermissionController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));

        $users = User::query()
            ->with('department:id,code,name')
            ->select(['id', 'user_code', 'name', 'email', 'department_id'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('user_code', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('adminweb.user_permissions.index', compact('users', 'q'));
    }

    public function edit(User $user)
    {
        $departmentId = $user->department_id;
        $department = $departmentId
            ? Department::query()->select(['id', 'code', 'name'])->find($departmentId)
            : null;

        $roles = SqlServerDb::table('dept_roles')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $selectedRoleIds = SqlServerDb::table('user_dept_roles')
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->pluck('role_id')
            ->map(fn($v) => (int) $v)
            ->toArray();

        $selectedRoleCodes = SqlServerDb::table('dept_roles')
            ->whereIn('id', $selectedRoleIds)
            ->pluck('code')
            ->map(fn($v) => (string) $v)
            ->toArray();

        return view('adminweb.user_permissions.edit', compact(
            'user',
            'departmentId',
            'department',
            'roles',
            'selectedRoleIds',
            'selectedRoleCodes'
        ));
    }

    public function update(Request $request, User $user)
    {
        $departmentId = $user->department_id;

        if (!$request->has('role_codes') && $request->has('role_ids')) {
            return back()
                ->withInput()
                ->withErrors([
                    'role_codes' => 'Permission form was opened before the latest fix. Please refresh this page and save again.',
                ]);
        }

        $validated = $request->validate([
            'role_codes'   => ['nullable', 'array'],
            'role_codes.*' => ['string', 'max:50'],
        ]);

        $roleCodes = collect($validated['role_codes'] ?? [])
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values();

        $roles = collect();

        if ($roleCodes->isNotEmpty()) {
            $roles = SqlServerDb::table('dept_roles')
                ->where('is_active', 1)
                ->whereIn('code', $roleCodes->all())
                ->get(['id', 'code']);

            $validRoleCodes = $roles
                ->pluck('code')
                ->map(fn($v) => strtoupper((string) $v));

            $invalidRoleCodes = $roleCodes->diff($validRoleCodes);

            if ($invalidRoleCodes->isNotEmpty()) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'role_ids' => 'Some selected permissions are no longer active or do not exist: '
                            . $invalidRoleCodes->implode(', '),
                    ]);
            }
        }

        $roleIds = $roles
            ->pluck('id')
            ->map(fn($v) => (int) $v)
            ->unique()
            ->values();

        SqlServerDb::transaction(function () use ($user, $departmentId, $roleIds) {
            SqlServerDb::table('user_dept_roles')
                ->where('user_id', $user->id)
                ->where('department_id', $departmentId)
                ->delete();

            if ($roleIds->isNotEmpty()) {
                $rows = $roleIds->map(fn($rid) => [
                    'user_id'       => $user->id,
                    'department_id' => $departmentId,
                    'role_id'       => $rid,
                ])->all();

                SqlServerDb::table('user_dept_roles')->insert($rows);
            }
        });

        return redirect()
            ->route('adminweb.user-permissions.edit', $user->id)
            ->with('success', 'บันทึก permission สำเร็จ');
    }
}
