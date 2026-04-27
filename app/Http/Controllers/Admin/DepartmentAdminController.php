<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\Department;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentAdminController extends Controller
{
    public function create(Request $r)
    {
        $row = [];

        return view('adminweb.departments.create', $this->buildViewData($r, $row));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'code'        => 'required|string|max:50|unique:sqlsrv_menam.departments,code',
            'name'        => 'required|string|max:200',
            'site_code'   => 'nullable|string|max:50',
            'cost_center' => 'nullable|string|max:50',
            'parent_id'   => 'nullable|exists:sqlsrv_menam.departments,id',
        ]);

        Department::create($data);

        return back()->with('ok', 'เพิ่มแผนกแล้ว');
    }

    public function edit(int $id, Request $r)
    {
        $row = SqlServerDb::table('departments')->where('id', $id)->first();
        abort_unless($row, 404);

        return view('adminweb.departments.create', $this->buildViewData($r, $row));
    }

    public function update(Request $r, $id)
    {
        $data = $r->validate([
            'code'        => 'required|string|max:50',
            'name'        => 'required|string|max:200',
            'site_code'   => 'nullable|string|max:50',
            'cost_center' => 'nullable|string|max:50',
            'parent_id'   => 'nullable|exists:sqlsrv_menam.departments,id',
            'is_active'   => 'required|boolean',
        ]);

        abort_if((int) $id === (int) ($data['parent_id'] ?? 0), 422, 'Department cannot be its own parent.');

        $exists = SqlServerDb::table('departments')
            ->where('code', $data['code'])
            ->where('id', '<>', $id)
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['code' => 'Department code already exists.']);
        }

        SqlServerDb::table('departments')->where('id', $id)->update($data + ['updated_at' => now()]);

        return redirect()
            ->route('adminweb.dept.create')
            ->with('ok', 'อัปเดตแผนกแล้ว');
    }

    public function destroy($id)
    {
        SqlServerDb::table('departments')->where('id', $id)->delete();

        return back()->with('ok', 'ลบแผนกแล้ว');
    }

    private function buildViewData(Request $r, $row): array
    {
        $q = trim((string) $r->query('q', ''));
        $deptId = (int) ($r->query('id') ?? 0);
        $rowId = (int) data_get($row, 'id', 0);

        $parents = Department::orderBy('code')->pluck('name', 'id');
        $headDepartment = Department::query()
            ->when($rowId > 0, fn($qq) => $qq->where('id', '<>', $rowId))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $items = Department::query()
            ->when($q, fn($qq) => $qq->where(function ($w) use ($q) {
                $w->where('code', 'like', "%$q%")
                    ->orWhere('name', 'like', "%$q%")
                    ->orWhere('site_code', 'like', "%$q%")
                    ->orWhere('cost_center', 'like', "%$q%");
            }))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return compact('row', 'parents', 'items', 'deptId', 'q', 'headDepartment');
    }
}
