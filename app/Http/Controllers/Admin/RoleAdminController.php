<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;

class RoleAdminController extends Controller
{
    public function create(Request $r)
    {

        $q = trim((string)$r->query('q', ''));
        $roles = SqlServerDb::table('dept_roles')
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('code', 'like', "%$q%")
                    ->orWhere('name', 'like', "%$q%");
            }))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();
        $row    = [];
        return view('adminweb.roles.create', compact('roles', 'row', 'q'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'code' => 'required|string|max:50|unique:sqlsrv_menam.dept_roles,code',
            'name' => 'required|string|max:100',
        ]);
        SqlServerDb::table('dept_roles')->insert($data);
        return back()->with('ok', 'เพิ่มตำแหน่งแล้ว');
    }

    public function edit(int $id, Request $r)
    {

        $q = trim((string)$r->query('q', ''));
        $roles = SqlServerDb::table('dept_roles')
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('code', 'like', "%$q%")
                    ->orWhere('name', 'like', "%$q%");
            }))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();
        $row = SqlServerDb::table('dept_roles')->where('id', $id)->first();
        return view('adminweb.roles.create', compact('roles', 'row', 'q'));
    }


    public function update(Request $r, $id)
    {
        //dd($id);

        $data = $r->validate([
            'code'          => 'required|string|max:50',
            'name'          => 'required|string|max:150',
            'is_active'     => 'required|boolean',
        ]);

        $exists = SqlServerDb::table('dept_roles')
            ->where('code', $data['code'])
            ->where('id', '<>', $id)
            ->exists();
        if ($exists) {
            return redirect()
                ->back()
                ->withInput() // ให้ค่าที่กรอกกลับไปในฟอร์ม
                ->withErrors(['code' => 'รหัสซ้ำในแผนกนี้']);
        }

        SqlServerDb::table('dept_roles')->where('id', $id)->update($data);

        return redirect()
            ->route('adminweb.roles.create')
            ->with('ok', 'อัปเดตแล้ว');
    }

    public function destroy($id)
    {
        SqlServerDb::table('dept_roles')->where('id', $id)->delete();

        return back()->with('ok', 'ลบแล้ว');
    }
}
