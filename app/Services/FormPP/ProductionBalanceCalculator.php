<?php

namespace App\Services\FormPP;

use App\Support\FormDP\ProductionWeightTolerance;
use Illuminate\Support\Collection;

final class ProductionBalanceCalculator
{
    public function calculate(Collection $orders, Collection $production, Collection $fgStock): Collection
    {
        $orders = $orders->sortBy(fn ($row) => [
            $row->item,
            $row->due_date ?: '9999-12-31',
            $row->order_date ?: '9999-12-31',
            $row->so,
        ])->values();

        $fgRemaining = $fgStock->groupBy(fn ($row) => $this->normalize($row->item).'|'.$this->normalize($row->site))
            ->map(fn (Collection $rows) => max(0.0, (float) $rows->sum('qty')));
        $productionByOrder = $production
            ->filter(fn ($row) => $this->normalize($row->so) !== '')
            ->groupBy(fn ($row) => $this->orderKey($row->so, $row->item));

        $rows = $orders->map(function ($order) use ($productionByOrder, &$fgRemaining) {
            $key = $this->orderKey($order->so, $order->item);
            $supply = $productionByOrder->get($key, collect());
            $soQty = max(0.0, (float) $order->backorder_kg);
            $fgAllocated = 0.0;
            $fgSites = [];
            foreach (['WIRE', 'PLUS'] as $site) {
                $fgKey = $this->normalize($order->item).'|'.$site;
                $availableFg = (float) $fgRemaining->get($fgKey, 0.0);
                $allocated = min($availableFg, max(0.0, $soQty - $fgAllocated));
                if ($allocated > 0) {
                    $fgSites[] = $site;
                    $fgAllocated += $allocated;
                    $fgRemaining->put($fgKey, $availableFg - $allocated);
                }
            }

            $mfg = round((float) $supply->sum('mfg_qty'), 4);
            $wip = round((float) $supply->sum('wip_qty'), 4);
            $need = round($soQty - ($fgAllocated + $wip + $mfg), 4);
            $tolerance = round(ProductionWeightTolerance::allowedShortageKg($soQty), 4);
            $dataIssues = collect($order->data_issues ?? [])->filter()->values();

            return (object) [
                'site' => $supply->pluck('site')->concat($fgSites)->filter()->unique()->sort()->implode(' + ') ?: '-',
                'customer' => $order->customer,
                'item' => $order->item,
                'description' => $order->description,
                'unit' => 'KG',
                'so' => $order->so,
                'due_date' => $order->due_date,
                'order_date' => $order->order_date,
                'so_backorder' => round($soQty, 4),
                'fg' => round($fgAllocated, 4),
                'wip' => $wip,
                'mfg' => $mfg,
                'need_qty' => $need,
                'excess_qty' => $need < -$tolerance ? abs($need) : 0.0,
                'tolerance_qty' => $tolerance,
                'tolerance_rate' => ProductionWeightTolerance::shortageRate($soQty),
                'status' => $dataIssues->isNotEmpty() ? 'DATA_ISSUE' : $this->status($need, $tolerance),
                'sales_orders' => [$order->so],
                'mfg_numbers' => $supply->pluck('mfg')->filter()->unique()->sort()->values()->all(),
                'missing_so_mfg' => [],
                'data_issues' => $dataIssues->all(),
            ];
        });

        $orphans = $production->filter(fn ($row) => $this->normalize($row->so) === '')
            ->groupBy(fn ($row) => $this->normalize($row->site).'|'.$this->normalize($row->item))
            ->map(function (Collection $supply) {
                $first = $supply->first();

                return (object) [
                    'site' => $first->site,
                    'customer' => $first->customer ?: 'ไม่ระบุลูกค้า',
                    'item' => $first->item,
                    'description' => $first->description ?? '',
                    'unit' => 'KG',
                    'so' => '',
                    'due_date' => null,
                    'order_date' => null,
                    'so_backorder' => 0.0,
                    'fg' => 0.0,
                    'wip' => round((float) $supply->sum('wip_qty'), 4),
                    'mfg' => round((float) $supply->sum('mfg_qty'), 4),
                    'need_qty' => 0.0,
                    'excess_qty' => 0.0,
                    'tolerance_qty' => 0.0,
                    'tolerance_rate' => 0.0,
                    'status' => 'DATA_ISSUE',
                    'sales_orders' => [],
                    'mfg_numbers' => $supply->pluck('mfg')->filter()->unique()->sort()->values()->all(),
                    'missing_so_mfg' => $supply->pluck('mfg')->filter()->unique()->sort()->values()->all(),
                    'data_issues' => ['MFG ไม่ระบุ SO'],
                ];
            })->values();

        $unallocatedFg = $fgRemaining->filter(fn (float $qty) => $qty > 0.0001)
            ->map(function (float $qty, string $key) {
                [$item, $site] = explode('|', $key, 2);
                return (object) [
                    'site' => $site, 'customer' => 'FG ไม่มี SO รองรับ', 'item' => $item, 'description' => '',
                    'unit' => 'KG', 'so' => '', 'due_date' => null, 'order_date' => null,
                    'so_backorder' => 0.0, 'fg' => round($qty, 4), 'wip' => 0.0, 'mfg' => 0.0,
                    'need_qty' => round(-$qty, 4), 'excess_qty' => round($qty, 4),
                    'tolerance_qty' => 0.0, 'tolerance_rate' => 0.0, 'status' => 'EXCESS',
                    'sales_orders' => [], 'mfg_numbers' => [], 'missing_so_mfg' => [],
                    'data_issues' => [],
                ];
            })->values();

        return $rows->concat($orphans)->concat($unallocatedFg)->values();
    }

    private function status(float $need, float $tolerance): string
    {
        if (abs($need) <= $tolerance) {
            return 'COMPLETE';
        }

        return $need > 0 ? 'SHORT' : 'EXCESS';
    }

    private function orderKey($so, $item): string
    {
        return $this->normalize($so).'|'.$this->normalize($item);
    }

    private function normalize($value): string
    {
        return mb_strtoupper(trim((string) $value), 'UTF-8');
    }
}
