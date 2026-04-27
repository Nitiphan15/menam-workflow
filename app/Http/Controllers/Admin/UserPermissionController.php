<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use App\Models\Users\DeptRole;
use Illuminate\Http\Request;
use App\Support\SqlServerDb;

class UserPermissionController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));

        $users = User::query()
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

        $roles = DeptRole::query()
            ->where('is_active', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $selectedRoleIds = SqlServerDb::table('user_dept_roles')
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->pluck('role_id')
            ->map(fn($v) => (int) $v)
            ->toArray();

        return view('adminweb.user_permissions.edit', compact(
            'user',
            'departmentId',
            'roles',
            'selectedRoleIds'
        ));
    }

    public function update(Request $request, User $user)
    {
        $departmentId = $user->department_id;

        $validated = $request->validate([
            'role_ids'   => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:sqlsrv_menam.dept_roles,id'],
        ]);

        $roleIds = collect($validated['role_ids'] ?? [])
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
