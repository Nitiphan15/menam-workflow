<?

namespace App\Http\Controllers\AutoComplete;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;

class DeptRoleApiController extends Controller
{
    public function byDept(int $dept)
    {
        $roles = SqlServerDb::table('department_roles')
            ->select('id','code','name','level_no')
            ->where('department_id', $dept)
            ->where('is_active', 1)
            ->orderBy('level_no')
            ->get();

        return response()->json($roles);
    }
}
