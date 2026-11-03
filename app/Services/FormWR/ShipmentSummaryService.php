<?php

namespace App\Services\FormWR;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShipmentSummaryService
{
    /** Default year-end stock target in MT [stainless, carbon]; from the supplied PU-30 report. */
    public const TARGET = [2000, 1100];

    public function report(array $companies, string $item = '', ?array $stockReport = null): array
    {
        $today = Carbon::today('Asia/Bangkok');
        $result = ['date' => $today->toDateString(), 'stock' => [0.0, 0.0], 'received' => [], 'incoming' => [], 'orders' => [], 'excluded' => [0.0, 0.0]];
        $firstWeek = self::weeks($today)[0]['start'];
        $classes = [];
        foreach (['MENAM WIRE' => 'pgsqlw', 'MENAM PLUS' => 'pgsqlp'] as $company => $connection) {
            if (!in_array($company, $companies, true)) continue;
            $db = DB::connection($connection);
            $filter = "p.partnumber LIKE 'R%'";
            $bindings = [];
            if (trim($item) !== '') {
                $filter .= ' AND (UPPER(p.partnumber) LIKE :item OR UPPER(p.description) LIKE :item)';
                $bindings['item'] = '%'.mb_strtoupper(trim($item)).'%';
            }
            $parts = $db->select("SELECT p.id, UPPER(TRIM(p.partnumber)) AS item, p.description, pt.description AS material_type FROM parts p LEFT JOIN partstype pt ON pt.id=p.partstype_id WHERE {$filter}", $bindings);
            foreach ($parts as $part) {
                $type = trim((string) $part->material_type);
                $class = ['วัตถุดิบสแตนเลส' => 0, 'วัตถุดิบเหล็ก' => 1][$type] ?? null;
                // The PDF excludes Slit Coil; match the explicit product description, not the company.
                $slit = preg_match('/^Slit Coil\b/i', trim($part->description)) === 1;
                $classes[$company.'|'.$part->item] = ['class' => $class, 'slit' => $slit];
            }
            $stock = $db->select("SELECT UPPER(TRIM(p.partnumber)) AS item, SUM(pm.qty) AS kg FROM partsmvmt pm JOIN parts p ON p.id=pm.parts_id WHERE {$filter} GROUP BY UPPER(TRIM(p.partnumber))", $bindings);
            foreach ($stock as $row) {
                $meta = $classes[$company.'|'.$row->item];
                if ($meta['class'] === null) continue;
                $field = $meta['slit'] ? 'excluded' : 'stock';
                $result[$field][$meta['class']] += (float) $row->kg / 1000;
            }
            $received = $db->select("SELECT UPPER(TRIM(p.partnumber)) AS item, ap.transdate AS date, SUM(ri.qty) AS qty, oi.unit, p.unit AS base_unit, p.purchase_unit, p.purchase_unit_qty
                FROM receiveitems ri JOIN orderitems oi ON oi.id=ri.orderitems_id JOIN parts p ON p.id=oi.parts_id JOIN receive r ON r.id=ri.receive_id JOIN ap ON ap.id=r.ap_id
                WHERE {$filter} AND ap.transdate >= :month_start AND ap.transdate <= :today
                GROUP BY UPPER(TRIM(p.partnumber)),ap.transdate,oi.unit,p.unit,p.purchase_unit,p.purchase_unit_qty", $bindings + ['month_start' => $firstWeek->toDateString(), 'today' => $today->toDateString()]);
            foreach ($received as $row) {
                $meta = $classes[$company.'|'.$row->item];
                if ($meta['class'] === null || $meta['slit']) continue;
                $week = Carbon::parse($row->date)->startOfWeek(Carbon::SATURDAY)->toDateString();
                $this->add($result['received'], $week, $meta['class'], $this->tonnes($row));
            }
            $incoming = $db->select("SELECT UPPER(TRIM(p.partnumber)) AS item, oe.reqdate AS date, (oi.qty-COALESCE(r.qty,0)) AS qty, oi.unit,p.unit AS base_unit,p.purchase_unit,p.purchase_unit_qty
                FROM orderitems oi JOIN oe ON oe.id=oi.trans_id JOIN parts p ON p.id=oi.parts_id
                LEFT JOIN (SELECT orderitems_id,SUM(qty) AS qty FROM receiveitems GROUP BY orderitems_id) r ON r.orderitems_id=oi.id
                WHERE {$filter} AND oe.ordnumber LIKE 'POR%' AND oe.shipped_or_received=false AND oi.qty>COALESCE(r.qty,0)", $bindings);
            foreach ($incoming as $row) {
                $meta = $classes[$company.'|'.$row->item];
                if ($meta['class'] === null || $meta['slit']) continue;
                $key = $this->period($row->date, $today);
                $this->add($result['incoming'], $key, $meta['class'], $this->tonnes($row));
            }
        }
        $stockReport ??= app(ReservedStockService::class)->report($companies, $item);
        $result['warnings'] = $stockReport['warnings'] ?? [];
        foreach ($stockReport['items'] as $stock) {
            $meta = $classes[$stock['company'].'|'.$stock['item']] ?? null;
            if (!$meta || $meta['class'] === null || $meta['slit']) continue;
            foreach ($stock['heats'] as $heat) foreach ($heat['orders'] as $order) {
                $this->add($result['orders'], $this->period($order['due_date'], $today), $meta['class'], $order['reserved']/1000);
            }
        }
        return $result;
    }

    /**
     * Saturday-Friday weeks of the report month, up to the report date.
     * The first week keeps its days from the previous month (27 Jun - 3 Jul belongs to July).
     */
    public static function weeks(Carbon $date): array
    {
        $weeks = [];
        $week = $date->copy()->startOfMonth()->startOfWeek(Carbon::SATURDAY);
        while ($week->lte($date)) {
            $weeks[] = ['start' => $week->copy(), 'end' => $week->copy()->addDays(6)->min($date)];
            $week->addWeek();
        }
        return $weeks;
    }

    /** Page rows for the same figures as the Excel summary sheet; all quantities are MT. */
    public function sections(array $data): array
    {
        $date = Carbon::parse($data['date']);
        $row = fn(string $label, array $values, bool $total = false) => ['label' => $label, 'values' => $values, 'total' => $total];
        $sum = fn(array $rows) => [array_sum(array_column(array_column($rows, 'values'), 0)), array_sum(array_column(array_column($rows, 'values'), 1))];

        $arrived = [];
        foreach (self::weeks($date) as $week) {
            $arrived[] = $row($week['start']->format('d M').' - '.$week['end']->format('d M'), $data['received'][$week['start']->toDateString()] ?? [0.0, 0.0]);
        }
        $arrivedTotal = $sum($arrived);
        $byMonth = [];
        foreach (['incoming', 'orders'] as $field) {
            $byMonth[$field] = [$row('ยกมา', $data[$field]['Carried forward'] ?? [0.0, 0.0])];
            for ($month = $date->month; $month <= 12; $month++) {
                $key = $date->copy()->startOfMonth()->month($month);
                $byMonth[$field][] = $row($key->format('M Y'), $data[$field][$key->format('Y-m')] ?? [0.0, 0.0]);
            }
        }
        $incoming = $sum($byMonth['incoming']);
        $orders = $sum($byMonth['orders']);
        $overall = [$data['stock'][0] + $incoming[0], $data['stock'][1] + $incoming[1]];
        $end = [$overall[0] - $orders[0], $overall[1] - $orders[1]];

        return [
            ['title' => 'สต็อกปัจจุบัน', 'rows' => [
                $row('Stock on hand '.$date->format('d M Y'), $data['stock'], true),
                $row('Slit Coil (ไม่รวมในสรุป)', $data['excluded']),
            ]],
            ['title' => 'รับเข้าแล้วรายสัปดาห์ (เสาร์-ศุกร์)', 'rows' => [...$arrived, $row('รวมรับเข้า', $arrivedTotal, true)]],
            ['title' => 'กำหนดเข้าโรงงาน (To be arrived)', 'rows' => [...$byMonth['incoming'], $row('รวมกำหนดเข้า', $incoming, true)]],
            ['title' => 'ยอดจองผลิต (Order)', 'rows' => [...$byMonth['orders'], $row('รวมยอดจอง', $orders, true)]],
            ['title' => 'ประมาณการสิ้นปี', 'rows' => [
                $row('Over all stock', $overall, true),
                $row('Stock End Year', $end, true),
                $row('Target', self::TARGET),
                $row('Over stock / (Shortfall)', [$end[0] - self::TARGET[0], $end[1] - self::TARGET[1]], true),
            ]],
        ];
    }

    public function tonnes(object $row): float
    {
        if ((string) $row->unit === (string) $row->base_unit) return (float) $row->qty / 1000;
        if ((string) $row->unit === (string) $row->purchase_unit && (float) $row->purchase_unit_qty > 0) return (float) $row->qty * (float) $row->purchase_unit_qty / 1000;
        throw new RuntimeException('Unknown purchase unit for '.$row->item);
    }

    public function period(?string $date, Carbon $today): string
    {
        if (!$date) return 'Undated';
        $due = Carbon::parse($date);
        if ($due->year > $today->year) return 'After year end';
        if ($due->lt($today->copy()->startOfMonth())) return 'Carried forward';
        return $due->format('Y-m');
    }

    private function add(array &$rows, string $key, int $class, float $qty): void
    {
        $rows[$key] ??= [0.0, 0.0];
        $rows[$key][$class] += $qty;
    }
}
