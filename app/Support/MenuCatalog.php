<?php

namespace App\Support;

use App\Models\Users\User;
use Illuminate\Support\Facades\Route;

/**
 * รวบรวมเมนูสาธารณะ (config menu.menu.guest) สำหรับหน้า "เมนูทั้งหมด"
 * โดยกรองตามสิทธิ์ผู้ใช้ และตัดรายการที่ route ไม่มีอยู่จริงออก (กันหน้า 500)
 */
class MenuCatalog
{
    /** route ที่ไม่ต้องแสดงในหน้าเมนูทั้งหมด */
    public const EXCLUDED_ROUTES = ['home', 'login', 'menus.index'];

    /**
     * @return array<int, array{text: string, icon: string, links: array<int, array{route: string, text: string, icon: string, url: string}>}>
     */
    public static function forUser(User $user): array
    {
        // โหลด role code ของผู้ใช้ครั้งเดียว แทนการเรียก hasRoleCode() ทีละเมนู
        $roleCodes = $user->deptRoles()
            ->pluck('code')
            ->map(fn($code) => strtoupper((string) $code))
            ->unique()
            ->values()
            ->all();

        $groups = self::groups(
            config('menu.menu.guest', []),
            $roleCodes,
            fn(string $route) => Route::has($route),
        );

        foreach ($groups as &$group) {
            foreach ($group['links'] as &$link) {
                $link['url'] = route($link['route'], $link['query']);
                unset($link['query']);
            }
            unset($link);
        }
        unset($group);

        return $groups;
    }

    /**
     * Logic ล้วน (ไม่แตะ DB / router) เพื่อให้ unit test ได้
     *
     * @param  array<int, array<string, mixed>>  $menuItems
     * @param  array<int, string>  $roleCodes  role code ของผู้ใช้ (ตัวพิมพ์ใหญ่)
     * @param  callable(string): bool  $routeExists
     */
    public static function groups(array $menuItems, array $roleCodes, callable $routeExists): array
    {
        $groups = [];
        $seenRoutes = [];

        foreach ($menuItems as $item) {
            if (!self::canSee($item, $roleCodes)) {
                continue; // ตัวแม่ไม่มีสิทธิ์ → ซ่อนทั้งกลุ่ม รวม children
            }

            $candidates = !empty($item['children']) ? $item['children'] : [$item];
            $links = [];

            foreach ($candidates as $child) {
                $route = $child['route'] ?? null;

                if (
                    !$route
                    || !empty($child['header'])
                    || in_array($route, self::EXCLUDED_ROUTES, true)
                    || isset($seenRoutes[$route])
                    || !self::canSee($child, $roleCodes)
                    || !$routeExists($route)
                ) {
                    continue;
                }

                $seenRoutes[$route] = true;
                $links[] = [
                    'route' => $route,
                    'text' => (string) ($child['text'] ?? $route),
                    'icon' => self::iconClass($child['icon'] ?? 'fa-angle-right'),
                    'query' => $child['query'] ?? [],
                ];
            }

            if ($links === []) {
                continue;
            }

            $groups[] = [
                'text' => (string) ($item['text'] ?? 'เมนูอื่น'),
                'icon' => self::iconClass($item['icon'] ?? 'fa-circle'),
                'links' => $links,
            ];
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $roleCodes
     */
    private static function canSee(array $item, array $roleCodes): bool
    {
        if (empty($item['permission'])) {
            return true;
        }

        $required = array_map(fn($code) => strtoupper((string) $code), (array) $item['permission']);

        return array_intersect($required, $roleCodes) !== [];
    }

    /** config ใช้ทั้งแบบ "fa-box" และ "fa-solid fa-box" → ทำให้เป็น class ที่ใช้ได้เสมอ */
    private static function iconClass(string $icon): string
    {
        $icon = trim($icon);

        $styles = ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands'];

        if (array_intersect(preg_split('/\s+/', $icon), $styles) !== []) {
            return $icon;
        }

        return 'fas ' . $icon;
    }
}
