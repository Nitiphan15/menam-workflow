<?php

namespace App\Services\FormDP;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class GpsTripQueueService
{
    /**
     * Read-only snapshot of the latest truck assignment per order/trip.
     * Stop order is a demo inference from assignment id because FormDP has no route sequence yet.
     */
    public function forDate(string $shipDate): Collection
    {
        try {
            $date = Carbon::parse($shipDate)->toDateString();
            $db = DB::connection('sqlsrv_menam');

            $latestAssignments = $db->table('delivery_plan_truck_assign')
                ->selectRaw('MAX(id) as latest_id, ord_id, ISNULL(trip_no, 1) as trip_no')
                ->whereRaw('CAST(ship_posted_at AS date) = ?', [$date])
                ->groupBy('ord_id', DB::raw('ISNULL(trip_no, 1)'));

            $rows = $db->table('delivery_plan_data as dp')
                ->joinSub($latestAssignments, 'la', fn ($join) => $join->on('la.ord_id', '=', 'dp.ord_id'))
                ->join('delivery_plan_truck_assign as ta', 'ta.id', '=', 'la.latest_id')
                ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
                ->leftJoin('customer as c', function ($join) {
                    $join->on('c.id', '=', 'dp.customer_id')->whereRaw("c.company = COALESCE(NULLIF(dp.company, ''), 'MSW')");
                })
                ->select([
                    'ta.id as assignment_id',
                    'ta.truck_id',
                    'ta.trip_no',
                    'ta.truck_source',
                    'ta.manual_plate_no',
                    'tm.plate_no as master_plate_no',
                    'ta.driver_name',
                    'ta.manual_driver_name',
                    'tm.driver_name as master_driver_name',
                    'dp.ord_id',
                    'dp.so_number',
                    'dp.part_number',
                    'dp.part_desc',
                    'dp.qty',
                    'dp.line_qty',
                    'dp.sell_by_line',
                    'dp.address',
                    'dp.customer_name',
                    'c.name as master_customer_name',
                ])
                ->whereRaw('CAST(dp.ship_posted_at AS date) = ?', [$date])
                ->orderBy('ta.id')
                ->get()
                ->map(function ($row) {
                    $manual = strtoupper(trim((string) $row->truck_source)) === 'MANUAL';
                    $row->plate_no = trim((string) ($manual ? $row->manual_plate_no : $row->master_plate_no));
                    $row->plate_key = $this->normalizePlate($row->plate_no);
                    $row->driver_display = trim((string) ($row->driver_name ?: ($row->manual_driver_name ?: $row->master_driver_name)));
                    $row->customer_display = trim((string) ($row->customer_name ?: $row->master_customer_name ?: $row->address ?: $row->so_number));

                    return $row;
                })
                ->filter(fn ($row) => $row->plate_key !== '');

            return $rows
                ->groupBy(fn ($row) => $row->plate_key . '|' . max(1, (int) ($row->trip_no ?? 1)))
                ->map(function (Collection $items) use ($date) {
                    $first = $items->first();

                    return [
                        'ship_date' => $date,
                        'plate_key' => $first->plate_key,
                        'plate_no' => $first->plate_no,
                        'trip_no' => max(1, (int) ($first->trip_no ?? 1)),
                        'truck_id' => $first->truck_id ? (int) $first->truck_id : null,
                        'callback_key' => $first->truck_id
                            ? ((int) $first->truck_id . ':' . max(1, (int) ($first->trip_no ?? 1)))
                            : null,
                        'driver_name' => $first->driver_display,
                        'stops' => $items->pluck('customer_display')->filter()->unique()->values()->all(),
                        'deliveries' => $items
                            ->groupBy('customer_display')
                            ->map(function (Collection $customerItems, string $customer) {
                                return [
                                    'customer' => $customer,
                                    'items' => $customerItems->map(function ($item) {
                                        $pieceQuantity = (int) ($item->sell_by_line ?? 0) === 1
                                            && (float) ($item->qty ?? 0) === 0.0;
                                        $quantity = $pieceQuantity
                                            ? (float) ($item->line_qty ?? 0)
                                            : (float) ($item->qty ?? 0);

                                        return [
                                            'dp_ref' => 'DP#' . (int) $item->ord_id,
                                            'so_number' => trim((string) ($item->so_number ?? '')),
                                            'product' => trim((string) ($item->part_desc ?: $item->part_number ?: '-')),
                                            'part_number' => trim((string) ($item->part_number ?? '')),
                                            'quantity' => $quantity,
                                            'unit' => $pieceQuantity ? 'ชิ้น' : 'ตัน',
                                        ];
                                    })->values()->all(),
                                ];
                            })
                            ->values()
                            ->all(),
                    ];
                })
                ->values();
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    public function normalizePlate(?string $plate): string
    {
        return mb_strtoupper((string) preg_replace('/[^0-9A-Zก-๙]/u', '', trim((string) $plate)));
    }
}
