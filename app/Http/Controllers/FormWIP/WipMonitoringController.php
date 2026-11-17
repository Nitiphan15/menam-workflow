<?php

namespace App\Http\Controllers\FormWIP;

use App\Http\Controllers\Controller;
use App\Repositories\FormWIP\WipRepository;
use App\Services\FormWIP\WipMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WipMonitoringController extends Controller
{
    public function index(Request $request, WipMonitoringService $service)
    {
        $v = $request->validate([
            'site' => ['nullable', Rule::in(array_merge(['ALL'], array_keys(WipRepository::SITES)))],
            'days' => ['nullable', 'integer', Rule::in([90, 180, 365, 730])],
            'scope' => ['nullable', Rule::in(['all', 'pk'])],
            'status' => ['nullable', Rule::in(array_keys(WipMonitoringService::STATUSES))],
            'wc' => ['nullable', 'string', 'max:30'],
            'aging' => ['nullable', Rule::in(array_keys(WipMonitoringService::AGING_BANDS))],
            'q' => ['nullable', 'string', 'max:100'],
            'only_wip' => ['nullable', 'in:0,1'],
            'refresh' => ['nullable', 'in:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = [
            'site' => $v['site'] ?? 'WIRE',        // Pilot: เริ่มที่ Wire
            'days' => (int) ($v['days'] ?? 180),
            'scope' => $v['scope'] ?? 'pk',         // Pilot: เฉพาะ route ที่จบด้วย PK
            'status' => $v['status'] ?? '',
            'wc' => trim((string) ($v['wc'] ?? '')),
            'aging' => $v['aging'] ?? '',
            'q' => trim((string) ($v['q'] ?? '')),
            'only_wip' => ($v['only_wip'] ?? '1') === '1',
            'refresh' => !empty($v['refresh']),
        ];

        return view('formwip.index', $service->getDashboardData($filters, (int) ($v['page'] ?? 1)));
    }

    public function detail(Request $request, WipMonitoringService $service, string $site, int $workorder)
    {
        $site = strtoupper($site);
        abort_unless(array_key_exists($site, WipRepository::SITES), 404);

        return view('formwip.detail', $service->getDetailData($site, $workorder) + [
            'backUrl' => url()->previous() !== url()->current() ? url()->previous() : route('wip.index'),
        ]);
    }
}
