<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\Department;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;

class UserDeptRoleAdminController extends Controller
{
    public function index(Request $r)
    {
        $row = null;
        $departments = Department::orderBy('code')->get(['id', 'code', 'name']);
        $deptId = (int) ($r->query('dept_id') ?: ($departments->first()->id ?? 0));
        $q = trim((string) $r->query('q', ''));

        $roles = SqlServerDb::table('department_roles')
            ->where('department_id', $deptId)
            ->where('is_active', 1)
            ->orderBy('level_no')
            ->orderBy('name')
            ->get();

        $items = $this->roleUserQuery($deptId, $q)
            ->paginate(12)
            ->withQueryString();

        return view('adminweb.udr.index', compact('departments', 'deptId', 'roles', 'items', 'q', 'row'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'department_id'      => 'required|exists:sqlsrv_menam.departments,id',
            'department_role_id' => 'required|exists:sqlsrv_menam.department_roles,id',
            'user_id'            => 'required|exists:sqlsrv_menam.users,id',
            'is_primary'         => 'required|boolean',
            'start_date'         => 'required|date',
            'end_date'           => 'nullable|date|after_or_equal:start_date',
        ]);

        $exists = SqlServerDb::table('department_role_users')->where([
            'user_id'            => $data['user_id'],
            'department_role_id' => $data['department_role_id'],
            'start_date'         => $data['start_date'],
        ])->exists();

        abort_if($exists, 422, 'This user already has this role on the selected start date.');

        SqlServerDb::transaction(function () use ($data) {
            SqlServerDb::table('department_role_users')->insert($data + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncPrimaryRoleUser($data);
        });

        return back()->with('ok', 'บันทึกการผูกผู้ใช้กับตำแหน่งแล้ว');
    }

    public function edit($id, Request $r)
    {
        $departments = Department::orderBy('code')->get(['id', 'code', 'name']);
        $row = SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users as u', 'u.id', '=', 'dru.user_id')
            ->where('dru.id', $id)
            ->select(
                'dru.*',
                'dr.department_id',
                'dr.name as role_name',
                'dr.code as role_code',
                'u.name',
                'u.email',
                'u.user_code'
            )
            ->first();
        abort_unless($row, 404);

        $deptId = (int) ($r->query('dept_id') ?: $row->department_id ?: ($departments->first()->id ?? 0));
        $q = trim((string) $r->query('q', ''));

        $roles = SqlServerDb::table('department_roles')
            ->where('department_id', $deptId)
            ->where('is_active', 1)
            ->orderBy('level_no')
            ->orderBy('name')
            ->get();

        $items = $this->roleUserQuery($deptId, $q)
            ->paginate(12)
            ->withQueryString();

        return view('adminweb.udr.index', compact('departments', 'deptId', 'roles', 'items', 'q', 'row'));
    }

    public function destroy($id)
    {
        $row = SqlServerDb::table('department_role_users')->where('id', $id)->first();
        abort_unless($row, 404);

        SqlServerDb::table('department_role_users')->where('id', $id)->delete();

        return back()->with('ok', 'ลบรายการผูกผู้ใช้กับตำแหน่งแล้ว');
    }

    private function roleUserQuery(int $deptId, string $q)
    {
        return SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->join('users as u', 'u.id', '=', 'dru.user_id')
            ->where('dr.department_id', $deptId)
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('u.name', 'like', "%$q%")
                    ->orWhere('u.email', 'like', "%$q%")
                    ->orWhere('u.user_code', 'like', "%$q%")
                    ->orWhere('dr.name', 'like', "%$q%");
            }))
            ->orderBy('dr.level_no')
            ->orderByDesc('dru.is_primary')
            ->orderByDesc('dru.start_date')
            ->orderBy('u.name')
            ->select(
                'dru.*',
                'u.name',
                'u.email',
                'u.user_code',
                'dr.name as role_name',
                'dr.code as role_code'
            );
    }

    private function syncPrimaryRoleUser(array $data): void
    {
        if (!$data['is_primary']) {
            return;
        }

        $today = now()->toDateString();
        $inEffect = ($data['start_date'] <= $today)
            && (empty($data['end_date']) || $data['end_date'] >= $today);

        if (!$inEffect) {
            return;
        }

        SqlServerDb::table('department_role_users')
            ->where('department_role_id', $data['department_role_id'])
            ->where('user_id', '!=', $data['user_id'])
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->update([
                'is_primary' => 0,
                'updated_at' => now(),
            ]);
    }
}
