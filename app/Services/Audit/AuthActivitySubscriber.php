<?php

namespace App\Services\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * บันทึก login สำเร็จ / login ไม่สำเร็จ / logout ลง user_activity_logs
 * logout จับจาก event เพราะหลัง Auth::logout() แล้ว $request->user() จะเป็น null
 */
class AuthActivitySubscriber
{
    public function handleLogin(Login $event): void
    {
        if (! $this->isWebGuard($event->guard)) {
            return;
        }

        ActivityLogger::record(request(), $event->user, 'login', 302);
    }

    public function handleFailed(Failed $event): void
    {
        if (! $this->isWebGuard($event->guard)) {
            return;
        }

        // เก็บเฉพาะชื่อที่ใช้ login เท่านั้น ห้ามเก็บรหัสผ่าน
        $credentials = (array) $event->credentials;
        $attempted = (string) ($credentials['username'] ?? $credentials['email'] ?? '');

        ActivityLogger::record(
            request(),
            $event->user, // ถ้าเจอบัญชีแต่รหัสผิด จะได้ user_id ด้วย
            'login_failed',
            302,
            'login.attempt',
            $attempted !== '' ? $attempted : 'guest'
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $this->isWebGuard($event->guard) || ! $event->user) {
            return;
        }

        ActivityLogger::record(request(), $event->user, 'logout', 302);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class  => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
        ];
    }

    protected function isWebGuard(?string $guard): bool
    {
        return $guard === null || $guard === 'web';
    }
}
