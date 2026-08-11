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

    public static function matches(?string $process, mixed $filters): bool
    {
        $filters = self::normalizeFilters($filters);
        if ($filters === []) {
            return true;
        }

        $label = self::label($process);

        foreach ($filters as $filter) {
            if (strcasecmp($label, $filter) === 0) {
                return true;
            }
        }

        return false;
    }
}
