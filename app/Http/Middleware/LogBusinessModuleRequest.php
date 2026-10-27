<?php

namespace App\Http\Middleware;

use App\Support\ModuleLogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogBusinessModuleRequest
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $routeName = $route?->getName();
        $module = ModuleLogContext::moduleForRouteName($routeName);

        if ($module === null) {
            return $next($request);
        }

        $requestId = ModuleLogContext::requestId($request);
        $startedAt = microtime(true);
        $context = array_merge([
            'module' => $module,
            'action' => $routeName,
            'controller' => $route?->getActionName(),
            'http_method' => $request->method(),
            'request_id' => $requestId,
            'user_id' => $request->user()?->id,
        ], ModuleLogContext::identifiers($request));

        // Log::withContext also enriches existing module logs emitted deeper in this request.
        Log::withContext($context);
        $shouldAudit = !$request->isMethodSafe();

        if ($shouldAudit) {
            Log::info("{$module} action started");
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Log::error("{$module} action failed", [
                'duration_ms' => self::durationMs($startedAt),
                'exception' => get_class($e),
                'message' => ModuleLogContext::exceptionMessage($e),
            ]);
            Log::withoutContext();

            throw $e;
        }

        if ($shouldAudit) {
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;
            Log::info("{$module} action completed", [
                'http_status' => $status,
                'outcome' => $status !== null && $status >= 400 ? 'rejected' : 'success',
                'duration_ms' => self::durationMs($startedAt),
            ]);
        }

        if ($response instanceof Response) {
            $response->headers->set('X-Request-ID', $requestId);
        }

        // Prevent context leaking when a long-running PHP worker handles the next request.
        Log::withoutContext();

        return $response;
    }

    private static function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
