<?php

namespace App\Services\FormWOS;

use App\Models\FormWOS\DeadstockItemCompareLog;
use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use App\Support\FormWOS\DeadstockReasonMap;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeadstockReviewService
{
    public function compareMonth(DeadstockSnapshotMonth $month, bool $writeLogs = true): array
    {
        $items = $month->items()
            ->whereNotNull('serialnumber')
            ->get();

        $liveRows = $this->loadLiveRows($items);
        $reasonDescriptions = $this->loadReasonDescriptions();
        $checkedAt = now('Asia/Bangkok');
        $counts = [
            'active' => 0,
            'cleared' => 0,
            'changed' => 0,
        ];

        foreach ($items as $item) {
            $live = $liveRows->get($this->liveKey($this->siteKey($item->company), $item->serialnumber));
            $status = 'cleared';
            $matchedQty = null;
            $matchedDueDate = null;
            $matchedCode = null;

            if ($live) {
                $matchedQty = (float) ($live->qty ?? 0);
                $matchedDueDate = !empty($live->due_date) ? Carbon::parse($live->due_date)->toDateString() : null;
                $matchedCode = trim((string) ($live->deadstock_code ?? ''));

                $qtyChanged = round($matchedQty, 2) !== round((float) $item->snapshot_qty, 2);
                $dueDateChanged = $matchedDueDate !== optional($item->due_date)->toDateString();
                $status = ($qtyChanged || $dueDateChanged) ? 'changed' : 'active';
            }

            $item->forceFill([
                'compare_status' => $status,
                'current_qty' => $matchedQty,
                'current_due_date' => $matchedDueDate,
                'deadstock_code' => $matchedCode ?: $item->deadstock_code,
                'deadstock_desc' => $matchedCode
                    ? DeadstockReasonMap::description($matchedCode, $reasonDescriptions[$matchedCode] ?? $item->deadstock_desc)
                    : $item->deadstock_desc,
                'last_checked_at' => $checkedAt,
                'cleared_at' => $status === 'cleared' ? ($item->cleared_at ?? $checkedAt) : null,
            ])->save();

            if ($writeLogs) {
                DeadstockItemCompareLog::create([
                    'snapshot_item_id' => $item->id,
                    'checked_at' => $checkedAt,
                    'compare_status' => $status,
                    'matched_qty' => $matchedQty,
                    'matched_due_date' => $matchedDueDate,
                    'matched_deadstock_code' => $matchedCode ?: null,
                    'note' => $this->compareNote($status),
                ]);
            }

            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * ดึง deadstock ปัจจุบันทั้งหมดจาก ERP (WIRE+PLUS) แล้ว import รายการที่ยังไม่เคยอยู่ใน
     * snapshot เดือนไหนเลย เข้าเดือนที่กำหนด เพื่อให้ขึ้นในหน้า review สำหรับ action
     * (อุดช่องโหว่ของที่เพิ่ง overdue ทีหลังซึ่ง snapshot รายวันไม่เคยเก็บ)
     */
    public function syncCurrentDeadstock(
        DeadstockSnapshotMonth $month,
        DeadstockSnapshotImportService $importService
    ): array {
        $asOf = now('Asia/Bangkok')->toDateString();

        $liveRows = collect();
        foreach (['WIRE' => 'MENAM WIRE', 'PLUS' => 'MENAM PLUS'] as $site => $companyName) {
            $rows = DB::connection($this->siteConnection($site))
                ->select($this->currentDeadstockSql($companyName), ['asOf' => $asOf]);
            $liveRows = $liveRows->concat(collect($rows));
        }

        // serial ที่มีอยู่แล้วในเดือนใดเดือนหนึ่ง ไม่ต้องเพิ่มซ้ำ
        $knownKeys = DeadstockSnapshotItem::query()
            ->whereNotNull('serialnumber')
            ->where('serialnumber', '<>', '')
            ->get(['company', 'serialnumber'])
            ->map(fn(DeadstockSnapshotItem $item) => $this->liveKey($this->siteKey($item->company), $item->serialnumber))
            ->flip();

        $missing = $liveRows
            ->filter(fn($row) => trim((string) ($row->serialnumber ?? '')) !== '')
            ->reject(fn($row) => $knownKeys->has($this->liveKey($this->siteKey((string) $row->company), (string) $row->serialnumber)))
            ->values();

        if ($missing->isEmpty()) {
            return ['live_total' => $liveRows->count(), 'missing' => 0, 'added' => 0];
        }

        $recvDate = $month->recv_date?->toDateString()
            ?? $month->snapshot_month?->toDateString()
            ?? $asOf;

        $result = $importService->importPayload([
            'recv_date' => $recvDate,
            'as_of' => $asOf,
            'generated' => now('Asia/Bangkok')->toDateTimeString(),
            'items' => $missing
                ->map(fn($row) => [
                    'company' => (string) ($row->company ?? ''),
                    'part_id' => $row->part_id ?? null,
                    'partnumber' => (string) ($row->partnumber ?? ''),
                    'part_description' => $row->part_description ?? null,
                    'transaction_number' => (string) ($row->transaction_number ?? $row->serialnumber ?? ''),
                    'serialnumber' => (string) ($row->serialnumber ?? ''),
                    'purchase_date' => $row->purchasedate ?? null,
                    'status_time' => $row->statustime ?? null,
                    'quantity' => (float) ($row->quantity ?? 0),
                    'unit' => $row->unit ?? null,
                    'unitcost' => isset($row->unitcost) ? (float) $row->unitcost : null,
                    'part_type_description' => $row->part_type_description ?? null,
                    'customer_id' => $row->customer_id ?? null,
                    'customer' => $row->customer ?? null,
                    'due_date' => $row->due_date ?? null,
                    'salesperson_id' => $row->salesperson_id ?? null,
                    'salesperson' => $row->salesperson ?? null,
                    'deadstock_code' => $row->deadstock_code ?? null,
                    'days_diff' => $row->days_diff ?? null,
                    'days_overdue' => $row->days_overdue ?? null,
                    'dead_stock_flag' => (bool) ($row->dead_stock_flag ?? true),
                ])
                ->all(),
        ]);

        return [
            'live_total' => $liveRows->count(),
            'missing' => $missing->count(),
            'added' => (int) ($result['created'] ?? 0),
            'month_id' => $result['month_id'] ?? $month->id,
        ];
    }

    private function currentDeadstockSql(string $companyName): string
    {
        // โครงเดียวกับ deadstockTotalSql ของ mail-daily: deadstock คงค้างทั้งหมด ณ ปัจจุบัน
        // (onhand + overdue หรือไม่มี due date) โดยไม่จำกัดช่วงวันรับเข้า
        return <<<SQL
            SELECT
                p.id                                 AS part_id,
                s.purchasedate,
                s.statustime,
                ROUND(s.qty, 2)                      AS quantity,
                s.serialnumber                       AS transaction_number,
                s.serialnumber                       AS serialnumber,
                p.partnumber,
                p.description                        AS part_description,
                um.description                       AS unit,
                ROUND(s.unitcost, 2)                 AS unitcost,
                pt.description                       AS part_type_description,
                '{$companyName}'                     AS company,
                COALESCE(c.name, c2.name, 'ไม่ระบุ') AS customer,
                COALESCE(oi.reqdate, wo.reqdate)     AS due_date,
                COALESCE(e_wo.name, e_oi.name)       AS salesperson,
                COALESCE(e_wo.id,   e_oi.id)         AS salesperson_id,
                COALESCE(c.id,      c2.id)           AS customer_id,
                s.f2                                 AS deadstock_code,
                (:asOf - s.purchasedate)             AS days_diff,
                CASE
                    WHEN COALESCE(oi.reqdate, wo.reqdate, :asOf) < :asOf THEN 1
                    ELSE 0
                END                                  AS dead_stock_flag,
                (:asOf - COALESCE(oi.reqdate, wo.reqdate, :asOf)) AS days_overdue
            FROM serializeunits s
            JOIN parts p             ON p.id = s.parts_id AND p.active = TRUE
            LEFT JOIN partstype pt   ON p.partstype_id = pt.id
            LEFT JOIN unitmeasure um ON p.unit = um.unit
            LEFT JOIN workorder wo   ON split_part(s.serialnumber,'-',1) = wo.workordernumber
            LEFT JOIN customer  c    ON wo.customer_id = c.id
            LEFT JOIN receiveitems ri ON s.purchase_invoice_id = ri.invoice_id
            LEFT JOIN orderitems  oi  ON ri.orderitems_id     = oi.id
            LEFT JOIN customer    c2  ON oi.ref1              = c2.customernumber
            LEFT JOIN employee e_wo  ON c.saleperson_id  = e_wo.id
            LEFT JOIN employee e_oi  ON c2.saleperson_id = e_oi.id
            WHERE
                s.onhand = TRUE
                AND (
                    COALESCE(oi.reqdate, wo.reqdate) IS NULL
                    OR COALESCE(oi.reqdate, wo.reqdate, :asOf) < :asOf
                )
                AND pt.id NOT IN (0,56,61,62,69,70,71,90,91)
            ORDER BY purchasedate DESC, transaction_number
            SQL;
    }

    private function loadLiveRows(Collection $items): Collection
    {
        $rows = collect();

        $groups = $items
            ->filter(fn(DeadstockSnapshotItem $item) => trim((string) $item->serialnumber) !== '')
            ->groupBy(fn(DeadstockSnapshotItem $item) => $this->siteKey($item->company));

        foreach ($groups as $site => $companyItems) {
            $connection = $this->siteConnection($site);
            $serials = $companyItems
                ->pluck('serialnumber')
                ->filter()
                ->map(fn($serial) => trim((string) $serial))
                ->unique()
                ->values();

            foreach ($serials->chunk(300) as $chunk) {
                $queryRows = DB::connection($connection)
                    ->table('serializeunits as s')
                    ->leftJoin('workorder as wo', DB::raw("split_part(s.serialnumber, '-', 1)"), '=', 'wo.workordernumber')
                    ->leftJoin('receiveitems as ri', 's.purchase_invoice_id', '=', 'ri.invoice_id')
                    ->leftJoin('orderitems as oi', 'ri.orderitems_id', '=', 'oi.id')
                    ->where('s.onhand', true)
                    ->whereIn('s.serialnumber', $chunk->all())
                    ->selectRaw('
                        s.serialnumber,
                        ROUND(s.qty, 4) AS qty,
                        COALESCE(oi.reqdate, wo.reqdate) AS due_date,
                        s.f2 AS deadstock_code
                    ')
                    ->get();
                foreach ($queryRows as $row) {
                    $rows->put($this->liveKey($site, (string) $row->serialnumber), $row);
                }
            }
        }

        return $rows;
    }

    private function loadReasonDescriptions(): array
    {
        $descriptions = DeadstockSnapshotItem::query()
            ->whereNotNull('deadstock_code')
            ->where('deadstock_code', '<>', '')
            ->whereNotNull('deadstock_desc')
            ->where('deadstock_desc', '<>', '')
            ->select('deadstock_code')
            ->selectRaw('MAX(deadstock_desc) AS deadstock_desc')
            ->groupBy('deadstock_code')
            ->pluck('deadstock_desc', 'deadstock_code')
            ->all();

        return collect($descriptions)
            ->reject(fn($description) => DeadstockReasonMap::isUnknownDescription((string) $description))
            ->all();
    }

    private function siteKey(?string $company): string
    {
        $company = strtoupper(trim((string) $company));

        return str_contains($company, 'PLUS') ? 'PLUS' : 'WIRE';
    }

    private function siteConnection(string $site): string
    {
        return $site === 'PLUS' ? 'pgsqlp' : 'pgsqlw';
    }

    private function liveKey(?string $site, ?string $serialnumber): string
    {
        return strtoupper(trim((string) $site)) . '|' . strtoupper(trim((string) $serialnumber));
    }

    private function compareNote(string $status): string
    {
        return match ($status) {
            'cleared' => 'No live on-hand serialize unit matched the snapshot item.',
            'changed' => 'Live item still exists, but quantity or due date changed from the snapshot.',
            default => 'Live item still matches the stored snapshot.',
        };
    }
}
