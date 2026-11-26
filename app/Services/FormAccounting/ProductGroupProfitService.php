<?php

namespace App\Services\FormAccounting;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductGroupProfitService
{
    private const CONNECTIONS = ['WIRE' => 'pgsqlw', 'PLUS' => 'pgsqlp'];

    public function filters(array $input): array
    {
        $from = $this->date($input['date_from'] ?? null, now()->startOfMonth()->toDateString());
        $to = $this->date($input['date_to'] ?? null, now()->toDateString());
        if ($from > $to) [$from, $to] = [$to, $from];

        return [
            'date_from' => $from,
            'date_to' => $to,
            'sales_site' => $this->site($input['sales_site'] ?? 'ALL'),
            'production_site' => $this->site($input['production_site'] ?? 'ALL'),
            'product_group' => trim((string) ($input['product_group'] ?? '')),
            'invoice' => strtoupper(trim((string) ($input['invoice'] ?? ''))),
            'partnumber' => strtoupper(trim((string) ($input['partnumber'] ?? ''))),
            'issues_only' => filter_var($input['issues_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function inquiry(array $input, int $limit = 500): array
    {
        $filters = $this->filters($input);
        $rows = $this->rows($filters);
        $limited = $rows->count() > $limit;

        return [
            'filters' => $filters,
            'rows' => $rows->take($limit)->values(),
            'summary' => $this->summarize($rows),
            'groups' => $rows->pluck('product_group')->filter()->unique()->sort()->values(),
            'limit' => $limit,
            'limited' => $limited,
        ];
    }

    public function exportData(array $input): array
    {
        $filters = $this->filters($input);
        $rows = $this->rows($filters);
        return compact('filters', 'rows') + ['summary' => $this->summarize($rows)];
    }

    public function summarize(Collection $rows): array
    {
        $sales = (float) $rows->sum('sales_thb');
        $costComplete = $rows->every(fn ($row) => $row->total_cost !== null);
        $cost = $costComplete ? (float) $rows->sum('total_cost') : null;
        $profit = $cost === null ? null : $sales - $cost;

        return [
            'line_count' => $rows->count(),
            'invoice_count' => $rows->pluck('sales_site_invoice_key')->unique()->count(),
            'sales' => $sales,
            'cost' => $cost,
            'gross_profit' => $profit,
            'margin' => $sales != 0.0 && $profit !== null ? ($profit / $sales) * 100 : null,
            'cost_complete' => $costComplete,
            'issue_count' => $rows->whereNotNull('data_issue')->count(),
            'by_site_group' => $rows->groupBy(fn ($row) => $row->production_site.'|'.$row->product_group)
                ->map(function (Collection $groupRows) {
                    $sales = (float) $groupRows->sum('sales_thb');
                    $cost = $groupRows->every(fn ($row) => $row->total_cost !== null)
                        ? (float) $groupRows->sum('total_cost') : null;
                    return (object) [
                        'production_site' => $groupRows->first()->production_site,
                        'product_group' => $groupRows->first()->product_group,
                        'quantity' => (float) $groupRows->sum('quantity'),
                        'sales' => $sales,
                        'cost' => $cost,
                        'gross_profit' => $cost === null ? null : $sales - $cost,
                        'margin' => $sales != 0.0 && $cost !== null ? (($sales - $cost) / $sales) * 100 : null,
                    ];
                })->sortBy(fn ($row) => $row->production_site.'|'.$row->product_group)->values(),
        ];
    }

    private function rows(array $filters): Collection
    {
        $rows = collect();
        foreach ($this->connections($filters['sales_site']) as $site => $connection) {
            $rows = $rows->concat($this->salesRows($connection, $site, $filters));
        }

        $rows = $this->applyProductionCosts($rows);

        if ($filters['production_site'] !== 'ALL') {
            $rows = $rows->where('production_site', $filters['production_site']);
        }
        if ($filters['product_group'] !== '') {
            $rows = $rows->where('product_group', $filters['product_group']);
        }
        if ($filters['issues_only']) {
            $rows = $rows->whereNotNull('data_issue');
        }

        return $rows->sortBy(['production_site', 'product_group', 'invoice_date', 'invoice_no', 'invoice_item_id'])->values();
    }

    private function salesRows(string $connection, string $site, array $filters): Collection
    {
        $where = ['ar.transdate BETWEEN ? AND ?', 'COALESCE(ar.invoice, TRUE) = TRUE'];
        $bindings = [$filters['date_from'], $filters['date_to']];
        if ($filters['invoice'] !== '') {
            $where[] = 'UPPER(TRIM(ar.invnumber)) LIKE ?';
            $bindings[] = '%'.$filters['invoice'].'%';
        }
        if ($filters['partnumber'] !== '') {
            $where[] = 'UPPER(TRIM(p.partnumber)) LIKE ?';
            $bindings[] = '%'.$filters['partnumber'].'%';
        }

        $sql = str_replace(
            ['__SITE__', '__WHERE__'],
            [$site, implode(' AND ', $where)],
            self::SALES_SQL
        );

        // The same bound predicate is used in both candidate CTEs and the final invoice query.
        $queryBindings = [...$bindings, ...$bindings, ...$bindings];
        return collect(DB::connection($connection)->select($sql, $queryBindings))->map(function ($row) use ($site) {
            foreach (['quantity', 'invoice_quantity', 'unit_price', 'sales_original', 'exchange_rate', 'sales_thb'] as $field) {
                $row->{$field} = $row->{$field} === null ? null : (float) $row->{$field};
            }
            $row->sales_site_invoice_key = $site.'|'.$row->invoice_no;
            $row->production_site = $row->mfg === null ? 'UNKNOWN' : (str_starts_with(trim($row->mfg), '+') ? 'PLUS' : 'WIRE');
            $row->normalized_mfg = $this->normalizeMfg($row->mfg);
            $row->data_issue = $this->joinIssues([
                (int) $row->mfg_candidate_count === 0 ? 'หา MFG จาก Delivery/Part+Qty ไม่ได้' : null,
                (int) $row->mfg_candidate_count > 1 ? 'พบ MFG มากกว่า 1 รายการจาก Part+Qty' : null,
                $row->product_group === 'ไม่ระบุกลุ่ม' ? 'Part ไม่มี partsgroup/partscategory' : null,
            ]);
            return $row;
        });
    }

    private function applyProductionCosts(Collection $rows): Collection
    {
        $costs = collect();
        foreach (self::CONNECTIONS as $site => $connection) {
            $targets = $rows->where('production_site', $site)
                ->filter(fn ($row) => $row->normalized_mfg !== '' && $row->partnumber !== null)
                ->map(fn ($row) => ['mfg' => $row->normalized_mfg, 'partnumber' => strtoupper(trim($row->partnumber))])
                ->unique(fn ($row) => $row['mfg'].'|'.$row['partnumber'])->values();
            if ($targets->isEmpty()) continue;

            $mfgs = $targets->pluck('mfg')->unique()->values()->all();
            $parts = $targets->pluck('partnumber')->unique()->values()->all();
            $mfgPlaceholders = implode(',', array_fill(0, count($mfgs), '?'));
            $partPlaceholders = implode(',', array_fill(0, count($parts), '?'));
            $sql = str_replace(['__MFGS__', '__PARTS__'], [$mfgPlaceholders, $partPlaceholders], self::COST_SQL);
            $found = collect(DB::connection($connection)->select($sql, [...$mfgs, ...$parts]))
                ->map(function ($row) use ($site) {
                    $row->production_site = $site;
                    $row->quantity = (float) $row->quantity;
                    $row->unit_cost = $row->unit_cost === null ? null : (float) $row->unit_cost;
                    return $row;
                });
            $costs = $costs->concat($found);
        }

        return $rows->map(function ($row) use ($costs) {
            $matches = $costs->filter(fn ($cost) =>
                $cost->production_site === $row->production_site
                && $cost->normalized_mfg === $row->normalized_mfg
                && strtoupper(trim($cost->partnumber)) === strtoupper(trim($row->partnumber))
                && abs(abs($cost->quantity) - abs((float) $row->quantity)) < 0.0001
            )->values();

            if ($matches->count() !== 1) {
                $row->unit_cost = null;
                $row->total_cost = null;
                $row->gross_profit = null;
                $row->margin = null;
                $row->data_issue = $this->joinIssues([
                    $row->data_issue,
                    $matches->isEmpty()
                        ? 'ไม่พบต้นทุนที่ตรง MFG+Part+Qty ใน Production Site'
                        : 'พบต้นทุนมากกว่า 1 รายการที่ตรง MFG+Part+Qty',
                ]);
                return $row;
            }

            $cost = $matches->first();
            $sign = (float) $row->quantity < 0 || (float) $row->sales_thb < 0 ? -1 : 1;
            $row->unit_cost = $cost->unit_cost;
            $row->total_cost = $cost->unit_cost === null ? null : $sign * abs((float) $row->quantity) * $cost->unit_cost;
            $row->gross_profit = $row->total_cost === null ? null : (float) $row->sales_thb - $row->total_cost;
            $row->margin = (float) $row->sales_thb != 0.0 && $row->gross_profit !== null
                ? ($row->gross_profit / (float) $row->sales_thb) * 100 : null;
            $row->data_issue = $this->joinIssues([
                $row->data_issue,
                $cost->unit_cost === null || $cost->unit_cost == 0.0 ? 'ต้นทุนว่างหรือเป็นศูนย์' : null,
                (int) $cost->cost_count > 1 ? 'MFG มีต้นทุนต่อหน่วยหลายค่า (Weighted Average)' : null,
            ]);
            return $row;
        });
    }

    private function connections(string $site): array
    {
        return $site === 'ALL' ? self::CONNECTIONS : [$site => self::CONNECTIONS[$site]];
    }

    private function site($value): string
    {
        $site = strtoupper(trim((string) $value));
        return in_array($site, ['ALL', 'WIRE', 'PLUS'], true) ? $site : 'ALL';
    }

    private function date($value, string $fallback): string
    {
        try { return $value ? Carbon::parse($value)->toDateString() : $fallback; }
        catch (\Throwable $e) { return $fallback; }
    }

    private function normalizeMfg($value): string
    {
        return ltrim(strtoupper(trim(explode('-', (string) $value, 2)[0])), '+');
    }

    private function joinIssues(array $issues): ?string
    {
        $issues = array_values(array_unique(array_filter(array_map(fn ($issue) => trim((string) $issue), $issues))));
        return $issues === [] ? null : implode('; ', $issues);
    }

    private const SALES_SQL = <<<'SQL'
WITH linked_movement AS (
    SELECT i.id invoice_item_id, NULLIF(SPLIT_PART(TRIM(su.serialnumber), '-', 1), '') mfg,
           SUM(ABS(sm.qty)) movement_qty
    FROM ar
    JOIN invoice i ON i.trans_id = ar.id
    JOIN parts p ON p.id = i.parts_id
    JOIN delivery d ON d.ar_id = ar.id
    JOIN deliveryitems di ON di.delivery_id = d.id AND di.invoice_id = i.id
    JOIN oe ON oe.id = d.ord_id
    JOIN dm ON dm.ordnumber = oe.ordnumber
    JOIN dmitems dmi ON dmi.trans_id = dm.id AND dmi.orderitems_id = di.orderitems_id
    JOIN serializeunitsmvmt sm ON sm.trans_id = dm.id AND sm.invoice_id = dmi.id
    JOIN serializeunits su ON su.id = sm.su_id
    WHERE __WHERE__
    GROUP BY i.id, NULLIF(SPLIT_PART(TRIM(su.serialnumber), '-', 1), '')
), exact_serial_candidate AS (
    SELECT i.id invoice_item_id, NULLIF(SPLIT_PART(TRIM(su.serialnumber), '-', 1), '') mfg,
           ABS(su.qty) movement_qty
    FROM ar
    JOIN invoice i ON i.trans_id = ar.id
    JOIN parts p ON p.id = i.parts_id
    JOIN parts sp ON UPPER(TRIM(sp.partnumber)) = UPPER(TRIM(p.partnumber))
    JOIN serializeunits su ON su.parts_id = sp.id
      AND ABS(ABS(su.qty) - ABS(i.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END)) < 0.0001
    WHERE __WHERE__
), candidates AS (
    SELECT invoice_item_id, mfg, movement_qty FROM linked_movement
    UNION ALL
    SELECT e.invoice_item_id, e.mfg, e.movement_qty FROM exact_serial_candidate e
    WHERE NOT EXISTS (SELECT 1 FROM linked_movement l WHERE l.invoice_item_id = e.invoice_item_id)
), candidate_summary AS (
    SELECT invoice_item_id, COUNT(DISTINCT mfg) mfg_candidate_count,
           CASE WHEN COUNT(DISTINCT mfg) = 1 THEN MIN(mfg) END mfg,
           CASE WHEN COUNT(DISTINCT mfg) = 1 THEN MAX(movement_qty) END movement_qty
    FROM candidates GROUP BY invoice_item_id
)
SELECT '__SITE__' sales_site, ar.transdate invoice_date, TRIM(ar.invnumber) invoice_no,
       i.id invoice_item_id, ar.customer_id, p.partnumber,
       COALESCE(NULLIF(TRIM(i.description), ''), p.description) item_description,
       COALESCE(NULLIF(TRIM(pg.description), ''), NULLIF(TRIM(pc.description), ''), 'ไม่ระบุกลุ่ม') product_group,
       CASE WHEN NULLIF(TRIM(pg.description), '') IS NOT NULL THEN 'partsgroup'
            WHEN NULLIF(TRIM(pc.description), '') IS NOT NULL THEN 'partscategory' ELSE NULL END product_group_source,
       CASE WHEN p.ref_unit = '03' THEN 'KG.' ELSE COALESCE(NULLIF(TRIM(i.unit), ''), p.ref_unit, p.unit) END unit,
       cs.mfg, COALESCE(cs.mfg_candidate_count, 0) mfg_candidate_count,
       (CASE WHEN i.qty * i.sellprice < 0 THEN -1 ELSE 1 END)
           * COALESCE(cs.movement_qty, ABS(i.qty) * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END) quantity,
       i.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END invoice_quantity,
       i.sellprice / NULLIF(CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END, 0) unit_price,
       i.qty * i.sellprice sales_original, TRIM(COALESCE(ar.curr, 'THB')) currency,
       CASE WHEN TRIM(COALESCE(ar.curr, 'THB')) = 'THB' THEN 1
            ELSE COALESCE(NULLIF(ex.sell, 0), NULLIF(ex.buy, 0)) END exchange_rate,
       i.qty * i.sellprice sales_thb
FROM ar
JOIN invoice i ON i.trans_id = ar.id
JOIN parts p ON p.id = i.parts_id
LEFT JOIN partsgroup pg ON pg.id = p.partsgroup_id
LEFT JOIN partscategory pc ON pc.id = p.partscategory_id
LEFT JOIN exchangerate ex ON ex.id = ar.exchangerate_id
LEFT JOIN candidate_summary cs ON cs.invoice_item_id = i.id
WHERE __WHERE__
ORDER BY ar.transdate, ar.invnumber, i.id
SQL;

    private const COST_SQL = <<<'SQL'
WITH serial_cost AS (
    SELECT su.id,
           UPPER(TRIM(BOTH '+' FROM SPLIT_PART(TRIM(su.serialnumber), '-', 1))) normalized_mfg,
           UPPER(TRIM(p.partnumber)) partnumber, ABS(su.qty) quantity,
           COALESCE(
               SUM(ABS(sm.qty) * COALESCE(NULLIF(sm.unitcost, 0), NULLIF(su.unitcost, 0))) / NULLIF(SUM(ABS(sm.qty)), 0),
               NULLIF(su.unitcost, 0)
           ) unit_cost
    FROM serializeunits su
    JOIN parts p ON p.id = su.parts_id
    LEFT JOIN serializeunitsmvmt sm ON sm.su_id = su.id
    WHERE UPPER(TRIM(BOTH '+' FROM SPLIT_PART(TRIM(su.serialnumber), '-', 1))) IN (__MFGS__)
      AND UPPER(TRIM(p.partnumber)) IN (__PARTS__)
    GROUP BY su.id, su.serialnumber, p.partnumber, su.qty, su.unitcost
)
SELECT normalized_mfg, partnumber, SUM(quantity) quantity,
       SUM(quantity * unit_cost) / NULLIF(SUM(quantity), 0) unit_cost,
       COUNT(DISTINCT unit_cost) cost_count
FROM serial_cost
GROUP BY normalized_mfg, partnumber
SQL;
}
