<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAnyPermission
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        $permissions = collect($permissions)
            ->map(fn($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        if (empty($permissions)) {
            return $next($request);
        }

        if (($user->is_superadmin ?? false) || $user->hasRoleCode($permissions)) {
            return $next($request);
        }

        abort(403);
    }
}
