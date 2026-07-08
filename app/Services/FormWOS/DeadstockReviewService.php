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
