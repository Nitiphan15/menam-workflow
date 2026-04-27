<?php

namespace App\Exceptions;

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
        }

        return parent::render($request, $e);
    }

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

    }
}
