<?php

namespace App\Http\Controllers\FormVC;

use App\Http\Controllers\Controller;
use App\Services\FormVC\VariableCostService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VariableCostAccountMasterController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';
    private string $table = 'vc_account_display_accounts';

    private const SITES = [
        'WIRE' => 'pgsqlw',
        'PLUS' => 'pgsqlp',
    ];

    public function index(Request $request)
    {
        $tableReady = $this->tableExists();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status', 'all'), ['all', 'active', 'inactive'], true)
                ? (string) $request->query('status', 'all')
                : 'all',
            'prefix' => in_array($request->query('prefix', 'all'), ['all', '1', '2', '3', '4', '5', '6', '7'], true)
                ? (string) $request->query('prefix', 'all')
                : 'all',
        ];

        $accounts = $this->accountCatalog();
        $saved = $tableReady ? $this->savedAccounts() : collect();
        $hasSavedRows = $saved->isNotEmpty();
        $defaultCodes = collect(VariableCostService::DEFAULT_ACCOUNT_OPTION_CODES)->flip();

        $rows = $accounts
            ->map(function ($account) use ($saved, $hasSavedRows, $defaultCodes) {
                $savedRow = $saved->get($account->account_code);
                $isActive = $savedRow
                    ? (int) $savedRow->is_active === 1
                    : (!$hasSavedRows && $defaultCodes->has($account->account_code));

                return (object) [
                    'account_code' => $account->account_code,
                    'account_name' => $savedRow->account_name ?? $account->account_name,
                    'is_active' => $isActive ? 1 : 0,
                    'source_sites' => $account->source_sites,
                    'updated_at' => $savedRow->updated_at ?? null,
                ];
            })
            ->filter(function ($row) use ($filters) {
                if ($filters['status'] === 'active' && (int) $row->is_active !== 1) {
                    return false;
                }
                if ($filters['status'] === 'inactive' && (int) $row->is_active === 1) {
                    return false;
                }
                if ($filters['prefix'] !== 'all' && !str_starts_with($row->account_code, $filters['prefix'])) {
                    return false;
                }
                if ($filters['q'] === '') {
                    return true;
                }

                $needle = mb_strtolower($filters['q']);
                return str_contains(mb_strtolower($row->account_code . ' ' . $row->account_name), $needle);
            })
            ->sortBy(fn($row) => ((int) $row->is_active === 1 ? '0' : '1') . '-' . $row->account_code)
            ->values();

        $activeTotal = $accounts
            ->map(function ($account) use ($saved, $hasSavedRows, $defaultCodes) {
                $savedRow = $saved->get($account->account_code);

                return $savedRow
                    ? (int) $savedRow->is_active === 1
                    : (!$hasSavedRows && $defaultCodes->has($account->account_code));
            })
            ->filter()
            ->count();

        return view('formvc.account_master', [
            'rows' => $rows,
            'filters' => $filters,
            'tableReady' => $tableReady,
            'hasSavedRows' => $hasSavedRows,
            'totalCount' => $accounts->count(),
            'activeCount' => $activeTotal,
            'filteredCount' => $rows->count(),
            'prefixOptions' => $this->prefixOptions($accounts),
        ]);
    }

    public function update(Request $request)
    {
        abort_if(!$this->tableExists(), 500, 'Please run database/sql/create_vc_account_display_accounts.sql first.');

        $data = $request->validate([
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['string', 'max:20'],
            'visible_accounts' => ['nullable', 'array'],
            'visible_accounts.*' => ['string', 'max:20'],
        ]);

        $selected = collect($data['accounts'] ?? [])
            ->map(fn($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->flip();
        $visible = collect($data['visible_accounts'] ?? [])
            ->map(fn($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->flip();

        $now = now();
        $accountsToSave = $this->accountCatalog()
            ->filter(fn($account) => $visible->isEmpty() || $visible->has($account->account_code));

        foreach ($accountsToSave as $account) {
            DB::connection($this->fcConn)
                ->table($this->table)
                ->updateOrInsert(
                    ['account_code' => $account->account_code],
                    [
                        'account_name' => $account->account_name,
                        'is_active' => $selected->has($account->account_code) ? 1 : 0,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
        }

        Cache::forget(VariableCostService::ACCOUNT_DISPLAY_CACHE_KEY);

        return back()->with('success', 'Updated Variable Cost account display master.');
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

    private function savedAccounts(): Collection
    {
        return DB::connection($this->fcConn)
            ->table($this->table)
            ->select('account_code', 'account_name', 'is_active', 'updated_at')
            ->get()
            ->keyBy(fn($row) => trim((string) $row->account_code));
    }

    private function accountCatalog(): Collection
    {
        $rows = collect();
        foreach (self::SITES as $site => $connection) {
            try {
                $siteRows = DB::connection($connection)
                    ->table('chart')
                    ->select('accno as account_code', 'description as account_name')
                    ->whereNotNull('accno')
                    ->orderBy('accno')
                    ->get()
                    ->map(function ($row) use ($site) {
                        return (object) [
                            'account_code' => trim((string) $row->account_code),
                            'account_name' => trim((string) $row->account_name),
                            'source_site' => $site,
                        ];
                    });
                $rows = $rows->concat($siteRows);
            } catch (\Throwable $e) {
                // Keep the page usable even if one ERP site is temporarily unreachable.
            }
        }

        $fallbackNames = $this->fallbackAccountNames();
        foreach (VariableCostService::DEFAULT_ACCOUNT_OPTION_CODES as $code) {
            if ($rows->contains(fn($row) => $row->account_code === $code)) {
                continue;
            }
            $rows->push((object) [
                'account_code' => $code,
                'account_name' => $fallbackNames[$code] ?? '',
                'source_site' => 'default',
            ]);
        }

        return $rows
            ->filter(fn($row) => $row->account_code !== '')
            ->groupBy('account_code')
            ->map(function (Collection $group) {
                $first = $group->first(fn($row) => $row->account_name !== '') ?: $group->first();

                return (object) [
                    'account_code' => $first->account_code,
                    'account_name' => $first->account_name,
                    'source_sites' => $group->pluck('source_site')->unique()->sort()->implode(', '),
                ];
            })
            ->values();
    }

    private function fallbackAccountNames(): array
    {
        return [
            '5210100' => 'ค่าซ่อมเครื่องจักร เครื่องมือ',
            '5210310' => 'ค่าวัสดุสิ้นเปลือง-สารเคมี',
            '5210330' => 'ค่าวัสดุสิ้นเปลือง-บรรจุภัณฑ์',
            '5210340' => 'ค่าวัสดุสิ้นเปลือง-อะไหล่',
            '5210350' => 'ค่าวัสดุสิ้นเปลือง-ทั่วไป',
            '5210370' => 'ค่าวัสดุสิ้นเปลือง-ไดร์',
            '5210401' => 'ค่าบริการทดสอบงาน',
            '5210602' => 'ค่าจ้างกัดกรดเหล็ก',
            '5210700' => 'ค่าภาชนะ',
            '5211000' => 'ค่าไฟฟ้า-โรงงาน',
            '5211100' => 'ค่าน้ำประปา-โรงงาน',
            '5211200' => 'ค่าเครื่องเขียนแบบพิมพ์-โรงงาน',
            '5211700' => 'ค่าซ่อมแซมอุปกรณ์สนง.-โรงงาน',
            '5211800' => 'ค่าใช้จ่ายเดินทางและที่พัก-โรงงาน',
            '5220200' => 'เงินเดือน/ค่าแรงพนักงาน-โรงงาน',
            '5220600' => 'ค่าล่วงเวลา-โรงงาน',
            '6030001' => 'ค่าพาหนะน้ำมัน-จัดส่ง',
            '6040000' => 'ค่าจ้างรถส่งสินค้า',
            '6050000' => 'ค่าวัสดุสิ้นเปลือง-ขาย-จัดส่ง',
            '6060100' => 'ค่าซ่อมโฟล์คลิฟ-จัดส่ง',
            '6120201' => 'เงินเดือนพนักงาน-จัดส่ง',
            '6120401' => 'ค่าล่วงเวลา-จัดส่ง',
            '7050000' => 'ค่าเครื่องเขียนแบบพิมพ์-สำนักงาน',
            '7060200' => 'ค่าซ่อมอุปกรณ์-สำนักงาน',
            '7070000' => 'ค่าใช้จ่ายเกี่ยวกับรถยนต์',
            '7080000' => 'ค่าพาหนะ น้ำมัน-สำนักงาน',
        ];
    }

    private function prefixOptions(Collection $accounts): Collection
    {
        $labels = [
            '1' => '1 Assets',
            '2' => '2 Liabilities',
            '3' => '3 Equity',
            '4' => '4 Revenue',
            '5' => '5 Cost/Factory',
            '6' => '6 Selling',
            '7' => '7 Admin/Other',
        ];

        return collect($labels)
            ->map(function (string $label, string $prefix) use ($accounts) {
                return [
                    'prefix' => $prefix,
                    'label' => $label,
                    'count' => $accounts->filter(fn($row) => str_starts_with($row->account_code, $prefix))->count(),
                ];
            })
            ->values();
    }
}
