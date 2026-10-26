<?php

namespace App\Services\FormAccounting;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceMfgProfitService
{
    private const CONNECTIONS = ['WIRE' => 'pgsqlw', 'PLUS' => 'pgsqlp'];

    public function filters(array $input): array
    {
        $from = $this->date($input['date_from'] ?? null, now()->startOfYear()->toDateString());
        $to = $this->date($input['date_to'] ?? null, now()->toDateString());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'site' => in_array(strtoupper((string) ($input['site'] ?? 'ALL')), ['WIRE', 'PLUS'], true)
                ? strtoupper((string) $input['site']) : 'ALL',
            'invoice' => trim((string) ($input['invoice'] ?? '')),
            'sales_order' => trim((string) ($input['sales_order'] ?? '')),
            'mfg' => trim((string) ($input['mfg'] ?? '')),
            'customer' => trim((string) ($input['customer'] ?? '')),
            'partnumber' => trim((string) ($input['partnumber'] ?? '')),
        ];
    }

    public function inquiry(array $input, int $limit = 500): array
    {
        $filters = $this->filters($input);
        $rows = collect();
        foreach ($this->years($filters) as $year) {
            foreach ($this->connections($filters['site']) as $site => $connection) {
                $remaining = max(0, $limit - $rows->count());
                if ($remaining === 0) {
                    break 2;
                }
                $rows = $rows->concat($this->query($connection, $site, $filters, $year, $remaining));
            }
        }

        $summary = $this->summarize($rows);

        return compact('filters', 'rows', 'summary') + [
            'years' => $this->years($filters),
            'limit' => $limit,
            'limited' => $rows->count() >= $limit,
        ];
    }

    public function rowsForYear(array $input, int $year): Collection
    {
        $filters = $this->filters($input);
        $rows = collect();
        foreach ($this->connections($filters['site']) as $site => $connection) {
            $rows = $rows->concat($this->query($connection, $site, $filters, $year));
        }
        return $rows->sortBy(['invoice_date', 'invoice_no', 'invoice_item_id', 'mfg'])->values();
    }

    public function exportData(array $input): array
    {
        $filters = $this->filters($input);
        $byYear = collect($this->years($filters))->mapWithKeys(fn ($year) => [$year => $this->rowsForYear($filters, $year)]);
        $all = $byYear->flatten(1);

        return compact('filters', 'byYear') + [
            'summary' => $this->summarize($all),
            'issues' => $all->filter(fn ($row) => $row->data_issue !== '')->values(),
        ];
    }

    public function summarize(Collection $rows): array
    {
        $sales = (float) $rows->sum('sales_thb');
        $cost = (float) $rows->sum('total_cost');
        $profit = $sales - $cost;

        return [
            'invoice_count' => $rows->pluck('site_invoice_key')->unique()->count(),
            'line_count' => $rows->count(),
            'mfg_count' => $rows->pluck('site_mfg_key')->filter()->unique()->count(),
            'sales' => $sales,
            'cost' => $cost,
            'gross_profit' => $profit,
            'margin' => $sales != 0.0 ? ($profit / $sales) * 100 : null,
            'issue_count' => $rows->where('data_issue', '!=', '')->count(),
            'years' => $rows->groupBy(fn ($row) => substr((string) $row->invoice_date, 0, 4))
                ->map(function (Collection $yearRows, $year) {
                    $sales = (float) $yearRows->sum('sales_thb');
                    $cost = (float) $yearRows->sum('total_cost');
                    return ['year' => (int) $year, 'sales' => $sales, 'cost' => $cost,
                        'gross_profit' => $sales - $cost,
                        'margin' => $sales != 0.0 ? (($sales - $cost) / $sales) * 100 : null,
                        'rows' => $yearRows->count()];
                })->values(),
        ];
    }

    private function query(string $connection, string $site, array $filters, int $year, ?int $limit = null): Collection
    {
        $yearFrom = max($filters['date_from'], sprintf('%04d-01-01', $year));
        $yearTo = min($filters['date_to'], sprintf('%04d-12-31', $year));
        $where = ['ar.transdate BETWEEN ? AND ?'];
        $bindings = [$yearFrom, $yearTo];
        foreach ([
            'invoice' => 'ar.invnumber', 'sales_order' => 'ar.ordnumber',
            'customer' => "COALESCE(c.customernumber,'') || ' ' || COALESCE(c.name,'')",
            'partnumber' => 'p.partnumber',
        ] as $key => $column) {
            if ($filters[$key] !== '') {
                $where[] = "{$column} ILIKE ?";
                $bindings[] = '%' . $filters[$key] . '%';
            }
        }
        $baseBindings = $bindings;
        $having = '';
        if ($filters['mfg'] !== '') {
            $having = 'WHERE q.mfg ILIKE ?';
        }
        $bindings = array_merge($baseBindings, $baseBindings);
        if ($filters['mfg'] !== '') $bindings[] = '%' . $filters['mfg'] . '%';
        $limitSql = $limit ? 'LIMIT ' . (int) $limit : '';
        $sql = str_replace(['__SITE__', '__WHERE__', '__MFG_WHERE__', '__LIMIT__'], [
            $site, implode(' AND ', $where), $having, $limitSql,
        ], self::SQL);

        return collect(DB::connection($connection)->select($sql, $bindings))->map(function ($row) use ($site) {
            $row->site_invoice_key = $site . '|' . $row->invoice_no;
            $row->site_mfg_key = $row->mfg ? $site . '|' . $row->mfg : null;
            foreach (['quantity', 'unit_price', 'sales_original', 'exchange_rate', 'sales_thb', 'unit_cost', 'total_cost', 'gross_profit', 'margin'] as $field) {
                $row->{$field} = $row->{$field} === null ? null : (float) $row->{$field};
            }
            return $row;
        });
    }

    private function connections(string $site): array
    {
        return $site === 'ALL' ? self::CONNECTIONS : [$site => self::CONNECTIONS[$site]];
    }

    private function years(array $filters): array
    {
        return range((int) substr($filters['date_from'], 0, 4), (int) substr($filters['date_to'], 0, 4));
    }

    private function date($value, string $fallback): string
    {
        try { return $value ? Carbon::parse($value)->toDateString() : $fallback; }
        catch (\Throwable $e) { return $fallback; }
    }

    private const SQL = <<<'SQL'
