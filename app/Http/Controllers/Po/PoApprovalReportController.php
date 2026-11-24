<?php

namespace App\Http\Controllers\Po;

use App\Exports\Po\PoApprovalReportExport;
use App\Http\Controllers\Controller;
use App\Services\Po\PoApprovalReportService;
use App\Support\SqlServerDb;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * รายงานการอนุมัติ PO + Dashboard (read-only).
 * POPUR / superadmin see every department; POV sees only their own department
 * (same rule as "ติดตาม PO แผนกของฉัน").
 */
class PoApprovalReportController extends Controller
{
    // DomPDF needs ~0.5 MB per detail row (500 rows ≈ 260 MB, ~4 s); full list is in Excel.
    private const PDF_ROW_LIMIT = 500;
    private const PDF_MEMORY_LIMIT = '512M';

    public function __construct(private readonly PoApprovalReportService $reportService) {}

    public function index(Request $request)
    {
        [$filters, $scope, $scopeLabel] = $this->resolve($request);

        return view('po.approval-report', [
            'report' => $this->reportService->build($filters, $scope),
            'filters' => $filters,
            'scopeLabel' => $scopeLabel,
            'statusLabels' => PoApprovalReportService::STATUS_LABELS,
            'slaHours' => PoApprovalReportService::SLA_HOURS_PER_STEP,
        ]);
    }

    public function exportExcel(Request $request)
    {
        [$filters, $scope, $scopeLabel] = $this->resolve($request);
        $report = $this->reportService->build($filters, $scope);

        return Excel::download(
            new PoApprovalReportExport($report, $scopeLabel),
            'po_approval_report_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        [$filters, $scope, $scopeLabel] = $this->resolve($request);
        $report = $this->reportService->build($filters, $scope);
        $totalRows = count($report['rows']);
        $this->raiseMemoryLimit(self::PDF_MEMORY_LIMIT);
        @set_time_limit(120);
        $report['rows'] = array_slice($report['rows'], 0, self::PDF_ROW_LIMIT);

        $pdf = Pdf::loadView('pdf.po-approval-report', [
            'report' => $report,
            'scopeLabel' => $scopeLabel,
            'statusLabels' => PoApprovalReportService::STATUS_LABELS,
            'slaHours' => PoApprovalReportService::SLA_HOURS_PER_STEP,
            'totalRows' => $totalRows,
            'rowLimit' => self::PDF_ROW_LIMIT,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('po_approval_report_' . now()->format('Ymd_His') . '.pdf');
    }

    /** Only ever raises the limit for this request; never lowers a bigger/unlimited one. */
    private function raiseMemoryLimit(string $wanted): void
    {
        $bytes = function (string $value): int {
            $value = trim($value);
            if ($value === '-1') {
                return PHP_INT_MAX;
            }
            $number = (int) $value;

            return match (strtoupper(substr($value, -1))) {
                'G' => $number * 1024 ** 3,
                'M' => $number * 1024 ** 2,
                'K' => $number * 1024,
                default => $number,
            };
        };

        if ($bytes((string) ini_get('memory_limit')) < $bytes($wanted)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    /** @return array{0: array, 1: ?array, 2: string} filters, department scope (null = all), scope label */
    private function resolve(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'site' => ['nullable', 'in:wire,plus'],
            'department' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:' . implode(',', array_keys(PoApprovalReportService::STATUS_LABELS))],
        ]);

        $to = !empty($data['date_to']) ? Carbon::parse($data['date_to']) : now();
        $from = !empty($data['date_from']) ? Carbon::parse($data['date_from']) : $to->copy()->subMonthsNoOverflow(3)->startOfMonth();
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        // Keep the query bounded: at most ~1 year per request.
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        $filters = [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'site' => $data['site'] ?? null,
            'department' => $data['department'] ?? null,
            'status' => $data['status'] ?? null,
        ];

        $user = $request->user();
        if (($user->is_superadmin ?? false) || $user->hasRoleCode(['POPUR'])) {
            return [$filters, null, 'ทุกแผนก'];
        }

        $departmentId = (int) ($user->department_id ?? 0);
        $department = $departmentId > 0
            ? SqlServerDb::table('departments')->where('id', $departmentId)->first(['name', 'code'])
            : null;

        return [
            $filters,
            ['department_id' => $departmentId, 'position' => $user->position ?? null],
            $department ? "เฉพาะแผนก {$department->name}" : 'ไม่พบแผนกของผู้ใช้',
        ];
    }
}
