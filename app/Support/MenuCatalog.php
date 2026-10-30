<?php

namespace App\Support;

use App\Models\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class MenuCatalog
{
    public static function forUser(User $user): Collection
    {
        $entries = collect();

        foreach (config('menu.menu', []) as $menuItems) {
            foreach ($menuItems as $item) {
                if (!self::canSee($user, $item)) {
                    continue;
                }

                if (!empty($item['children'])) {
                    foreach ($item['children'] as $child) {
                        self::append($entries, $user, $child, $item['text'] ?? 'เมนูอื่น');
                    }

                    continue;
                }

                self::append($entries, $user, $item, $item['text'] ?? 'เมนูอื่น');
            }
        }

        return $entries
            ->reject(fn(array $entry) => in_array($entry['route'], ['home', 'login', 'menus.index'], true))
            ->unique('route')
            ->values();
    }

    private static function append(Collection $entries, User $user, array $item, string $group): void
    {
        $route = $item['route'] ?? null;

        if (!$route || !empty($item['header']) || !self::canSee($user, $item) || !Route::has($route)) {
            return;
        }

        $entries->push([
            'route' => $route,
            'text' => $item['text'] ?? $route,
            'icon' => $item['icon'] ?? 'fa-circle',
            'group' => $group,
            'url' => route($route, $item['query'] ?? []),
        ]);
    }

    private static function canSee(User $user, array $item): bool
    {
        return empty($item['permission']) || $user->hasRoleCode($item['permission']);
    }
}
