<?php

namespace App\Http\Middleware;

use App\Services\Audit\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * บันทึก audit log ว่า user (หรือ guest) เข้าหน้าเมนูไหน และทำ action อะไร
 * ทำงานแบบ terminable (หลังส่ง response ให้ผู้ใช้แล้ว) เพื่อไม่กระทบเวลาโหลดหน้า
 *
 * - การเปิดหน้า (view) เก็บเฉพาะหน้า HTML จริง ข้าม AJAX/JSON เพื่อลด log ขยะ
 * - login / login_failed / logout เก็บจาก auth events (ดู AuthActivitySubscriber)
 */
class LogUserActivity
{
    protected array $allowedMethods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** route name ที่มี keyword เหล่านี้จะไม่เก็บ (ajax/lookup/asset/dev tools) */
    protected array $skipRouteKeywords = [
        'lookup',
        'autocomplete',
        'ignition',
        'sanctum',
        'livewire',
        'debugbar',
        'telescope',
        'client-errors', // JS error reporter — เก็บใน client-errors log แยกแล้ว
    ];

    /** route ที่ auth events บันทึกให้แล้ว */
    protected array $authRoutes = ['login.attempt', 'logout'];

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
        if (! ActivityLogger::enabled()) {
            return;
        }

        $routeName = $request->route()?->getName();

        // เก็บเฉพาะ request ที่ map เป็น "หน้า" จริง (มี route name) -> ตัด asset/ajax ทั่วไป
        if (! $routeName) {
            return;
        }

        $method = strtoupper($request->getMethod());
        if (! in_array($method, $this->allowedMethods, true)) {
            return;
        }

        $status = $response->getStatusCode();

        if (in_array($routeName, $this->authRoutes, true)) {
            // login ถูก throttle (429) ไม่มี auth event ให้จับ -> เก็บจากตรงนี้
            if ($routeName === 'login.attempt' && $status === 429) {
                ActivityLogger::record(
                    $request,
                    null,
                    'login_blocked',
                    $status,
                    $routeName,
                    $this->attemptedLogin($request)
                );
            }

            return;
        }

        $lowerName = strtolower($routeName);
        foreach ($this->skipRouteKeywords as $kw) {
            if (str_contains($lowerName, $kw)) {
                return;
            }
        }

        $action = ActivityLogger::classify($method, $routeName);

        if ($action === 'view' && $this->isBackgroundRequest($request, $response)) {
            return;
        }

        ActivityLogger::record($request, $request->user(), $action, $status, $routeName);
    }

    /**
     * AJAX / JSON ที่หน้าเว็บเรียกเบื้องหลัง (options, defaults, duplicateCheck, search ฯลฯ)
     */
    protected function isBackgroundRequest(Request $request, Response $response): bool
    {
        if ($request->ajax() || $request->wantsJson()) {
            return true;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        return str_contains($contentType, 'json');
    }

    protected function attemptedLogin(Request $request): string
    {
        return mb_substr(trim((string) ($request->input('login') ?: $request->input('email'))), 0, 150) ?: 'guest';
    }
}
