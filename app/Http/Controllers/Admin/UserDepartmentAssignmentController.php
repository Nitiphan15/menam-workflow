<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class UserDepartmentAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $today = now()->toDateString();

        $departments = SqlServerDb::table('departments')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $roles = SqlServerDb::table('department_roles')
            ->where('is_active', 1)
            ->orderBy('department_id')
            ->orderBy('level_no')
            ->orderBy('code')
            ->get(['id', 'department_id', 'code', 'name', 'level_no']);

        /** @var LengthAwarePaginator $users */
        $users = SqlServerDb::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->when($q, fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('u.name', 'like', "%{$q}%")
                    ->orWhere('u.email', 'like', "%{$q}%")
                    ->orWhere('u.user_code', 'like', "%{$q}%");
            }))
            ->orderBy('u.name')
            ->select([
                'u.id',
                'u.user_code',
                'u.name',
                'u.email',
                'u.department_id',
                'u.is_active',
                'd.code as department_code',
                'd.name as department_name',
            ])
            ->paginate(20)
            ->withQueryString();

        $userIds = $users->getCollection()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $currentRoles = empty($userIds)
            ? collect()
            : SqlServerDb::table('department_role_users as dru')
                ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
                ->whereIn('dru.user_id', $userIds)
                ->where('dru.is_primary', 1)
                ->where('dru.start_date', '<=', $today)
                ->where(function ($query) use ($today) {
                    $query->whereNull('dru.end_date')
                        ->orWhere('dru.end_date', '>=', $today);
                })
                ->orderByDesc('dru.start_date')
                ->get([
                    'dru.id',
                    'dru.user_id',
                    'dru.department_role_id',
                    'dru.start_date',
                    'dru.end_date',
                    'dr.department_id as role_department_id',
                    'dr.code as role_code',
                    'dr.name as role_name',
                ])
                ->groupBy(fn ($row) => (int) $row->user_id)
                ->map(fn ($rows) => $rows->first());

        $users->getCollection()->transform(function ($user) use ($currentRoles) {
            $user->current_role = $currentRoles->get((int) $user->id);
            return $user;
        });

        return view('adminweb.user_department_assignments.index', [
            'departments' => $departments,
            'roles' => $roles,
            'users' => $users,
            'q' => $q,
            'today' => $today,
        ]);
    }

    public function update(Request $request, int $userId)
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:sqlsrv_menam.departments,id'],
            'department_role_id' => ['required', 'integer', 'exists:sqlsrv_menam.department_roles,id'],
            'start_date' => ['nullable', 'date'],
        ]);

        $role = SqlServerDb::table('department_roles')
            ->where('id', $data['department_role_id'])
            ->where('department_id', $data['department_id'])
            ->where('is_active', 1)
            ->first();

        if (!$role) {
            return back()->withInput()->withErrors([
                'department_role_id' => 'Selected role is not active or does not belong to the selected department.',
            ]);
        }

        $user = SqlServerDb::table('users')->where('id', $userId)->first();
        abort_unless($user, 404);

        $startDate = $data['start_date'] ?: now()->toDateString();
        $endDate = Carbon::parse($startDate)->subDay()->toDateString();
        $now = now();

        SqlServerDb::transaction(function () use ($userId, $data, $startDate, $endDate, $now) {
            SqlServerDb::table('users')
                ->where('id', $userId)
                ->update([
                    'department_id' => (int) $data['department_id'],
                    'updated_at' => $now,
                ]);

            SqlServerDb::table('department_role_users')
                ->where('user_id', $userId)
                ->where('is_primary', 1)
                ->where(function ($query) use ($startDate) {
                    $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', $startDate);
                })
                ->update([
                    'is_primary' => 0,
                    'end_date' => $endDate,
                    'updated_at' => $now,
                ]);

            $existing = SqlServerDb::table('department_role_users')
                ->where('user_id', $userId)
                ->where('department_role_id', $data['department_role_id'])
                ->where('start_date', $startDate)
                ->first();

            if ($existing) {
                SqlServerDb::table('department_role_users')
                    ->where('id', $existing->id)
                    ->update([
                        'is_primary' => 1,
                        'end_date' => null,
                        'updated_at' => $now,
                    ]);

                return;
            }

            SqlServerDb::table('department_role_users')->insert([
                'department_role_id' => (int) $data['department_role_id'],
                'user_id' => $userId,
                'is_primary' => 1,
                'start_date' => $startDate,
                'end_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return back()->with('ok', 'User department and primary role updated.');
    }
}
