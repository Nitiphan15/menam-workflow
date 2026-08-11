<?php

namespace App\Support\FormDP;

final class ProductionProcessGroup
{
    public static function label(?string $process): string
    {
        $process = trim((string) $process);
        if ($process === '') {
            return 'Unknown';
        }

        $code = strtoupper($process);

        if (str_starts_with($code, 'CB')) {
            return 'Combine';
        }

        if (in_array($code, ['CT', 'CU', 'D10'], true)) {
            return 'Cut';
        }

        return $process;
    }

    public static function normalizeFilters(mixed $filters): array
    {
        $normalized = [];

        foreach (is_array($filters) ? $filters : [$filters] as $filter) {
            $filter = trim((string) $filter);
            if ($filter === '') {
                continue;
            }

            $normalized[mb_strtolower($filter)] = $filter;
        }

        return array_values($normalized);
    }

    public static function selectableLabels(iterable $processes): array
    {
        $labels = [];

        foreach ($processes as $process) {
            $process = trim((string) $process);
            if ($process === '') {
                continue;
            }

            $label = self::label($process);
            if (in_array(strtoupper($label), ['COMPLETE', 'COMPLETED'], true)) {
                continue;
            }

            $labels[mb_strtolower($label)] = $label;
        }

        return array_values($labels);
    }

    public static function matches(?string $process, mixed $filters): bool
    {
        return self::matchesAny([$process], $filters);
    }

    public static function matchesAny(iterable $processes, mixed $filters): bool
    {
        $filters = self::normalizeFilters($filters);
        if ($filters === []) {
            return true;
        }

        foreach (self::selectableLabels($processes) as $label) {
            foreach ($filters as $filter) {
                if (strcasecmp($label, $filter) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
