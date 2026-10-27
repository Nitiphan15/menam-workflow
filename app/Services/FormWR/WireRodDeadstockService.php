<?php

namespace App\Services\FormWR;

use App\Support\FormWR\WireRodDeadstockRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class WireRodDeadstockService
{
    private const SITES = [
        'WIRE' => ['erp' => 'pgsqlw', 'mfg' => 'pgsqlmfgw', 'company' => 'MENAM WIRE'],
        'PLUS' => ['erp' => 'pgsqlp', 'mfg' => 'pgsqlmfgp', 'company' => 'MENAM PLUS'],
    ];

    private string $workflowConnection;

    public function __construct()
    {
        $this->workflowConnection = config('database.workflow_connection', 'sqlsrv_menam');
    }

    public function refresh(?int $userId = null, ?string $userName = null, string $runType = 'MANUAL'): array
    {
        $result = ['status' => 'SUCCEEDED', 'sites' => [], 'retrieved_at' => now('Asia/Bangkok')];

        foreach (self::SITES as $site => $config) {
            $started = microtime(true);
            $runId = DB::connection($this->workflowConnection)->table('wr_ds_sync_runs')->insertGetId([
                'run_type' => $runType,
                'site_code' => $site,
                'started_at' => now('Asia/Bangkok'),
                'status' => 'STARTED',
                'triggered_by' => $userId,
                'triggered_by_name' => $userName,
                'created_at' => now('Asia/Bangkok'),
            ]);

            try {
                $rows = $this->fetchSite($site, $config);
                $applied = $this->applySite($site, $rows, $result['retrieved_at']);
                $this->finishRun($runId, 'SUCCEEDED', $rows->count(), $applied, $started);
                $result['sites'][$site] = ['status' => 'SUCCEEDED', 'rows' => $applied];
            } catch (Throwable $e) {
                $status = str_contains(strtolower($e->getMessage()), 'timeout') ? 'TIMEOUT' : 'FAILED';
                $this->finishRun($runId, $status, null, null, $started, Str::limit($e->getMessage(), 1900));
                $result['sites'][$site] = ['status' => $status, 'message' => $e->getMessage()];
                $result['status'] = count(array_filter($result['sites'], fn ($r) => $r['status'] === 'SUCCEEDED')) > 0
                    ? 'PARTIAL'
                    : 'FAILED';
            }
        }

        return $result;
    }

    public function latestLot(string $site, string $serialNumber): ?array
    {
        $site = strtoupper(trim($site));
        if (! isset(self::SITES[$site])) {
            return null;
        }

        return $this->fetchSite($site, self::SITES[$site], $serialNumber)->first();
    }

    public function createSnapshot(string $kind): int
    {
        $kind = strtoupper($kind);
        abort_unless(in_array($kind, ['DAILY', 'MONTH_END', 'MANUAL'], true), 422);
        $db = DB::connection($this->workflowConnection);

        return $db->transaction(function () use ($db, $kind) {
            $rows = $db->table('wr_ds_current_items as c')
                ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
                ->where('l.is_current', 1)
                ->select('c.*', 'l.cycle_no')
                ->get();
            $snapshotId = $db->table('wr_ds_snapshots')->insertGetId([
                'snapshot_kind' => $kind,
                'as_of_at' => now('Asia/Bangkok'),
                'source_retrieved_at' => $rows->max('source_retrieved_at'),
                'completion_status' => 'COMPLETE',
                'included_sites' => $rows->pluck('site_code')->unique()->implode(','),
                'item_count' => $rows->count(),
                'total_qty_kg' => $rows->where('display_unit', 'KG')->sum('current_qty'),
                'total_qty_pcs' => $rows->where('display_unit', 'PCS')->sum('current_qty'),
                'total_value_thb' => $rows->sum('current_value_thb'),
                'created_at' => now('Asia/Bangkok'),
            ]);

            foreach ($rows->chunk(300) as $chunk) {
                $db->table('wr_ds_snapshot_items')->insert($chunk->map(fn ($r) => [
                    'snapshot_id' => $snapshotId, 'lifecycle_id' => $r->lifecycle_id,
                    'site_code' => $r->site_code, 'serial_number' => $r->serial_number,
                    'cycle_no' => $r->cycle_no, 'part_number' => $r->part_number,
                    'part_description' => $r->part_description, 'part_type_id' => $r->part_type_id,
                    'material_group' => $r->material_group, 'heat_number' => $r->heat_number,
                    'grade' => $r->grade, 'diameter_size' => $r->diameter_size,
                    'purchase_date' => $r->purchase_date, 'stock_age_days' => $r->stock_age_days,
                    'stock_status' => $r->stock_status, 'became_dead_stock' => $r->became_dead_stock,
                    'source_unit_code' => $r->source_unit_code, 'display_unit' => $r->display_unit,
                    'division_code' => $r->effective_division_code, 'snapshot_qty' => $r->current_qty,
                    'unitcost_thb' => $r->unitcost_thb, 'snapshot_value_thb' => $r->current_value_thb,
                    'trace_status' => $r->trace_status, 'mapping_source' => $r->mapping_source,
                    'effective_sales_key' => $r->effective_sales_key,
                    'effective_sales_name' => $r->effective_sales_name,
                    'effective_division_code' => $r->effective_division_code,
                    'trace_json' => json_encode(['mfg' => json_decode($r->mfg_json ?: '[]', true), 'customers' => json_decode($r->customer_json ?: '[]', true)], JSON_UNESCAPED_UNICODE),
                    'created_at' => now('Asia/Bangkok'),
                ])->all());
            }

            return $snapshotId;
        });
    }

    private function fetchSite(string $site, array $config, ?string $serialNumber = null): Collection
    {
        $erp = DB::connection($config['erp']);
        $erp->statement("SET statement_timeout TO '30s'");
        $bindings = ['company' => $config['company']];
        if ($serialNumber !== null) $bindings['serial'] = $serialNumber;
        $rows = collect($erp->select($this->stockSql($serialNumber !== null), $bindings))
            ->groupBy(fn ($row) => strtoupper(trim((string) $row->serialnumber)))
            ->map(function (Collection $matches) {
                $base = (array) $matches->first();
                $base['po_sales_names'] = $matches->pluck('po_salesperson')->filter()->unique()->values()->all();
                return (object) $base;
            })->values();
        $heats = $rows->pluck('heat_number')->filter()->unique()->values();
        $mfgByHeat = $this->loadMfgTrace($config['mfg'], $heats);
        $customerNames = $mfgByHeat->flatten(1)->pluck('customer')->filter()->unique()->values();
        $salesByCustomer = $this->loadSalesByCustomer($config['erp'], $customerNames);
        $asOf = Carbon::today('Asia/Bangkok');

        return $rows->map(function ($row) use ($site, $mfgByHeat, $salesByCustomer, $asOf) {
            $row = (array) $row;
            $purchaseDate = empty($row['purchasedate']) ? null : Carbon::parse($row['purchasedate'], 'Asia/Bangkok')->startOfDay();
            $age = $purchaseDate ? $purchaseDate->diffInDays($asOf, false) : null;
            $mfgRows = collect($mfgByHeat->get(strtoupper(trim((string) $row['heat_number'])), []));
            $mfgSales = $mfgRows->map(function ($mfg) use ($salesByCustomer) {
                $sales = $salesByCustomer->get(mb_strtoupper(trim((string) ($mfg['customer'] ?? '')), 'UTF-8'));
                return $mfg + ['salesperson_id' => $sales['id'] ?? null, 'salesperson' => $sales['name'] ?? null];
            });
            $mfgNames = $mfgSales->pluck('salesperson')->filter()->unique()->values();
            $poSalesNames = collect($row['po_sales_names'] ?? [])->filter()->unique()->values();
            $poName = $poSalesNames->count() === 1 ? $poSalesNames->first() : null;
            $traceStatus = 'UNMAPPED';
            $mappingSource = 'UNMAPPED';
            $effectiveName = null;

            if ($mfgNames->count() > 1 || $poSalesNames->count() > 1) {
                $traceStatus = 'SALES_MAPPING_CONFLICT';
            } elseif ($mfgNames->count() === 1) {
                $effectiveName = $mfgNames->first();
                $mappingSource = 'MFG';
                $traceStatus = $poName && $poName !== $effectiveName ? 'SALES_MAPPING_CONFLICT' : 'MAPPED';
            } elseif ($poName) {
                $effectiveName = $poName;
                $mappingSource = 'PO';
                $traceStatus = 'MAPPED';
            }

            $division = WireRodDeadstockRule::division($effectiveName);
            $displayUnit = WireRodDeadstockRule::displayUnit($division, $row['source_unit_code'] ?? null);
            $sourceUnit = strtoupper(trim((string) ($row['source_unit_code'] ?? '')));
            $expectedSourceUnit = $displayUnit === 'PCS' ? '03' : ($displayUnit === 'KG' ? '01' : null);
            $qty = round((float) ($row['quantity'] ?? 0), 4);
            $cost = isset($row['unitcost']) ? round((float) $row['unitcost'], 4) : null;

            return [
                'site_code' => $site, 'serial_number' => trim((string) $row['serialnumber']),
                'erp_serializeunit_id' => $row['serializeunit_id'] ?? null, 'erp_part_id' => $row['part_id'] ?? null,
                'part_number' => trim((string) $row['partnumber']), 'part_description' => $row['part_description'] ?? null,
                'part_type_id' => isset($row['part_type_id']) ? (int) $row['part_type_id'] : null,
                'material_group' => WireRodDeadstockRule::materialGroup($row['part_description'] ?? null, isset($row['part_type_id']) ? (int) $row['part_type_id'] : null),
                'heat_number' => $row['heat_number'] ?? null, 'purchase_date' => $purchaseDate?->toDateString(),
                'stock_age_days' => $age, 'stock_status' => WireRodDeadstockRule::stockStatus($purchaseDate, $asOf),
                'source_unit_code' => $sourceUnit ?: null, 'display_unit' => $displayUnit,
                'unit_rule_source' => $division ? 'SALES_DIVISION' : 'ERP_UNIT',
                'unit_conflict' => $expectedSourceUnit && $sourceUnit && $expectedSourceUnit !== $sourceUnit,
                'current_qty' => $qty, 'unitcost_thb' => $cost,
                'current_value_thb' => $cost === null ? null : round($qty * $cost, 4),
                'po_number' => $row['transaction_number'] ?? null,
                'trace_status' => $traceStatus, 'mapping_source' => $mappingSource,
                'erp_salesperson_id' => $row['po_salesperson_id'] ?? null,
                'erp_salesperson_name' => $row['po_salesperson'] ?? null,
                'erp_division_code' => WireRodDeadstockRule::division($poName),
                'effective_sales_key' => $division, 'effective_sales_name' => $effectiveName,
                'effective_division_code' => $division,
                'mfg_json' => json_encode($mfgSales->values()->all(), JSON_UNESCAPED_UNICODE),
                'sales_order_json' => json_encode($mfgSales->pluck('sales_order')->filter()->unique()->values()->all(), JSON_UNESCAPED_UNICODE),
                'customer_json' => json_encode($mfgSales->pluck('customer')->push($row['po_customer'] ?? null)->filter()->unique()->values()->all(), JSON_UNESCAPED_UNICODE),
                'sales_json' => json_encode($mfgNames->merge($poSalesNames)->filter()->unique()->values()->all(), JSON_UNESCAPED_UNICODE),
            ];
        })->filter(fn ($row) => $row['serial_number'] !== '')->values();
    }

    private function applySite(string $site, Collection $rows, Carbon $retrievedAt): int
    {
        $db = DB::connection($this->workflowConnection);

        return $db->transaction(function () use ($db, $site, $rows, $retrievedAt) {
            $existing = $db->table('wr_ds_current_items as c')
                ->join('wr_ds_item_lifecycles as l', 'l.id', '=', 'c.lifecycle_id')
                ->where('c.site_code', $site)
                ->select('c.*', 'l.is_current as lifecycle_is_current')
                ->get()->keyBy(fn ($r) => strtoupper($r->serial_number));
            $incomingKeys = $rows->pluck('serial_number')->map(fn ($v) => strtoupper($v))->flip();

            foreach ($existing as $key => $old) {
                if ((bool) $old->lifecycle_is_current && ! $incomingKeys->has($key)) {
                    // No DELETE: keep the cache row and full history. Closed lifecycles are
                    // excluded from current screens and the same serial can reopen in a new cycle.
                    $db->table('wr_ds_current_items')->where('id', $old->id)->update([
                        'current_qty' => 0, 'current_value_thb' => 0,
                        'last_successful_sync_at' => $retrievedAt, 'updated_at' => $retrievedAt,
                    ]);
                    $db->table('wr_ds_item_lifecycles')->where('id', $old->lifecycle_id)->update([
                        'is_current' => 0, 'cleared_at' => $retrievedAt, 'clear_reason' => 'NO_LONGER_ON_HAND', 'updated_at' => $retrievedAt,
                    ]);
                    $this->writeCompare($db, $old, null, 'cleared', false, ['current_qty']);
                }
            }

            foreach ($rows as $row) {
                $key = strtoupper($row['serial_number']);
                $old = $existing->get($key);
                $lifecycleId = ($old && (bool) $old->lifecycle_is_current) ? $old->lifecycle_id : null;
                if (! $lifecycleId) {
                    $cycle = (int) $db->table('wr_ds_item_lifecycles')->where('site_code', $site)->where('serial_number', $row['serial_number'])->max('cycle_no') + 1;
                    $lifecycleId = $db->table('wr_ds_item_lifecycles')->insertGetId([
                        'site_code' => $site, 'serial_number' => $row['serial_number'], 'cycle_no' => $cycle,
                        'is_current' => 1, 'opened_at' => $retrievedAt, 'last_seen_at' => $retrievedAt,
                        'created_at' => $retrievedAt, 'updated_at' => $retrievedAt,
                    ]);
                } else {
                    $db->table('wr_ds_item_lifecycles')->where('id', $lifecycleId)->update(['last_seen_at' => $retrievedAt, 'updated_at' => $retrievedAt]);
                }

                $override = $db->table('wr_ds_sales_overrides')->where('lifecycle_id', $lifecycleId)->where('is_active', 1)->first();
                if ($override) {
                    if ($row['effective_sales_key'] && strtoupper($row['effective_sales_key']) !== strtoupper($override->sales_key)) {
                        $row['trace_status'] = 'OVERRIDE_CONFLICT';
                    }
                    $row['mapping_source'] = 'MANUAL';
                    $row['effective_sales_key'] = $override->sales_key;
                    $row['effective_sales_name'] = $override->sales_name;
                    $row['effective_division_code'] = $override->division_code;
                    $row['display_unit'] = WireRodDeadstockRule::displayUnit($override->division_code, $row['source_unit_code']);
                }

                $changed = $old ? $this->changedFields($old, $row) : [];
                $becameDead = $old && $old->stock_status !== 'DEAD' && $row['stock_status'] === 'DEAD';
                $payload = $row + [
                    'lifecycle_id' => $lifecycleId, 'became_dead_stock' => $becameDead ? 1 : 0,
                    'became_dead_at' => $row['purchase_date'] ? Carbon::parse($row['purchase_date'])->addDays(181)->toDateString() : null,
                    'source_retrieved_at' => $retrievedAt, 'last_successful_sync_at' => $retrievedAt,
                    'updated_at' => $retrievedAt,
                ];
                if ($old) {
                    $db->table('wr_ds_current_items')->where('id', $old->id)->update($payload);
                    if ((bool) $old->lifecycle_is_current) {
                        $this->writeCompare($db, $old, (object) $row, $changed ? 'changed' : 'active', $becameDead, $changed);
                    }
                } else {
                    $payload['created_at'] = $retrievedAt;
                    $db->table('wr_ds_current_items')->insert($payload);
                }
            }

            return $rows->count();
        });
    }

    private function changedFields(object $old, array $new): array
    {
        $fields = [];
        if (abs((float) $old->current_qty - (float) $new['current_qty']) > 0.01) $fields[] = 'current_qty';
        if (abs((float) $old->unitcost_thb - (float) $new['unitcost_thb']) > 0.01) $fields[] = 'unitcost_thb';
        foreach (['purchase_date', 'part_number', 'material_group', 'stock_status', 'trace_status'] as $field) {
            if ((string) $old->{$field} !== (string) $new[$field]) $fields[] = $field;
        }
        return $fields;
    }

    private function writeCompare($db, object $old, ?object $new, string $status, bool $becameDead, array $changed): void
    {
        $db->table('wr_ds_compare_logs')->insert([
            'lifecycle_id' => $old->lifecycle_id, 'checked_at' => now('Asia/Bangkok'), 'compare_status' => $status,
            'previous_qty' => $old->current_qty, 'current_qty' => $new->current_qty ?? null,
            'qty_difference' => $new ? round((float) $new->current_qty - (float) $old->current_qty, 4) : null,
            'previous_unitcost_thb' => $old->unitcost_thb, 'current_unitcost_thb' => $new->unitcost_thb ?? null,
            'became_dead_stock' => $becameDead ? 1 : 0, 'changed_fields' => json_encode($changed),
            'created_at' => now('Asia/Bangkok'),
        ]);
    }

    private function loadMfgTrace(string $connection, Collection $heats): Collection
    {
        $result = collect();
        foreach ($heats->chunk(300) as $chunk) {
            $rows = DB::connection($connection)->table('workorderbom as b')
                ->join('workorder as w', 'w.id', '=', 'b.workorder_id')
                ->whereIn(DB::raw("UPPER(TRIM(b.fheatno))"), $chunk->map(fn ($v) => strtoupper(trim($v)))->all())
                ->selectRaw('UPPER(TRIM(b.fheatno)) AS heat, w.workordernumber AS mfg, w.ordnumber AS sales_order, w.customer, w.dateopen AS order_date')
                ->distinct()->get();
            foreach ($rows as $row) $result->push((array) $row);
        }
        return $result->groupBy('heat');
    }

    private function loadSalesByCustomer(string $connection, Collection $names): Collection
    {
        $result = collect();
        foreach ($names->chunk(300) as $chunk) {
            $rows = DB::connection($connection)->table('customer as c')->leftJoin('employee as e', 'e.id', '=', 'c.saleperson_id')
                ->whereIn(DB::raw('UPPER(TRIM(c.name))'), $chunk->map(fn ($v) => mb_strtoupper(trim($v), 'UTF-8'))->all())
                ->selectRaw('UPPER(TRIM(c.name)) AS customer_key, e.id, e.name')->get();
            foreach ($rows as $row) $result->put($row->customer_key, ['id' => $row->id, 'name' => $row->name]);
        }
        return $result;
    }

    private function finishRun(int $id, string $status, ?int $sourceCount, ?int $appliedCount, float $started, ?string $error = null): void
    {
        DB::connection($this->workflowConnection)->table('wr_ds_sync_runs')->where('id', $id)->update([
            'finished_at' => now('Asia/Bangkok'), 'status' => $status, 'source_item_count' => $sourceCount,
            'applied_item_count' => $appliedCount, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error_message' => $error,
        ]);
    }

    private function stockSql(bool $filterSerial = false): string
    {
        $serialFilter = $filterSerial ? ' AND s.serialnumber = :serial' : '';
        return <<<SQL
WITH latest_positive AS (
    SELECT MAX(id) AS id, su_id
    FROM serializeunitsmvmt
    WHERE transdate <= CURRENT_DATE AND qty > 0
    GROUP BY su_id
), balances AS (
    SELECT su_id, SUM(qty) AS qty
    FROM serializeunitsmvmt
    WHERE transdate <= CURRENT_DATE
    GROUP BY su_id
    HAVING SUM(qty) > 0
)
SELECT s.id AS serializeunit_id, p.id AS part_id, s.purchasedate,
       COALESCE(ap.invnumber, r.returnnumber, gl.transnumber) AS transaction_number,
       p.partnumber, p.description AS part_description, s.serialnumber,
       ROUND(b.qty, 4) AS quantity, p.unit AS source_unit_code,
       ROUND(ax.unitcost, 4) AS unitcost, pt.id AS part_type_id,
       pt.description AS part_type_description, :company AS company,
       UPPER(TRIM(SPLIT_PART(s.serialnumber, '-', 1))) AS heat_number,
       c2.name AS po_customer, e_po.id AS po_salesperson_id, e_po.name AS po_salesperson
FROM serializeunitsmvmt ax
JOIN latest_positive lp ON ax.id = lp.id
JOIN balances b ON ax.su_id = b.su_id
JOIN serializeunits s ON ax.su_id = s.id
JOIN parts p ON s.parts_id = p.id
LEFT JOIN ap ON ax.trans_id = ap.id
LEFT JOIN gl ON ax.trans_id = gl.id
LEFT JOIN "return" r ON ax.trans_id = r.id
LEFT JOIN partsmvmt pm ON ax.invoice_id = pm.id
LEFT JOIN receiveitems ri ON s.purchase_invoice_id = ri.invoice_id
LEFT JOIN orderitems oi ON ri.orderitems_id = oi.id
LEFT JOIN customer c2 ON oi.ref1 = c2.customernumber
LEFT JOIN employee e_po ON c2.saleperson_id = e_po.id
LEFT JOIN partstype pt ON p.partstype_id = pt.id
WHERE p.active = TRUE
  AND UPPER(TRIM(p.partnumber)) LIKE 'R%'
  {$serialFilter}
  AND ((gl.id IS NOT NULL AND pm.id IS NOT NULL) OR ap.id IS NOT NULL OR r.id IS NOT NULL OR ax.trans_id = -1)
ORDER BY p.partnumber, s.serialnumber
SQL;
    }
}
