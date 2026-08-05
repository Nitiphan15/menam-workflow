<?php

namespace App\Support\FormWOS;

use App\Models\FormWOS\DeadstockUserSalesAccess;
use App\Models\Users\User;

final class DeadstockSalesAccess
{
    private static ?\WeakMap $fullAccessCache = null;

    private static ?\WeakMap $salesAccessCache = null;

    public static function hasFullAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        self::$fullAccessCache ??= new \WeakMap();

        if (isset(self::$fullAccessCache[$user])) {
            return self::$fullAccessCache[$user];
        }

        return self::$fullAccessCache[$user] =
            $user->hasRoleCode(['ADMINWEB', 'DS_MANAGE_ALL']);
    }

    public static function canManageAny(?User $user): bool
    {
        return self::hasFullAccess($user)
            || self::mappedKeys($user, 'can_edit') !== [];
    }

    public static function canManage(?User $user, $salesperson): bool
    {
        if (self::hasFullAccess($user)) {
            return true;
        }

        return in_array(DeadstockSalesMap::accessKey($salesperson), self::mappedKeys($user, 'can_edit'), true);
    }

    public static function canImportAll(?User $user): bool
    {
        return $user !== null
            && $user->hasRoleCode(['ADMINWEB', 'DS_IMPORT_ALL', 'DS_MANAGE_ALL']);
    }

    public static function canImportAny(?User $user): bool
    {
        return self::canImportAll($user)
            || ($user !== null
                && $user->hasRoleCode('DS_IMPORT')
                && self::mappedKeys($user, 'can_import') !== []);
    }

    public static function canImport(?User $user, $salesperson): bool
    {
        if (self::canImportAll($user)) {
            return true;
        }

        return $user !== null
            && $user->hasRoleCode('DS_IMPORT')
            && in_array(DeadstockSalesMap::accessKey($salesperson), self::mappedKeys($user, 'can_import'), true);
    }

    public static function managedSalespeople(?User $user): array
    {
        return self::mappedKeys($user, 'can_edit');
    }

    private static function mappedKeys(?User $user, string $capability): array
    {
        if ($user === null) {
            return [];
        }

        self::$salesAccessCache ??= new \WeakMap();

        if (! isset(self::$salesAccessCache[$user])) {
            $accesses = $user->relationLoaded('deadstockSalesAccesses')
                ? $user->getRelation('deadstockSalesAccesses')
                : DeadstockUserSalesAccess::query()
                    ->where('user_id', $user->getKey())
                    ->where('is_active', true)
                    ->get();

            self::$salesAccessCache[$user] = [
                'can_import' => $accesses
                    ->filter(fn (DeadstockUserSalesAccess $access) => $access->is_active && $access->can_import)
                    ->pluck('salesperson_key')
                    ->map(fn ($key) => strtoupper(trim((string) $key)))
                    ->unique()
                    ->values()
                    ->all(),
                'can_edit' => $accesses
                    ->filter(fn (DeadstockUserSalesAccess $access) => $access->is_active && $access->can_edit)
                    ->pluck('salesperson_key')
                    ->map(fn ($key) => strtoupper(trim((string) $key)))
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        return self::$salesAccessCache[$user][$capability] ?? [];
    }
}
