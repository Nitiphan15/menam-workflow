<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * รับ JavaScript error จาก browser (public/js/client-error-reporter.js)
 * เขียนลง storage/logs/client-errors-YYYY-MM-DD.log — ดูได้ที่ adminweb > Error Log
 *
 * route: auth + throttle — ไม่เก็บ query string ของหน้าเพื่อกันข้อมูลลับหลุด
 */
class ClientErrorController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'type'    => ['nullable', 'string', 'in:error,unhandledrejection'],
            'message' => ['required', 'string', 'max:2000'],
            'source'  => ['nullable', 'string', 'max:1000'],
            'line'    => ['nullable', 'integer', 'min:0'],
            'column'  => ['nullable', 'integer', 'min:0'],
            'stack'   => ['nullable', 'string', 'max:6000'],
            'page'    => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        $context = array_filter([
            'type'     => $data['type'] ?? 'error',
            'page'     => $this->stripQuery($data['page'] ?? null),
            'source'   => $this->stripQuery($data['source'] ?? null),
            'line'     => $data['line'] ?? null,
            'column'   => $data['column'] ?? null,
            'userId'   => $user?->id,
            'userName' => $user?->name,
            'ip'       => $request->ip(),
            'browser'  => mb_substr((string) $request->userAgent(), 0, 200),
        ], fn ($v) => $v !== null && $v !== '');

        $message = 'JS: ' . preg_replace('/\s+/', ' ', mb_substr($data['message'], 0, 500));
        $stack = trim((string) ($data['stack'] ?? ''));

        // stack ต่อท้ายเป็นบรรทัดใหม่ เพื่อให้หน้า Error Log แสดงในส่วน "รายละเอียด"
        Log::channel('client_errors')->error($message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ($stack !== '' ? "\n" . $stack : ''));

        return response()->noContent();
    }

    private function stripQuery(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return mb_substr(strtok($url, '?#') ?: $url, 0, 300);
    }
}
