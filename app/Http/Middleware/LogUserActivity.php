<?php

namespace App\Http\Middleware;

use App\Models\UserActivityLog;
use App\Support\MenuResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * บันทึก audit log ว่า user (หรือ guest) เข้าหน้าเมนูไหน และทำ action อะไร
 * ทำงานแบบ terminable (หลังส่ง response ให้ผู้ใช้แล้ว) เพื่อไม่กระทบเวลาโหลดหน้า
 */
class LogUserActivity
{
    /** map HTTP method -> action_type */
    protected array $methodActions = [
        'GET'    => 'view',
        'HEAD'   => 'view',
        'POST'   => 'insert',
        'PUT'    => 'update',
        'PATCH'  => 'update',
        'DELETE' => 'delete',
    ];

    /** route name ที่มี keyword เหล่านี้จะไม่เก็บ (ajax/lookup/asset/dev tools) */
    protected array $skipRouteKeywords = [
        'lookup',
        'autocomplete',
        'ignition',
        'sanctum',
        'livewire',
        'debugbar',
        'telescope',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            // ห้ามทำให้ request ผู้ใช้พังเพราะ log ล้มเหลว
            Log::warning('LogUserActivity failed: ' . $e->getMessage());
        }
    }

    protected function record(Request $request, Response $response): void
    {
        // ไม่บันทึก activity log บนเครื่อง dev/local เพราะใช้ DB 'sqlsrv_menam' ร่วมกับ prod (กัน log ปนกัน)
        // override ได้ด้วย ACTIVITY_LOG_ENABLED ใน .env (true/false)
        $enabled = config('app.activity_log_enabled');
        if ($enabled === null) {
            $enabled = ! app()->environment('local');
        }
        if (! $enabled) {
            return;
        }

        $route = $request->route();
        $routeName = $route ? $route->getName() : null;

        // เก็บเฉพาะ request ที่ map เป็น "หน้า" จริง (มี route name) -> ตัด asset/ajax ทั่วไป
        if (!$routeName) {
            return;
        }

        $method = strtoupper($request->getMethod());
        if (!isset($this->methodActions[$method])) {
            return;
        }

        $lowerName = strtolower($routeName);
        foreach ($this->skipRouteKeywords as $kw) {
            if (str_contains($lowerName, $kw)) {
                return;
            }
        }

        $user = $request->user();

        UserActivityLog::create([
            'user_id'          => $user?->id,
            'user_name'        => $user?->name ?? 'guest',
            'is_authenticated' => $user !== null,
            'menu_name'        => MenuResolver::labelFor($routeName),
            'route_name'       => $routeName,
            'url_path'         => '/' . ltrim($request->path(), '/'),  // path เท่านั้น ไม่เก็บ query string
            'method'           => $method,
            'action_type'      => $this->methodActions[$method],
            'status_code'      => $response->getStatusCode(),
            'ip_address'       => $request->ip(),
            'user_agent'       => mb_substr((string) $request->userAgent(), 0, 255),
            'accessed_at'      => now(),
        ]);
    }
}
