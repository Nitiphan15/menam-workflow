<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * จำกัดการเข้าถึงให้เฉพาะ IP ที่อยู่ใน allowlist เท่านั้น
 * รายการ IP อ่านจาก config/security.php (ตั้งผ่าน env ALLOWED_API_IPS ได้)
 *
 * ใช้งาน: ->middleware('ip.allow')
 */
class RestrictIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('security.allowed_ips', []);

        // ไม่ได้ตั้งค่าไว้ = ไม่จำกัด (กันเผลอ lock ตัวเองตอน config หาย)
        if (empty($allowed)) {
            return $next($request);
        }

        if (! in_array($request->ip(), $allowed, true)) {
            abort(403, 'Access denied: your IP is not allowed to use this resource.');
        }

        return $next($request);
    }
}
