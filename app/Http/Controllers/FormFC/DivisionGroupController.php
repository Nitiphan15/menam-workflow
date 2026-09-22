<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DivisionGroupController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';
    private string $table = 'fc_division_account_groups';
    private string $masterTable = 'fc_division_group_masters';

    private array $divisionLabels = [
        'D1' => 'D1',
        'D2' => 'D2',
        'D3' => 'D3',
        'D4' => 'D4',
        'D5' => 'D5',
        'D6' => 'D6',
        'D7' => 'D7',
        'D8' => 'D8',
        'D9' => 'D9',
    ];

    private array $accountCodes = [
        '5210100',
        '5210310',
        '5210330',
        '5210340',
        '5210350',
        '5210370',
        '5210401',
        '5210602',
        '5210700',
        '5211000',
        '5211100',
        '5211200',
        '5211700',
        '5211800',
        '5220200',
        '5220600',
        '6030001',
        '6040000',
        '6050000',
        '6060100',
        '6120201',
        '6120401',
        '7050000',
        '7060200',
        '7070000',
        '7080000',
    ];

    private function userOr403()
    {
        $u = auth()->user();
        if (!$u) {
            abort(403, 'Please login before using this page.');
        }

        return $u;
    }

    private function normalizeDivision(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        $code = str_replace(['.', ' '], '', $code);

        if (preg_match('/^D([1-9])$/', $code, $m)) {
            return 'D' . $m[1];
        }

        return null;
    }

    private function allDivisions(): array
    {
        return array_keys($this->divisionLabels);
    }

    private function availableDivisions(): array
    {
        if (app()->environment('local')) {
            return $this->allDivisions();
        }

        $u = auth()->user();
        if (!$u) {
            return [];
        }

        if ((int) ($u->superadmin ?? 0) === 1) {
            return $this->allDivisions();
        }

        $allowed = [];
        foreach ($this->allDivisions() as $code) {
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                $allowed[] = $code;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function currentDivision(Request $request): ?string
    {
        $allowed = $this->availableDivisions();
        if (empty($allowed)) {
            return null;
        }

        $requested = $this->normalizeDivision(
            $request->query('division', $request->input('division'))
        );

        if ($requested && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0];
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

    private function masterTableExists(): bool
    {
        try {
            return DB::connection($this->fcConn)
                ->table('sys.objects')
                ->where('object_id', DB::raw("OBJECT_ID(N'dbo.{$this->masterTable}')"))
                ->where('type', 'U')
                ->exists();
        } catch (QueryException $e) {
            return false;
        }
    }

    private function fetchAccountNames(): array
    {
        $rows = collect();

        foreach (['pgsqlw', 'pgsqlp'] as $conn) {
            try {
                $rows = $rows->concat(
                    DB::connection($conn)
                        ->table('chart')
                        ->whereIn('accno', $this->accountCodes)
                        ->get(['accno', 'description'])
                );
            } catch (QueryException $e) {
                continue;
            }
        }

        return $rows
            ->groupBy(fn($row) => trim((string) ($row->accno ?? '')))
            ->map(fn($group) => trim((string) ($group->first()->description ?? '')))
            ->filter()
            ->all();
    }

    public function index(Request $request)
    {
        $this->userOr403();

        $allowedDivisions = $this->availableDivisions();
        abort_if(empty($allowedDivisions), 403, 'No division permission.');

        $division = $this->currentDivision($request);
        abort_if(!$division, 403, 'No division permission.');

        $tableReady = $this->tableExists();
        $saved = collect();

        if ($tableReady) {
            $saved = DB::connection($this->fcConn)
                ->table($this->table)
                ->where('division', $division)
                ->whereIn('account_code', $this->accountCodes)
                ->get()
                ->keyBy(fn($row) => trim((string) $row->account_code));
        }

        $accountNames = $this->fetchAccountNames();

        $rows = collect($this->accountCodes)
            ->map(function (string $code) use ($saved, $accountNames) {
                $row = $saved->get($code);

                return (object) [
                    'account_code' => $code,
                    'account_name' => $accountNames[$code] ?? '',
                    'group_name' => (string) ($row->group_name ?? ''),
                    'is_active' => (int) ($row->is_active ?? 1),
                    'remark' => (string) ($row->remark ?? ''),
                ];
            })
            ->values();

        $groups = $this->masterTableExists()
            ? DB::connection($this->fcConn)
                ->table($this->masterTable)
                ->where('is_active', 1)
                ->orderBy('sort_order')
                ->orderBy('group_code')
                ->pluck('group_name')
                ->map(fn($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
            : $rows
                ->pluck('group_name')
                ->map(fn($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->sort()
                ->values();

        return view('formfc.division.group', [
            'division' => $division,
            'rows' => $rows,
            'groups' => $groups,
            'allowedDivisions' => $allowedDivisions,
            'showDivisionDropdown' => count($allowedDivisions) > 1,
            'divisionLabels' => $this->divisionLabels,
            'tableReady' => $tableReady,
        ]);
    }

    public function save(Request $request)
    {
        $u = $this->userOr403();

        abort_if(!$this->tableExists(), 500, 'Please run database/sql/create_fc_division_account_groups.sql first.');

        $division = $this->currentDivision($request);
        abort_if(!$division, 403, 'No division permission.');

        $validated = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.account_code' => ['required', 'string'],
            'rows.*.group_name' => ['nullable', 'string', 'max:100'],
            'rows.*.remark' => ['nullable', 'string', 'max:255'],
        ]);

        $now = now();
        $rows = $validated['rows'] ?? [];

        DB::connection($this->fcConn)->transaction(function () use ($rows, $division, $u, $now) {
            $upserts = [];

            foreach ($rows as $row) {
                $accountCode = trim((string) ($row['account_code'] ?? ''));
                if (!in_array($accountCode, $this->accountCodes, true)) {
                    continue;
                }

                $groupName = trim((string) ($row['group_name'] ?? ''));
                $remark = trim((string) ($row['remark'] ?? ''));

                $upserts[] = [
                    'division' => $division,
                    'account_code' => $accountCode,
                    'group_name' => $groupName !== '' ? $groupName : null,
                    'is_active' => isset($row['is_active']) ? 1 : 0,
                    'remark' => $remark !== '' ? $remark : null,
                    'created_at' => $now,
                    'created_by' => $u->id ?? null,
                    'updated_at' => $now,
                    'updated_by' => $u->id ?? null,
                ];
            }

            if (!empty($upserts)) {
                DB::connection($this->fcConn)
                    ->table($this->table)
                    ->upsert(
                        $upserts,
                        ['division', 'account_code'],
                        ['group_name', 'is_active', 'remark', 'updated_at', 'updated_by']
                    );
            }
        });

        return redirect()
            ->route('fc.divisionGroup.index', ['division' => $division])
            ->with('success', 'Saved FormFC Group settings.');
    }
}
