<?php

namespace App\Services\FormWOS;

use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DeadstockSnapshotImportService
{
    private const UPSERT_CHUNK_SIZE = 70;

    public function importFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Snapshot file not found: ' . $path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            throw new \RuntimeException('Invalid deadstock item snapshot payload.');
        }

        $recvDate = Carbon::parse((string) ($payload['recv_date'] ?? now('Asia/Bangkok')->toDateString()))->toDateString();
        $asOfDate = !empty($payload['as_of']) ? Carbon::parse((string) $payload['as_of'])->toDateString() : null;
        $capturedAt = !empty($payload['generated'])
            ? Carbon::parse((string) $payload['generated'])
            : now('Asia/Bangkok');
        $snapshotMonth = Carbon::parse($recvDate)->startOfMonth()->toDateString();
        $items = collect($payload['items']);

        return DB::connection(config('database.workflow_connection', 'sqlsrv_menam'))
            ->transaction(function () use ($items, $recvDate, $asOfDate, $capturedAt, $snapshotMonth) {
                $month = DeadstockSnapshotMonth::query()->updateOrCreate(
                    ['snapshot_month' => $snapshotMonth],
                    [
                        'recv_date' => $recvDate,
                        'as_of_date' => $asOfDate,
                        'captured_at' => $capturedAt,
                        'source_name' => 'mail-daily.report:deadstock.items',
                        'status' => 'ready',
                        'item_count' => $items->count(),
                        'total_qty' => $items->sum(fn(array $item) => (float) ($item['quantity'] ?? 0)),
                        'total_value' => $items->sum(fn(array $item) => (float) ($item['quantity'] ?? 0) * (float) ($item['unitcost'] ?? 0)),
                    ]
                );

                $normalizedRows = $items
                    ->map(fn(array $item) => $this->normalizeItem((array) $item))
                    ->values();

                $existingKeys = collect();
                foreach ($normalizedRows->pluck('item_key')->chunk(800) as $keys) {
                    $existingKeys = $existingKeys->merge(
                        DeadstockSnapshotItem::query()
                            ->where('snapshot_month_id', $month->id)
                            ->whereIn('item_key', $keys->all())
                            ->pluck('item_key')
                    );
                }
                $existingKeys = $existingKeys->flip();

                $created = $normalizedRows
                    ->reject(fn(array $row) => $existingKeys->has($row['item_key']))
                    ->count();
                $updated = $normalizedRows->count() - $created;

                $now = now('Asia/Bangkok')->toDateTimeString();
                $upsertRows = $normalizedRows
                    ->map(function (array $row) use ($month, $now) {
                        return [
                            'snapshot_month_id' => $month->id,
                            'item_key' => $row['item_key'],
                            ...$row['payload'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })
                    ->all();

                foreach (array_chunk($upsertRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    DeadstockSnapshotItem::query()->upsert(
                        $chunk,
                        ['snapshot_month_id', 'item_key'],
                        [
                            'company',
                            'part_id',
                            'partnumber',
                            'part_description',
                            'transaction_number',
                            'serialnumber',
                            'purchase_date',
                            'status_time',
                            'snapshot_qty',
                            'unit',
                            'unitcost',
                            'snapshot_value',
                            'part_type_description',
                            'customer_id',
                            'customer_name',
                            'due_date',
                            'salesperson_id',
                            'salesperson_name',
                            'deadstock_code',
                            'deadstock_desc',
                            'days_diff',
                            'days_overdue',
                            'dead_stock_flag',
                            'updated_at',
                        ]
                    );
                }

                return [
                    'month_id' => $month->id,
                    'snapshot_month' => $snapshotMonth,
                    'recv_date' => $recvDate,
                    'items' => $items->count(),
                    'created' => $created,
                    'updated' => $updated,
                ];
            });
    }

    public function importPayload(array $payload): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'deadstock_payload_');
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            return $this->importFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function importItemChunks(
        string $recvDate,
        ?string $asOfDate,
        Carbon $capturedAt,
        iterable $chunks
    ): array {
        $snapshotMonth = Carbon::parse($recvDate)->startOfMonth()->toDateString();
        $month = DeadstockSnapshotMonth::query()->updateOrCreate(
            ['snapshot_month' => $snapshotMonth],
            [
                'recv_date' => $recvDate,
                'as_of_date' => $asOfDate,
                'captured_at' => $capturedAt,
                'source_name' => 'mail-daily.excel-backfill',
                'status' => 'ready',
            ]
        );

        $created = 0;
        $updated = 0;
        $items = 0;
        $totalQty = 0.0;
        $totalValue = 0.0;

        foreach ($chunks as $chunkItems) {
            $normalizedRows = collect($chunkItems)
                ->map(fn(array $item) => $this->normalizeItem($item))
                ->values();

            if ($normalizedRows->isEmpty()) {
                continue;
            }

            $existingKeys = DeadstockSnapshotItem::query()
                ->where('snapshot_month_id', $month->id)
                ->whereIn('item_key', $normalizedRows->pluck('item_key')->all())
                ->pluck('item_key')
                ->flip();

            $chunkCreated = $normalizedRows
                ->reject(fn(array $row) => $existingKeys->has($row['item_key']))
                ->count();
            $created += $chunkCreated;
            $updated += $normalizedRows->count() - $chunkCreated;

            $now = now('Asia/Bangkok')->toDateTimeString();
            $upsertRows = $normalizedRows
                ->map(function (array $row) use ($month, $now) {
                    return [
                        'snapshot_month_id' => $month->id,
                        'item_key' => $row['item_key'],
                        ...$row['payload'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })
                ->all();

            foreach (array_chunk($upsertRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                DeadstockSnapshotItem::query()->upsert(
                    $chunk,
                    ['snapshot_month_id', 'item_key'],
                    [
                        'company',
                        'part_id',
                        'partnumber',
                        'part_description',
                        'transaction_number',
                        'serialnumber',
                        'purchase_date',
                        'status_time',
                        'snapshot_qty',
                        'unit',
                        'unitcost',
                        'snapshot_value',
                        'part_type_description',
                        'customer_id',
                        'customer_name',
                        'due_date',
                        'salesperson_id',
                        'salesperson_name',
                        'deadstock_code',
                        'deadstock_desc',
                        'days_diff',
                        'days_overdue',
                        'dead_stock_flag',
                        'updated_at',
                    ]
                );
            }

            foreach ($normalizedRows as $row) {
                $items++;
                $totalQty += (float) ($row['payload']['snapshot_qty'] ?? 0);
                $totalValue += (float) ($row['payload']['snapshot_value'] ?? 0);
            }
        }

        $month->update([
            'item_count' => $items,
            'total_qty' => $totalQty,
            'total_value' => $totalValue,
        ]);

        return [
            'month_id' => $month->id,
            'snapshot_month' => $snapshotMonth,
            'recv_date' => $recvDate,
            'items' => $items,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    private function normalizeItem(array $item): array
    {
        $company = trim((string) ($item['company'] ?? ''));
        $serial = trim((string) ($item['serialnumber'] ?? ''));
        $transaction = trim((string) ($item['transaction_number'] ?? ''));
        $partNumber = trim((string) ($item['partnumber'] ?? ''));
        $purchaseDate = $this->dateValue($item['purchase_date'] ?? null);
        $quantity = (float) ($item['quantity'] ?? 0);
        $unitCost = isset($item['unitcost']) ? (float) $item['unitcost'] : null;

        $keyParts = [
            strtoupper($company),
            strtoupper($serial ?: $transaction),
            strtoupper($partNumber),
            $purchaseDate ?: '',
        ];
        $itemKey = hash('sha256', implode('|', $keyParts));

        return [
            'item_key' => $itemKey,
            'payload' => [
                'company' => $company ?: null,
                'part_id' => $this->integerValue($item['part_id'] ?? null),
                'partnumber' => $partNumber ?: null,
                'part_description' => $this->stringValue($item['part_description'] ?? null),
                'transaction_number' => $transaction ?: null,
                'serialnumber' => $serial ?: null,
                'purchase_date' => $purchaseDate,
                'status_time' => $this->dateTimeValue($item['status_time'] ?? null),
                'snapshot_qty' => $quantity,
                'unit' => $this->stringValue($item['unit'] ?? null),
                'unitcost' => $unitCost,
                'snapshot_value' => $quantity * (float) ($unitCost ?? 0),
                'part_type_description' => $this->stringValue($item['part_type_description'] ?? null),
                'customer_id' => $this->integerValue($item['customer_id'] ?? null),
                'customer_name' => $this->stringValue($item['customer'] ?? null),
                'due_date' => $this->dateValue($item['due_date'] ?? null),
                'salesperson_id' => $this->integerValue($item['salesperson_id'] ?? null),
                'salesperson_name' => $this->stringValue($item['salesperson'] ?? null),
                'deadstock_code' => $this->stringValue($item['deadstock_code'] ?? null),
                'deadstock_desc' => $this->stringValue($item['deadstock_desc'] ?? null),
                'days_diff' => $this->integerValue($item['days_diff'] ?? null),
                'days_overdue' => $this->integerValue($item['days_overdue'] ?? null),
                'dead_stock_flag' => (bool) ($item['dead_stock_flag'] ?? true),
            ],
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function integerValue(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function dateValue(mixed $value): ?string
    {
        return $value ? Carbon::parse((string) $value)->toDateString() : null;
    }

    private function dateTimeValue(mixed $value): ?string
    {
        return $value ? Carbon::parse((string) $value)->toDateTimeString() : null;
    }
}
