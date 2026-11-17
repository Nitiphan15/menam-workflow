<?php

namespace App\Exceptions;

use App\Services\OpsMonitoring\ErrorAlertService;
use App\Support\ModuleLogContext;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function render($request, Throwable $e)
    {
        if ($e instanceof TokenMismatchException) {
            $isLogin = $request->is('login') || $request->is('*/login');
            $isLogout = $request->is('logout') || $request->is('*/logout');

            if ($isLogin || $isLogout) {
                if (Auth::check()) {
                    Auth::logout();
                }

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with(
                    $isLogin ? 'error' : 'success',
                    'Session expired. Please log in again.'
                );
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Session expired. Please refresh the page and try again.',
                ], 419);
            }

            return response()->view('errors.419', [], 419);
        }

        return parent::render($request, $e);
    }

    /**
     * ข้อมูลประกอบที่แนบไปกับทุก error ใน laravel.log
     * เพื่อให้รู้ว่า error เกิดกับใคร หน้าไหน (ไม่เก็บ query string / input เพื่อกันข้อมูลลับหลุด)
     *
     * @return array<string, mixed>
     */
    protected function context()
    {
        $context = parent::context();

        try {
            if ($this->container->runningInConsole()) {
                $argv = $_SERVER['argv'] ?? [];
                $context['command'] = mb_substr(implode(' ', array_slice($argv, 1)), 0, 255);
                $module = ModuleLogContext::labelForCommand($argv[1] ?? null);
                if ($module !== null) {
                    $context['module'] = $module;
                }

                return $context;
            }

            $request = $this->container->bound('request') ? $this->container->make('request') : null;
            if (! $request) {
                return $context;
            }

            $user = $request->user();
            $routeName = $request->route()?->getName();

            return array_merge($context, array_filter([
                'module'   => ModuleLogContext::labelForRouteName($routeName),
                'userName' => $user?->name,
                'route'    => $routeName,
                'method'   => $request->getMethod(),
                'url'      => '/' . ltrim($request->path(), '/'),
                'ip'       => $request->ip(),
                'ajax'     => $request->ajax() || $request->wantsJson() ? true : null,
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (Throwable) {
            // ห้ามให้การเก็บ context ทำให้การ report error ล้มเหลว
            return $context;
        }
    }

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // แจ้งเตือน Telegram เมื่อมี error ที่ต้อง report (404/403/validation/419 ถูกกรองออกโดย Laravel แล้ว)
        // ไม่ return false เพื่อให้ยังบันทึกลง laravel.log ตามปกติ
        $this->reportable(function (Throwable $e) {
            $this->container->make(ErrorAlertService::class)->notifyException($e, $this->context());
        });

    }
}
