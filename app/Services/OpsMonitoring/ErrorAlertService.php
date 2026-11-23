<?php

namespace App\Services\OpsMonitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ส่งแจ้งเตือน error ร้ายแรงเข้า Telegram (config: services.error_alert)
 *
 * - ปิดไว้เป็นค่าเริ่มต้น และไม่ส่งตอนรัน test
 * - error เดิมซ้ำ ๆ แจ้งไม่เกิน 1 ครั้งต่อ throttle_minutes
 * - มีเพดานรวมต่อ 10 นาที กันแชทท่วมตอนระบบล่มทั้งก้อน
 * - ทุก method ห้าม throw ออกไป (ถูกเรียกจาก Exception Handler)
 */
class ErrorAlertService
{
    private const MESSAGE_LIMIT = 3500; // Telegram จำกัด 4096 ตัวอักษร

    public function enabled(): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        return filter_var(config('services.error_alert.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $this->token() !== ''
            && $this->chatId() !== '';
    }

    /**
     * @param  array<string, mixed>  $context  ข้อมูลจาก Handler::context() (user/route/url/ip/command)
     */
    public function notifyException(Throwable $e, array $context = []): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $file = $this->relativePath($e->getFile());
            $fingerprint = get_class($e) . '|' . $file . '|' . $e->getLine();

            if (! $this->passesThrottle($fingerprint)) {
                return;
            }

            $lines = [
                '🚨 ' . $this->appLabel() . ' — Error',
                'Type: ' . class_basename($e),
                'Message: ' . $this->clean($e->getMessage(), 600),
                'At: ' . $file . ':' . $e->getLine(),
            ];

            $lines = array_merge($lines, $this->contextLines($context));
            $lines[] = 'Time: ' . now('Asia/Bangkok')->format('Y-m-d H:i:s');

            $this->send(implode("\n", $lines));
        } catch (Throwable $ignored) {
            // ห้ามทำให้การ report error ล้มเพราะแจ้งเตือนไม่สำเร็จ
        }
    }

    /**
     * scheduler task จบด้วย exit code ไม่ใช่ 0
     */
    public function notifyScheduleFailure(string $command, ?int $exitCode = null): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            if (! $this->passesThrottle('schedule|' . $command)) {
                return;
            }

            $this->send(implode("\n", [
                '⚠️ ' . $this->appLabel() . ' — Scheduled task failed',
                'Command: ' . $this->clean($command, 300),
                'Exit code: ' . ($exitCode ?? '-'),
                'Time: ' . now('Asia/Bangkok')->format('Y-m-d H:i:s'),
                'ดูรายละเอียดใน storage/logs/laravel-' . now('Asia/Bangkok')->format('Y-m-d') . '.log',
            ]));
        } catch (Throwable $ignored) {
            //
        }
    }

    private function passesThrottle(string $fingerprint): bool
    {
        $minutes = max(1, (int) config('services.error_alert.throttle_minutes', 10));

        // Cache::add คืน false ถ้ามี key อยู่แล้ว = เพิ่งแจ้ง error นี้ไป
        if (! Cache::add('error_alert:fp:' . md5($fingerprint), 1, now()->addMinutes($minutes))) {
            return false;
        }

        $windowKey = 'error_alert:window:' . intdiv(time(), 600);
        Cache::add($windowKey, 0, now()->addMinutes(11));
        $count = (int) Cache::increment($windowKey);

        return $count <= max(1, (int) config('services.error_alert.max_per_window', 20));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function contextLines(array $context): array
    {
        $lines = [];

        if (! empty($context['module'])) {
            $lines[] = 'Module: ' . $this->clean((string) $context['module'], 40);
        }

        if (! empty($context['command'])) {
            $lines[] = 'Command: ' . $this->clean((string) $context['command'], 200);
        }

        if (! empty($context['url'])) {
            $lines[] = 'Request: ' . ($context['method'] ?? '') . ' ' . $this->clean((string) $context['url'], 200)
                . (! empty($context['ajax']) ? ' (ajax)' : '');
        }

        if (! empty($context['route'])) {
            $lines[] = 'Route: ' . $context['route'];
        }

        if (! empty($context['userId']) || ! empty($context['userName'])) {
            $lines[] = 'User: ' . $this->clean((string) ($context['userName'] ?? '-'), 80)
                . ' (#' . ($context['userId'] ?? '-') . ')';
        }

        if (! empty($context['ip'])) {
            $lines[] = 'IP: ' . $context['ip'];
        }

        return $lines;
    }

    private function send(string $text): void
    {
        try {
            $response = Http::timeout(max(1, (int) config('services.error_alert.timeout', 3)))
                ->acceptJson()
                ->asForm()
                ->post($this->apiUrl('sendMessage'), [
                    'chat_id' => $this->chatId(),
                    'text' => mb_substr($text, 0, self::MESSAGE_LIMIT),
                    'disable_web_page_preview' => 'true',
                ]);

            if (! $response->successful()) {
                // ห้าม log URL เพราะมี token อยู่ใน path
                Log::warning('Error alert: Telegram API call failed', [
                    'status' => $response->status(),
                    'description' => $response->json('description'),
                ]);
            }
        } catch (Throwable $e) {
            // message ของ exception อาจมี URL (พร้อม token) — log แค่ชื่อ class
            Log::warning('Error alert: Telegram API call error', ['exception' => get_class($e)]);
        }
    }

    /**
     * ตัดความยาว และปิด token Telegram ที่อาจติดมากับข้อความ error
     */
    private function clean(string $text, int $limit): string
    {
        $text = preg_replace('/bot\d+:[A-Za-z0-9_-]{20,}/', 'bot***', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    private function relativePath(string $file): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';

        return str_replace($base, '', str_replace('\\', '/', $file));
    }

    private function appLabel(): string
    {
        return config('app.name', 'App') . ' [' . app()->environment() . ']';
    }

    private function apiUrl(string $method): string
    {
        $base = rtrim((string) config('services.error_alert.api_base', 'https://api.telegram.org'), '/');

        return $base . '/bot' . $this->token() . '/' . $method;
    }

    private function token(): string
    {
        return trim((string) config('services.error_alert.bot_token', ''));
    }

    private function chatId(): string
    {
        return trim((string) config('services.error_alert.chat_id', ''));
    }
}
