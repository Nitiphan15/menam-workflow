<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PoApproverMasterController extends Controller
{
    private const TABLE = 'po_department_approvers';

    public function index(Request $request)
    {
        $departmentId = max(0, (int) $request->query('department_id', 0));

        $departments = SqlServerDb::table('departments')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $users = SqlServerDb::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.is_active', 1)
            ->whereNotNull('u.department_id')
            ->orderBy('u.name')
            ->get([
                'u.id',
                'u.username',
                'u.email',
                'u.name',
                'd.name as department_name',
            ]);

        $baseRows = $this->mappingQuery()
            ->where('m.sequence_no', 1)
            ->when($departmentId > 0, fn ($query) => $query->where('m.department_id', $departmentId))
            ->orderBy('d.name')
            ->get();

        $specialRows = $this->mappingQuery()
            ->where('m.sequence_no', '>=', 2)
            ->when($departmentId > 0, fn ($query) => $query->where('m.department_id', $departmentId))
            ->orderBy('d.name')
            ->orderBy('m.sequence_no')
            ->get();

        return view('po.approver_master', compact(
            'departments',
            'users',
            'baseRows',
            'specialRows',
            'departmentId',
        ));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->assertMappingIsAvailable($data);

        SqlServerDb::transaction(function () use ($data) {
            $this->ensurePoPermission((int) $data['approver_user_id']);
            SqlServerDb::table(self::TABLE)->insert([
                ...$data,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('ok', 'เพิ่มผู้อนุมัติพิเศษของ PO แล้ว');
    }

    public function update(Request $request, int $mapping)
    {
        $row = SqlServerDb::table(self::TABLE)
            ->where('id', $mapping)
            ->where('sequence_no', '>=', 2)
            ->first();
        abort_unless($row, 404);

        $data = $this->validated($request);
        $this->assertMappingIsAvailable($data, $mapping);

        SqlServerDb::transaction(function () use ($data, $mapping) {
            $this->ensurePoPermission((int) $data['approver_user_id']);
            SqlServerDb::table(self::TABLE)
                ->where('id', $mapping)
                ->update([...$data, 'updated_at' => now()]);
        });

        return back()->with('ok', 'แก้ไขผู้อนุมัติพิเศษของ PO แล้ว');
    }

    public function destroy(int $mapping)
    {
        $updated = SqlServerDb::table(self::TABLE)
            ->where('id', $mapping)
            ->where('sequence_no', '>=', 2)
            ->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);
        abort_if($updated === 0, 404);

        return back()->with('ok', 'ปิดผู้อนุมัติพิเศษของ PO แล้ว');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer'],
            'approver_user_id' => ['required', 'integer'],
            'sequence_no' => ['required', 'integer', 'min:2', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $departmentExists = SqlServerDb::table('departments')
            ->where('id', (int) $data['department_id'])
            ->where('is_active', 1)
            ->exists();
        $userExists = SqlServerDb::table('users')
            ->where('id', (int) $data['approver_user_id'])
            ->where('is_active', 1)
            ->whereNotNull('department_id')
            ->exists();

        if (!$departmentExists || !$userExists) {
            $messages = [];
            if (!$departmentExists) {
                $messages['department_id'] = 'ไม่พบแผนกที่ active';
            }
            if (!$userExists) {
                $messages['approver_user_id'] = 'ไม่พบผู้ใช้ที่ active หรือผู้ใช้ยังไม่มีแผนก';
            }
            throw ValidationException::withMessages($messages);
        }

        return [
            'department_id' => (int) $data['department_id'],
            'approver_user_id' => (int) $data['approver_user_id'],
            'sequence_no' => (int) $data['sequence_no'],
            'is_active' => (int) ($data['is_active'] ?? 0),
        ];
    }

    private function assertMappingIsAvailable(array $data, ?int $exceptId = null): void
    {
        $slotExists = SqlServerDb::table(self::TABLE)
            ->where('department_id', $data['department_id'])
            ->where('sequence_no', $data['sequence_no'])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        $userExists = SqlServerDb::table(self::TABLE)
            ->where('department_id', $data['department_id'])
            ->where('approver_user_id', $data['approver_user_id'])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($slotExists || $userExists) {
            $messages = [];
            if ($slotExists) {
                $messages['sequence_no'] = 'ลำดับนี้ถูกใช้แล้ว กรุณาแก้ไขรายการเดิมหรือเลือกลำดับอื่น';
            }
            if ($userExists) {
                $messages['approver_user_id'] = 'ผู้ใช้นี้อยู่ในรายการอนุมัติของแผนกแล้ว';
            }
            throw ValidationException::withMessages($messages);
        }
    }

    private function ensurePoPermission(int $userId): void
    {
        $user = SqlServerDb::table('users')->where('id', $userId)->first(['id', 'department_id']);
        $poRoleId = SqlServerDb::table('dept_roles')
            ->where('code', 'PO')
            ->where('is_active', 1)
            ->value('id');

        if (!$user || !(int) $user->department_id || !$poRoleId) {
            throw ValidationException::withMessages([
                'approver_user_id' => 'ไม่สามารถเพิ่มสิทธิ์ PO ให้ผู้ใช้นี้ได้',
            ]);
        }

        $exists = SqlServerDb::table('user_dept_roles')
            ->where('user_id', $userId)
            ->where('department_id', (int) $user->department_id)
            ->where('role_id', (int) $poRoleId)
            ->exists();

        if (!$exists) {
            SqlServerDb::table('user_dept_roles')->insert([
                'user_id' => $userId,
                'department_id' => (int) $user->department_id,
                'role_id' => (int) $poRoleId,
            ]);
        }
    }

    private function mappingQuery()
    {
        return SqlServerDb::table(self::TABLE . ' as m')
            ->join('departments as d', 'd.id', '=', 'm.department_id')
            ->join('users as u', 'u.id', '=', 'm.approver_user_id')
            ->select([
                'm.id',
                'm.department_id',
                'm.approver_user_id',
                'm.sequence_no',
                'm.is_active',
                'd.code as department_code',
                'd.name as department_name',
                'u.username',
                'u.email',
                'u.name as approver_name',
            ]);
    }
}
