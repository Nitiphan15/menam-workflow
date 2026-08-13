<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DivisionGroupMasterController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';
    private string $table = 'vc_department_division_groups';
    private array $sites = ['WIRE', 'PLUS'];
    private array $divisionGroups = ['Production', 'Logistic', 'Sale', 'Purchase', 'Admin'];

    private function userOr403()
    {
        return auth()->user();
    }

    private function tableExists(): bool
    {
        try {
            return DB::connection($this->fcConn)
                ->table('sys.objects')
                ->where('object_id', DB::raw("OBJECT_ID(N'dbo.{$this->table}')"))
                ->where('type', 'U')
                ->exists();
        } catch (QueryException $e) {
            return false;
        }
    }

    public function index(Request $request)
    {
        $this->userOr403();

        $tableReady = $this->tableExists();
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'active'));
        $site = strtoupper(trim((string) $request->query('site', 'ALL')));
        if (!in_array($site, array_merge(['ALL'], $this->sites), true)) {
            $site = 'ALL';
        }

        $groups = collect();
        if ($tableReady) {
            $groups = DB::connection($this->fcConn)
                ->table($this->table)
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($sub) use ($search) {
                        $sub->where('department_code', 'like', '%' . $search . '%')
                            ->orWhere('department_name', 'like', '%' . $search . '%')
                            ->orWhere('division_group', 'like', '%' . $search . '%')
                            ->orWhere('remark', 'like', '%' . $search . '%');
                    });
                })
                ->when($site !== 'ALL', fn($query) => $query->where('site', $site))
                ->when($status === 'active', fn($query) => $query->where('is_active', 1))
                ->when($status === 'inactive', fn($query) => $query->where('is_active', 0))
                ->orderByDesc('is_active')
                ->orderBy('site')
                ->orderBy('division_group')
                ->orderBy('department_code')
                ->get();
        }

        return view('formfc.division.group_master', [
            'groups' => $groups,
            'filters' => [
                'q' => $search,
                'site' => $site,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
            ],
            'sites' => $this->sites,
            'divisionGroups' => $this->divisionGroups,
            'tableReady' => $tableReady,
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->userOr403();
        abort_if(!$this->tableExists(), 500, 'Please run database/sql/create_vc_department_division_groups.sql first.');

        $data = $this->validatedData($request);
        $now = now();

        DB::connection($this->fcConn)
            ->table($this->table)
            ->insert([
                'site' => $data['site'],
                'department_code' => $data['department_code'],
                'department_name' => $data['department_name'],
                'division_group' => $data['division_group'],
                'is_active' => $data['is_active'],
                'remark' => $data['remark'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        return back()->with('success', 'Saved group master.');
    }

    public function update(Request $request, int $id)
    {
        $u = $this->userOr403();
        abort_if(!$this->tableExists(), 500, 'Please run database/sql/create_vc_department_division_groups.sql first.');

        $exists = DB::connection($this->fcConn)
            ->table($this->table)
            ->where('id', $id)
            ->exists();

        abort_if(!$exists, 404);

        $data = $this->validatedData($request, $id);

        DB::connection($this->fcConn)
            ->table($this->table)
            ->where('id', $id)
            ->update([
                'site' => $data['site'],
                'department_code' => $data['department_code'],
                'department_name' => $data['department_name'],
                'division_group' => $data['division_group'],
                'is_active' => $data['is_active'],
                'remark' => $data['remark'],
                'updated_at' => now(),
            ]);

        return back()->with('success', 'Updated group master.');
    }

    public function destroy(int $id)
    {
        $u = $this->userOr403();
        abort_if(!$this->tableExists(), 500, 'Please run database/sql/create_vc_department_division_groups.sql first.');

        DB::connection($this->fcConn)
            ->table($this->table)
            ->where('id', $id)
            ->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);

        return back()->with('success', 'Disabled group master.');
    }

    private function validatedData(Request $request, ?int $ignoreId = null): array
    {
        $site = strtoupper(trim((string) $request->input('site')));
        $departmentCode = strtoupper(trim((string) $request->input('department_code')));

        $unique = Rule::unique($this->fcConn . '.dbo.' . $this->table, 'department_code')
            ->where(fn($query) => $query->where('site', $site));
        if ($ignoreId) {
            $unique->ignore($ignoreId);
        }

        $data = $request->validate([
            'site' => ['required', Rule::in($this->sites)],
            'department_code' => ['required', 'string', 'max:30', $unique],
            'department_name' => ['required', 'string', 'max:200'],
            'division_group' => ['required', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'site' => $site,
            'department_code' => $departmentCode,
            'department_name' => trim((string) $data['department_name']),
            'division_group' => trim((string) $data['division_group']),
            'remark' => trim((string) ($data['remark'] ?? '')) ?: null,
            'is_active' => $request->boolean('is_active', true) ? 1 : 0,
        ];
    }
}
