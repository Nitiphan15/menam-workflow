<?php

namespace App\Services\FormDP;

use App\Models\FormDP\DeliveryConfirmation;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryConfirmationService
{
    private function normalizeMfgNo(?string $value): string
    {
        return strtoupper(ltrim(trim((string) $value, " \t\n\r\0\x0B'\""), '+'));
    }

    public function save(
        string $mfgNo,
        ?string $site,
        ?string $soNumber,
        string $status,
        ?string $newDeliveryDate,
        ?string $originalShipDate,
        ?string $remark = null
    ): DeliveryConfirmation {
        $mfgNo = $this->normalizeMfgNo($mfgNo);
        if ($mfgNo === '') {
            throw ValidationException::withMessages(['mfg_no' => 'MFG No. is required.']);
        }

        $status = strtoupper(trim($status));
        if (!in_array($status, [DeliveryConfirmation::STATUS_CONFIRM, DeliveryConfirmation::STATUS_POSTPONE], true)) {
            throw ValidationException::withMessages(['confirmation_status' => 'Invalid status.']);
        }

        $newDate = null;
        if ($status === DeliveryConfirmation::STATUS_POSTPONE) {
            if (!$newDeliveryDate) {
                throw ValidationException::withMessages(['new_delivery_date' => 'กรุณาระบุวันส่งใหม่']);
            }
            try {
                $newDate = Carbon::parse($newDeliveryDate)->startOfDay();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['new_delivery_date' => 'รูปแบบวันที่ไม่ถูกต้อง']);
            }

            if ($originalShipDate) {
                try {
                    $orig = Carbon::parse($originalShipDate)->startOfDay();
                    if (!$newDate->gt($orig)) {
                        throw ValidationException::withMessages([
                            'new_delivery_date' => 'New Delivery Date ต้องมากกว่าวันส่งสินค้าเดิม (' . $orig->format('d/m/Y') . ')',
                        ]);
                    }
                } catch (ValidationException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // ignore parse error of original
                }
            }
        }

        $user = Auth::user();

        $row = DeliveryConfirmation::create([
            'mfg_no' => $mfgNo,
            'site' => strtoupper(trim((string) $site)),
            'so_number' => $soNumber ? trim($soNumber) : null,
            'confirmation_status' => $status,
            'original_ship_date' => $originalShipDate ? Carbon::parse($originalShipDate)->toDateString() : null,
            'new_delivery_date' => $newDate?->toDateString(),
            'remark' => $remark ? mb_substr(trim($remark), 0, 500) : null,
            'confirmed_by_id' => $user?->id,
            'confirmed_by_login' => $user?->login ?? $user?->username ?? null,
            'confirmed_by_name' => $user?->name ?? null,
            'confirmed_at' => now(),
        ]);

        return $row;
    }

    /**
     * Latest confirmation per (mfg_no, site).
     * Returns map keyed by "SITE|MFG" => stdClass with the row columns.
     */
    public function latestMap(array $mfgNos, array $sites = []): Collection
    {
        $mfgNos = collect($mfgNos)
            ->map(fn($x) => $this->normalizeMfgNo((string) $x))
            ->filter()
            ->unique()
            ->values();

        if ($mfgNos->isEmpty()) {
            return collect();
        }

        $sub = DB::connection('sqlsrv_menam')
            ->table('dp_delivery_confirmation')
            ->select([
                'mfg_no',
                'site',
                DB::raw('MAX(id) as max_id'),
            ])
            ->whereIn('mfg_no', $mfgNos->all())
            ->when(!empty($sites), function ($q) use ($sites) {
                $q->whereIn('site', collect($sites)->map(fn($s) => strtoupper(trim((string) $s)))->all());
            })
            ->groupBy('mfg_no', 'site');

        $rows = DB::connection('sqlsrv_menam')
            ->table('dp_delivery_confirmation as c')
            ->joinSub($sub, 'm', function ($join) {
                $join->on('m.max_id', '=', 'c.id');
            })
            ->select([
                'c.id',
                'c.mfg_no',
                'c.site',
                'c.so_number',
                'c.confirmation_status',
                'c.original_ship_date',
                'c.new_delivery_date',
                'c.remark',
                'c.confirmed_by_id',
                'c.confirmed_by_login',
                'c.confirmed_by_name',
                'c.confirmed_at',
            ])
            ->get();

        return $rows->keyBy(function ($row) {
            return strtoupper((string) $row->site) . '|' . trim((string) $row->mfg_no);
        });
    }

    /**
     * Bulk save items as CONFIRM only.
     * $items: each ['mfg_no' => ..., 'site' => ..., 'so_number' => ..., 'original_ship_date' => ...]
     * Skips items that already have any saved confirmation (CONFIRM or POSTPONE).
     */
    public function saveBulkConfirm(array $items): array
    {
        $saved = 0;
        $skipped = 0;
        $errors = 0;

        $normalized = collect($items)
            ->map(function ($it) {
                return [
                    'mfg_no' => $this->normalizeMfgNo((string) ($it['mfg_no'] ?? '')),
                    'site' => strtoupper(trim((string) ($it['site'] ?? ''))),
                    'so_number' => $it['so_number'] ?? null,
                    'original_ship_date' => $it['original_ship_date'] ?? null,
                ];
            })
            ->filter(fn($it) => $it['mfg_no'] !== '')
            ->values();

        if ($normalized->isEmpty()) {
            return compact('saved', 'skipped', 'errors');
        }

        // Skip rows that already have a confirmation
        $existing = $this->latestMap(
            $normalized->pluck('mfg_no')->all(),
            $normalized->pluck('site')->filter()->unique()->values()->all()
        );

        foreach ($normalized as $it) {
            $key = $it['site'] . '|' . $it['mfg_no'];
            if ($existing->has($key)) {
                $skipped++;
                continue;
            }

            try {
                $this->save(
                    $it['mfg_no'],
                    $it['site'],
                    $it['so_number'],
                    DeliveryConfirmation::STATUS_CONFIRM,
                    null,
                    $it['original_ship_date'],
                    null
                );
                $saved++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return compact('saved', 'skipped', 'errors');
    }

    public function history(string $mfgNo, ?string $site = null): Collection
    {
        return DB::connection('sqlsrv_menam')
            ->table('dp_delivery_confirmation')
            ->where('mfg_no', $this->normalizeMfgNo($mfgNo))
            ->when($site, fn($q) => $q->where('site', strtoupper(trim((string) $site))))
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get();
    }
}