WITH movement AS (
    SELECT i.id invoice_item_id, di.orderitems_id, d.ord_id,
           NULLIF(split_part(TRIM(su.serialnumber), '-', 1), '') mfg,
           SUM(ABS(sm.qty)) movement_qty,
           SUM(ABS(sm.qty) * COALESCE(NULLIF(sm.unitcost, 0), su.unitcost, 0)) movement_cost,
           COUNT(DISTINCT COALESCE(sm.unitcost, su.unitcost)) cost_count,
           COUNT(DISTINCT su.id) serial_count
    FROM ar
    JOIN invoice i ON i.trans_id = ar.id
    JOIN parts p ON p.id = i.parts_id
    LEFT JOIN customer c ON c.id = ar.customer_id
    JOIN delivery d ON d.ar_id = ar.id
    JOIN deliveryitems di ON di.delivery_id = d.id AND di.invoice_id = i.id
    JOIN oe ON oe.id = d.ord_id
    JOIN dm ON dm.ordnumber = oe.ordnumber AND dm.transdate = ar.transdate
    JOIN dmitems dmi ON dmi.trans_id = dm.id AND dmi.orderitems_id = di.orderitems_id
    JOIN serializeunitsmvmt sm ON sm.trans_id = dm.id AND sm.invoice_id = dmi.id
    JOIN serializeunits su ON su.id = sm.su_id
    WHERE COALESCE(ar.invoice, TRUE) = TRUE AND __WHERE__
    GROUP BY i.id, di.orderitems_id, d.ord_id, NULLIF(split_part(TRIM(su.serialnumber), '-', 1), '')
), movement_total AS (
    SELECT movement.*, SUM(movement_qty) OVER (PARTITION BY invoice_item_id) item_movement_qty
    FROM movement
), base AS (
    SELECT '__SITE__' site, ar.transdate invoice_date, TRIM(ar.invnumber) invoice_no,
           TRIM(COALESCE(oe.ordnumber, ar.ordnumber)) sales_order, i.id invoice_item_id, mt.orderitems_id sales_order_item_id,
           c.customernumber customer_code, c.name customer_name,
           COALESCE(NULLIF(TRIM(oe.shippingpoint), ''), NULLIF(TRIM(c.shiptoname), ''), '-') ship_to,
           p.partnumber, COALESCE(NULLIF(TRIM(i.description), ''), p.description) item_description,
           CASE WHEN p.ref_unit = '03' THEN 'KG.' ELSE COALESCE(NULLIF(TRIM(i.unit), ''), p.ref_unit, p.unit) END unit,
           mt.mfg, (CASE WHEN i.qty * i.sellprice < 0 THEN -1 ELSE 1 END)
               * COALESCE(mt.movement_qty, ABS(i.qty) * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END) quantity,
           i.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END invoice_quantity,
           (i.sellprice / NULLIF(CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END, 0)) unit_price,
           (i.qty * i.sellprice) * CASE WHEN mt.item_movement_qty > 0 THEN mt.movement_qty / mt.item_movement_qty ELSE 1 END sales_original,
           TRIM(COALESCE(ar.curr, 'THB')) currency,
           CASE WHEN TRIM(COALESCE(ar.curr, 'THB')) = 'THB' THEN 1 ELSE COALESCE(NULLIF(ex.sell, 0), NULLIF(ex.buy, 0)) END exchange_rate,
           (CASE WHEN i.qty * i.sellprice < 0 THEN -1 ELSE 1 END) * mt.movement_cost total_cost,
           mt.cost_count, mt.serial_count, mt.item_movement_qty,
           (mt.orderitems_id IS NULL) missing_so_item
    FROM ar
    JOIN invoice i ON i.trans_id = ar.id
    JOIN parts p ON p.id = i.parts_id
    LEFT JOIN customer c ON c.id = ar.customer_id
    LEFT JOIN exchangerate ex ON ex.id = ar.exchangerate_id
    LEFT JOIN movement_total mt ON mt.invoice_item_id = i.id
    LEFT JOIN oe ON oe.id = mt.ord_id
    WHERE COALESCE(ar.invoice, TRUE) = TRUE AND __WHERE__
), q AS (
    SELECT *, sales_original * exchange_rate sales_thb,
           total_cost / NULLIF(quantity, 0) unit_cost
    FROM base
)
SELECT q.*, (sales_thb - COALESCE(total_cost, 0)) gross_profit,
       CASE WHEN sales_thb <> 0 THEN ((sales_thb - COALESCE(total_cost, 0)) / sales_thb) * 100 END margin,
       CONCAT_WS('; ',
           CASE WHEN missing_so_item THEN 'Invoice Item เชื่อม SO Item ไม่ได้' END,
           CASE WHEN serial_count IS NULL OR serial_count = 0 THEN 'ไม่มี Delivery/Serial movement' END,
           CASE WHEN mfg IS NULL THEN 'หา MFG จาก Serial ไม่ได้' END,
           CASE WHEN total_cost IS NULL OR total_cost = 0 THEN 'ต้นทุนว่างหรือเป็นศูนย์' END,
           CASE WHEN item_movement_qty IS NOT NULL AND ABS(ABS(invoice_quantity) - item_movement_qty) > 0.0001 THEN 'จำนวน Invoice กับจำนวนส่งจริงไม่ตรง' END,
           CASE WHEN currency <> 'THB' AND exchange_rate IS NULL THEN 'Invoice ต่างประเทศแต่ไม่มี Exchange Rate' END,
           CASE WHEN cost_count > 1 THEN 'MFG มีต้นทุนต่อหน่วยหลายค่า (Weighted Average)' END
       ) data_issue
FROM q
__MFG_WHERE__
ORDER BY invoice_date, invoice_no, invoice_item_id, mfg
__LIMIT__
SQL;
}
