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

    public function recoveryAnalysis(Request $request, LossProvisionService $service)
    {
        return redirect()->route('accounting.loss-provision.recovery-dashboard', $request->query());
    }

    public function recoveryDashboard(Request $request, LossProvisionService $service)
    {
        $filters = $request->only([
            'invoice_from',
            'invoice_to',
            'recovery_from',
            'recovery_to',
            'site',
            'customer',
            'invoice',
            'term_source',
            'payment_timing',
            'payment_completion',
            'recovery_aging',
            'risk_tier',
            'payment_basis',
        ]);
        $filters['_default_invoice_months'] = 12;

        return view('formaccounting.loss_recovery_analysis', $service->getRecoveryAnalysisData($filters));
    }

    public function recoveryInquiry(Request $request, LossProvisionService $service)
    {
        $filters = $request->only([
            'invoice_from',
            'invoice_to',
            'recovery_from',
            'recovery_to',
            'site',
            'customer',
            'invoice',
            'term_source',
            'payment_timing',
            'payment_completion',
            'recovery_aging',
            'risk_tier',
            'payment_basis',
        ]);
        $filters['_default_invoice_months'] = 3;

        return view('formaccounting.loss_recovery_inquiry', $service->getRecoveryAnalysisData($filters));
    }

    public function exportRecovery(Request $request, LossProvisionService $service)
    {
        $response = $service->exportRecoveryAnalysis($request->only([
            'invoice_from',
            'invoice_to',
            'recovery_from',
            'recovery_to',
            'site',
            'customer',
            'invoice',
            'term_source',
            'payment_timing',
            'payment_completion',
            'recovery_aging',
            'risk_tier',
            'payment_basis',
        ]));
        return $this->attachDownloadCookie($response, $request);
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
