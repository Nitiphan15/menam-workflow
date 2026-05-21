<?php

namespace App\Services\FormVC;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CostCenterReportService
{
    private const CONNECTIONS = [
        'WIRE' => 'pgsqlw',
        'PLUS' => 'pgsqlp',
    ];

    public function reportRows(array $filters): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $sites = $filters['site'] === 'ALL'
            ? self::CONNECTIONS
            : [$filters['site'] => self::CONNECTIONS[$filters['site']]];

        $rows = collect();
        foreach ($sites as $site => $connectionName) {
            $rows = $rows->concat($this->fetchReportRows(DB::connection($connectionName), $site, $filters));
        }

        return $rows
            ->sortBy([
                ['site', 'asc'],
                ['classnumber', 'asc'],
                ['account_no', 'asc'],
                ['report_date', 'asc'],
                ['invoice_no', 'asc'],
                ['order_no', 'asc'],
                ['ccalloc_id', 'asc'],
                ['invoice_line_id', 'asc'],
            ])
            ->values();
    }

    public function exportRows(array $filters): Collection
    {
        return $this->reportRows($filters)
            ->groupBy(fn($row) => $row->site . '|' . $row->classnumber)
            ->flatMap(function (Collection $classRows) {
                $runningBalance = 0.0;

                return $classRows
                    ->groupBy('account_label')
                    ->flatMap(function (Collection $accountRows) use (&$runningBalance) {
                        $rows = $accountRows->map(function ($row) use (&$runningBalance) {
                            $runningBalance += (float) $row->line_amount;

                            return [
                                'report_date' => $row->report_date,
                                'invoice_no' => $row->invoice_no,
                                'order_no' => $row->order_no,
                                'account_label' => $row->account_label,
                                'item_name' => $row->item_name,
                                'qty' => (float) $row->qty,
                                'unit_price' => (float) $row->unit_price,
                                'line_amount' => (float) $row->line_amount,
                                'running_balance' => $runningBalance,
                                'notes' => $row->notes,
                                'extra_1' => $row->extra_1,
                                'extra_2' => $row->extra_2,
                                'extra_3' => $row->extra_3,
                                'extra_4' => $row->extra_4,
                                'extra_5' => $row->extra_5,
                            ];
                        });

                        $rows->push([
                            'report_date' => '',
                            'invoice_no' => '',
                            'order_no' => '',
                            'account_label' => '',
                            'item_name' => $this->subtotalLabel($accountRows->count()),
                            'qty' => null,
                            'unit_price' => null,
                            'line_amount' => (float) $accountRows->sum('line_amount'),
                            'running_balance' => $runningBalance,
                            'notes' => '',
                            'extra_1' => '',
                            'extra_2' => '',
                            'extra_3' => '',
                            'extra_4' => '',
                            'extra_5' => '',
                        ]);

                        return $rows;
                    });
            })
            ->values();
    }

    public function validationTotals(array $filters): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $sites = $filters['site'] === 'ALL'
            ? self::CONNECTIONS
            : [$filters['site'] => self::CONNECTIONS[$filters['site']]];

        $rows = collect();
        foreach ($sites as $site => $connectionName) {
            $rows = $rows->concat($this->fetchValidationTotals(DB::connection($connectionName), $site, $filters));
        }

        return $rows->sortBy(['site', 'classnumber', 'account_no'])->values();
    }

    public function monthlyTotals(array $filters): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $sites = $filters['site'] === 'ALL'
            ? self::CONNECTIONS
            : [$filters['site'] => self::CONNECTIONS[$filters['site']]];

        $rows = collect();
        foreach ($sites as $site => $connectionName) {
            $bindings = [
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'classnumber' => $filters['classnumber'],
            ];

            $sql = <<<'SQL'
SELECT
    EXTRACT(MONTH FROM at.transdate)::int AS month,
    SUM(ABS(ca.amount)) AS total
FROM acc_trans at
JOIN ccalloc ca
    ON ca.acc_trans_id = at.id
JOIN classinfo ci
    ON ci.id = ca.class_id
WHERE at.transdate >= :date_from
  AND at.transdate < :date_to
  AND (CAST(:classnumber AS text) IS NULL OR ci.classnumber = CAST(:classnumber AS text))
GROUP BY EXTRACT(MONTH FROM at.transdate)
ORDER BY 1
SQL;

            $rows = $rows->concat(
                collect(DB::connection($connectionName)->select($sql, $bindings))
                    ->map(function ($row) use ($site) {
                        $row->site = $site;
                        $row->month = (int) $row->month;
                        $row->total = (float) $row->total;
                        return $row;
                    })
            );
        }

        return $rows->values();
    }

    public function topAccountTotals(array $filters, int $limit = 10): Collection
    {
        return $this->validationTotals($filters)
            ->groupBy('account_label')
            ->map(function (Collection $g) {
                return (object) [
                    'account_label' => $g->first()->account_label,
                    'account_no' => $g->first()->account_no,
                    'total' => (float) $g->sum('ccalloc_total'),
                ];
            })
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }

    private function fetchReportRows(Connection $db, string $site, array $filters): Collection
    {
        $bindings = [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'classnumber' => $filters['classnumber'],
        ];

        return collect($db->select($this->reportSql(), $bindings))
            ->map(function ($row) use ($site) {
                $row->site = $site;
                foreach ([
                    'class_group',
                    'invoice_no',
                    'order_no',
                    'account_label',
                    'item_name',
                    'notes',
                    'extra_1',
                    'extra_2',
                    'extra_3',
                    'extra_4',
                    'extra_5',
                    'parent_type',
                ] as $column) {
                    $row->{$column} = $this->cleanText($row->{$column} ?? '');
                }

                $row->qty = (float) $row->qty;
                $row->unit_price = (float) $row->unit_price;
                $row->line_amount = (float) $row->line_amount;
                $row->running_balance = (float) $row->running_balance;

                return $row;
            });
    }

    private function fetchValidationTotals(Connection $db, string $site, array $filters): Collection
    {
        $bindings = [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'classnumber' => $filters['classnumber'],
        ];

        return collect($db->select($this->validationSql(), $bindings))
            ->map(function ($row) use ($site) {
                $row->site = $site;
                $row->class_group = $this->cleanText($row->class_group ?? '');
                $row->account_label = $this->cleanText($row->account_label ?? '');
                $row->ccalloc_total = (float) $row->ccalloc_total;
                $row->allocation_rows = (int) $row->allocation_rows;

                return $row;
            });
    }

    private function normalizeFilters(array $filters): array
    {
        $site = strtoupper((string) ($filters['site'] ?? 'WIRE'));
        if (!isset(self::CONNECTIONS[$site]) && $site !== 'ALL') {
            $site = 'WIRE';
        }

        $dateFrom = (string) ($filters['date_from'] ?? date('Y-m-01'));
        $dateTo = (string) ($filters['date_to'] ?? date('Y-m-d', strtotime('first day of next month')));
        $classnumber = trim((string) ($filters['classnumber'] ?? $filters['class_no'] ?? ''));

        return [
            'site' => $site,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'classnumber' => $classnumber !== '' ? $classnumber : null,
        ];
    }

    private function reportSql(): string
    {
        return <<<'SQL'
WITH base_alloc AS (
    SELECT
        at.id AS acc_trans_id,
        at.trans_id,
        at.transdate,
        at.source AS acc_source,
        ca.id AS ccalloc_id,
        ca.chart_id,
        ca.class_id,
        ABS(ca.amount)::numeric AS class_amount,
        c.accno AS account_no,
        c.description AS account_name,
        ci.classnumber,
        ci.description AS class_name
    FROM acc_trans at
    JOIN ccalloc ca
        ON ca.acc_trans_id = at.id
    JOIN chart c
        ON c.id = ca.chart_id
    JOIN classinfo ci
        ON ci.id = ca.class_id
    WHERE at.transdate >= :date_from
      AND at.transdate < :date_to
      AND (CAST(:classnumber AS text) IS NULL OR ci.classnumber = CAST(:classnumber AS text))
),
document_rows AS (
    SELECT
        ba.*,
        CASE
            WHEN gl.id IS NOT NULL THEN 'GL'
            WHEN ap.id IS NOT NULL THEN 'AP'
            WHEN ar.id IS NOT NULL THEN 'AR'
            WHEN ct.trans_id IS NOT NULL THEN 'PAYMENT/CASHTRANS'
            WHEN rt.id IS NOT NULL THEN 'RETURN'
            ELSE 'UNKNOWN'
        END AS parent_type,
        CASE
            WHEN gl.id IS NOT NULL THEN gl.reference
            WHEN ap.id IS NOT NULL THEN ap.invnumber
            WHEN ar.id IS NOT NULL THEN ar.invnumber
            WHEN ct.trans_id IS NOT NULL THEN COALESCE(ap_pay.invnumber, ar_pay.invnumber)
            WHEN rt.id IS NOT NULL THEN rt.returnnumber
            ELSE ba.trans_id::text
        END AS invoice_no,
        CASE
            WHEN ap.id IS NOT NULL THEN ap.ordnumber
            WHEN ar.id IS NOT NULL THEN ar.ordnumber
            WHEN ct.trans_id IS NOT NULL THEN COALESCE(ap_pay.ordnumber, ar_pay.ordnumber)
            WHEN rt.id IS NOT NULL THEN COALESCE(ar_ret.ordnumber, rt.invnumber)
            ELSE ''
        END AS order_no,
        COALESCE(ap.notes, ar.notes, gl.notes, ba.acc_source) AS notes,
        COALESCE(ap.f1, ar.f1, '') AS extra_2,
        COALESCE(ap.f2, ar.f2, '') AS extra_3,
        COALESCE(ap.f3, ar.f3, '') AS extra_4,
        COALESCE(ap.f4, ar.f4, '') AS extra_5
    FROM base_alloc ba
    LEFT JOIN ap
        ON ap.id = ba.trans_id
    LEFT JOIN ar
        ON ar.id = ba.trans_id
    LEFT JOIN gl
        ON gl.id = ba.trans_id
    LEFT JOIN cashtrans ct
        ON ct.trans_id = ba.trans_id
    LEFT JOIN ap ap_pay
        ON ap_pay.id = ct.ap_ar_id
    LEFT JOIN ar ar_pay
        ON ar_pay.id = ct.ap_ar_id
    LEFT JOIN public."return" rt
        ON rt.id = ba.trans_id
    LEFT JOIN LATERAL (
        SELECT ar_match.*
        FROM ar ar_match
        WHERE ar_match.id = rt.ap_ar_id
           OR ar_match.invnumber = rt.invnumber
        ORDER BY
            CASE WHEN ar_match.id = rt.ap_ar_id THEN 0 ELSE 1 END,
            ar_match.id
        LIMIT 1
    ) ar_ret
        ON rt.id IS NOT NULL
),
invoice_candidates AS (
    SELECT
        dr.*,
        i.id AS invoice_line_id,
        i.description AS invoice_description,
        i.ref1 AS invoice_ref1,
        i.ref2 AS invoice_ref2,
        i.f1 AS invoice_f1,
        i.f2 AS invoice_f2,
        i.f3 AS invoice_f3,
        i.f4 AS invoice_f4,
        i.f5 AS invoice_f5,
        i.qty,
        i.sellprice,
        i.unitcost,
        i.chart_id AS invoice_chart_id,
        i.class_id AS invoice_class_id,
        CASE
            WHEN i.id IS NULL THEN -1
            WHEN i.chart_id = dr.chart_id AND i.class_id = dr.class_id THEN 3
            WHEN i.class_id = dr.class_id THEN 2
            WHEN i.chart_id = dr.chart_id THEN 1
            WHEN i.chart_id IS NULL
                AND i.class_id IS NULL
                AND NULLIF(TRIM(i.description), '') IS NOT NULL THEN 0
            ELSE -1
        END AS match_rank
    FROM document_rows dr
    LEFT JOIN invoice i
        ON i.trans_id = dr.trans_id
       AND (
            (i.chart_id = dr.chart_id AND i.class_id = dr.class_id)
            OR i.class_id = dr.class_id
            OR i.chart_id = dr.chart_id
            OR (
                i.chart_id IS NULL
                AND i.class_id IS NULL
                AND NULLIF(TRIM(i.description), '') IS NOT NULL
            )
       )
),
best_invoice_lines AS (
    SELECT *
    FROM (
        SELECT
            ic.*,
            MAX(ic.match_rank) OVER (PARTITION BY ic.ccalloc_id) AS best_match_rank
        FROM invoice_candidates ic
    ) ranked
    WHERE ranked.invoice_line_id IS NULL
       OR ranked.match_rank = ranked.best_match_rank
),
detail_calc AS (
    SELECT
        bil.*,
        CASE
            WHEN bil.invoice_line_id IS NULL THEN 1::numeric
            WHEN ABS(COALESCE(bil.qty, 0)) = 0 THEN 1::numeric
            ELSE ROUND(ABS(bil.qty))::numeric
        END AS display_qty,
        CASE
            WHEN bil.invoice_line_id IS NULL THEN bil.class_amount
            ELSE ABS(COALESCE(bil.sellprice, bil.unitcost, 0))::numeric
        END AS raw_unit_price
    FROM best_invoice_lines bil
),
detail_reconciled AS (
    SELECT
        dc.*,
        dc.display_qty * dc.raw_unit_price AS raw_line_amount,
        COUNT(dc.invoice_line_id) OVER (PARTITION BY dc.ccalloc_id) AS invoice_line_count,
        SUM(dc.display_qty * dc.raw_unit_price) OVER (PARTITION BY dc.ccalloc_id) AS raw_invoice_total,
        ROW_NUMBER() OVER (
            PARTITION BY dc.ccalloc_id
            ORDER BY dc.invoice_line_id NULLS LAST
        ) AS invoice_line_seq
    FROM detail_calc dc
),
detail_rows AS (
    SELECT
        dr.*,
        CASE
            WHEN dr.invoice_line_id IS NULL THEN dr.class_amount
            WHEN dr.invoice_line_seq = dr.invoice_line_count THEN
                dr.raw_line_amount + (dr.class_amount - COALESCE(dr.raw_invoice_total, 0))
            ELSE dr.raw_line_amount
        END AS line_amount
    FROM detail_reconciled dr
),
ordered_rows AS (
    SELECT
        dr.*,
        ROW_NUMBER() OVER (
            PARTITION BY dr.classnumber
            ORDER BY dr.account_no, dr.transdate, dr.invoice_no, dr.order_no, dr.ccalloc_id, dr.invoice_line_id NULLS LAST
        ) AS class_sort_seq
    FROM detail_rows dr
)
SELECT
    classnumber || ' - ' || COALESCE(class_name, '') AS class_group,
    classnumber,
    class_name,
    transdate AS report_date,
    invoice_no,
    order_no,
    account_no,
    account_no || ' ' || COALESCE(account_name, '') AS account_label,
    COALESCE(NULLIF(invoice_description, ''), NULLIF(acc_source, ''), '-') AS item_name,
    display_qty AS qty,
    CASE WHEN display_qty = 0 THEN line_amount ELSE line_amount / display_qty END AS unit_price,
    line_amount,
    SUM(line_amount) OVER (
        PARTITION BY classnumber
        ORDER BY class_sort_seq
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS running_balance,
    notes,
    class_name AS extra_1,
    COALESCE(NULLIF(invoice_ref1, ''), NULLIF(extra_2, ''), '') AS extra_2,
    COALESCE(NULLIF(invoice_ref2, ''), NULLIF(extra_3, ''), '') AS extra_3,
    COALESCE(NULLIF(invoice_f1, ''), NULLIF(extra_4, ''), '') AS extra_4,
    COALESCE(NULLIF(invoice_f2, ''), NULLIF(extra_5, ''), '') AS extra_5,
    parent_type,
    acc_trans_id,
    ccalloc_id,
    invoice_line_id
FROM ordered_rows
ORDER BY
    classnumber,
    account_no,
    transdate,
    invoice_no,
    order_no,
    ccalloc_id,
    invoice_line_id NULLS LAST
SQL;
    }

    private function validationSql(): string
    {
        return <<<'SQL'
SELECT
    ci.classnumber,
    ci.classnumber || ' - ' || COALESCE(ci.description, '') AS class_group,
    c.accno AS account_no,
    c.accno || ' ' || COALESCE(c.description, '') AS account_label,
    COUNT(DISTINCT ca.id) AS allocation_rows,
    SUM(ABS(ca.amount)) AS ccalloc_total
FROM acc_trans at
JOIN ccalloc ca
    ON ca.acc_trans_id = at.id
JOIN chart c
    ON c.id = ca.chart_id
JOIN classinfo ci
    ON ci.id = ca.class_id
WHERE at.transdate >= :date_from
  AND at.transdate < :date_to
  AND (CAST(:classnumber AS text) IS NULL OR ci.classnumber = CAST(:classnumber AS text))
GROUP BY ci.classnumber, ci.description, c.accno, c.description
ORDER BY ci.classnumber, c.accno
SQL;
    }

    private function cleanText(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            foreach (['Windows-874', 'TIS-620'] as $encoding) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
                if (is_string($converted) && $converted !== '') {
                    return trim($converted);
                }
            }
        }

        return $value;
    }

    private function subtotalLabel(int $count): string
    {
        return sprintf("\u{0E23}\u{0E27}\u{0E21} %d \u{0E23}\u{0E32}\u{0E22}\u{0E01}\u{0E32}\u{0E23}", $count);
    }
}
