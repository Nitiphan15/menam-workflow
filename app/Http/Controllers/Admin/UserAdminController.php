<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use App\Models\Users\Department;
use App\Services\OrgCore;
use App\Support\SqlServerDb;

class UserAdminController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string)$r->query('q', ''));
        $departments = Department::where('is_active', 1)
            ->orderBy('code')->get(['id', 'code', 'name']);
        $webRoles = SqlServerDb::table('dept_roles')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $users = User::query()
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('username', 'like', "%$q%")
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
            'password'              => 'required|string|min:4|confirmed',
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
                'username'              => User::uniqueUsernameForName($data['name']),
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

            // สิทธิ์การใช้งานเว็บ (ตาม schema ปัจจุบันคุณใช้ user_dept_roles)
            if (!empty($roleIds) && $deptId > 0) {
                $roleIds = collect($roleIds)
                    ->map(fn($rid) => (int) $rid)
                    ->filter(fn($rid) => $rid > 0)
                    ->unique()
                    ->values();

                $existingRoleIds = SqlServerDb::table('user_dept_roles')
                    ->where('user_id', $user->id)
                    ->where('department_id', $deptId)
                    ->pluck('role_id')
                    ->map(fn($rid) => (int) $rid);

                $rows = $roleIds
                    ->reject(fn($rid) => $existingRoleIds->contains($rid))
                    ->map(fn($rid) => [
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

    public function edit(User $user)
    {
        return view('adminweb.users.edit', compact('user'));
    }

    public function update(Request $r, User $user)
    {
        $data = $r->validate([
            'user_code' => 'nullable|string|max:50|unique:sqlsrv_menam.users,user_code,' . $user->id,
            'name'      => 'required|string|max:200',
            'email'     => 'required|email|max:255|unique:sqlsrv_menam.users,email,' . $user->id,
            'phone'     => 'nullable|string|max:50',
            'is_active' => 'required|boolean',
            'password'  => 'nullable|string|min:4|confirmed',
        ]);

        $update = [
            'user_code' => $data['user_code'] ?? null,
            'username'  => User::uniqueUsernameForName($data['name'], (int) $user->id),
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'is_active' => $data['is_active'],
        ];

        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        $user->update($update);

        return redirect()
            ->route('adminweb.users.register')
            ->with('ok', 'แก้ไขข้อมูลผู้ใช้แล้ว');
    }

    public function destroy(Request $r, User $user)
    {
        if ((int) $user->id === (int) $r->user()->id) {
            return back()->with('error', 'ไม่สามารถลบบัญชีของตัวเองได้');
        }

        // ตารางที่ FK อ้างถึง users และ "ห้ามลบทิ้ง" — ถ้ามีข้อมูลผูกอยู่ให้ปิดใช้งานแทน
        $blockers = [
            ['wf_forms',             'request_by_user_id',  'เอกสาร workflow'],
            ['wf_form_authorizes',   'approver_user_id',    'สิทธิ์อนุมัติ workflow'],
            ['wf_action_histories',  'actor_user_id',       'ประวัติการอนุมัติ'],
            ['ev360_subjects',       'user_id',             'ข้อมูลผู้ถูกประเมิน 360'],
            ['ev360_subjects',       'created_by',          'ข้อมูลประเมิน 360'],
            ['ev360_evaluators',     'user_id',             'ข้อมูลผู้ประเมิน 360'],
            ['ev360_evaluators',     'created_by',          'ข้อมูลประเมิน 360'],
            ['ev360_action_history', 'performed_by',        'ประวัติการประเมิน 360'],
            ['ev360_cycles',         'created_by',          'รอบประเมิน 360'],
            ['ev360_cycles',         'closed_by',           'รอบประเมิน 360'],
            ['ev360_cycles',         'result_released_by',  'รอบประเมิน 360'],
            ['ev360_forms',          'created_by',          'ฟอร์มประเมิน 360'],
        ];

        foreach ($blockers as [$table, $col, $label]) {
            if (SqlServerDb::table($table)->where($col, $user->id)->exists()) {
                return back()->with(
                    'error',
                    "ลบไม่ได้: ผู้ใช้ \"{$user->name}\" มี{$label}อยู่ในระบบ ให้ปิดสถานะใช้งาน (Inactive) แทน"
                );
            }
        }

        try {
            SqlServerDb::transaction(function () use ($user) {
                // เคลียร์ข้อมูลผูกที่ลบได้ปลอดภัยก่อน กัน FK violation
                SqlServerDb::table('department_role_users')->where('user_id', $user->id)->delete();
                SqlServerDb::table('user_dept_roles')->where('user_id', $user->id)->delete();
                SqlServerDb::table('users')
                    ->where('supervisor_user_id', $user->id)
                    ->update(['supervisor_user_id' => null]);
                SqlServerDb::table('departments')
                    ->where('manager_user_id', $user->id)
                    ->update(['manager_user_id' => null]);

                $user->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            return back()->with('error', 'ลบผู้ใช้ไม่สำเร็จ: มีข้อมูลอื่นผูกกับผู้ใช้นี้อยู่ ให้ปิดสถานะใช้งาน (Inactive) แทน');
        }

        // ลบสำเนาในฐาน MySQL (ระบบเดิม) — id สองฐานอาจไม่ตรงกัน จึงจับคู่จาก user_code/email/ชื่อ แล้วค่อยลบตาม id
        $legacyWarning = null;
        try {
            $legacyId = $this->findLegacyMysqlUserId($user);
            if ($legacyId) {
                DB::connection('mysql')->table('users')->where('id', $legacyId)->delete();
            }
        } catch (\Throwable $e) {
            report($e);
            $legacyWarning = ' (ลบฝั่ง SQL Server แล้ว แต่ลบสำเนาในฐาน MySQL ไม่สำเร็จ)';
        }

        return back()->with(
            $legacyWarning ? 'error' : 'success',
            'ลบผู้ใช้ "' . $user->name . '" แล้ว' . ($legacyWarning ?? '')
        );
    }

    public function toggleActive(Request $r, User $user)
    {
        if ((int) $user->id === (int) $r->user()->id) {
            return back()->with('error', 'ไม่สามารถปิดใช้งานบัญชีของตัวเองได้');
        }

        $user->is_active = $user->is_active ? 0 : 1;
        $user->save();

        // sync สถานะไปยังสำเนาในฐาน MySQL ด้วย (ระบบเมลบางตัวยังอ่าน users จากฝั่ง MySQL)
        $legacyWarning = null;
        try {
            $legacyId = $this->findLegacyMysqlUserId($user);
            if ($legacyId) {
                DB::connection('mysql')->table('users')->where('id', $legacyId)->update([
                    'is_active'  => $user->is_active,
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            $legacyWarning = ' (อัปเดตฝั่ง SQL Server แล้ว แต่ sync ฐาน MySQL ไม่สำเร็จ)';
        }

        $label = $user->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

        return back()->with(
            $legacyWarning ? 'error' : 'success',
            $label . 'ผู้ใช้ "' . $user->name . '" แล้ว' . ($legacyWarning ?? '')
        );
    }

    /** หา id ของสำเนา user ในฐาน MySQL (ระบบเดิม) — id สองฐานอาจไม่ตรงกัน จับคู่จาก user_code/email/ชื่อ */
    private function findLegacyMysqlUserId(User $user): ?int
    {
        $mysql = DB::connection('mysql');
        $legacyId = null;

        if ($user->user_code) {
            $legacyId = $mysql->table('users')->where('user_code', $user->user_code)->value('id');
        }
        if (!$legacyId && $user->email) {
            $legacyId = $mysql->table('users')->where('email', $user->email)->value('id');
        }
        if (!$legacyId) {
            $legacyId = $mysql->table('users')->where('name', $user->name)->value('id');
        }

        return $legacyId ? (int) $legacyId : null;
    }
}
