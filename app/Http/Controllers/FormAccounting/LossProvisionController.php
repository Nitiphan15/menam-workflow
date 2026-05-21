<?php

namespace App\Http\Controllers\FormAccounting;

use App\Http\Controllers\Controller;
use App\Services\FormAccounting\LossProvisionService;
use Illuminate\Http\Request;

class LossProvisionController extends Controller
{
    public function index(Request $request, LossProvisionService $service)
    {
        return view('formaccounting.loss_provision', $service->getData($request->only([
            'date_from',
            'date_to',
            'site',
            'customer',
            'invoice',
            'status',
            'aging',
            'group_customer',
        ])));
    }

    public function yearly(Request $request, LossProvisionService $service)
    {
        return view('formaccounting.loss_provision_yearly', $service->getYearlyData($request->only(['year', 'site'])));
    }

    public function exportYearly(Request $request, LossProvisionService $service)
    {
        $response = $service->exportYearly($request->only(['year', 'site']));
        return $this->attachDownloadCookie($response, $request);
    }

    public function export(Request $request, LossProvisionService $service)
    {
        $response = $service->export($request->only([
            'date_from',
            'date_to',
            'site',
            'customer',
            'invoice',
            'status',
            'aging',
        ]));
        return $this->attachDownloadCookie($response, $request);
    }

    private function attachDownloadCookie($response, Request $request)
    {
        if (method_exists($response, 'headers')) {
            // Use raw Symfony Cookie to bypass any Laravel middleware quirks.
            // httpOnly=false so JS can read; SameSite=Lax for cross-tab download
            $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie(
                'lp_dl_token',
                (string) ($request->input('dl_token') ?: '1'),
                time() + 60,
                '/',
                null,
                false,
                false,
                false,
                'lax'
            ));
        }
        return $response;
    }
}
