<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\Department;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;

class DeptManagerAdminController extends Controller
{
    public function index(Request $r)
    {
        $departments = Department::orderBy('code')->get(['id', 'code', 'name']);
        $deptId = (int) ($r->query('dept_id') ?: ($departments->first()->id ?? 0));

        $list = SqlServerDb::table('department_managers as dm')
            ->join('users as u', 'u.id', '=', 'dm.user_id')
            ->where('dm.department_id', $deptId)
            ->orderByDesc('dm.is_primary')
            ->orderByDesc('dm.start_date')
            ->select([
                'dm.id',
                'dm.department_id',
                'dm.user_id',
                'dm.start_date',
                'dm.end_date',
                'dm.is_primary',
                'u.name',
                'u.email',
            ])
            ->paginate(10);

        return view('adminweb.deptmng.index', compact('departments', 'deptId', 'list'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'department_id' => 'required|exists:sqlsrv_menam.departments,id',
            'user_id'       => 'required|exists:sqlsrv_menam.users,id',
            'start_date'    => 'required|date',
            'end_date'      => 'nullable|date|after_or_equal:start_date',
            'is_primary'    => 'required|boolean',
        ]);

        SqlServerDb::transaction(function () use ($data) {
            $exists = SqlServerDb::table('department_managers')->where([
                'department_id' => $data['department_id'],
                'user_id'       => $data['user_id'],
                'start_date'    => $data['start_date'],
            ])->exists();

            abort_if($exists, 422, 'This manager assignment already exists for the selected start date.');

            SqlServerDb::table('department_managers')->insert($data + ['created_at' => now()]);

            if ($data['is_primary']) {
                $this->clearOtherCurrentPrimaries($data);
            }

            $this->syncCurrentManager((int) $data['department_id']);
        });

        return redirect()
            ->route('adminweb.deptmgr.index', ['dept_id' => $data['department_id']])
            ->with('ok', 'บันทึกผู้จัดการแผนกแล้ว');
    }

    public function destroy($id)
    {
        $row = SqlServerDb::table('department_managers')->where('id', $id)->first();
        abort_unless($row, 404);

        SqlServerDb::transaction(function () use ($id, $row) {
            SqlServerDb::table('department_managers')->where('id', $id)->delete();
            $this->syncCurrentManager((int) $row->department_id);
        });

        return redirect()
            ->route('adminweb.deptmgr.index', ['dept_id' => $row->department_id])
            ->with('ok', 'ลบผู้จัดการแผนกแล้ว');
    }

    private function clearOtherCurrentPrimaries(array $data): void
    {
        $today = now()->toDateString();
        $inEffect = ($data['start_date'] <= $today)
            && (empty($data['end_date']) || $data['end_date'] >= $today);

        if (!$inEffect) {
            return;
        }

        SqlServerDb::table('department_managers')
            ->where('department_id', $data['department_id'])
            ->where('user_id', '!=', $data['user_id'])
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->update(['is_primary' => 0]);
    }

    private function syncCurrentManager(int $departmentId): void
    {
        $today = now()->toDateString();

        $current = SqlServerDb::table('department_managers')
            ->where('department_id', $departmentId)
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->orderByDesc('is_primary')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        SqlServerDb::table('departments')
            ->where('id', $departmentId)
            ->update([
                'manager_user_id' => $current->user_id ?? null,
                'updated_at'      => now(),
            ]);
    }
}
