<?php

namespace App\Services\FormWR;

use App\Support\FormWR\WireRodDeadstockRule;
use Illuminate\Support\Collection;

/**
 * Dashboard summaries built from the already-filtered current rows (no extra queries).
 */
class WireRodDeadstockSummary
{
    /**
     * Division table in KG: Dead / Normal / Total per sales division.
     * D8 (PCS) and other non-KG lots are excluded and reported separately.
     */
    public function byDivision(Collection $rows): array
    {
        $table = collect(WireRodDeadstockRule::DIVISION_SUMMARY_ROWS)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'dead' => 0.0, 'normal' => 0.0, 'unknown' => 0.0, 'total' => 0.0])
            ->all();
        $excluded = ['lots' => 0, 'pcs' => 0.0];

        foreach ($rows as $row) {
            if (($row->display_unit ?? null) !== 'KG') {
                $excluded['lots']++;
                if (($row->display_unit ?? null) === 'PCS') {
                    $excluded['pcs'] += (float) $row->current_qty;
                }
                continue;
            }

            $key = WireRodDeadstockRule::divisionSummaryKey($row->effective_division_code ?? null, $this->hasCustomer($row));
            $qty = (float) $row->current_qty;
            $bucket = match ($row->stock_status) {
                'DEAD' => 'dead',
                'INCOMING' => 'normal',
                default => 'unknown',
            };
            $table[$key][$bucket] += $qty;
            $table[$key]['total'] += $qty;
        }

        $totals = ['dead' => 0.0, 'normal' => 0.0, 'unknown' => 0.0, 'total' => 0.0];
        foreach ($table as $line) {
            foreach ($totals as $field => $_) {
                $totals[$field] += $line[$field];
            }
        }

        // Keep the fixed D-rows always visible; hide fallback rows that have nothing.
        $lines = collect($table)
            ->reject(fn ($line) => in_array($line['key'], ['NO_SALES', 'OTHER'], true) && $line['total'] == 0.0)
            ->values()->all();

        return ['rows' => $lines, 'totals' => $totals, 'excluded' => $excluded];
    }

    /**
     * Aging buckets: 0-90 / 91-180 / 181-365 / >365 days, plus lots without a purchase date.
     */
    public function byAging(Collection $rows): array
    {
        $table = collect(WireRodDeadstockRule::AGING_BUCKETS)
            ->map(fn ($bucket, $key) => ['key' => $key, 'label' => $bucket['label'], 'lots' => 0, 'kg' => 0.0, 'pcs' => 0.0, 'value' => 0.0])
            ->all();
        $table['unknown'] = ['key' => 'unknown', 'label' => 'ไม่ทราบอายุ', 'lots' => 0, 'kg' => 0.0, 'pcs' => 0.0, 'value' => 0.0];

        foreach ($rows as $row) {
            $age = $row->stock_age_days;
            $key = WireRodDeadstockRule::agingBucket($age === null ? null : (int) $age) ?? 'unknown';
            $table[$key]['lots']++;
            if ($row->display_unit === 'KG') {
                $table[$key]['kg'] += (float) $row->current_qty;
            } elseif ($row->display_unit === 'PCS') {
                $table[$key]['pcs'] += (float) $row->current_qty;
            }
            $table[$key]['value'] += (float) ($row->current_value_thb ?? 0);
        }

        $totals = ['lots' => 0, 'kg' => 0.0, 'pcs' => 0.0, 'value' => 0.0];
        foreach ($table as $line) {
            foreach ($totals as $field => $_) {
                $totals[$field] += $line[$field];
            }
        }

        if ($table['unknown']['lots'] === 0) {
            unset($table['unknown']);
        }

        return ['rows' => array_values($table), 'totals' => $totals];
    }

    private function hasCustomer(object $row): bool
    {
        $customers = json_decode((string) ($row->customer_json ?? ''), true);

        return is_array($customers) && collect($customers)->filter(fn ($name) => trim((string) $name) !== '')->isNotEmpty();
    }
}
