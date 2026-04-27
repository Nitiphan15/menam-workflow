<?php

namespace App\Http\Controllers\AutoComplete;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;


class DepartmentRoleLookupController extends Controller
{
    public function byDepartment($deptId)
    {
        return SqlServerDb::table('department_roles')
            ->where('department_id', $deptId)
            ->where('is_active', 1)
            ->orderBy('level_no')->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
