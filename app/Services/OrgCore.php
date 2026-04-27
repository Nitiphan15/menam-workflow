<?php

namespace App\Services;

use App\Models\Users\User;
use App\Models\Users\DepartmentRole;
use App\Support\SqlServerDb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class OrgCore
{
    /** Insert บทบาทในแผนกให้ user (กำหนดด้วย department + level_no) */
    public static function assignRoleByLevel(
        int $userId,
        int $departmentId,
        int $levelNo,
        bool $isPrimary = true,
        ?string $startDate = null,
        ?string $endDate = null
    ): void {
        $roleId = DepartmentRole::where('department_id', $departmentId)
            ->level($levelNo)
            ->value('id');

        if (!$roleId) {
            throw ValidationException::withMessages([
                'department_role_id' => "ไม่พบ role ของแผนกนี้ (level {$levelNo})",
            ]);
        }

        SqlServerDb::table('department_role_users')->updateOrInsert(
            [
                'department_role_id' => $roleId,
                'user_id'            => $userId,
                'start_date'         => $startDate ?: now()->toDateString(),
            ],
            [
                'is_primary' => $isPrimary ? 1 : 0,
                'end_date'   => $endDate,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /** รายชื่อ Supervisor (lv2) ของ “แผนกของ originator” ณ วันนี้ */
    public static function supervisorsFor(User $originator): Collection
    {
        return self::usersByDeptLevelNow($originator->department_id, 2);
    }

    /** รายชื่อ Manager (lv3) ของ “แผนกของ originator” ณ วันนี้ */
    public static function managersFor(User $originator): Collection
    {
        return self::usersByDeptLevelNow($originator->department_id, 3);
    }

    public static function pickDepartmentSupervisorId(int $departmentId): ?int
    {
        $today = Carbon::today()->toDateString();

        $row = SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users as u', 'u.id', '=', 'dru.user_id')
            ->where('dr.department_id', $departmentId)
            ->where('dr.level_no', 2)                   // ← lv2 = Supervisor
            ->where('dr.is_active', 1)
            ->whereDate('dru.start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('dru.end_date')->orWhereDate('dru.end_date', '>=', $today);
            })
            ->where('u.is_active', 1)
            ->orderByDesc('dru.is_primary')
            ->orderByDesc('dru.start_date')
            ->select('u.id')
            ->first();

        return $row?->id;
    }

    /** รายชื่อทั้งหมดของแผนก (users ในแผนกนั้น ณ ปัจจุบัน) */
    public static function membersInDepartment(int $departmentId, bool $onlyActive = true): Collection
    {
        return User::query()
            ->when($onlyActive, fn($q) => $q->where('is_active', 1))
            ->where('department_id', $departmentId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id']);
    }

    /** รายชื่อแผนก แบ่งตาม level (1/2/3) ณ วันนี้ */
    public static function membersInDepartmentGroupedByLevel(int $departmentId): array
    {
        $rows = SqlServerDb::table('department_role_users AS dru')
            ->join('department_roles AS dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users AS u', 'u.id', '=', 'dru.user_id')
            ->where('dr.department_id', $departmentId)
            ->whereDate('dru.start_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('dru.end_date')->orWhereDate('dru.end_date', '>=', now()->toDateString());
            })
            ->where('u.is_active', 1)
            ->orderBy('dr.level_no')->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email', 'dr.level_no', 'dr.code as role_code']);

        return [
            'lv1_staff' => $rows->where('level_no', 1)->values(),
            'lv2_sup'   => $rows->where('level_no', 2)->values(),
            'lv3_mng'   => $rows->where('level_no', 3)->values(),
        ];
    }

    public static function ensureUserSupervisor(User $user): User
    {
        if (!$user->department_id) {
            return $user; // ไม่มีแผนก ก็ตั้งหัวหน้าไม่ได้
        }

        // ถ้ามี supervisor อยู่แล้วและยัง active ก็ไม่ยุ่ง
        if ($user->supervisor_user_id) {
            $ok = SqlServerDb::table('users')->where('id', $user->supervisor_user_id)->where('is_active', 1)->exists();
            if ($ok) return $user;
        }

        // หา supervisor จากบทบาทแผนก
        $supId = self::pickDepartmentSupervisorId((int)$user->department_id);

        if ($supId && $supId !== $user->id) {
            SqlServerDb::table('users')->where('id', $user->id)->update([
                'supervisor_user_id' => $supId,
                'updated_at'         => now(),
            ]);
            $user->supervisor_user_id = $supId;
        }

        return $user;
    }

    /** helper: หา user ตาม department + level ณ วันนี้ */
    protected static function usersByDeptLevelNow(int $departmentId, int $levelNo): Collection
    {
        return SqlServerDb::table('department_role_users AS dru')
            ->join('department_roles AS dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users AS u', 'u.id', '=', 'dru.user_id')
            ->where('dr.department_id', $departmentId)
            ->where('dr.level_no', $levelNo)
            ->whereDate('dru.start_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('dru.end_date')->orWhereDate('dru.end_date', '>=', now()->toDateString());
            })
            ->where('u.is_active', 1)
            ->orderByDesc('dru.is_primary')
            ->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email', 'dru.is_primary']);
    }

    public static function approversByDeptRole(int $deptId, int $roleId)
    {
        $today = now()->toDateString();
        return SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users as u', 'u.id', '=', 'dru.user_id')
            ->where('dr.department_id', $deptId)
            ->where('dr.id', $roleId)
            ->where('dr.is_active', 1)
            ->whereDate('dru.start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('dru.end_date')->orWhere('dru.end_date', '>=', $today);
            })
            ->where('u.is_active', 1)
            ->orderByDesc('dru.is_primary')->orderByDesc('dru.start_date')
            ->get(['u.id', 'u.name']);
    }

    public static function anyoneInDepartment(int $deptId)
    {
        return SqlServerDb::table('users')->where('department_id', $deptId)->where('is_active', 1)->get(['id', 'name']);
    }


    // App/Services/OrgCore.php (เติม methods ด้านล่าง)
    public static function departmentIdsManagedBy(int $userId): array
    {
        $today = now()->toDateString();
        return SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->where('dru.user_id', $userId)
            ->where('dr.level_no', 3)                // Manager
            ->where('dr.is_active', 1)
            ->whereDate('dru.start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('dru.end_date')->orWhere('dru.end_date', '>=', $today);
            })
            ->pluck('dr.department_id')->unique()->values()->all();
    }

    public static function isHR(int $userId): bool
    {
        $today = now()->toDateString();
        return SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->where('dru.user_id', $userId)
            ->where(function ($q) {
                $q->where('dr.code', 'HR')->orWhereIn('dr.name', ['HR', 'HR Admin']);
            })
            ->where('dr.is_active', 1)
            ->whereDate('dru.start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('dru.end_date')->orWhere('dru.end_date', '>=', $today);
            })
            ->exists();
    }

    /** แผนกที่ HR คนนี้รับผิดชอบ (ถ้าอยากให้ HR เห็นทุกแผนก ให้คืนค่าทุก department) */
    public static function hrScopeDepartmentIds(int $userId): array
    {
        if (self::isHR($userId)) {
            // เลือกทั้งหมด แล้วให้ HR กรองเองในหน้าเว็บด้วย dropdown
            return SqlServerDb::table('departments')->pluck('id')->all();
        }
        return [];
    }
}
