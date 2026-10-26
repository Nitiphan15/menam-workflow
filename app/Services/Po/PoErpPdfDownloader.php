<?php

namespace App\Services\Po;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PoErpPdfDownloader
{
    public function download(string $url, string $sessionId, array $context): string
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::withOptions(['verify' => false])
                    ->timeout((int) config('services.erp.timeout', 30))
                    ->withCookies(['PHPSESSID' => $sessionId], parse_url($url, PHP_URL_HOST) ?: '')
                    ->get($url);
            } catch (ConnectionException $exception) {
                Log::warning('ERP PO PDF connection failed', $context + ['attempt' => $attempt]);
                if ($attempt < 2) {
                    continue;
                }
                throw ValidationException::withMessages([
                    'erp_phpsessid' => 'ติดต่อ ERP ไม่สำเร็จ กรุณาลองดาวน์โหลดอีกครั้ง ระบบยังเก็บ ERP session ไว้',
                ]);
            }

            $body = $response->body();
            if ($response->successful() && str_starts_with(ltrim($body), '%PDF-')) {
                return $body;
            }

            $loginRequired = in_array($response->status(), [401, 403], true)
                || (bool) preg_match('/<input\b[^>]*\bname\s*=\s*[\'"]?(?:i_login|i_passwd)(?=[\s\'"\/>])/i', $body);
            $reason = $loginRequired ? 'login_required' : 'unexpected_response';
            Log::warning('ERP PO PDF response was not a PDF', $context + [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type', ''),
                'reason' => $reason,
                'attempt' => $attempt,
            ]);

            if ($loginRequired) {
                session()->forget('po_erp_phpsessid');
                // Do not silently reuse an expired shared fallback session.
                session(['po_erp_reconnect_required' => true]);
                throw ValidationException::withMessages([
                    'erp_phpsessid' => 'ERP ขอให้เข้าสู่ระบบใหม่ หรือบัญชีไม่มีสิทธิ์เข้ารายงาน กรุณาเชื่อมต่อ ERP อีกครั้งและตรวจชุดข้อมูล Wire/Plus',
                ]);
            }

            if ($attempt < 2 && in_array($response->status(), [502, 503, 504], true)) {
                continue;
            }

            throw ValidationException::withMessages([
                'erp_phpsessid' => 'ERP ไม่ได้ส่งไฟล์ PDF กลับมา กรุณาลองใหม่ หากยังพบปัญหาให้ตรวจสิทธิ์รายงาน ชุดข้อมูล Wire/Plus และการตั้งค่ารายงาน ERP (ระบบยังเก็บ session ไว้)',
            ]);
        }
    }
}
