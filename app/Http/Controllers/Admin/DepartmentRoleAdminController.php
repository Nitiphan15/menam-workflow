<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\Department;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;

class DepartmentRoleAdminController extends Controller
{
    public function index(Request $r)
    {
        $row = [];
        $q = trim((string)$r->query('q', ''));
        $deptId = (int)($r->query('dept_id') ?? 0);

        $departments = Department::where('is_active', '=', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);


        $items = SqlServerDb::table('department_roles as dr')
            ->join('departments as d', 'd.id', '=', 'dr.department_id')
            ->when($deptId, fn($w) => $w->where('dr.department_id', $deptId))
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('dr.code', 'like', "%$q%")
                    ->orWhere('dr.name', 'like', "%$q%")
                    ->orWhere('d.code', 'like', "%$q%")
                    ->orWhere('d.name', 'like', "%$q%");
            }))
            ->orderBy('d.code')->orderBy('dr.level_no')->orderBy('dr.code')
            ->select('dr.*', 'd.code as dept_code', 'd.name as dept_name')
            ->paginate(12)->withQueryString();

        return view('adminweb.deptroles.index', compact('row', 'departments', 'items', 'deptId', 'q'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'department_id' => 'required|exists:sqlsrv_menam.departments,id',
            'code'          => 'required|string|max:50',
            'name'          => 'required|string|max:150',
            'level_no'      => 'nullable|integer|min:0|max:32767',
            'is_active'     => 'required|boolean',
        ]);

        // กันซ้ำในแผนกเดียวกัน (department_id, code)
        $exists = SqlServerDb::table('department_roles')->where([
            'department_id' => $data['department_id'],
            'code'          => $data['code'],
        ])->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['code' => 'รหัสซ้ำในแผนกนี้']);
        }

        SqlServerDb::table('department_roles')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        return back()->with('ok', 'เพิ่มตำแหน่งของแผนกแล้ว');
    }

    public function edit($id, Request $r)
    {
        $row = SqlServerDb::table('department_roles')->where('id', $id)->first();
        abort_unless($row, 404);

        $q = trim((string) $r->query('q', ''));
        $deptId = (int) $r->query('dept_id', 0);

        $items = SqlServerDb::table('department_roles as dr')
            ->join('departments as d', 'd.id', '=', 'dr.department_id')
            ->when($deptId, fn($w) => $w->where('dr.department_id', $deptId))
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('dr.code', 'like', "%$q%")
                    ->orWhere('dr.name', 'like', "%$q%")
                    ->orWhere('d.code', 'like', "%$q%")
                    ->orWhere('d.name', 'like', "%$q%");
            }))
            ->orderBy('d.code')->orderBy('dr.level_no')->orderBy('dr.code')
            ->select('dr.*', 'd.code as dept_code', 'd.name as dept_name')
            ->paginate(12)->withQueryString();

        $departments = Department::orderBy('code')->get(['id', 'code', 'name']);
        return view('adminweb.deptroles.index', compact('row', 'departments', 'items', 'deptId', 'q'));
    }

    public function update(Request $r, $id)
    {
        $data = $r->validate([
            'department_id' => 'required|exists:sqlsrv_menam.departments,id',
            'code'          => 'required|string|max:50',
            'name'          => 'required|string|max:150',
            'level_no'      => 'nullable|integer|min:0|max:32767',
            'is_active'     => 'required|boolean',
        ]);

        $exists = SqlServerDb::table('department_roles')
            ->where('department_id', $data['department_id'])
            ->where('code', $data['code'])
            ->where('id', '<>', $id)
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['code' => 'รหัสซ้ำในแผนกนี้']);
        }

        SqlServerDb::table('department_roles')->where('id', $id)->update($data + ['updated_at' => now()]);
        return redirect()
            ->route('adminweb.deptroles.index')
            ->with('ok', 'อัปเดตแล้ว');
    }

    public function destroy($id)
    {
        $inUse = SqlServerDb::table('department_role_users')
            ->where('department_role_id', $id)
            ->exists();

        if ($inUse) {
            return back()->withErrors([
                'delete' => 'ลบตำแหน่งไม่ได้: มีพนักงานถูกผูกกับตำแหน่งนี้ (การลบจะทำให้ประวัติการผูกหายไป) — กรุณายกเลิกการผูกในหน้า “ย้ายแผนก/ตำแหน่งพนักงาน” ก่อน',
            ]);
        }

        SqlServerDb::table('department_roles')->where('id', $id)->delete();
        return back()->with('ok', 'ลบแล้ว');
    }
}
