<?php

namespace App\Support\FormWOS;

use Illuminate\Support\Collection;

final class DeadstockSalesMap
{
    private const DIVISION_KEYWORDS = [
        'ดิลก' => 'D1',
        'ขวัญเรือน' => 'D1',
        'ปรียาพรรณ' => 'D2',
        'นิตยา' => 'D2',
        'ภควดี' => 'D3',
        'ธนัชชา' => 'D3',
        'วิลาวัณย์' => 'D4',
        'ธัธลิญา' => 'D5',
        'เฌอร์ลิญา' => 'D5',
        'สุรศักดิ์' => 'D6',
        'คณัญญ์นิชา' => 'D6',
        'ศิรินภา' => 'D7',
        'มนพัทธ์' => 'D7',
        'สาธิต' => 'D8',
        'สุธาสินี' => 'D8',
        'วรเดชา' => 'D9',
        'ลัดดาวัลย์' => 'D9',
    ];

    private const DIVISION_ACCESS_LABELS = [
        'D1' => 'ดิลก + ขวัญเรือน',
        'D2' => 'ปรียาพรรณ + นิตยา',
        'D3' => 'ภควดี + ธนัชชา',
        'D4' => 'วิลาวัณย์',
        'D5' => 'ธัธลิญา + เฌอร์ลิญา',
        'D6' => 'สุรศักดิ์ + คณัญญ์นิชา',
        'D7' => 'ศิรินภา + มนพัทธ์',
        'D8' => 'สาธิต + สุธาสินี',
        'D9' => 'วรเดชา + ลัดดาวัลย์',
    ];

    private const SALES = [
        'คุณดิลก สอนแจ้ง' => ['division' => 'D1', 'aliases' => ['Export Sales 01']],
        'คุณปรียาพรรณ ทิพหา' => ['division' => 'D2', 'aliases' => ['Export Sales 02']],
        'คุณภควดี เรืองเชื้อเหมือน' => ['division' => 'D3', 'aliases' => ['Sales Person 03']],
        'คุณวิลาวัณย์ สิงหวิบูลย์' => ['division' => 'D4', 'aliases' => ['Sales Person 04']],
        'คุณธัธลิญา พงษ์ศิริ' => ['division' => 'D5', 'aliases' => ['Sales Person 05']],
        'คุณสุรศักดิ์ เลียงมงคลการ' => ['division' => 'D6', 'aliases' => ['Sales Person 06']],
        'คุณศิรินภา สุวรรณมา' => ['division' => 'D7', 'aliases' => ['Sales Person 07']],
        'คุณสาธิต หมื่นนรินทร์' => ['division' => 'D8', 'aliases' => ['Sales Person 08']],
        'คุณวรเดชา วัธนกุล' => ['division' => 'D9', 'aliases' => ['Sales Person 09']],
        'Assadaporn Maneechote' => ['division' => 'ASSADAPORN', 'aliases' => ['assadaporn_m']],
        'Thanin Prakobsaeng' => ['division' => 'THANIN', 'aliases' => ['thanin_p']],
        'Procurement' => ['division' => 'PROCUREMENT', 'aliases' => []],
    ];

    public static function label($raw): string
    {
        $label = trim((string) $raw);
        if ($label === '' || in_array($label, ['ไม่ระบุ', 'ไม่ระบุ Sales'], true)) {
            return 'ไม่ระบุ Sales';
        }

        $key = self::normalize($label);

        foreach (self::SALES as $name => $config) {
            if ($key === self::normalize($name)) {
                return $name;
            }

            foreach ($config['aliases'] as $alias) {
                if ($key === self::normalize($alias)) {
                    return $name;
                }
            }
        }

        return $label;
    }

    public static function salesOptions(iterable $rawValues): Collection
    {
        return collect($rawValues)
            ->map(fn ($value) => self::label($value))
            ->filter(fn (string $value) => $value !== 'ไม่ระบุ Sales')
            ->unique()
            ->sort()
            ->values();
    }

    public static function rawValuesForSales(array $selectedNames): array
    {
        return collect($selectedNames)
            ->flatMap(function ($selected) {
                $label = self::label($selected);
                $config = self::SALES[$label] ?? null;

                return $config
                    ? array_merge([$label], self::aliasVariants($config['aliases']))
                    : [$selected];
            })
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function rawValuesForDivisions(array $divisions): array
    {
        $selected = collect($divisions)->map(fn ($value) => strtoupper(trim((string) $value)));

        return collect(self::SALES)
            ->filter(fn (array $config) => $selected->contains($config['division']))
            ->flatMap(fn (array $config, string $name) => array_merge([$name], self::aliasVariants($config['aliases'])))
            ->unique()
            ->values()
            ->all();
    }

    public static function divisionOptions(): array
    {
        return [
            'D1' => 'D1 - ดิลก + ขวัญเรือน',
            'D2' => 'D2 - ปรียาพรรณ + นิตยา',
            'D3' => 'D3 - ภควดี + ธนัชชา',
            'D5' => 'D5 - ธัธลิญา + เฌอร์ลิญา',
            'D6' => 'D6 - สุรศักดิ์ + คณัญญ์นิชา',
            'D7' => 'D7 - ศิรินภา + มนพัทธ์',
            'D8' => 'D8 - สาธิต + สุธาสินี',
            'D9' => 'D9 - วรเดชา + ลัดดาวัลย์',
            'ASSADAPORN' => 'Assadaporn',
            'THANIN' => 'Thanin',
            'PROCUREMENT' => 'Procurement',
        ];
    }

    public static function accessKey($raw): string
    {
        $label = self::label($raw);
        $config = self::SALES[$label] ?? null;

        if ($config) {
            return strtoupper((string) $config['division']);
        }

        foreach (self::DIVISION_KEYWORDS as $keyword => $division) {
            if (mb_stripos($label, $keyword, 0, 'UTF-8') !== false) {
                return $division;
            }
        }

        $key = preg_replace('/[^\pL\pN]+/u', '_', trim($label));

        return mb_strtoupper(trim((string) $key, '_'), 'UTF-8');
    }

    public static function accessOptions(): array
    {
        return collect(self::SALES)
            ->mapWithKeys(fn (array $config, string $label) => [
                strtoupper((string) $config['division']) => self::DIVISION_ACCESS_LABELS[strtoupper((string) $config['division'])] ?? $label,
            ])
            ->all();
    }

    private static function aliasVariants(array $aliases): array
    {
        return collect($aliases)
            ->flatMap(function (string $alias) {
                if (! preg_match('/^(Export Sales|Sales Person)\s*0?([1-9])$/i', $alias, $matches)) {
                    return [$alias];
                }

                return [
                    $matches[1].' 0'.$matches[2],
                    $matches[1].'0'.$matches[2],
                    $matches[1].' '.$matches[2],
                ];
            })
            ->unique()
            ->values()
            ->all();
    }

    private static function normalize(string $value): string
    {
        $value = preg_replace('/[_-]+/', ' ', trim($value));
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        $value = preg_replace_callback(
            '/^(export sales|sales person)\s*0?([1-9])$/i',
            fn (array $matches) => $matches[1].' 0'.$matches[2],
            $value
        );

        return mb_strtolower((string) $value, 'UTF-8');
    }
}
