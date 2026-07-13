<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerOrderInvoiceComparisonController extends Controller
{
    private string $connection = 'pgsqlw';

    public function index(Request $request)
    {
        $customer = trim((string) $request->query('customer', ''));
        $tier = strtoupper(trim((string) $request->query('tier', '')));
        if (!in_array($tier, ['A', 'B', 'C'], true)) {
            $tier = '';
        }

        $today = Carbon::today('Asia/Bangkok');
        $cutoffDate = $today->copy()->subYears(3)->toDateString();
        $rollingFrom = $today->copy()->startOfMonth()->subMonths(11)->toDateString();
        $allRows = $this->assignTiers($this->customerSummary($rollingFrom), $cutoffDate);
        $filteredRows = array_values(array_filter($allRows, function ($row) use ($customer, $tier) {
            if ($tier !== '' && $row->tier !== $tier) {
                return false;
            }

            if ($customer === '') {
                return true;
            }

            $haystack = mb_strtolower(trim($row->customernumber . ' ' . $row->customer_name));

            return str_contains($haystack, mb_strtolower($customer));
        }));

        $totals = [
            'customers' => count($filteredRows),
            'sales_orders' => collect($filteredRows)->sum(fn ($row) => (int) $row->sales_order_count),
            'sales_orders_12m' => collect($filteredRows)->sum(fn ($row) => (int) $row->sales_order_count_12m),
            'order_weight' => collect($filteredRows)->sum(fn ($row) => (float) $row->order_weight),
            'order_weight_12m' => collect($filteredRows)->sum(fn ($row) => (float) $row->order_weight_12m),
            'invoice_weight' => collect($filteredRows)->sum(fn ($row) => (float) $row->invoice_weight),
            'invoice_weight_12m' => collect($filteredRows)->sum(fn ($row) => (float) $row->invoice_weight_12m),
        ];
        $totals['remaining_weight'] = $totals['order_weight'] - $totals['invoice_weight'];
        $totals['invoice_percent'] = $totals['order_weight'] > 0
            ? ($totals['invoice_weight'] / $totals['order_weight']) * 100
            : null;
        $tierCounts = collect($allRows)->countBy('tier')->all();
        $perPage = 100;
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator(
            array_slice($filteredRows, ($page - 1) * $perPage, $perPage),
            count($filteredRows),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('formwos.customer_order_invoice.index', [
            'rows' => $rows,
            'totals' => $totals,
            'tierCounts' => array_merge(['A' => 0, 'B' => 0, 'C' => 0], $tierCounts),
            'filters' => compact('customer', 'tier'),
            'cutoffDate' => $cutoffDate,
            'rollingFrom' => $rollingFrom,
            'rollingTo' => $today->toDateString(),
        ]);
    }

    public function detail(Request $request, int $customerId)
    {
        $customer = DB::connection($this->connection)
            ->table('customer')
            ->select('id', 'customernumber', 'name', 'purchase_grade', 'payment_grade')
            ->where('active', true)
            ->find($customerId);

        abort_if(!$customer, 404);

        $allOrders = $this->customerOrderDetail($customerId);
        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $pageOrders = array_slice($allOrders, ($page - 1) * $perPage, $perPage);
        $orders = new LengthAwarePaginator(
            $pageOrders,
            count($allOrders),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
        $invoiceRows = $this->invoiceDetail($customerId, array_column($pageOrders, 'ordnumber'));
        $invoicesByOrder = collect($invoiceRows)->groupBy('ordnumber');

        $totals = [
            'sales_orders' => count($allOrders),
            'order_weight' => collect($allOrders)->sum(fn ($row) => (float) $row['order_weight']),
            'invoice_weight' => collect($allOrders)->sum(fn ($row) => (float) $row['invoice_weight']),
        ];
        $totals['remaining_weight'] = $totals['order_weight'] - $totals['invoice_weight'];
        $totals['invoice_percent'] = $totals['order_weight'] > 0
            ? ($totals['invoice_weight'] / $totals['order_weight']) * 100
            : null;

        return view('formwos.customer_order_invoice.detail', [
            'customer' => $customer,
            'orders' => $orders,
            'invoicesByOrder' => $invoicesByOrder,
            'totals' => $totals,
            'filters' => [
                'customer' => trim((string) $request->query('customer', '')),
                'tier' => strtoupper(trim((string) $request->query('tier', ''))),
            ],
        ]);
    }

    private function customerSummary(string $rollingFrom): array
    {
        $sql = "
            WITH order_by_so AS (
                SELECT
                    oe.customer_id,
                    c.customernumber,
                    c.name AS customer_name,
                    c.purchase_grade,
                    c.payment_grade,
                    TRIM(oe.ordnumber) AS ordnumber,
                    MIN(oe.transdate) AS order_date,
                    SUM(oi.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END) AS order_weight,
                    SUM(
                        CASE WHEN oe.transdate >= ?
                        THEN oi.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END
                        ELSE 0 END
                    ) AS order_weight_12m
                FROM oe
                JOIN orderitems oi ON oi.trans_id = oe.id
                JOIN customer c ON c.id = oe.customer_id
                JOIN parts p ON p.id = oi.parts_id
                WHERE oe.ordnumber IS NOT NULL
                  AND c.active = TRUE
                  AND COALESCE(oe.cancelled, FALSE) = FALSE
                  AND COALESCE(TRIM(oi.unit), '') <> ''
                GROUP BY oe.customer_id, c.customernumber, c.name, c.purchase_grade, c.payment_grade, TRIM(oe.ordnumber)
            ),
            invoice_by_so AS (
                SELECT
                    ar.customer_id,
                    TRIM(ar.ordnumber) AS ordnumber,
                    SUM(inv.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END) AS invoice_weight,
                    SUM(
                        CASE WHEN ar.transdate >= ?
                        THEN inv.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END
                        ELSE 0 END
                    ) AS invoice_weight_12m,
                    COUNT(DISTINCT ar.invnumber) AS invoice_count,
                    MAX(ar.transdate) AS latest_invoice_date
                FROM ar
                JOIN invoice inv ON inv.trans_id = ar.id
                JOIN parts p ON p.id = inv.parts_id
                WHERE ar.ordnumber IS NOT NULL
                  AND COALESCE(ar.invoice, TRUE) = TRUE
                GROUP BY ar.customer_id, TRIM(ar.ordnumber)
            )
            SELECT
                obs.customer_id,
                obs.customernumber,
                obs.customer_name,
                obs.purchase_grade,
                obs.payment_grade,
                COUNT(*) AS sales_order_count,
                MIN(obs.order_date) AS first_order_date,
                MAX(obs.order_date) AS last_order_date,
                ROUND(COALESCE(SUM(obs.order_weight), 0), 2) AS order_weight,
                ROUND(COALESCE(SUM(obs.order_weight_12m), 0), 2) AS order_weight_12m,
                ROUND(COALESCE(SUM(ibs.invoice_weight), 0), 2) AS invoice_weight,
                ROUND(COALESCE(SUM(ibs.invoice_weight_12m), 0), 2) AS invoice_weight_12m,
                COUNT(*) FILTER (WHERE obs.order_date >= ?) AS sales_order_count_12m,
                COUNT(DISTINCT DATE_TRUNC('month', obs.order_date))
                    FILTER (WHERE obs.order_date >= ?) AS active_order_months_12m,
                ROUND(COALESCE(SUM(obs.order_weight), 0) - COALESCE(SUM(ibs.invoice_weight), 0), 2) AS remaining_weight,
                CASE
                    WHEN SUM(obs.order_weight) > 0
                    THEN ROUND((COALESCE(SUM(ibs.invoice_weight), 0) / SUM(obs.order_weight)) * 100, 2)
                    ELSE NULL
                END AS invoice_percent,
                COALESCE(SUM(ibs.invoice_count), 0) AS invoice_count,
                MAX(ibs.latest_invoice_date) AS latest_invoice_date
            FROM order_by_so obs
            LEFT JOIN invoice_by_so ibs
              ON ibs.customer_id = obs.customer_id
             AND ibs.ordnumber = obs.ordnumber
            GROUP BY obs.customer_id, obs.customernumber, obs.customer_name, obs.purchase_grade, obs.payment_grade
            ORDER BY invoice_weight_12m DESC, obs.customer_name
        ";

        return DB::connection($this->connection)->select($sql, [
            $rollingFrom,
            $rollingFrom,
            $rollingFrom,
            $rollingFrom,
        ]);
    }

    private function assignTiers(array $rows, string $cutoffDate): array
    {
        $activeRows = array_values(array_filter(
            $rows,
            fn ($row) => !empty($row->last_order_date) && $row->last_order_date >= $cutoffDate
        ));
        usort($activeRows, function ($left, $right) {
            $weightCompare = (float) $right->invoice_weight_12m <=> (float) $left->invoice_weight_12m;

            return $weightCompare !== 0
                ? $weightCompare
                : strcmp((string) $left->customer_name, (string) $right->customer_name);
        });

        $tierACount = (int) ceil(count($activeRows) * 0.20);
        $activeRanks = [];
        foreach ($activeRows as $index => $row) {
            $activeRanks[(int) $row->customer_id] = $index + 1;
        }

        foreach ($rows as $row) {
            $isActive = !empty($row->last_order_date) && $row->last_order_date >= $cutoffDate;
            if (!$isActive) {
                $row->rank = null;
                $row->tier = 'C';
                continue;
            }

            $row->rank = $activeRanks[(int) $row->customer_id] ?? null;
            $row->tier = $row->rank <= $tierACount
                && (int) $row->active_order_months_12m >= 6
                ? 'A'
                : 'B';
        }

        usort($rows, function ($left, $right) {
            $tierOrder = ['A' => 1, 'B' => 2, 'C' => 3];
            $tierCompare = $tierOrder[$left->tier] <=> $tierOrder[$right->tier];
            if ($tierCompare !== 0) {
                return $tierCompare;
            }

            $weightCompare = (float) $right->invoice_weight_12m <=> (float) $left->invoice_weight_12m;

            return $weightCompare !== 0
                ? $weightCompare
                : strcmp((string) $left->customer_name, (string) $right->customer_name);
        });

        return $rows;
    }

    private function customerOrderDetail(int $customerId): array
    {
        $sql = "
            WITH order_by_so AS (
                SELECT
                    TRIM(oe.ordnumber) AS ordnumber,
                    MIN(oe.transdate) AS order_date,
                    SUM(oi.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END) AS order_weight
                FROM oe
                JOIN orderitems oi ON oi.trans_id = oe.id
                JOIN parts p ON p.id = oi.parts_id
                WHERE oe.customer_id = ?
                  AND oe.ordnumber IS NOT NULL
                  AND COALESCE(oe.cancelled, FALSE) = FALSE
                  AND COALESCE(TRIM(oi.unit), '') <> ''
                GROUP BY TRIM(oe.ordnumber)
            ),
            invoice_by_so AS (
                SELECT
                    TRIM(ar.ordnumber) AS ordnumber,
                    SUM(inv.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END) AS invoice_weight,
                    COUNT(DISTINCT ar.invnumber) AS invoice_count,
                    MIN(ar.transdate) AS first_invoice_date,
                    MAX(ar.transdate) AS latest_invoice_date
                FROM ar
                JOIN invoice inv ON inv.trans_id = ar.id
                JOIN parts p ON p.id = inv.parts_id
                WHERE ar.ordnumber IS NOT NULL
                  AND ar.customer_id = ?
                  AND COALESCE(ar.invoice, TRUE) = TRUE
                GROUP BY TRIM(ar.ordnumber)
            )
            SELECT
                obs.ordnumber,
                obs.order_date,
                ROUND(obs.order_weight, 2) AS order_weight,
                ROUND(COALESCE(ibs.invoice_weight, 0), 2) AS invoice_weight,
                ROUND(obs.order_weight - COALESCE(ibs.invoice_weight, 0), 2) AS remaining_weight,
                CASE
                    WHEN obs.order_weight > 0
                    THEN ROUND((COALESCE(ibs.invoice_weight, 0) / obs.order_weight) * 100, 2)
                    ELSE NULL
                END AS invoice_percent,
                COALESCE(ibs.invoice_count, 0) AS invoice_count,
                ibs.first_invoice_date,
                ibs.latest_invoice_date
            FROM order_by_so obs
            LEFT JOIN invoice_by_so ibs ON ibs.ordnumber = obs.ordnumber
            ORDER BY obs.order_date DESC, obs.ordnumber DESC
        ";

        return array_map(
            fn ($row) => (array) $row,
            DB::connection($this->connection)->select($sql, [$customerId, $customerId])
        );
    }

    private function invoiceDetail(int $customerId, array $salesOrders): array
    {
        $salesOrders = array_values(array_unique(array_filter(array_map('trim', $salesOrders))));
        if ($salesOrders === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($salesOrders), '?'));
        $sql = "
            SELECT
                TRIM(ar.ordnumber) AS ordnumber,
                ar.invnumber,
                ar.transdate AS invoice_date,
                ROUND(SUM(inv.qty * CASE WHEN p.ref_unit = '03' THEN COALESCE(p.ref_unit_qty, 1) ELSE 1 END), 2) AS invoice_weight
            FROM ar
            JOIN invoice inv ON inv.trans_id = ar.id
            JOIN parts p ON p.id = inv.parts_id
            WHERE TRIM(ar.ordnumber) IN ($placeholders)
              AND ar.customer_id = ?
              AND COALESCE(ar.invoice, TRUE) = TRUE
            GROUP BY TRIM(ar.ordnumber), ar.invnumber, ar.transdate
            ORDER BY TRIM(ar.ordnumber), ar.transdate, ar.invnumber
        ";

        return DB::connection($this->connection)->select($sql, array_merge($salesOrders, [$customerId]));
    }

}
