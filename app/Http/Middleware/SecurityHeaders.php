<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * เพิ่ม security headers ให้ทุก response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // กันโดนฝังใน iframe จากโดเมนอื่น (clickjacking)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // กัน browser เดา content type เอง (MIME sniffing)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // ไม่ส่ง URL ภายในระบบไปเป็น referrer ให้เว็บภายนอก
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // ปิด browser feature ที่ระบบไม่ได้ใช้
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
