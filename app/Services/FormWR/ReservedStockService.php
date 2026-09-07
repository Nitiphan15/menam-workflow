<?php

namespace App\Services\FormWR;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReservedStockService
{
    private const CONNECTIONS = [
        'MENAM WIRE' => ['pgsqlw', 'pgsqlmfgw'],
        'MENAM PLUS' => ['pgsqlp', 'pgsqlmfgp'],
    ];

    public function report(array $companies, string $item = ''): array
    {
        $stocks = $heats = $orders = [];
        foreach (self::CONNECTIONS as $company => [$erp, $mfg]) {
            if (!in_array($company, $companies, true)) {
                continue;
            }
            $bindings = [];
            $filter = "UPPER(TRIM(p.partnumber)) LIKE 'R%'";
            if (trim($item) !== '') {
                $filter .= ' AND (UPPER(TRIM(p.partnumber)) LIKE :item OR UPPER(p.description) LIKE :item)';
                $bindings['item'] = '%'.mb_strtoupper(trim($item)).'%';
            }
            foreach (DB::connection($erp)->select("
                SELECT UPPER(TRIM(p.partnumber)) AS item, p.description, SUM(pm.qty) AS balance
                FROM partsmvmt pm JOIN parts p ON p.id = pm.parts_id
                WHERE {$filter} GROUP BY UPPER(TRIM(p.partnumber)), p.description
            ", $bindings) as $row) {
                $stocks[] = ['company' => $company] + (array) $row;
            }
            // Serial stock is grouped independently of movements to avoid multiplying balances.
            foreach (DB::connection($erp)->select("
                SELECT UPPER(TRIM(p.partnumber)) AS item,
                       UPPER(TRIM(SPLIT_PART(s.serialnumber, '-', 1))) AS heat,
                       s.purchasedate AS received_date, SUM(s.qty) AS balance
                FROM serializeunits s JOIN parts p ON p.id = s.parts_id
                WHERE s.onhand IS TRUE AND {$filter}
                GROUP BY UPPER(TRIM(p.partnumber)), UPPER(TRIM(SPLIT_PART(s.serialnumber, '-', 1))), s.purchasedate
            ", $bindings) as $row) {
                $heats[] = ['company' => $company] + (array) $row;
            }
            // Material usage is the warehouse issue, not consumption at each production step.
            // Sum it before joining BOM so a partial issue is deducted exactly once.
            foreach (DB::connection($mfg)->select("
                WITH rm AS (
                    SELECT DISTINCT b.workorder_id, b.parts_id,
                           NULLIF(NULLIF(UPPER(TRIM(b.fheatno)), '-'), '') AS heat
                    FROM workorderbom b JOIN parts p ON p.id = b.parts_id
                    WHERE UPPER(TRIM(p.partnumber)) LIKE 'R%'
                ), counts AS (
                    SELECT workorder_id, COUNT(*) AS allocation_count FROM rm GROUP BY workorder_id
                ), issued AS (
                    SELECT workorder_id, parts_id, SUM(qty) AS issued
                    FROM workordermatusage GROUP BY workorder_id, parts_id
                )
                SELECT UPPER(TRIM(p.partnumber)) AS item, p.description, rm.heat,
                       w.id AS workorder_id, w.workordernumber AS mfg, w.qty AS planned,
                       COALESCE(i.issued, 0) AS issued, counts.allocation_count,
                       w.dateopen AS order_date, w.reqdate AS due_date, w.ordnumber AS sales_order,
                       fg.partnumber AS product, fg.description AS product_description,
                       w.customer, w.approved
                FROM rm JOIN workorder w ON w.id = rm.workorder_id
                JOIN counts ON counts.workorder_id = w.id
                JOIN parts p ON p.id = rm.parts_id
                LEFT JOIN parts fg ON fg.id = w.parts_id
                LEFT JOIN issued i ON i.workorder_id = w.id AND i.parts_id = rm.parts_id
                WHERE w.dateclose IS NULL AND COALESCE(w.suspended, false) = false
                  AND ROUND(w.qty, 4) > 0 AND {$filter}
                ORDER BY w.dateopen, w.workordernumber
            ", $bindings) as $row) {
                $orders[] = ['company' => $company] + (array) $row;
            }
        }

        return $this->assemble($stocks, $heats, $orders);
    }

    /** Pure calculation shared by the page and export; all quantities are KG. */
    public function assemble(array $stocks, array $heatStocks, array $orders): array
    {
        $items = [];
        $ensure = function (array $row) use (&$items): string {
            $key = $row['company'].'|'.$row['item'];
            if (!isset($items[$key])) {
                $items[$key] = [
                    'company' => $row['company'], 'item' => $row['item'],
                    'description' => $row['description'] ?? '', 'balance' => 0.0,
                    'reserved' => 0.0, 'net' => 0.0, 'heats' => [],
                ];
            }
            return $key;
        };
        $emptyHeat = fn($heat, $kind = 'heat') => [
            'heat' => $heat, 'kind' => $kind, 'received_dates' => [],
            'balance' => 0.0, 'reserved' => 0.0, 'net' => 0.0, 'orders' => [],
        ];
        foreach ($stocks as $row) {
            $key = $ensure($row);
            $items[$key]['balance'] += (float) $row['balance'];
        }
        foreach ($heatStocks as $row) {
            $key = $ensure($row);
            $heat = trim((string) ($row['heat'] ?? ''));
            $bucket = $heat === '' ? 'unidentified' : 'heat:'.$heat;
            $items[$key]['heats'][$bucket] ??= $emptyHeat($heat, $heat === '' ? 'unidentified' : 'heat');
            $h = &$items[$key]['heats'][$bucket];
            $h['balance'] += (float) $row['balance'];
            if (!empty($row['received_date'])) {
                $h['received_dates'][] = $row['received_date'];
            }
            unset($h);
        }
        foreach ($orders as $row) {
            // A WO quantity cannot be copied to multiple materials/heats without an allocation rule.
            if ((int) $row['allocation_count'] !== 1) {
                throw new RuntimeException('MFG '.$row['mfg'].' มีหลาย Item/Heat ต้องระบุจำนวนจองแยกก่อนคำนวณ');
            }
            $row['planned'] = round((float) $row['planned'], 4);
            $row['issued'] = round((float) $row['issued'], 4);
            $row['reserved'] = max(0.0, round($row['planned'] - $row['issued'], 4));
            if ($row['reserved'] <= 0) {
                continue;
            }
            $key = $ensure($row);
            $heat = trim((string) ($row['heat'] ?? ''));
            $heat = $heat === '-' ? '' : $heat;
            $bucket = $heat === '' ? 'waiting' : 'heat:'.$heat;
            $row['heat'] = $heat;
            $row['status'] = $heat === '' ? 'รอวัตถุดิบ' : ($row['issued'] > 0 ? 'เบิกบางส่วน' : 'รอเบิก');
            $items[$key]['heats'][$bucket] ??= $emptyHeat($heat, $heat === '' ? 'waiting' : 'heat');
            $items[$key]['heats'][$bucket]['orders'][] = $row;
            $items[$key]['heats'][$bucket]['reserved'] += $row['reserved'];
            $items[$key]['reserved'] += $row['reserved'];
        }
        foreach ($items as &$item) {
            // Preserve the movement balance used by FormWR. Never invent a Heat for a discrepancy.
            $difference = round($item['balance'] - array_sum(array_column($item['heats'], 'balance')), 4);
            if ($difference != 0) {
                $item['heats']['reconciliation'] = $emptyHeat('', 'reconciliation');
                $item['heats']['reconciliation']['balance'] = $difference;
            }
            foreach ($item['heats'] as &$heat) {
                $heat['received_dates'] = array_values(array_unique($heat['received_dates']));
                sort($heat['received_dates']);
                $heat['balance'] = round($heat['balance'], 4);
                $heat['reserved'] = round($heat['reserved'], 4);
                $heat['net'] = round($heat['balance'] - $heat['reserved'], 4);
            }
            unset($heat);
            ksort($item['heats']);
            $item['heats'] = array_values($item['heats']);
            $item['balance'] = round($item['balance'], 4);
            $item['reserved'] = round($item['reserved'], 4);
            $item['net'] = round($item['balance'] - $item['reserved'], 4);
        }
        unset($item);
        $items = array_filter($items, fn($r) => $r['balance'] != 0 || $r['reserved'] > 0 || array_filter($r['heats'], fn($h) => $h['balance'] != 0));
        uasort($items, fn($a, $b) => [$a['item'], $a['company']] <=> [$b['item'], $b['company']]);

        return ['items' => array_values($items), 'retrieved_at' => now('Asia/Bangkok')->format('Y-m-d H:i:s')];
    }

    public function mergeBalances(array $balances, array $report): array
    {
        $rows = [];
        foreach ($balances as $row) {
            $rows[$row['item']] = $row + ['reserved' => 0.0, 'net' => $row['balance'], 'stock_details' => []];
        }
        foreach ($report['items'] as $item) {
            $key = $item['item'];
            $rows[$key] ??= ['item' => $key, 'company' => '', 'balance' => 0.0, 'open' => 0.0, 'overdue_po' => [], 'reserved' => 0.0, 'net' => 0.0, 'stock_details' => []];
            $rows[$key]['stock_details'][] = $item;
        }
        foreach ($rows as &$row) {
            if ($row['stock_details']) {
                $row['balance'] = array_sum(array_column($row['stock_details'], 'balance'));
                $row['reserved'] = array_sum(array_column($row['stock_details'], 'reserved'));
                $row['company'] = implode(', ', array_column($row['stock_details'], 'company'));
            }
            $row['net'] = round($row['balance'] - $row['reserved'], 4);
        }
        unset($row);
        ksort($rows);
        return array_values($rows);
    }

    public static function heatLabel(array $heat): string
    {
        return ['waiting' => 'รอวัตถุดิบ / ยังไม่ระบุ Heat', 'unidentified' => 'สต็อกไม่ระบุ Heat',
            'reconciliation' => 'ส่วนต่างสต็อกที่ยังระบุ Heat ไม่ได้'][$heat['kind']] ?? $heat['heat'];
    }
}
