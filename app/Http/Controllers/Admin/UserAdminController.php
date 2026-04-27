<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use App\Models\Users\Department;
use App\Models\Users\DeptRole;
use App\Services\OrgCore;
use App\Support\SqlServerDb;

class UserAdminController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string)$r->query('q', ''));
        $departments = Department::where('is_active', 1)
            ->orderBy('code')->get(['id', 'code', 'name']);
        $webRoles    = DeptRole::where('is_active', 1)
            ->orderBy('code')->get(['id', 'code', 'name']);

        $users = User::query()
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('email', 'like', "%$q%")
                    ->orWhere('user_code', 'like', "%$q%");
            }))
            ->orderBy('id', 'desc')
            ->paginate(10)->withQueryString();

        return view('adminweb.users.register', compact('departments', 'webRoles', 'users', 'q'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'user_code'             => 'nullable|string|max:50|unique:sqlsrv_menam.users,user_code',
            'name'                  => 'required|string|max:200',
            'email'                 => 'required|email|max:255|unique:sqlsrv_menam.users,email',
            'phone'                 => 'nullable|string|max:50',
            'password'              => 'required|string|min:6|confirmed',
            'department_id'         => 'nullable|exists:sqlsrv_menam.departments,id',
            'department_role_id'    => 'nullable|exists:sqlsrv_menam.department_roles,id',
            'web_roles'             => 'array',
            'web_roles.*'           => 'integer|exists:sqlsrv_menam.dept_roles,id',
            'supervisor_user_id'     => 'nullable|exists:sqlsrv_menam.users,id|different:id',
            'is_active'             => 'required|boolean',
        ]);


        if (!empty($data['department_role_id'])) {
            $ok = SqlServerDb::table('department_roles')
                ->where('id', $data['department_role_id'])
                ->where('department_id', $data['department_id'])
                ->exists();

            abort_unless($ok, 422, 'ตำแหน่ง (department_role_id) ไม่ได้อยู่ในแผนกที่เลือก');
        }

        return SqlServerDb::transaction(function () use ($data) {

            $user = User::create([
                'user_code'             => $data['user_code'] ?? null,
                'name'                  => $data['name'],
                'email'                 => $data['email'],
                'phone'                 => $data['phone'] ?? null,
                'password'              => Hash::make($data['password']),
                'department_id'         => $data['department_id'] ?? null,
                'is_active'             => $data['is_active'],
            ]);

            if (empty($data['supervisor_user_id']) && $user->department_id) {
                OrgCore::ensureUserSupervisor($user);
            }


            $deptId = (int)$data['department_id'];
            $roleIds = $data['web_roles'] ?? [];

            // ✅ ผูก “ตำแหน่งจริงของแผนก” — ใช้ pivot ที่แนะนำ: department_role_users
            if (!empty($data['department_role_id'])) {
                SqlServerDb::table('department_role_users')->insert([
                    'user_id'            => $user->id,
                    'department_role_id' => (int) $data['department_role_id'],
                    'is_primary'         => 1,
                    'start_date'         => now()->toDateString(),
                    'end_date'           => null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }

            // ✅ สิทธิ์การใช้งานเว็บ (ตาม schema ปัจจุบันคุณใช้ user_dept_roles)
            if (!empty($roleIds) && $deptId > 0) {
                $roleIds = collect($roleIds)
                    ->map(fn ($rid) => (int) $rid)
                    ->filter(fn ($rid) => $rid > 0)
                    ->unique()
                    ->values();

                $existingRoleIds = SqlServerDb::table('user_dept_roles')
                    ->where('user_id', $user->id)
                    ->where('department_id', $deptId)
                    ->pluck('role_id')
                    ->map(fn ($rid) => (int) $rid);

                $rows = $roleIds
                    ->reject(fn ($rid) => $existingRoleIds->contains($rid))
                    ->map(fn ($rid) => [
                        'user_id' => $user->id,
                        'department_id' => $deptId,
                        'role_id' => $rid,
                    ])
                    ->values()
                    ->all();

                if (!empty($rows)) {
                    SqlServerDb::table('user_dept_roles')->insert($rows);
                }
            }

            return back()->with('ok', 'เพิ่มผู้ใช้เรียบร้อย');
        });
    }

    public function destroy(User $user)
    {
        $user->delete();
        return back()->with('ok', 'ลบผู้ใช้แล้ว');
    }
}
