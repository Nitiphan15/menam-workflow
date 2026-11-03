<?php

namespace App\Http\Controllers;

use App\Services\RequestFormSsoTokenSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RequestFormSsoController extends Controller
{
    public function __invoke(Request $request, RequestFormSsoTokenSigner $signer)
    {
        abort_unless(config('services.request_form.sso_enabled'), 404);

        $user = $request->user();
        $user->loadMissing('department');
        try {
            $destination = $this->destination();
            $token = $signer->sign([
                'id' => $user->getKey(),
                'email' => $user->email,
                'name' => $user->name,
                'department' => $user->department?->name,
                'active' => (bool) $user->is_active,
            ]);
        } catch (RuntimeException $exception) {
            Log::warning('Request Form SSO handoff rejected.', [
                'user_id' => $user->getKey(),
                'reason' => $exception->getMessage(),
            ]);

            return redirect()->route('home')->with(
                'error',
                'ไม่สามารถเข้าสู่ Request Form System อัตโนมัติได้ กรุณาติดต่อผู้ดูแลระบบ'
            );
        }

        return response()
            ->view('integrations.request-form-sso-handoff', [
                'destination' => $destination,
                'token' => $token,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY');
    }

    private function destination(): string
    {
        $destination = (string) config('services.request_form.sso_url');

        // url() resolves a relative path against the current request's scheme.
        if (app()->isProduction() && parse_url(url($destination), PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Request Form SSO URL must use HTTPS in production.');
        }

        return $destination;
    }
}
