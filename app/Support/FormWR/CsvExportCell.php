<?php

namespace App\Support\FormWR;

final class CsvExportCell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);

        if ($trimmed !== '' && is_numeric($trimmed)) {
            return $value;
        }

        return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)
            ? "'".$value
            : $value;
    }

    public static function row(array $values): array
    {
        return array_map([self::class, 'safe'], $values);
    }
}
