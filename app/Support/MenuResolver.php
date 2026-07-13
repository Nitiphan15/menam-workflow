<?php

namespace App\Support;

/**
 * แปลง route name -> ชื่อเมนู (text) จาก config/menu.php
 * ใช้สำหรับ audit log ให้อ่านง่ายว่า user เข้าหน้าเมนูไหน
 */
class MenuResolver
{
    /** @var array<string,string>|null */
    protected static ?array $map = null;

    public static function labelFor(?string $routeName): ?string
    {
        if (!$routeName) {
            return null;
        }

        return self::map()[$routeName] ?? null;
    }

    /**
     * @return array<string,string>
     */
    protected static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [];
        foreach ((array) config('menu.menu', []) as $group) {
            self::walk($group, $map);
        }

        return self::$map = $map;
    }

    /**
     * @param mixed               $items
     * @param array<string,string> $map
     */
    protected static function walk($items, array &$map): void
    {
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['route']) && !empty($item['text'])) {
                // ตัวแรกที่เจอชนะ (เมนู guest มาก่อน auth)
                $map[$item['route']] ??= $item['text'];
            }

            if (!empty($item['children'])) {
                self::walk($item['children'], $map);
            }
        }
    }
}
