<?php

namespace App\Http\Controllers\FormWIP;

use App\Exports\FormWIP\WipExport;
use App\Http\Controllers\Controller;
use App\Repositories\FormWIP\WipRepository;
use App\Services\FormWIP\WipMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class WipMonitoringController extends Controller
{
    /** Dashboard: ภาพรวม + กราฟ + heatmap + Top 10 (drill-down ไปหน้ารายการ) */
    public function index(Request $request, WipMonitoringService $service)
    {
        $filters = $this->filters($request);

        return view('formwip.index', $service->getDashboardData($filters, 1, 50, route('wip.index')));
    }

    /** รายการ WO: filter เต็ม + ตาราง + export */
    public function list(Request $request, WipMonitoringService $service)
    {
        $filters = $this->filters($request);

        return view('formwip.list', $service->getDashboardData($filters, max(1, (int) $request->query('page', 1)), 50, route('wip.list')));
    }

    public function export(Request $request, WipMonitoringService $service)
    {
        $filters = $this->filters($request);
        $filters['refresh'] = false; // export ใช้ข้อมูลชุดเดียวกับที่เห็นบนจอ

        $fileName = 'wip-monitoring-' . strtolower($filters['site']) . '-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(new WipExport($service->getExportData($filters)), $fileName);
    }

    public function detail(Request $request, WipMonitoringService $service, string $site, int $workorder)
    {
        $site = strtoupper($site);
        abort_unless(array_key_exists($site, WipRepository::SITES), 404);

        return view('formwip.detail', $service->getDetailData($site, $workorder) + [
            'backUrl' => url()->previous() !== url()->current() ? url()->previous() : route('wip.index'),
        ]);
    }

    private function filters(Request $request): array
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
            'dept' => ['nullable', 'string', 'max:60'],
            'machine' => ['nullable', 'string', 'max:60'],
            'product' => ['nullable', 'string', 'max:80'],
            'customer' => ['nullable', 'string', 'max:200'],
            'grade' => ['nullable', 'string', 'max:80'],
            'size' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'due' => ['nullable', Rule::in(['overdue', '7', '30', 'none'])],
            'sort' => ['nullable', Rule::in(['aging', 'wip', 'due', 'open', 'wo'])],
            'refresh' => ['nullable', 'in:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'date_to.after_or_equal' => 'วันที่ "ถึง" ต้องไม่ก่อนวันที่ "ตั้งแต่"',
            'date_from.date_format' => 'รูปแบบวันที่ไม่ถูกต้อง',
            'date_to.date_format' => 'รูปแบบวันที่ไม่ถูกต้อง',
        ]);

        // ถ้าเลือกวันเปิด WO เก่ากว่าช่วงที่ตั้งไว้ ขยายช่วงข้อมูลให้ครอบคลุมอัตโนมัติ
        $days = (int) ($v['days'] ?? 180);
        if (!empty($v['date_from'])) {
            $need = (int) \Carbon\Carbon::parse($v['date_from'])->diffInDays(today()) + 1;
            $cover = collect([90, 180, 365, 730])->first(fn($opt) => $opt >= $need) ?? 730;
            $days = max($days, $cover);
        }

        return [
            'site' => $v['site'] ?? 'WIRE',        // Pilot: เริ่มที่ Wire
            'days' => $days,
            'dept' => trim((string) ($v['dept'] ?? '')),
            'machine' => trim((string) ($v['machine'] ?? '')),
            'product' => trim((string) ($v['product'] ?? '')),
            'customer' => trim((string) ($v['customer'] ?? '')),
            'grade' => trim((string) ($v['grade'] ?? '')),
            'size' => trim((string) ($v['size'] ?? '')),
            'date_from' => $v['date_from'] ?? '',
            'date_to' => $v['date_to'] ?? '',
            'due' => $v['due'] ?? '',
            'sort' => $v['sort'] ?? 'aging',
            'scope' => $v['scope'] ?? 'pk',         // Pilot: เฉพาะ route ที่จบด้วย PK
            'status' => $v['status'] ?? '',
            'wc' => trim((string) ($v['wc'] ?? '')),
            'aging' => $v['aging'] ?? '',
            'q' => trim((string) ($v['q'] ?? '')),
            'only_wip' => ($v['only_wip'] ?? '1') === '1',
            'refresh' => !empty($v['refresh']),
        ];
    }
}
