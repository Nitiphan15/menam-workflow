<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Exports\FormOTD\InquiryByShipDateExport;
use App\Mail\AssignedTruckBoardMail;
use App\Mail\DeliveryPlanMail;
use App\Models\FormDP\DeliveryConfirmation;
use App\Services\FormDP\DeliveryConfirmationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;;

use Illuminate\Support\Facades\Storage;

use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\Process\Process;

class DeliveryPlanInquiryController extends Controller
{
    private const ERP_SALEORDER_SYNC_RESULT_PATH = 'erp-sync/delivery-plan-last.json';

    private const SPECIAL_DISPATCH_TYPES = [
        'CONTAINER_LOAD' => 'โหลดตู้คอนเทนเนอร์',
        'CUSTOMER_PICKUP' => 'ลูกค้ามารับเอง',
        'SALES_CAR' => 'รถเซลล์',
        'WEIGHT_REQUEST' => 'งานขอน้ำหนัก',
        'POSTPONED' => 'งานเลื่อน',
    ];

    private function conn()
    {
        return DB::connection('sqlsrv_menam');
    }

    private function normalizePlanStatus($status): string
    {
        return strtoupper(trim((string) ($status ?? '')));
    }

    private function isVoidedPlanStatus($status): bool
    {
        return in_array($this->normalizePlanStatus($status), ['VOID', 'VOIDED', 'CANCEL', 'CANCELED', 'CANCELLED'], true);
    }

    private function isSales8User(?int $userId = null): bool
    {
        $id = $userId ?? (auth()->check() ? (int) auth()->id() : 0);
        return in_array($id, [50, 51], true);
    }

    private function toNumberOrNull($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }

        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function calcKgFromLineQty(?int $partsId, ?float $lineQty): ?float
    {
        if (!$partsId || !$lineQty || $lineQty <= 0) {
            return null;
        }

        $part = $this->conn()
            ->table('parts')
            ->select('partnumber', 'ref_unit', 'ref_unit_qty')
            ->where('id', $partsId)
            ->first();

        if (!$part) {
            return null;
        }

        $partNo = strtoupper(trim((string) ($part->partnumber ?? '')));
        $refUnit = strtoupper(trim((string) ($part->ref_unit ?? '')));
        $kgPerLine = $this->toNumberOrNull($part->ref_unit_qty ?? null);

        if ($refUnit !== '03' || !str_ends_with($partNo, 'E') || $kgPerLine === null || $kgPerLine <= 0) {
            return null;
        }

        return round($lineQty * $kgPerLine, 3);
    }

    private function applyTrackingStockFgToRows($rows, string $property): void
    {
        $rows = collect($rows);
        if ($rows->isEmpty()) {
            return;
        }

        $stockMap = $this->trackingStockFgMapForRows($rows);

        foreach ($rows as $row) {
            $row->{$property} = $this->trackingStockFgForRow($row, $stockMap);
        }
    }

    private function trackingStockFgMapForRows($rows): array
    {
        $partsByConnection = [
            'pgsqlw' => collect(),
            'pgsqlp' => collect(),
        ];

        foreach (collect($rows) as $row) {
            $partNumber = trim((string) ($row->part_number ?? ''));
            if ($partNumber === '') {
                continue;
            }

            foreach ($this->stockConnectionsForMfg($row->mfg_no ?? '') as $connection) {
                $partsByConnection[$connection]->push($partNumber);
            }
        }

        $stockMap = [
            'pgsqlw' => [],
            'pgsqlp' => [],
        ];

        foreach ($partsByConnection as $connection => $partNumbers) {
            $partNumbers = $partNumbers->filter()->unique()->values();
            if ($partNumbers->isEmpty()) {
                continue;
            }

            try {
                DB::connection($connection)
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->whereIn('p.partnumber', $partNumbers->all())
                    ->groupBy('p.partnumber')
                    ->selectRaw('p.partnumber, SUM(CASE WHEN su.onhand THEN sus.qty ELSE 0 END) AS balance_qty')
                    ->get()
                    ->each(function ($stockRow) use (&$stockMap, $connection) {
                        $partNumber = trim((string) ($stockRow->partnumber ?? ''));
                        if ($partNumber !== '') {
                            $stockMap[$connection][$partNumber] = (float) ($stockRow->balance_qty ?? 0);
                        }
                    });
            } catch (\Throwable $e) {
                Log::warning('Delivery Plan Inquiry StockFG lookup failed', [
                    'connection' => $connection,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $stockMap;
    }

    private function trackingStockFgForRow($row, array $stockMap): float
    {
        $partNumber = trim((string) ($row->part_number ?? ''));
        if ($partNumber === '') {
            return 0.0;
        }

        $stock = 0.0;
        foreach ($this->stockConnectionsForMfg($row->mfg_no ?? '') as $connection) {
            $stock += (float) ($stockMap[$connection][$partNumber] ?? 0);
        }

        return $stock;
    }

    private function stockConnectionsForMfg($mfgNo): array
    {
        foreach (explode(',', (string) $mfgNo) as $rawToken) {
            $token = strtoupper(trim((string) $rawToken, " \t\n\r\0\x0B'\""));
            if ($token !== '' && str_starts_with($token, '+')) {
                return ['pgsqlp'];
            }
        }

        return ['pgsqlw'];
    }

    public function export(Request $request)
    {
        $filters = $request->all();
        unset(
            $filters['revision_number'],
            $filters['revision_max_number'],
            $filters['display_revision_number']
        );

        return Excel::download(
            new InquiryByShipDateExport($filters),
            'Inquiry_By_ShipDate.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        @set_time_limit(180);

        $pdfData = $this->buildInquiryPdfData($request);

        if (empty($pdfData['pdfRows'])) {
            return back()->withErrors(['ไม่พบข้อมูลสำหรับสร้าง PDF ตามเงื่อนไขที่เลือก']);
        }

        $pdf = Pdf::loadView('pdf.delivery-plan-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        return $pdf->download('Inquiry_By_ShipDate_' . now()->format('Ymd_His') . '.pdf');
    }

    private function buildInquiryPdfData(Request $request): array
    {
        [$shipFrom, $shipTo] = $this->resolveShipDateRange($request);

        if ($shipFrom !== $shipTo) {
            abort(422, 'Export PDF รองรับเฉพาะวันเดียว กรุณาเลือกวันที่ส่งสินค้า (เริ่มต้น) และ (ถึง) ให้เป็นวันเดียวกัน');
        }

        $shipDate = $shipFrom;

        $mode     = strtoupper(trim((string) $request->query('mode', '')));
        $status   = strtoupper(trim((string) $request->query('status', 'NEW')));
        $so       = trim((string) $request->query('so', ''));
        $customer = trim((string) $request->query('customer', ''));
        $shipto   = trim((string) $request->query('shipto', ''));
        $divsales = trim((string) $request->query('divsales', ''));

        $orderBy  = trim((string) $request->query('order_by', ''));
        $orderDir = strtolower(trim((string) $request->query('order_dir', '')));
        $dir      = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'asc';

        $revision         = trim((string) $request->query('revision_number', ''));
        $revisionMax      = trim((string) $request->query('revision_max_number', ''));
        $displayRevision  = trim((string) $request->query('display_revision_number', ''));
        $excludeVoidCancel = (string) $request->query('exclude_void_cancel', '') === '1';

        $q = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->selectRaw("
                d.ord_id,
                d.sales_id,
                d.so_number,
                d.part_number,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(d.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                d.part_desc,
                d.mfg_no,
                d.qty,
                d.remark,
                d.address,
                d.delivery_type,
                d.due_date_remark,
                d.sell_by_line,
                d.line_qty,
                CONVERT(varchar(10), d.ship_posted_at, 23) as ship_posted_at,
                d.revision_number,
                p.onhand as stock_qty,
                p.f3 as part_type,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) AS sales_name,
                d.edit_remark,
                d.attach_docs,
                d.attach_docs_other,
                d.status
            ")
            ->whereBetween('d.ship_posted_at', [
                Carbon::parse($shipFrom)->startOfDay()->format('Y-m-d H:i:s'),
                Carbon::parse($shipTo)->endOfDay()->format('Y-m-d H:i:s'),
            ]);

        if ($mode === 'SO') {
            $q->where('d.delivery_type', 'SO');
        } elseif ($mode === 'ACID') {
            $q->where('d.delivery_type', 'ACID');
        } elseif ($mode === 'SPECIAL') {
            $q->where('d.delivery_type', 'SPECIAL');
        }

        if ($so !== '') {
            $q->where('d.so_number', 'like', "%{$so}%");
        }

        if ($customer !== '') {
            $q->where(function ($w) use ($customer) {
                $w->where('c.name', 'like', "%{$customer}%")
                    ->orWhere('c.customernumber', 'like', "%{$customer}%")
                    ->orWhereRaw('d.customer_name COLLATE DATABASE_DEFAULT LIKE ?', ["%{$customer}%"]);
            });
        }

        if ($shipto !== '') {
            $q->where('d.address', 'like', "%{$shipto}%");
        }

        if ($divsales !== '') {
            $this->applyDivisionSalesFilter($q, $divsales);
        }

        if ($status !== 'ALL' && $status !== '') {
            $q->where('d.status', $status);
        } else {
            $q->where(function ($w) {
                $w->whereNull('d.status')
                    ->orWhereRaw("UPPER(ISNULL(d.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')");
            });
        }

        if ($excludeVoidCancel) {
            $q->whereRaw("ISNULL(d.status,'') NOT IN ('VOID','CANCEL')");
        }

        if ($revisionMax !== '' && is_numeric($revisionMax)) {
            $q->where('d.revision_number', '<=', (int) $revisionMax);
        } elseif ($revision !== '' && is_numeric($revision)) {
            $q->where('d.revision_number', (int) $revision);
        }

        $allowedOrder = [
            'so'             => 'd.so_number',
            'customer'       => 'c.name',
            'shipto'         => 'd.address',
            'qty'            => 'd.qty',
            'window_at'      => 'd.window_at',
            'ship_posted_at' => 'd.ship_posted_at',
            'revision'       => 'd.revision_number',
        ];

        $q->orderBy('d.ship_posted_at');

        if (isset($allowedOrder[$orderBy])) {
            $q->orderBy('d.sales_id')
                ->orderBy($allowedOrder[$orderBy], $dir)
                ->orderBy('d.delivery_type')
                ->orderBy('d.part_number');
        } else {
            $q->orderBy('d.sales_id')
                ->orderBy('c.name')
                ->orderBy('d.delivery_type')
                ->orderBy('d.part_number');
        }

        $rows = $q->get();

        if ($rows->isEmpty()) {
            return ['pdfRows' => []];
        }

        $this->applyTrackingStockFgToRows($rows, 'stock_qty');

        if ($displayRevision !== '' && is_numeric($displayRevision)) {
            foreach ($rows as $r) {
                $r->revision_number = (int) $displayRevision;
            }
        }

        $docMaster = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)]);

        $mfgTokens = $rows->pluck('mfg_no')
            ->filter()
            ->flatMap(fn($mfg) => collect(preg_split('/\s*,\s*/', (string) $mfg))
                ->map(fn($x) => trim((string) $x))
                ->filter())
            ->unique()
            ->values();

        $mfgByConn = ['pgsqlmfgw' => collect(), 'pgsqlmfgp' => collect()];
        foreach ($mfgTokens as $tok) {
            $conn = str_starts_with((string) $tok, '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
            $mfgByConn[$conn]->push((string) $tok);
        }

        $packageMaps = ['pgsqlmfgw' => collect(), 'pgsqlmfgp' => collect()];
        foreach ($mfgByConn as $connName => $list) {
            $list = $list->unique()->values();
            if ($list->isNotEmpty()) {
                $packageMaps[$connName] = DB::connection($connName)
                    ->table('workorder')
                    ->select('workordernumber', 'fcat')
                    ->whereIn('workordernumber', $list->all())
                    ->get()
                    ->pluck('fcat', 'workordernumber');
            }
        }

        $resolvePackage = function (?string $mfgNo) use ($packageMaps): string {
            if (blank($mfgNo)) {
                return '-';
            }
            $packs = collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->map(function ($token) use ($packageMaps) {
                    $conn = str_starts_with((string) $token, '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
                    return trim((string) ($packageMaps[$conn]->get((string) $token) ?? ''));
                })
                ->filter()
                ->unique()
                ->values();
            return $packs->isNotEmpty() ? $packs->implode(', ') : '-';
        };

        $normalizeMfgDisplay = function (?string $mfgNo): string {
            if (blank($mfgNo)) {
                return '-';
            }
            return collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->unique()
                ->implode(', ');
        };

        $resolveAttachDocs = function ($attachDocs, $attachDocsOther) use ($docMaster): string {
            $other = trim((string) ($attachDocsOther ?? ''));
            $codes = collect(preg_split('/\s*,\s*/', (string) $attachDocs))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->when($other !== '', fn($items) => $items->reject(fn($code) => $code === 'OTHER'))
                ->unique();

            $names = $codes->map(fn($code) => $docMaster[$code] ?? $code);

            if ($other !== '') {
                $names->push('อื่น ๆ: ' . $other);
            }
            $names = $names->unique()->values();
            return $names->isNotEmpty() ? $names->implode(', ') : '-';
        };

        $divLabel = $this->divisionLabels();
        $pdfRows  = [];

        $groups    = $rows->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))->all();
        $divGroups = $this->buildDivisionGroups($groups);

        foreach ($divGroups as $divCode => $items) {
            $pdfRows[] = [
                'row_type'   => 'group',
                'group_code' => $divCode,
                'group_name' => $divLabel[$divCode] ?? $divCode,
            ];

            $itemNo = 0;
            foreach ($items as $r) {
                $itemNo++;
                $isPieceQty = ((int) ($r->sell_by_line ?? 0) === 1)
                    && is_numeric($r->line_qty ?? null)
                    && (float) $r->line_qty > 0
                    && (float) ($r->qty ?? 0) == 0.0;
                $revNo = (int) ($r->revision_number ?? 0);

                $pdfRows[] = [
                    'row_type'        => 'item',
                    'item_no'         => $itemNo,
                    'revision_number' => $revNo,
                    'customer'        => $r->customer_name ?? '-',
                    'package'         => $resolvePackage($r->mfg_no),
                    'type'            => filled($r->part_type ?? null) ? $r->part_type : '-',
                    'size_length'     => $r->part_desc ?? '-',
                    'mfg_no'          => $normalizeMfgDisplay($r->mfg_no),
                    'pieces'          => ((int) ($r->sell_by_line ?? 0) === 1 && is_numeric($r->line_qty ?? null) && (float) $r->line_qty > 0)
                        ? ($isPieceQty ? 'ชิ้น : ' : 'ระบุเส้น : ') . number_format((float) $r->line_qty, 0)
                        : '-',
                    'sales_qty'       => (float) ($r->qty ?? 0),
                    'stock_qty'       => (float) ($r->stock_qty ?? 0),
                    'production_qty'  => (float) ($r->qty ?? 0),
                    'logistics_qty'   => 0,
                    'logistics_note'  => $r->due_date_remark ?? '',
                    'priority'        => '',
                    'delivery_place'  => $r->address ?? '-',
                    'oe_no'           => $r->so_number ?? '-',
                    'problem_note'    => $revNo > 0 ? ($r->edit_remark ?? '-') : ($r->remark ?? '-'),
                    'action_plan'     => '',
                    'attach_docs'     => $resolveAttachDocs($r->attach_docs ?? null, $r->attach_docs_other ?? null),
                ];
            }
        }

        $shipDateText     = Carbon::parse($shipDate)->format('d/m/Y');
        $shipDateThaiText = Carbon::parse($shipDate)->locale('th')->translatedFormat('j F Y');

        $badgeRev = $displayRevision !== '' && is_numeric($displayRevision)
            ? (int) $displayRevision
            : ($revision !== '' && is_numeric($revision) ? (int) $revision : 0);
        $mailSlot = $this->deliveryPlanMailSlot($badgeRev);

        return [
            'shipDateText'      => $shipDateText,
            'shipDateThaiText'  => $shipDateThaiText,
            'shipDateFile'      => now()->format('Ymd_His'),
            'monthText'         => Carbon::parse($shipDate)->locale('th')->translatedFormat('F Y'),
            'targetText'        => '900 ตัน/เดือน',
            'preparedBy'        => '....................',
            'productionConfirm' => '....................',
            'docReceiver'       => '....................',
            'footerCode'        => 'MK-03',
            'sumSalesQty'       => collect($pdfRows)->where('row_type', 'item')->sum('sales_qty'),
            'sumStockQty'       => collect($pdfRows)->where('row_type', 'item')->sum('stock_qty'),
            'sumProductionQty'  => collect($pdfRows)->where('row_type', 'item')->sum('production_qty'),
            'sumLogisticsQty'   => collect($pdfRows)->where('row_type', 'item')->sum('logistics_qty'),
            'pdfRows'           => $pdfRows,
            'totalBodyRows'     => count($pdfRows) + 1,
            'revisionBadgeText' => $mailSlot['badge'],
            'revisionBadgeTime' => $mailSlot['time'],
        ];
    }

    public function syncSaleOrderFromErp()
    {
        $scripts = $this->erpDeliveryPlanSyncScripts();

        if ($scripts->isEmpty()) {
            return back()->with('error', 'ยังไม่ได้ตั้งค่า script สำหรับดึงข้อมูล ERP');
        }

        foreach ($scripts as $script) {
            if (! is_file($script['path'])) {
                return back()->with(
                    'error',
                    'ไม่พบไฟล์ ' . $script['label'] . ' script บนเครื่อง web server: ' . $script['path']
                        . ' (ถ้าทดสอบบน local จะต้องมี path นี้บนเครื่อง local ด้วย หรือปรับค่า script ใน .env)'
                );
            }

            if (! in_array(strtolower(pathinfo($script['path'], PATHINFO_EXTENSION)), ['bat', 'cmd'], true)) {
                return back()->with('error', 'อนุญาตเฉพาะไฟล์ .bat หรือ .cmd เท่านั้น: ' . $script['path']);
            }
        }

        $timeout = $this->erpSaleOrderSyncTimeout();
        $lockSeconds = ($timeout * max(1, $scripts->count())) + 60;
        $lock = Cache::lock('erp-delivery-plan-sync-run', $lockSeconds);

        if (! $lock->get()) {
            return back()->with('error', 'มีการดึงข้อมูล ERP กำลังทำงานอยู่ กรุณารอสักครู่');
        }

        $startedAt = now('Asia/Bangkok');
        $output = '';
        $exitCode = null;
        $results = [];

        try {
            @set_time_limit($lockSeconds + 30);

            foreach ($scripts as $script) {
                $scriptOutput = '';
                $output .= "=== {$script['label']} ===\n";

                $process = new Process(
                    ['cmd.exe', '/d', '/c', str_replace('"', '', $script['path'])],
                    dirname($script['path']),
                    [
                        'PYTHONIOENCODING' => 'utf-8',
                        'PYTHONUTF8' => '1',
                    ]
                );
                $process->setTimeout($timeout);
                $process->run(function (string $type, string $buffer) use (&$scriptOutput, &$output): void {
                    $scriptOutput .= $buffer;
                    $output .= $buffer;
                });

                $exitCode = $process->getExitCode();
                $results[] = [
                    'label' => $script['label'],
                    'script_path' => $script['path'],
                    'exit_code' => $exitCode,
                    'output' => trim($scriptOutput),
                ];

                $output .= "\n";

                if (! $process->isSuccessful()) {
                    break;
                }
            }

            $finishedAt = now('Asia/Bangkok');

            $this->storeErpSaleOrderSyncLastRun([
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'duration_seconds' => $finishedAt->diffInSeconds($startedAt),
                'exit_code' => $exitCode,
                'scripts' => $results,
                'user_id' => optional(auth()->user())->id,
                'user_name' => optional(auth()->user())->name,
                'output' => trim($output),
            ]);

            $failed = collect($results)->first(fn($row) => ($row['exit_code'] ?? null) !== 0);

            if (!$failed) {
                return back()->with('success', 'ดึงข้อมูล ERP เรียบร้อยแล้ว: ' . $scripts->pluck('label')->implode(', '));
            }

            return back()
                ->with('error', 'ดึงข้อมูล ERP ไม่สำเร็จที่ ' . ($failed['label'] ?? '-') . ' exit code: ' . ($failed['exit_code'] ?? '-'))
                ->with('erp_sync_output', trim($output));
        } catch (\Throwable $e) {
            $finishedAt = now('Asia/Bangkok');

            $this->storeErpSaleOrderSyncLastRun([
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'duration_seconds' => $finishedAt->diffInSeconds($startedAt),
                'exit_code' => $exitCode,
                'scripts' => $results,
                'user_id' => optional(auth()->user())->id,
                'user_name' => optional(auth()->user())->name,
                'output' => trim($output),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'ดึงข้อมูล ERP ไม่สำเร็จ: ' . $e->getMessage());
        } finally {
            optional($lock)->release();
        }
    }

    public function inquiry(Request $request)
    {
        [$shipFrom, $shipTo] = $this->resolveShipDateRange($request);

        $orderBy  = trim((string) $request->query('order_by', ''));
        $orderDir = strtolower(trim((string) $request->query('order_dir', '')));

        $status   = strtoupper(trim((string) $request->query('status', 'NEW')));
        $so       = trim((string) $request->query('so', ''));
        $customer = trim((string) $request->query('customer', ''));
        $shipto   = trim((string) $request->query('shipto', ''));
        $divsales = trim((string) $request->query('divsales', ''));
        $revision = trim((string) $request->query('revision', ''));
        $mode     = strtoupper(trim((string) $request->query('mode', '')));
        $mfg      = trim((string) $request->query('mfg', ''));
        $ordIdFilter = trim((string) $request->query('ord_id', ''));
        $searched = $request->has('searched');

        $hasKeyword = ($so !== '' || $customer !== '' || $shipto !== '' || $divsales !== '' || $mfg !== '' || $ordIdFilter !== '');

        if ($searched) {
            try {
                $from = Carbon::parse($shipFrom)->startOfDay();
                $to   = Carbon::parse($shipTo)->endOfDay();

                if (!$hasKeyword && $from->diffInDays($to) > 31) {
                    return back()
                        ->withInput()
                        ->withErrors(['ช่วงวันแทงส่งกว้างเกินไป (เกิน 31 วัน) กรุณาจำกัดช่วงวัน หรือใส่เงื่อนไขค้นหาเพิ่ม']);
                }
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['รูปแบบวันที่ไม่ถูกต้อง']);
            }
        }

        $assignSumSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('ord_id, SUM(ISNULL(assigned_weight, 0)) as assigned_weight_sum')
            ->groupBy('ord_id');

        $latestAssignSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('MAX(id) as latest_assign_id, ord_id')
            ->groupBy('ord_id');

        $truckListSub = $this->conn()
            ->table('delivery_plan_truck_assign as tx')
            ->leftJoin('delivery_plan_truck_master as tmx', 'tmx.id', '=', 'tx.truck_id')
            ->selectRaw("
                    tx.ord_id,
                    STRING_AGG(
                        LTRIM(RTRIM(
                            CASE
                                WHEN tx.truck_source = 'MANUAL' THEN ISNULL(tx.manual_plate_no, '')
                                ELSE ISNULL(tmx.plate_no, '')
                            END
                        )),
                        ', '
                    ) AS truck_plate_list
            ")
            ->groupBy('tx.ord_id');

        $latestSpecialSub = $this->conn()
            ->table('delivery_plan_special_dispatch')
            ->selectRaw('MAX(id) as latest_special_id, ord_id')
            ->whereIn('status', ['OPEN', 'CLOSED'])
            ->groupBy('ord_id');


        $q = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('users as u_create', 'u_create.id', '=', 'd.created_by')
            ->leftJoin('users as u_revise', 'u_revise.id', '=', 'd.revise_by')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->leftJoin('revision_master as rv', 'rv.revision_number', '=', 'd.revision_number')
            ->leftJoinSub($assignSumSub, 'tas', function ($join) {
                $join->on('tas.ord_id', '=', 'd.ord_id');
            })
            ->leftJoinSub($latestAssignSub, 'tal', function ($join) {
                $join->on('tal.ord_id', '=', 'd.ord_id');
            })
            ->leftJoinSub($truckListSub, 'tpl', function ($join) {
                $join->on('tpl.ord_id', '=', 'd.ord_id');
            })
            ->leftJoinSub($latestSpecialSub, 'lsd', function ($join) {
                $join->on('lsd.ord_id', '=', 'd.ord_id');
            })
            ->leftJoin('delivery_plan_truck_assign as ta', 'ta.id', '=', 'tal.latest_assign_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->leftJoin('delivery_plan_special_dispatch as sd', 'sd.id', '=', 'lsd.latest_special_id')
            ->select([
                'd.ord_id',
                'd.sales_id',

                DB::raw("
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) AS sales_name
            "),

                'd.customer_id',
                'c.customernumber',
                DB::raw("COALESCE(NULLIF(LTRIM(RTRIM(d.customer_name COLLATE DATABASE_DEFAULT)),''), NULLIF(LTRIM(RTRIM(c.name COLLATE DATABASE_DEFAULT)),''), '') AS customer_name"),
                'd.line_qty',
                'd.delivery_type',
                'd.part_number',
                'p.id as parts_id',
                'd.part_desc',
                'p.f1 as part_f1',
                'p.f2 as part_f2',
                'p.f3 as part_f3',
                'p.description as part_master_desc',
                'd.mfg_no',
                'd.sell_by_line',
                'd.qty',
                'd.address',
                'd.so_number',
                'd.status',
                'd.remark',
                'd.edit_remark',
                'd.remark_void',
                'd.ship_posted_at',
                'd.due_date',
                'd.due_date_remark',
                'd.tel',
                'd.revision_number',
                'd.created_at',
                'd.created_by',
                'd.attach_docs',
                'd.attach_docs_other',
                'd.window_at',
                'd.window_text',
                'tpl.truck_plate_list',
                'u_create.name as created_by_name',
                'u_revise.name as revise_by_name',

                DB::raw("
                CASE COALESCE(rv.color_code,'black')
                    WHEN 'sky' THEN 'deepskyblue'
                    ELSE COALESCE(rv.color_code,'black')
                END AS rev_color
            "),

                DB::raw('ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum'),
                DB::raw('ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum'),
                'tpl.truck_plate_list',
                'ta.id as truck_assign_id',
                'ta.truck_source',
                'ta.truck_id',
                'ta.manual_plate_no',
                'ta.manual_driver_name',
                'ta.manual_driver_phone',
                'ta.manual_max_load',
                'ta.manual_car_length',
                'ta.manual_remark',
                'ta.assigned_weight',
                'ta.ship_posted_at as truck_ship_posted_at',
                'ta.trip_no as truck_trip_no',
                'ta.closed_at as truck_closed_at',
                'ta.assigned_at',
                'ta.assigned_by',
                'ta.remark as truck_assign_remark',

                'tm.plate_no as truck_plate_no',
                'tm.driver_name as truck_driver_name',
                'tm.driver_phone as truck_driver_phone',
                'tm.max_load as truck_max_load',
                'tm.car_length as truck_car_length',
                'tm.remark as truck_master_remark',
                'sd.id as special_dispatch_id',
                'sd.dispatch_type as special_dispatch_type',
                'sd.status as special_dispatch_status',
                'sd.remark as special_dispatch_remark',
                'sd.action_at as special_dispatch_action_at',
                'sd.closed_at as special_dispatch_closed_at',
            ]);

        try {
            $from = Carbon::parse($shipFrom)->startOfDay();
            $to   = Carbon::parse($shipTo)->endOfDay();

            $q->whereBetween('d.ship_posted_at', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $today = now()->startOfDay();
            $q->whereBetween('d.ship_posted_at', [
                $today->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ]);
        }

        if ($mode === 'SO') {
            $q->where('d.delivery_type', 'SO');
        } elseif ($mode === 'ACID') {
            $q->where('d.delivery_type', 'ACID');
        } elseif ($mode === 'SPECIAL') {
            $q->where('d.delivery_type', 'SPECIAL');
        }

        if ($so !== '') {
            $q->where('d.so_number', 'like', "%{$so}%");
        }

        if ($mfg !== '') {
            $q->where('d.mfg_no', 'like', "%{$mfg}%");
        }

        if ($ordIdFilter !== '' && is_numeric($ordIdFilter)) {
            $q->where('d.ord_id', (int) $ordIdFilter);
        }

        if ($customer !== '') {
            $q->where(function ($w) use ($customer) {
                $w->where('c.name', 'like', "%{$customer}%")
                    ->orWhere('c.customernumber', 'like', "%{$customer}%")
                    ->orWhereRaw('d.customer_name COLLATE DATABASE_DEFAULT LIKE ?', ["%{$customer}%"]);
            });
        }

        if ($shipto !== '') {
            $q->where('d.address', 'like', "%{$shipto}%");
        }

        if ($divsales !== '') {
            $this->applyDivisionSalesFilter($q, $divsales);
        }

        if ($revision !== '' && is_numeric($revision)) {
            $q->where('d.revision_number', (int) $revision);
        }

        if ($status !== 'ALL' && $status !== '') {
            $q->where('d.status', $status);
        } else {
            $q->where(function ($w) {
                $w->whereNull('d.status')
                    ->orWhere('d.status', '!=', 'VOID');
            });
        }

        $dir = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'desc';

        $allowedOrder = [
            'so'             => 'd.so_number',
            'customer'       => 'c.name',
            'shipto'         => 'd.address',
            'qty'            => 'd.qty',
            'window_at'      => 'd.window_at',
            'ship_posted_at' => 'd.ship_posted_at',
            'revision'       => 'd.revision_number',
            'assigned_at'    => 'ta.assigned_at',
        ];

        if (isset($allowedOrder[$orderBy])) {
            $q->orderBy($allowedOrder[$orderBy], $dir);
        } else {
            $q->orderBy('d.sales_id')
                ->orderBy('c.name')
                ->orderBy('d.delivery_type')
                ->orderBy('d.part_number');
        }

        $rows = $q->paginate(100)->withQueryString();

        $soLines = collect($rows->items())
            ->groupBy(function ($r) {
                $so = (string) ($r->so_number ?? '');
                $shipDate = $this->dateOnly($r->ship_posted_at);
                return $so . '|' . $shipDate;
            })
            ->map(function ($items) {
                return $items
                    ->reject(fn($r) => $this->isVoidedPlanStatus($r->status ?? null))
                    ->map(function ($r) {
                        $qtyTotal = (float) ($r->qty ?? 0);
                        $assigned = (float) ($r->assigned_weight_sum ?? 0);
                        $remaining = max(0, $qtyTotal - $assigned);

                        $sellByLine = (int) ($r->sell_by_line ?? 0) === 1;
                        $lineQty = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : null;
                        $isPieceQty = $sellByLine && $lineQty !== null && $lineQty > 0 && (float) ($r->qty ?? 0) == 0.0;

                        return [
                            'ord_id'         => (int) ($r->ord_id ?? 0),
                            'mfg_no'         => (string) ($r->mfg_no ?? ''),
                            'part'           => (string) ($r->part_number ?? ''),
                            'desc'           => (string) ($r->part_desc ?? ''),

                            // แยกให้ชัด
                            'sell_by_line'   => $sellByLine ? 1 : 0,
                            'line_qty'       => $lineQty,
                            'line_text'      => $sellByLine
                                ? ($lineQty !== null ? number_format($lineQty, 0) . ' ' . ($isPieceQty ? 'ชิ้น' : 'เส้น') : ($isPieceQty ? 'ระบุชิ้น' : 'ระบุเส้น'))
                                : '-',

                            'shipto'         => trim((string) ($r->address ?? '')) ?: '-',

                            'qty_total'      => $qtyTotal,
                            'qty_assigned'   => $assigned,
                            'qty_remaining'  => $remaining,
                            'ship_date'      => $this->dateOnly($r->ship_posted_at),
                        ];
                    })->values();
            });

        $this->applyTrackingStockFgToRows($rows->items(), 'stock_qty_rt');

        foreach ($rows as $r) {
            $totalQty = (float) ($r->qty ?? 0);
            $assignedSum = (float) ($r->assigned_weight_sum ?? 0);
            $remainingQty = max(0, $totalQty - $assignedSum);

            $r->assigned_weight_sum = $assignedSum;
            $r->remaining_assign_qty = $remainingQty;

            // ล็อกทันทีเมื่อจัดรถปกติ หรือมีช่องทางพิเศษที่ยัง OPEN
            // POSTPONED เป็นการเลื่อนงาน ไม่ถือว่าเป็นการจัดรถ
            $specialDispatchIsOpen =
                strtoupper(trim((string) ($r->special_dispatch_status ?? ''))) === 'OPEN'
                && strtoupper(trim((string) ($r->special_dispatch_type ?? ''))) !== 'POSTPONED';
            $r->edit_locked = $assignedSum > 0 || $specialDispatchIsOpen;
            $specialType = strtoupper(trim((string) ($r->special_dispatch_type ?? '')));
            $r->special_dispatch_label = self::SPECIAL_DISPATCH_TYPES[$specialType] ?? '';

            $truckPlate = '';
            if (($r->truck_source ?? '') === 'MANUAL') {
                $truckPlate = trim((string) ($r->manual_plate_no ?? ''));
            } else {
                $truckPlate = trim((string) ($r->truck_plate_no ?? ''));
            }
            $r->truck_plate_display = $truckPlate;

            $shipDateOnly = $this->safeCarbonDate($r->ship_posted_at);

            $r->can_pick_truck = $shipDateOnly
                ? now()->startOfDay()->lte($shipDateOnly->copy()->subDay())
                : false;

            $r->truck_current_load = null;
            $r->truck_remaining_capacity = null;
        }

        // Split mfg_no into individual tokens (e.g. "W873, W875, W876" → [W873, W875, W876])
        $tokenize = function ($value) {
            return collect(explode(',', (string) $value))
                ->map(fn($x) => strtoupper(ltrim(trim($x), " \t\n\r\0\x0B'\"+")))
                ->filter()
                ->unique()
                ->values();
        };

        try {
            $confirmService = app(DeliveryConfirmationService::class);
            $allTokens = collect($rows->items())
                ->flatMap(fn($r) => $tokenize($r->mfg_no ?? ''))
                ->unique()
                ->values()
                ->all();
            $confirmMap = $confirmService->latestMap($allTokens);
        } catch (\Throwable $e) {
            $confirmMap = collect();
        }

        foreach ($rows as $r) {
            $tokens = $tokenize($r->mfg_no ?? '');
            $entry = null;
            if ($tokens->isNotEmpty() && $confirmMap->isNotEmpty()) {
                foreach ($tokens as $tok) {
                    foreach ($confirmMap as $key => $value) {
                        if (str_ends_with((string) $key, '|' . $tok)) {
                            $valStatus = strtoupper((string) ($value->confirmation_status ?? ''));
                            $bestStatus = strtoupper((string) ($entry->confirmation_status ?? ''));
                            // prefer Postpone > Confirm; same status → latest by confirmed_at
                            if (
                                $entry === null
                                || ($valStatus === 'POSTPONE' && $bestStatus !== 'POSTPONE')
                                || ($valStatus === $bestStatus
                                    && ($value->confirmed_at ?? '') > ($entry->confirmed_at ?? ''))
                            ) {
                                $entry = $value;
                            }
                        }
                    }
                }
            }
            $r->planner_confirmation_status = $entry->confirmation_status ?? null;
            $r->planner_confirmation_label = DeliveryConfirmation::statusLabel($entry->confirmation_status ?? null);
            $r->planner_confirmation_badge = DeliveryConfirmation::statusBadgeClass($entry->confirmation_status ?? null);
            $r->planner_confirmation_new_date = $entry->new_delivery_date ?? null;
            $r->planner_confirmation_confirmed_at = $entry->confirmed_at ?? null;
            $r->planner_confirmation_by = $entry->confirmed_by_name ?? ($entry->confirmed_by_login ?? null);
            $r->planner_confirmation_remark = $entry->remark ?? null;
        }

        $groups = collect($rows->items())
            ->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))
            ->all();

        $docMap = $this->docMap();
        $this->attachInquiryDisplayText($rows, $docMap);
        $defaultShipDate = collect($rows->items())
            ->map(fn($r) => $this->dateOnly($r->ship_posted_at))
            ->filter()
            ->first();

        $trucks = $this->getAssignableTrucksByShipDate($defaultShipDate);

        $divLabel = $this->divisionLabels();
        $divGroups = $this->buildDivisionGroups($groups);
        $revColorMap = $this->revisionColorMap();
        $specialDispatchTypes = self::SPECIAL_DISPATCH_TYPES;

        // Autocomplete sources — distinct ทั้ง DB (cache 10 นาที กัน DB กระแทก)
        $autocompleteSources = \Illuminate\Support\Facades\Cache::remember(
            'dp_inquiry_autocomplete_sources',
            now()->addMinutes(10),
            function () {
                $conn = $this->conn();
                $so = $conn->table('delivery_plan_data')
                    ->whereNotNull('so_number')->where('so_number', '!=', '')
                    ->distinct()->pluck('so_number')->take(2000)->values()->all();
                $customer = $conn->table('customer')
                    ->whereNotNull('name')->where('name', '!=', '')
                    ->distinct()->pluck('name')->take(2000)->values()->all();
                $shipto = $conn->table('delivery_plan_data')
                    ->whereNotNull('address')->where('address', '!=', '')
                    ->distinct()->pluck('address')->take(2000)->values()->all();
                return [
                    'so'       => $so,
                    'customer' => $customer,
                    'shipto'   => $shipto,
                ];
            }
        );

        // ดึงประวัติส่งเมลแผนสำหรับช่วงวันที่ที่กำลังดู (ใช้ $shipFrom..$shipTo ที่ resolve ไว้แล้ว
        // ซึ่งจะ fallback เป็นวันนี้เมื่อผู้ใช้ไม่ได้ filter) เพื่อบอกว่า rev ไหนถูกส่งไปแล้วบ้าง
        $mailSentLogs = [];
        try {
            $logFrom = Carbon::parse($shipFrom)->toDateString();
            $logTo   = Carbon::parse($shipTo)->toDateString();
            $mailSentLogs = $this->conn()
                ->table('delivery_plan_mail_logs')
                ->whereRaw('CAST(ship_posted_at AS date) BETWEEN ? AND ?', [$logFrom, $logTo])
                ->orderByRaw('CAST(ship_posted_at AS date) ASC')
                ->orderBy('revision_number', 'asc')
                ->orderBy('sent_at', 'asc')
                ->get([
                    'ship_posted_at',
                    'revision_number',
                    'mail_type',
                    'to_emails',
                    'cc_emails',
                    'sent_at',
                    'sent_by_name',
                    'total_items',
                ])
                ->all();
        } catch (\Throwable $e) {
            $mailSentLogs = [];
        }

        return view('formdp.inquiry', compact(
            'rows',
            'groups',
            'soLines',
            'docMap',
            'trucks',
            'divLabel',
            'divGroups',
            'revColorMap',
            'specialDispatchTypes',
            'autocompleteSources',
            'mailSentLogs'
        ));
    }

    public function assignTruck(Request $request, $ordId = null)
    {
        $request->validate([
            'truck_pick_mode' => ['required', 'in:MASTER,MANUAL,MANUAL_TEMP'],
            'so_number'       => ['nullable', 'string', 'max:100'],
            'ship_posted_at'  => ['nullable', 'date'],

            'truck_id'        => ['nullable', 'integer'],
            'trip_no'         => ['nullable', 'integer', 'min:1', 'max:99'],

            'manual_plate_no'     => ['nullable', 'string', 'max:50'],
            'manual_driver_name'  => ['nullable', 'string', 'max:100'],
            'manual_driver_phone' => ['nullable', 'string', 'max:50'],
            'manual_max_load'     => ['nullable', 'numeric'],
            'manual_car_length'   => ['nullable', 'numeric'],
            'manual_remark'       => ['nullable', 'string', 'max:255'],

            'driver_staff_id'    => ['nullable', 'integer'],
            'driver_name_input'  => ['nullable', 'string', 'max:100'],
            'driver_phone_input' => ['nullable', 'string', 'max:50'],
            'shipping_phone'     => ['nullable', 'string', 'max:50'],
            'helper1_staff_id' => ['nullable', 'integer'],
            'helper2_staff_id' => ['nullable', 'integer'],
            'helper3_staff_id' => ['nullable', 'integer'],
            'helper4_staff_id' => ['nullable', 'integer'],
            'helper5_staff_id' => ['nullable', 'integer'],

            'replace_mode' => ['nullable', 'in:1'],

            'ord_ids'   => ['required', 'array', 'min:1'],
            'ord_ids.*' => ['integer', 'distinct'],
            'assign_weight_kg' => ['nullable', 'array'],
            'assign_weight_kg.*' => ['nullable', 'numeric', 'min:0'],
            'assign_weight_tons' => ['nullable', 'array'],
            'assign_weight_tons.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'truck_pick_mode.required' => 'กรุณาเลือกประเภทรถ',
            'truck_pick_mode.in'       => 'ประเภทรถไม่ถูกต้อง',
            'ord_ids.required'         => 'กรุณาเลือก MFG ที่ต้องการขึ้นรถ',
            'ord_ids.min'              => 'กรุณาเลือก MFG อย่างน้อย 1 รายการ',
        ]);

        $replaceMode = (string) $request->input('replace_mode', '') === '1';
        $tripNo = max(1, (int) $request->input('trip_no', 1));

        $truckPickMode = strtoupper(trim((string) $request->input('truck_pick_mode')));
        $truckSource = in_array($truckPickMode, ['MANUAL', 'MANUAL_TEMP'], true)
            ? 'MANUAL'
            : 'MASTER';

        if ($truckSource === 'MASTER' && !$request->filled('truck_id')) {
            return back()->withErrors(['กรุณาเลือกรถในระบบ'])->withInput();
        }

        if (in_array($truckPickMode, ['MANUAL', 'MANUAL_TEMP'], true)) {
            $request->validate([
                'manual_plate_no'     => ['required', 'string', 'max:50'],
                'manual_driver_name'  => ['nullable', 'string', 'max:100'],
                'manual_driver_phone' => ['nullable', 'string', 'max:50'],
                'manual_max_load'     => ['nullable', 'numeric', 'gte:0'],
                'manual_car_length'   => ['nullable', 'numeric', 'gte:0'],
                'manual_remark'       => ['nullable', 'string', 'max:255'],
            ], [
                'manual_plate_no.required' => 'กรุณากรอกทะเบียนรถ',
                'manual_max_load.gte'      => 'Max Load ต้องไม่ติดลบ',
            ]);
        }

        $this->validateUniqueTruckStaffSelection($request);

        $conn = $this->conn();

        $savedRows = 0;
        $savedWeight = 0;
        $requestedWeight = 0;
        $requestedPieceCount = 0;

        $conn->transaction(function () use (
            $conn,
            $request,
            $ordId,
            $truckSource,
            $replaceMode,
            $tripNo,
            &$savedRows,
            &$savedWeight,
            &$requestedWeight,
            &$requestedPieceCount
        ) {
            $targetOrdId = (int) ($ordId ?: 0);

            $ordIds = collect($request->input('ord_ids', []))
                ->map(fn($x) => (int) $x)
                ->filter()
                ->unique()
                ->values();

            if ($ordIds->isEmpty() && $targetOrdId > 0) {
                $ordIds = collect([$targetOrdId]);
            }

            if ($ordIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['กรุณาเลือก MFG อย่างน้อย 1 รายการ'],
                ]);
            }

            $dpRows = $conn->table('delivery_plan_data')
                ->whereIn('ord_id', $ordIds->all())
                ->select([
                    'ord_id',
                    'so_number',
                    'ship_posted_at',
                    'status',
                    'qty',
                    'sell_by_line',
                    'line_qty',
                ])
                ->get();

            if ($dpRows->count() !== $ordIds->count()) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['พบ ord_id บางรายการไม่ถูกต้อง'],
                ]);
            }

            $shipSet = $dpRows->map(function ($r) {
                return $this->dateOnly($r->ship_posted_at);
            })->filter()->unique()->values();

            if ($shipSet->count() > 1) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['MFG ที่เลือกต้องมีวันที่ส่งสินค้าเดียวกัน'],
                ]);
            }

            $hasVoid = $dpRows->contains(fn($r) => $this->isVoidedPlanStatus($r->status ?? null));
            if ($hasVoid) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่สามารถเลือกรถให้รายการ VOID ได้'],
                ]);
            }

            $hasClosed = $dpRows->contains(fn($r) => $this->normalizePlanStatus($r->status ?? null) === 'CLOSED');
            if ($hasClosed) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่สามารถเลือกรถให้รายการ CLOSED ได้'],
                ]);
            }

            $hasPostponed = $dpRows->contains(fn($r) => $this->normalizePlanStatus($r->status ?? null) === 'POSTPONED');
            if ($hasPostponed) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['รายการเลื่อนงาน ไม่สามารถจัดรถได้'],
                ]);
            }

            $shipDateFromDb = (string) $shipSet->first();
            if ($shipDateFromDb === '') {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่พบวันที่ส่งสินค้าของรายการที่เลือก'],
                ]);
            }

            if ($replaceMode) {
                $conn->table('delivery_plan_truck_assign')
                    ->whereIn('ord_id', $ordIds->all())
                    ->delete();
            }

            $conn->table('delivery_plan_special_dispatch')
                ->whereIn('ord_id', $ordIds->all())
                ->whereIn('status', ['OPEN', 'CLOSED'])
                ->update([
                    'status' => 'SUPERSEDED',
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'close_remark' => 'Changed back to normal truck assignment',
                ]);

            $assignedSumRows = $conn->table('delivery_plan_truck_assign')
                ->whereIn('ord_id', $ordIds->all())
                ->selectRaw('ord_id, SUM(ISNULL(assigned_weight, 0)) as assigned_sum')
                ->groupBy('ord_id')
                ->get()
                ->keyBy('ord_id');

            $dpRowsByOrdId = $dpRows->keyBy('ord_id');
            $manualWeightKgByOrd = collect((array) $request->input('assign_weight_kg', []))
                ->mapWithKeys(function ($value, $key) {
                    $ordKey = (int) $key;
                    $kg = is_numeric($value) ? (float) $value : 0.0;
                    return $ordKey > 0 && $kg > 0 ? [$ordKey => $kg] : [];
                });

            if ($manualWeightKgByOrd->isEmpty() && $request->has('assign_weight_tons')) {
                $manualWeightKgByOrd = collect((array) $request->input('assign_weight_tons', []))
                    ->mapWithKeys(function ($value, $key) {
                        $ordKey = (int) $key;
                        $tons = is_numeric($value) ? (float) $value : 0.0;
                        return $ordKey > 0 && $tons > 0 ? [$ordKey => $tons * 1000] : [];
                    });
            }

            $remainingByOrd = [];
            $pieceLineByOrd = [];
            foreach ($ordIds as $oneOrdId) {
                $dp = $dpRowsByOrdId->get($oneOrdId);
                $qty = (float) ($dp->qty ?? 0);
                $assigned = (float) (($assignedSumRows->get($oneOrdId)->assigned_sum ?? 0));
                $lineQty = is_numeric($dp->line_qty ?? null) ? (float) $dp->line_qty : 0.0;
                $isPieceQty = (int) ($dp->sell_by_line ?? 0) === 1
                    && $lineQty > 0
                    && $qty == 0.0;

                $manualWeightKg = (float) ($manualWeightKgByOrd->get($oneOrdId, 0));
                if ($manualWeightKg > 0) {
                    $remaining = $manualWeightKg;
                } elseif ($isPieceQty) {
                    $remaining = 0;
                    $requestedPieceCount += $lineQty;
                } else {
                    $remaining = $replaceMode ? $qty : max(0, $qty - $assigned);
                }

                $remainingByOrd[$oneOrdId] = $remaining;
                $pieceLineByOrd[$oneOrdId] = $isPieceQty;
                $requestedWeight += $remaining;
            }

            if ($requestedWeight <= 0 && $requestedPieceCount <= 0) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['รายการที่เลือกถูก assign ครบหมดแล้ว'],
                ]);
            }

            $manualPlateNo = trim((string) $request->input('manual_plate_no', ''));
            $existingManualTruck = null;
            $manualMaxLoadEffective = 0;
            $truckRemainingCapacity = 0;

            if ($truckSource === 'MASTER') {
                $truckId = (int) $request->input('truck_id');

                $conn->selectOne(
                    "SELECT id FROM delivery_plan_truck_master WITH (UPDLOCK, HOLDLOCK) WHERE id = ?",
                    [$truckId]
                );

                $truck = $conn->table('delivery_plan_truck_master')
                    ->where('id', $truckId)
                    ->where('status', 'ACTIVE')
                    ->first();

                if (!$truck) {
                    throw ValidationException::withMessages([
                        'truck_id' => ['ไม่พบรถในระบบ หรือรถไม่ active'],
                    ]);
                }

                $currentLoadRow = $conn->selectOne(
                    "
                SELECT ISNULL(SUM(ISNULL(ta.assigned_weight, 0)), 0) AS current_load
                FROM delivery_plan_truck_assign ta WITH (UPDLOCK, HOLDLOCK)
                INNER JOIN delivery_plan_data dp
                    ON dp.ord_id = ta.ord_id
                WHERE ta.truck_source = 'MASTER'
                  AND ta.truck_id = ?
                  AND CAST(ta.ship_posted_at AS date) = ?
                  AND ISNULL(ta.trip_no, 1) = ?
                  AND UPPER(ISNULL(dp.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')
                ",
                    [$truckId, $shipDateFromDb, $tripNo]
                );

                $closedTrip = $conn->table('delivery_plan_truck_assign')
                    ->where('truck_source', 'MASTER')
                    ->where('truck_id', $truckId)
                    ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDateFromDb])
                    ->whereRaw('ISNULL(trip_no, 1) = ?', [$tripNo])
                    ->whereNotNull('closed_at')
                    ->exists();

                if ($closedTrip) {
                    throw ValidationException::withMessages([
                        'trip_no' => ['เที่ยวรถนี้ปิดแล้ว กรุณาเลือกเที่ยวถัดไป'],
                    ]);
                }

                $truckMaxLoad = (float) ($truck->max_load ?? 0);
                $capacityUnlimited = $truckMaxLoad <= 0;
                $currentLoad = (float) ($currentLoadRow->current_load ?? 0);
                $truckRemainingCapacity = $capacityUnlimited
                    ? $requestedWeight
                    : max(0, $truckMaxLoad - $currentLoad);

                if ($requestedWeight > 0 && !$capacityUnlimited && $truckRemainingCapacity <= 0) {
                    throw ValidationException::withMessages([
                        'truck_id' => ['รถคันนี้น้ำหนักเต็มแล้ว'],
                    ]);
                }
            } else {
                $existingManualTruck = $conn->table('delivery_plan_truck_assign')
                    ->where('truck_source', 'MANUAL')
                    ->where('manual_plate_no', $manualPlateNo)
                    ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDateFromDb])
                    ->whereRaw('ISNULL(trip_no, 1) = ?', [$tripNo])
                    ->orderByDesc('id')
                    ->first();

                $manualMaxLoadInput = (float) $request->input('manual_max_load', 0);

                $manualMaxLoadEffective = $manualMaxLoadInput > 0
                    ? $manualMaxLoadInput
                    : (float) ($existingManualTruck->manual_max_load ?? 0);

                if ($existingManualTruck && $manualMaxLoadInput > 0) {
                    $oldMax = (float) ($existingManualTruck->manual_max_load ?? 0);
                    if ($oldMax > 0 && abs($manualMaxLoadInput - $oldMax) > 0.001) {
                        throw ValidationException::withMessages([
                            'manual_max_load' => ['ทะเบียนนี้มี Max Load เดิมอยู่แล้ว กรุณาใช้ค่าเดิม ' . number_format($oldMax, 0)],
                        ]);
                    }
                }

                $currentManualLoadRow = $conn->selectOne(
                    "
                SELECT ISNULL(SUM(ISNULL(ta.assigned_weight, 0)), 0) AS current_load
                FROM delivery_plan_truck_assign ta WITH (UPDLOCK, HOLDLOCK)
                INNER JOIN delivery_plan_data dp
                    ON dp.ord_id = ta.ord_id
                WHERE ta.truck_source = 'MANUAL'
                  AND ta.manual_plate_no = ?
                  AND CAST(ta.ship_posted_at AS date) = ?
                  AND ISNULL(ta.trip_no, 1) = ?
                  AND UPPER(ISNULL(dp.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')
                ",
                    [$manualPlateNo, $shipDateFromDb, $tripNo]
                );

                $closedManualTrip = $conn->table('delivery_plan_truck_assign')
                    ->where('truck_source', 'MANUAL')
                    ->where('manual_plate_no', $manualPlateNo)
                    ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDateFromDb])
                    ->whereRaw('ISNULL(trip_no, 1) = ?', [$tripNo])
                    ->whereNotNull('closed_at')
                    ->exists();

                if ($closedManualTrip) {
                    throw ValidationException::withMessages([
                        'trip_no' => ['เที่ยวรถนี้ปิดแล้ว กรุณาเลือกเที่ยวถัดไป'],
                    ]);
                }

                $capacityUnlimited = $manualMaxLoadEffective <= 0;
                $currentManualLoad = (float) ($currentManualLoadRow->current_load ?? 0);
                $truckRemainingCapacity = $capacityUnlimited
                    ? $requestedWeight
                    : max(0, $manualMaxLoadEffective - $currentManualLoad);

                if ($requestedWeight > 0 && !$capacityUnlimited && $truckRemainingCapacity <= 0) {
                    throw ValidationException::withMessages([
                        'manual_plate_no' => ['รถนอกคันนี้น้ำหนักเต็มแล้ว'],
                    ]);
                }
            }

            $staffSnapshot = $this->getTruckStaffSnapshotFromRequest($request);

            // คนขับเป็น text input (ชื่อ/เบอร์) ไม่ใช่ FK ไปยัง staff master แล้ว
            // เพราะคนขับมักเป็นบุคคลภายนอก ไม่ใช่พนักงานบริษัท
            $driverNameTyped  = trim((string) $request->input('driver_name_input', ''));
            $driverPhoneTyped = trim((string) $request->input('driver_phone_input', ''));
            $shippingPhoneTyped = trim((string) $request->input('shipping_phone', ''));
            if ($driverNameTyped !== '' || $driverPhoneTyped !== '') {
                $staffSnapshot['driver_staff_id'] = null;
                $staffSnapshot['driver_name']  = $driverNameTyped !== '' ? $driverNameTyped : null;
                $staffSnapshot['driver_phone'] = $driverPhoneTyped !== '' ? $driverPhoneTyped : null;
            }

            foreach ($ordIds as $oneOrdId) {
                $dp = $dpRowsByOrdId->get($oneOrdId);
                if (!$dp) {
                    continue;
                }

                $isPieceQty = !empty($pieceLineByOrd[$oneOrdId]);
                $remainingOrdQty = (float) ($remainingByOrd[$oneOrdId] ?? 0);
                if (!$isPieceQty && $remainingOrdQty <= 0) {
                    continue;
                }

                if (!$isPieceQty && $truckRemainingCapacity <= 0) {
                    continue;
                }

                $allocateWeight = $isPieceQty ? 0 : min($remainingOrdQty, $truckRemainingCapacity);
                if (!$isPieceQty && $allocateWeight <= 0) {
                    continue;
                }

                // กัน insert ซ้ำ: ถ้ามี row เดียวกัน (ord_id + truck + trip + ship date) อยู่แล้ว
                // ในเที่ยวที่ยังไม่ปิด ให้ข้าม (เกิดจากกดบันทึกซ้ำหลายครั้ง)
                $dupQuery = $conn->table('delivery_plan_truck_assign')
                    ->where('ord_id', $oneOrdId)
                    ->where('truck_source', $truckSource)
                    ->whereRaw('ISNULL(trip_no, 1) = ?', [$tripNo])
                    ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDateFromDb])
                    ->whereNull('closed_at');

                if ($truckSource === 'MASTER') {
                    $dupQuery->where('truck_id', (int) $request->input('truck_id'));
                } else {
                    $dupQuery->where('manual_plate_no', $manualPlateNo);
                }

                if ($dupQuery->exists()) {
                    if (!$isPieceQty) {
                        $truckRemainingCapacity -= $allocateWeight;
                        $savedWeight += $allocateWeight;
                    }
                    $savedRows++;
                    continue;
                }

                $payload = array_merge([
                    'ord_id'              => $oneOrdId,
                    'so_number'           => (string) ($dp->so_number ?? ''),
                    'truck_source'        => $truckSource,
                    'truck_id'            => $truckSource === 'MASTER' ? (int) $request->input('truck_id') : null,

                    'manual_plate_no'     => $truckSource === 'MANUAL' ? $manualPlateNo : null,
                    'manual_driver_name'  => $truckSource === 'MANUAL'
                        ? ($request->input('manual_driver_name') ?: ($existingManualTruck->manual_driver_name ?? null))
                        : null,
                    'manual_driver_phone' => $truckSource === 'MANUAL'
                        ? ($request->input('manual_driver_phone') ?: ($existingManualTruck->manual_driver_phone ?? null))
                        : null,
                    'manual_max_load'     => $truckSource === 'MANUAL' ? $manualMaxLoadEffective : null,
                    'manual_car_length'   => $truckSource === 'MANUAL'
                        ? ($request->input('manual_car_length') ?: ($existingManualTruck->manual_car_length ?? null))
                        : null,
                    'manual_remark'       => $truckSource === 'MANUAL'
                        ? ($request->input('manual_remark') ?: ($existingManualTruck->manual_remark ?? null))
                        : null,

                    'shipping_phone'      => $shippingPhoneTyped !== '' ? $shippingPhoneTyped : null,

                    'assigned_weight'     => $allocateWeight,
                    'ship_posted_at'      => $shipDateFromDb,
                    'trip_no'             => $tripNo,
                    'assigned_at'         => now(),
                    'assigned_by'         => auth()->id(),
                ], $staffSnapshot);

                $conn->table('delivery_plan_truck_assign')->insert($payload);

                $conn->table('delivery_plan_data')
                    ->where('ord_id', $oneOrdId)
                    ->whereRaw("UPPER(ISNULL(status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED','CLOSED')")
                    ->update([
                        'status' => 'ASSIGN',
                    ]);

                if (!$isPieceQty) {
                    $truckRemainingCapacity -= $allocateWeight;
                    $savedWeight += $allocateWeight;
                }
                $savedRows++;
            }

            if ($savedRows <= 0) {
                throw ValidationException::withMessages([
                    'ord_ids' => ['ไม่สามารถจัดน้ำหนักขึ้นรถได้ กรุณาตรวจสอบความจุรถและยอดคงเหลือ'],
                ]);
            }
        });

        $msg = $replaceMode ? 'เปลี่ยนรถสำเร็จ' : 'Assign truck สำเร็จ';

        if ($requestedWeight > 0 && $savedWeight < $requestedWeight) {
            $msg .= ' (บันทึกบางส่วน ' . number_format($savedWeight, 0) . ' / ' . number_format($requestedWeight, 0) . ' KG)';
        } elseif ($requestedWeight > 0) {
            $msg .= ' (' . number_format($savedWeight, 0) . ' KG)';
        }

        if ($requestedPieceCount > 0) {
            $msg .= ' (งานชิ้น ' . number_format($requestedPieceCount, 0) . ' ชิ้น)';
        }

        return redirect()
            ->to($this->resolveReturnUrl($request))
            ->with('success', $msg);
    }

    public function truckCapacity(Request $request)
    {
        $shipDate = trim((string) $request->query('ship_posted_at', ''));
        $tripNo = max(1, (int) $request->query('trip_no', 1));
        $rows = $this->getAssignableTrucksByShipDate($shipDate, $tripNo);

        return response()->json(
            $rows->map(function ($r) {
                return [
                    'row_key' => $r->row_key,
                    'truck_pick_type' => $r->truck_pick_type,
                    'truck_id' => $r->truck_id,
                    'manual_plate_no' => $r->manual_plate_no,
                    'plate_no' => $r->plate_no,
                    'driver_name' => $r->driver_name,
                    'driver_phone' => $r->driver_phone,
                    'max_load' => (float) $r->max_load,
                    'car_length' => $r->car_length,
                    'remark' => $r->remark,
                    'current_load' => (float) $r->current_load,
                    'remaining_capacity' => (float) $r->remaining_capacity,
                    'capacity_unlimited' => !empty($r->capacity_unlimited),
                    'trip_no' => (int) ($r->trip_no ?? 1),
                    'source_label' => $r->source_label,
                    'job_summary' => $r->job_summary ?? [],
                    'so_summary_text' => $r->so_summary_text ?? '',
                    'mfg_summary_text' => $r->mfg_summary_text ?? '',
                ];
            })->values()
        );
    }

    public function history(int $ordId)
    {
        try {
            $conn = $this->conn();

            $fields = [
                'ship_posted_at',
                'due_date',
                'window_at',
                'window_text',
                'delivery_type',
                'so_number',
                'customer_id',
                'sales_id',
                'part_number',
                'part_desc',
                'mfg_no',
                'qty',
                'stock_qty',
                'address',
                'sell_by_line',
                'tel',
                'remark',
                'attach_docs',
                'attach_docs_other',
                'edit_remark',
                'due_date_remark',
                'revise_by',
                'status',
                'remark_void',
            ];

            $labels = [
                'ship_posted_at'     => 'วันที่ส่งสินค้า',
                'due_date'           => 'วันส่งจริง',
                'window_at'          => 'ช่วงเวลารับส่ง (เวลา)',
                'window_text'        => 'ช่วงเวลารับส่ง',
                'delivery_type'      => 'ประเภทส่ง',
                'so_number'          => 'Sales Order',
                'customer_id'        => 'ลูกค้า',
                'sales_id'           => 'Sales',
                'part_number'        => 'Part No',
                'part_desc'          => 'Part Desc',
                'mfg_no'             => 'MFG No',
                'qty'                => 'จำนวน (KG)',
                'stock_qty'          => 'Stock FG',
                'address'            => 'สถานที่ส่ง',
                'sell_by_line'       => 'ขายแบบระบุเส้น',
                'tel'                => 'เบอร์โทร',
                'remark'             => 'หมายเหตุ',
                'attach_docs'        => 'เอกสารแนบ',
                'attach_docs_other'  => 'เอกสารอื่นๆ',
                'edit_remark'        => 'เหตุผลแก้ไข',
                'due_date_remark'    => 'สาเหตุเลื่อนส่ง',
                'revise_by'          => 'แก้ไขโดย',
                'status'             => 'สถานะ',
                'remark_void'        => 'เหตุผลยกเลิก',
            ];

            $docMap = $this->docMap();

            $all = $conn->table(DB::raw('dbo.delivery_plan_data AS x'))
                ->leftJoin('dbo.customer as c', 'c.id', '=', 'x.customer_id')
                ->leftJoin('dbo.employees as e', 'e.id', '=', 'x.sales_id')
                ->leftJoin('dbo.users as u_rev', 'u_rev.id', '=', 'x.revise_by')
                ->leftJoin('dbo.users as u_create', 'u_create.id', '=', 'x.created_by')
                ->leftJoin('dbo.revision_master as rv', 'rv.revision_number', '=', 'x.revision_number')
                ->where('x.ord_id', $ordId)
                ->orderByDesc(DB::raw('x.SysStartTime'))
                ->select(array_merge([
                    'x.ord_id',
                    'x.revision_number',
                    'x.revise_by',
                    DB::raw("x.created_at as sys_start"),
                    DB::raw("CAST('9999-12-31 23:59:59' AS datetime) as sys_end"),

                    DB::raw("COALESCE(NULLIF(LTRIM(RTRIM(x.customer_name COLLATE DATABASE_DEFAULT)), ''), c.name COLLATE DATABASE_DEFAULT) as customer_name"),
                    'c.customernumber',

                    'e.login as sales_user_code',
                    'e.name  as sales_user_name',

                    'u_rev.name as revise_by_name',
                    'u_create.name as created_by_name',

                    'rv.color_code',
                ], array_map(fn($f) => "x.$f", $fields)))
                ->get()
                ->map(fn($x) => (array) $x)
                ->values()
                ->all();

            if (!$all) {
                return response()->json(['ok' => false, 'message' => 'ไม่พบข้อมูล ord_id นี้']);
            }

            $currentRow = collect($all)->first(function ($r) {
                $end = (string) ($r['sys_end'] ?? '');
                return str_starts_with($end, '9999-12-31');
            }) ?? $all[0];

            $curArr = $currentRow;

            $history = array_values(array_filter($all, function ($r) use ($currentRow) {
                return (string) ($r['sys_start'] ?? '') !== (string) ($currentRow['sys_start'] ?? '');
            }));

            $fmt = function (string $field, array $row) use ($docMap) {
                $v = $row[$field] ?? null;
                if ($v === null) {
                    return '';
                }
                if (is_string($v) && trim($v) === '') {
                    return '';
                }

                if ($field === 'customer_id') {
                    $name = trim((string) ($row['customer_name'] ?? ''));
                    $code = trim((string) ($row['customernumber'] ?? ''));
                    if ($name === '' && $code === '') {
                        return (string) $v;
                    }
                    return ($code !== '' ? "{$code} — " : '') . $name;
                }

                if ($field === 'sales_id') {
                    $name = trim((string) ($row['sales_user_name'] ?? ''));
                    $code = trim((string) ($row['sales_user_code'] ?? ''));
                    if ($name === '' && $code === '') {
                        return (string) $v;
                    }
                    return trim(($code !== '' ? "{$code} " : '') . $name);
                }

                if (in_array($field, ['ship_posted_at', 'due_date', 'window_at', 'sys_start', 'sys_end'], true)) {
                    try {
                        $dt = Carbon::parse($v);
                        if ($field === 'window_at') {
                            return $dt->format('Y-m-d H:i');
                        }
                        if ($field === 'due_date' || $field === 'ship_posted_at') {
                            return $dt->format('Y-m-d');
                        }
                        return $dt->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                        return (string) $v;
                    }
                }

                if ($field === 'sell_by_line') {
                    $b = (string) $v === '1' || strtolower((string) $v) === 'true';
                    return $b ? 'ใช่' : 'ไม่ใช่';
                }

                if ($field === 'delivery_type') {
                    $t = strtoupper(trim((string) $v));
                    return match ($t) {
                        'ACID' => 'ส่งกัดกรด',
                        'SPECIAL' => 'งานพิเศษ (Special)',
                        default => 'ปกติ (SO)',
                    };
                }

                if ($field === 'status') {
                    return strtoupper(trim((string) $v));
                }

                if (in_array($field, ['qty', 'stock_qty'], true)) {
                    $n = is_numeric($v) ? (float) $v : null;
                    return $n === null ? (string) $v : number_format($n, 3);
                }

                if ($field === 'attach_docs') {
                    $codes = $this->normalizeDocCodes((string) $v);
                    if (!$codes) {
                        return '';
                    }
                    $names = array_map(fn($c) => $docMap[$c] ?? $c, $codes);
                    return implode(', ', $names);
                }

                return trim((string) $v);
            };

            $versionsDesc = array_map(function (array $h) use ($fields, $labels, $fmt, $curArr) {
                $diffCurrent = [];
                foreach ($fields as $f) {
                    $old = $fmt($f, $h);
                    $now = $fmt($f, $curArr);
                    if ($old !== $now) {
                        $diffCurrent[] = [
                            'field' => $f,
                            'label' => $labels[$f] ?? $f,
                            'from'  => $old,
                            'to'    => $now,
                        ];
                    }
                }
                $h['diff_current'] = $diffCurrent;
                $h['diff_step'] = [];
                return $h;
            }, $history);

            $timelineAsc = $all;
            usort($timelineAsc, fn($a, $b) => strcmp((string) $a['sys_start'], (string) $b['sys_start']));

            $stepMapOld = [];
            for ($i = 0; $i < count($timelineAsc) - 1; $i++) {
                $old = $timelineAsc[$i];
                $new = $timelineAsc[$i + 1];

                $diffStep = [];
                foreach ($fields as $f) {
                    $a = $fmt($f, $old);
                    $b = $fmt($f, $new);
                    if ($a !== $b) {
                        $diffStep[] = [
                            'field' => $f,
                            'label' => $labels[$f] ?? $f,
                            'from'  => $a,
                            'to'    => $b,
                        ];
                    }
                }

                $stepMapOld[(string) $old['sys_start']] = $diffStep;
            }

            foreach ($versionsDesc as &$v) {
                $k = (string) ($v['sys_start'] ?? '');
                $v['diff_step'] = $stepMapOld[$k] ?? [];
            }
            unset($v);

            return response()->json([
                'ok'               => true,
                'ord_id'           => (int) $ordId,
                'current_revision' => (int) ($curArr['revision_number'] ?? 0),
                'current'          => $curArr,
                'versions'         => $versionsDesc,
            ]);
        } catch (\Throwable $e) {
            Log::error('DeliveryPlanInquiryController@history failed', [
                'ord_id' => $ordId,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'เกิดข้อผิดพลาดในการดึงประวัติข้อมูล',
            ], 500);
        }
    }

    public function voidPlan(Request $request, $ordId)
    {
        $request->validate([
            'remark_void' => ['required', 'string', 'max:500'],
        ], [
            'remark_void.required' => 'กรุณากรอกเหตุผลยกเลิก',
        ]);

        $conn = $this->conn();
        $successSoNumber = '';

        $conn->transaction(function () use ($conn, $request, $ordId, &$successSoNumber) {
            $row = $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->select(['ord_id', 'status', 'ship_posted_at', 'so_number'])
                ->first();

            if (!$row) {
                throw ValidationException::withMessages([
                    'ord_id' => ["ไม่พบรายการ ord_id={$ordId}"],
                ]);
            }

            if (strtoupper((string) ($row->status ?? '')) === 'VOID') {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ถูกยกเลิกไปแล้ว"],
                ]);
            }

            if (strtoupper((string) ($row->status ?? '')) === 'CLOSED') {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ปิดงานแล้ว ไม่สามารถยกเลิกได้"],
                ]);
            }

            $successSoNumber = trim((string) ($row->so_number ?? ''));

            $shipRaw = trim((string) ($row->ship_posted_at ?? ''));
            if ($shipRaw === '') {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ยกเลิกไม่ได้ เพราะไม่มีวันที่ส่งสินค้า"],
                ]);
            }

            try {
                $shipDt = Carbon::parse($shipRaw)->startOfDay();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ยกเลิกไม่ได้ เพราะรูปแบบวันที่ส่งสินค้าไม่ถูกต้อง"],
                ]);
            }

            $cutoff = $shipDt->copy()->subDays(3)->endOfDay();
            if (Carbon::now()->gt($cutoff)) {
                throw ValidationException::withMessages([
                    'ord_id' => [
                        "ยกเลิกไม่ได้: ต้องยกเลิกก่อนวันส่งสินค้าอย่างน้อย 3 วัน (กำหนดล่าสุด {$cutoff->format('d/m/Y H:i')})"
                    ],
                ]);
            }

            $userId = auth()->check() ? (int) auth()->id() : null;

            $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->update([
                    'status'          => 'VOID',
                    'remark_void'     => $request->input('remark_void'),
                    'revise_by'       => $userId,
                    'edit_remark'     => 'VOID: ' . trim((string) $request->input('remark_void')),
                    'revision_number' => DB::raw('ISNULL(revision_number,0) + 1'),
                ]);

            $conn->table('delivery_plan_truck_assign')
                ->where('ord_id', $ordId)
                ->delete();
        });

        $successSoText = $successSoNumber !== '' ? $successSoNumber : 'รายการนี้';
        return back()->with('success', 'ยกเลิกรายการ Sales Order ' . $successSoText . ' เรียบร้อย');
    }

    public function postponePlan(Request $request, int $ordId)
    {
        $data = $request->validate([
            'new_ship_posted_date' => ['required', 'date'],
            'new_window_time' => ['required', 'date_format:H:i'],
            'postpone_reason' => ['required', 'string', 'max:500'],
            'return_url' => ['nullable', 'string', 'max:1000'],
        ], [
            'new_ship_posted_date.required' => 'กรุณาเลือกวันที่ส่งใหม่',
            'new_window_time.required' => 'กรุณาเลือกเวลาใหม่',
            'postpone_reason.required' => 'กรุณากรอกเหตุผลเลื่อนแผน',
        ]);

        return $this->postponePlanWithSharedLogic($data, $ordId);
    }

    private function postponePlanWithSharedLogic(array $data, int $ordId)
    {
        $conn = $this->conn();
        $userId = auth()->check() ? (int) auth()->id() : null;
        $createdBy = auth()->check()
            ? (auth()->user()->login ?? auth()->user()->id ?? auth()->user()->name ?? 'system')
            : 'system';

        $newShipDate = Carbon::parse($data['new_ship_posted_date'])->startOfDay();
        $reason = trim((string) $data['postpone_reason']);
        $result = null;

        $conn->transaction(function () use ($conn, $ordId, $userId, $createdBy, $newShipDate, $data, $reason, &$result) {
            $result = $this->postponeOnePlan(
                $conn,
                $ordId,
                $newShipDate,
                (string) $data['new_window_time'],
                $reason,
                $userId,
                (string) $createdBy
            );
        });

        $successSoText = trim((string) ($result['so_number'] ?? '')) !== '' ? $result['so_number'] : 'รายการนี้';

        return redirect($data['return_url'] ?? url()->previous())
            ->with('success', 'Sales Order ' . $successSoText . ' เลื่อนไปวันที่ ' . $newShipDate->format('d/m/Y') . ' เรียบร้อย');
    }

    public function bulkPostponePlans(Request $request)
    {
        $data = $request->validate([
            'ord_ids' => ['required', 'array', 'min:1', 'max:200'],
            'ord_ids.*' => ['integer', 'distinct'],
            'new_ship_posted_date' => ['required', 'date'],
            'new_window_time' => ['required', 'date_format:H:i'],
            'postpone_reason' => ['required', 'string', 'max:500'],
            'return_url' => ['nullable', 'string', 'max:1000'],
        ], [
            'ord_ids.required' => 'กรุณาเลือกรายการที่ต้องการเลื่อนแผน',
            'ord_ids.min' => 'กรุณาเลือกรายการที่ต้องการเลื่อนแผน',
            'new_ship_posted_date.required' => 'กรุณาเลือกวันที่ส่งใหม่',
            'new_window_time.required' => 'กรุณาเลือกเวลาใหม่',
            'postpone_reason.required' => 'กรุณากรอกเหตุผลเลื่อนแผน',
        ]);

        $ordIds = collect($data['ord_ids'])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($ordIds->isEmpty()) {
            throw ValidationException::withMessages([
                'ord_ids' => ['กรุณาเลือกรายการที่ต้องการเลื่อนแผน'],
            ]);
        }

        $conn = $this->conn();
        $userId = auth()->check() ? (int) auth()->id() : null;
        $createdBy = auth()->check()
            ? (auth()->user()->login ?? auth()->user()->id ?? auth()->user()->name ?? 'system')
            : 'system';

        $newShipDate = Carbon::parse($data['new_ship_posted_date'])->startOfDay();
        $reason = trim((string) $data['postpone_reason']);
        $results = [];

        $conn->transaction(function () use ($conn, $ordIds, $userId, $createdBy, $newShipDate, $data, $reason, &$results) {
            foreach ($ordIds as $ordId) {
                $results[] = $this->postponeOnePlan(
                    $conn,
                    (int) $ordId,
                    $newShipDate,
                    (string) $data['new_window_time'],
                    $reason,
                    $userId,
                    (string) $createdBy
                );
            }
        });

        $soCount = collect($results)
            ->pluck('so_number')
            ->map(fn($so) => trim((string) $so))
            ->filter()
            ->unique()
            ->count();

        return redirect($data['return_url'] ?? url()->previous())
            ->with('success', 'เลื่อนแผนสำเร็จ ' . count($results) . ' รายการ / ' . $soCount . ' SO ไปวันที่ ' . $newShipDate->format('d/m/Y'))
            ->with('postpone_view_url', route('dp.inquiry', [
                'ship_from' => $newShipDate->toDateString(),
                'ship_to' => $newShipDate->toDateString(),
                'status' => 'ALL',
                'searched' => 1,
            ]));
    }

    private function postponeOnePlan($conn, int $ordId, Carbon $newShipDate, string $newWindowTime, string $reason, ?int $userId, string $createdBy): array
    {
        $row = $conn->table('delivery_plan_data')
            ->where('ord_id', $ordId)
            ->first();

        if (!$row) {
            throw ValidationException::withMessages([
                'ord_ids' => ["ไม่พบรายการ ord_id={$ordId}"],
            ]);
        }

        $statusUpper = $this->normalizePlanStatus($row->status ?? null);
        if ($this->isVoidedPlanStatus($statusUpper) || in_array($statusUpper, ['CLOSED', 'POSTPONED'], true)) {
            throw ValidationException::withMessages([
                'ord_ids' => ["รายการ ord_id={$ordId} ไม่สามารถเลื่อนแผนได้ (สถานะ {$statusUpper})"],
            ]);
        }

        $copyColumns = [
            'due_date',
            'window_at',
            'window_text',
            'ship_posted_at',
            'due_date_remark',
            'tel',
            'remark',
            'attach_docs',
            'attach_docs_other',
            'has_doc',
            'delivery_type',
            'so_number',
            'customer_id',
            'sales_id',
            'part_number',
            'part_desc',
            'mfg_no',
            'qty',
            'stock_qty',
            'address',
            'sell_by_line',
            'line_qty',
        ];

        $payload = [];
        foreach ($copyColumns as $column) {
            $payload[$column] = $row->{$column} ?? null;
        }

        $originalShipDate = !empty($row->ship_posted_at)
            ? Carbon::parse($row->ship_posted_at)->startOfDay()
            : $newShipDate->copy();
        $originalShipDateText = !empty($row->ship_posted_at)
            ? $originalShipDate->format('d/m/Y')
            : '-';
        $originalDueDate = !empty($row->due_date)
            ? Carbon::parse($row->due_date)->startOfDay()
            : $newShipDate->copy();
        $newWindowAt = Carbon::parse($originalDueDate->toDateString() . ' ' . $newWindowTime . ':00');
        $postponedRevisionNumber = $this->resolvePostponeRevisionNumber(
            $conn,
            $originalShipDate,
            (int) ($row->revision_number ?? 0)
        );

        $payload['due_date'] = $originalDueDate;
        $payload['ship_posted_at'] = $newShipDate;
        $payload['window_at'] = $newWindowAt;
        $payload['status'] = 'NEW';
        $payload['revision_number'] = 0;
        $payload['created_at'] = DB::raw('GETDATE()');
        $payload['created_by'] = $createdBy;
        $payload['revise_by'] = null;
        $payload['edit_remark'] = 'เลื่อนมาจาก วันที่ ' . $originalShipDateText . ' เหตุผล : ' . $reason;
        $payload['remark_void'] = null;

        $newOrdId = $conn->table('delivery_plan_data')->insertGetId($payload, 'ord_id');

        $conn->table('delivery_plan_special_dispatch')
            ->where('ord_id', $ordId)
            ->where('status', 'OPEN')
            ->update([
                'status' => 'SUPERSEDED',
                'closed_at' => now(),
                'closed_by' => $userId,
                'close_remark' => 'Postponed and copied to ord_id=' . $newOrdId,
            ]);

        $conn->table('delivery_plan_truck_assign')
            ->where('ord_id', $ordId)
            ->delete();

        $conn->table('delivery_plan_special_dispatch')
            ->insert([
                'ord_id' => $ordId,
                'dispatch_type' => 'POSTPONED',
                'status' => 'CLOSED',
                'remark' => $reason . ' | New ord_id=' . $newOrdId . ' | New ship date=' . $newShipDate->toDateString(),
                'action_by' => $userId,
                'action_at' => now(),
                'closed_at' => now(),
                'closed_by' => $userId,
                'close_remark' => 'Postponed copy created as ord_id=' . $newOrdId,
            ]);

        $conn->table('delivery_plan_data')
            ->where('ord_id', $ordId)
            ->whereRaw("UPPER(ISNULL(status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED','CLOSED')")
            ->update([
                'status' => 'POSTPONED',
                'revision_number' => $postponedRevisionNumber,
                'revise_by' => $userId,
                'edit_remark' => 'เลื่อนไป ord_id=' . $newOrdId . ' เหตุผล : ' . $reason,
            ]);

        return [
            'ord_id' => $ordId,
            'new_ord_id' => $newOrdId,
            'so_number' => trim((string) ($row->so_number ?? '')),
            'revision_number' => $postponedRevisionNumber,
        ];
    }

    private function resolvePostponeRevisionNumber($conn, Carbon $shipDate, int $sourceRevision): int
    {
        $sentRevisions = $conn->table('delivery_plan_mail_logs')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate->toDateString()])
            ->pluck('revision_number')
            ->map(fn($revision) => (int) $revision)
            ->filter(fn($revision) => $revision >= 0)
            ->unique();

        // บวก rev อัตโนมัติเฉพาะเมื่อ rev ปัจจุบัน (หรือสูงกว่า) เคยถูกส่งเมลออกไปแล้ว
        // ถ้ายังไม่เคยส่งเมลสำหรับ ship_date+rev นี้ ให้คงค่า revision เดิมไว้
        $hasAnnouncedCurrent = $sentRevisions->contains(fn($r) => $r >= $sourceRevision);
        if (!$hasAnnouncedCurrent) {
            return $sourceRevision;
        }

        $sentSet = $sentRevisions->flip();
        $nextRevision = max(0, $sourceRevision + 1);
        while ($sentSet->has($nextRevision)) {
            $nextRevision++;
        }

        return $nextRevision;
    }

    public function duplicatePlan(Request $request, int $ordId)
    {
        $data = $request->validate([
            'duplicate_ship_posted_date' => ['required', 'date'],
            'duplicate_window_time' => ['required', 'date_format:H:i'],
            'duplicate_mfg_no' => ['nullable', 'string', 'max:500'],
            'duplicate_qty' => ['nullable', 'numeric', 'min:0'],
            'duplicate_sell_by_line' => ['nullable', 'in:0,1'],
            'duplicate_line_qty' => ['nullable', 'integer', 'min:1'],
            'duplicate_address' => ['required', 'string', 'max:500'],
            'duplicate_tel' => ['nullable', 'string', 'max:255'],
            'duplicate_remark' => ['nullable', 'string', 'max:1000'],
            'duplicate_attach_docs' => ['nullable', 'array'],
            'duplicate_attach_docs.*' => ['nullable', 'string', 'max:50'],
            'duplicate_attach_docs_other' => ['nullable', 'string', 'max:255'],
            'duplicate_revision_number' => ['nullable', 'integer', 'min:0'],
            'duplicate_continue_same_plan' => ['nullable', 'in:1'],
            'return_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $conn = $this->conn();
        $createdBy = auth()->check()
            ? (auth()->user()->login ?? auth()->user()->id ?? auth()->user()->name ?? 'system')
            : 'system';

        $newShipDate = Carbon::parse($data['duplicate_ship_posted_date'])->startOfDay();
        $newOrdId = null;
        $successSoNumber = '';

        $docs = $request->input('duplicate_attach_docs', []);
        $docs = is_array($docs) ? $docs : [$docs];
        $docs = array_values(array_unique(array_filter(array_map(fn($x) => trim((string) $x), $docs))));
        $docs = array_filter($docs, fn($x) => $x !== '' && strtolower($x) !== 'array');
        $docs = array_values(array_unique($docs));

        $allowedDocs = $conn->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('code')
            ->map(fn($x) => trim((string) $x))
            ->filter()
            ->values()
            ->all();

        $docs = array_values(array_filter($docs, fn($x) => in_array($x, $allowedDocs, true)));
        $docsOtherText = trim((string) ($data['duplicate_attach_docs_other'] ?? ''));

        $otherCode = 'OTHER';
        if ($docsOtherText !== '' && !in_array($otherCode, $docs, true)) {
            $docs[] = $otherCode;
        }

        if (in_array($otherCode, $docs, true) && $docsOtherText === '') {
            throw ValidationException::withMessages([
                'duplicate_attach_docs_other' => ['กรุณาระบุเอกสารอื่นๆ'],
            ]);
        }

        $attachDocs = implode(',', $docs);
        $hasDoc = ($attachDocs !== '' || $docsOtherText !== '') ? 1 : 0;

        $conn->transaction(function () use (
            $conn,
            $ordId,
            $createdBy,
            $newShipDate,
            $data,
            $attachDocs,
            $docsOtherText,
            $hasDoc,
            &$newOrdId,
            &$successSoNumber
        ) {
            $row = $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->first();

            if (!$row) {
                throw ValidationException::withMessages([
                    'ord_id' => ["ไม่พบรายการ ord_id={$ordId}"],
                ]);
            }

            $statusUpper = strtoupper((string) ($row->status ?? ''));
            if (in_array($statusUpper, ['VOID', 'CLOSED', 'POSTPONED'], true)) {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ไม่สามารถ duplicate ได้ (สถานะ {$statusUpper})"],
                ]);
            }

            $successSoNumber = trim((string) ($row->so_number ?? ''));

            $copyColumns = [
                'due_date',
                'window_at',
                'window_text',
                'ship_posted_at',
                'due_date_remark',
                'tel',
                'remark',
                'attach_docs',
                'attach_docs_other',
                'has_doc',
                'delivery_type',
                'so_number',
                'customer_id',
                'sales_id',
                'part_number',
                'part_desc',
                'mfg_no',
                'qty',
                'stock_qty',
                'address',
                'sell_by_line',
                'line_qty',
            ];

            $payload = [];
            foreach ($copyColumns as $column) {
                $payload[$column] = $row->{$column} ?? null;
            }

            $originalDueDate = !empty($row->due_date)
                ? Carbon::parse($row->due_date)->startOfDay()
                : $newShipDate->copy();
            $newWindowAt = Carbon::parse($originalDueDate->toDateString() . ' ' . $data['duplicate_window_time'] . ':00');

            $sellByLine = (string) ($data['duplicate_sell_by_line'] ?? ($row->sell_by_line ?? '0'));
            $sellByLine = ($sellByLine === '1' || strtolower($sellByLine) === 'true') ? 1 : 0;
            $lineQty = $sellByLine === 1 && ($data['duplicate_line_qty'] ?? null) !== null
                ? (int) $data['duplicate_line_qty']
                : null;

            $partsId = (int) $conn->table('parts')
                ->where('partnumber', $row->part_number ?? '')
                ->value('id');

            if ($this->isSales8User()) {
                $sellByLine = 1;
                if ($lineQty === null || $lineQty <= 0) {
                    throw ValidationException::withMessages([
                        'duplicate_line_qty' => ['กรุณากรอกจำนวนชิ้น'],
                    ]);
                }
                $qtyKg = 0.0;
            } elseif ($sellByLine === 1) {
                if ($lineQty === null || $lineQty <= 0) {
                    throw ValidationException::withMessages([
                        'duplicate_line_qty' => ['กรุณากรอก Qty ระบุเส้น'],
                    ]);
                }

                $qtyKg = $this->calcKgFromLineQty($partsId, (float) $lineQty);
                if ($qtyKg === null || $qtyKg <= 0) {
                    throw ValidationException::withMessages([
                        'duplicate_line_qty' => ['ไม่สามารถคำนวณ KG จากจำนวนเส้นได้ กรุณาตรวจสอบ Part / ref_unit / ref_unit_qty'],
                    ]);
                }
            } else {
                $qtyKg = $this->toNumberOrNull($data['duplicate_qty'] ?? null);
                if ($qtyKg === null || $qtyKg <= 0) {
                    throw ValidationException::withMessages([
                        'duplicate_qty' => ['กรุณากรอกจำนวน KG'],
                    ]);
                }
            }

            $payload['due_date'] = $originalDueDate;
            $payload['ship_posted_at'] = $newShipDate;
            $payload['window_at'] = $newWindowAt;
            $payload['mfg_no'] = trim((string) ($data['duplicate_mfg_no'] ?? ''));
            $payload['qty'] = $qtyKg;
            $payload['address'] = trim((string) ($data['duplicate_address'] ?? ''));
            $payload['tel'] = trim((string) ($data['duplicate_tel'] ?? ''));
            $payload['remark'] = trim((string) ($data['duplicate_remark'] ?? ''));
            $payload['attach_docs'] = $attachDocs;
            $payload['attach_docs_other'] = $docsOtherText;
            $payload['has_doc'] = $hasDoc;
            $payload['sell_by_line'] = $sellByLine;
            $payload['line_qty'] = $lineQty;
            $payload['status'] = 'NEW';
            $payload['revision_number'] = (int) ($data['duplicate_revision_number'] ?? ($row->revision_number ?? 0));
            $payload['created_at'] = DB::raw('GETDATE()');
            $payload['created_by'] = $createdBy;
            $payload['revise_by'] = null;
            $payload['edit_remark'] = null;
            $payload['remark_void'] = null;

            $newOrdId = $conn->table('delivery_plan_data')->insertGetId($payload, 'ord_id');
        });

        $successSoText = $successSoNumber !== '' ? $successSoNumber : 'รายการนี้';
        $redirect = redirect($data['return_url'] ?? url()->previous())
            ->with('success', 'Duplicate Sales Order ' . $successSoText . ' เรียบร้อย');

        if ($request->boolean('duplicate_continue_same_plan')) {
            $redirect->with('dp_duplicate_continue', [
                'ord_id' => $ordId,
                'ship_date' => $newShipDate->toDateString(),
                'window_time' => (string) $data['duplicate_window_time'],
                'sell_by_line' => (string) ($data['duplicate_sell_by_line'] ?? '0'),
                'address' => trim((string) ($data['duplicate_address'] ?? '')),
                'tel' => trim((string) ($data['duplicate_tel'] ?? '')),
                'remark' => trim((string) ($data['duplicate_remark'] ?? '')),
                'attach_docs' => $attachDocs,
                'attach_docs_other' => $docsOtherText,
                'revision_number' => (int) ($data['duplicate_revision_number'] ?? 0),
            ]);
        }

        return $redirect;
    }

    public function unassignTruck(Request $request, $ordId)
    {
        $request->validate([
            'remark_unassign' => ['required', 'string', 'max:500'],
        ], [
            'remark_unassign.required' => 'กรุณากรอกเหตุผลยกเลิกรถ',
        ]);

        $conn = $this->conn();
        $ordId = (int) $ordId;
        $successSoNumber = '';

        $conn->transaction(function () use ($conn, $request, $ordId, &$successSoNumber) {
            $row = $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->select(['ord_id', 'status', 'so_number'])
                ->first();

            if (!$row) {
                throw ValidationException::withMessages([
                    'ord_id' => ["ไม่พบรายการ ord_id={$ordId}"],
                ]);
            }

            $statusUpper = strtoupper((string) ($row->status ?? ''));

            if (in_array($statusUpper, ['VOID', 'CLOSED'], true)) {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ไม่สามารถยกเลิกรถได้ (สถานะ {$statusUpper})"],
                ]);
            }

            $successSoNumber = trim((string) ($row->so_number ?? ''));

            $hasAssign = $conn->table('delivery_plan_truck_assign')
                ->where('ord_id', $ordId)
                ->exists();

            if (!$hasAssign) {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ยังไม่ได้เลือกรถ"],
                ]);
            }

            $hasClosedTrip = $conn->table('delivery_plan_truck_assign')
                ->where('ord_id', $ordId)
                ->whereNotNull('closed_at')
                ->exists();

            if ($hasClosedTrip) {
                throw ValidationException::withMessages([
                    'ord_id' => ["รายการ ord_id={$ordId} ปิดเที่ยวรถแล้ว ไม่สามารถยกเลิกรถได้"],
                ]);
            }

            $reason = trim((string) $request->input('remark_unassign'));
            $userId = auth()->check() ? (int) auth()->id() : null;

            $conn->table('delivery_plan_truck_assign')
                ->where('ord_id', $ordId)
                ->delete();

            $conn->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->whereNotIn('status', ['VOID', 'CLOSED'])
                ->update([
                    'status'          => 'NEW',
                    'revise_by'       => $userId,
                    'edit_remark'     => 'UNASSIGN: ' . $reason,
                    'revision_number' => DB::raw('ISNULL(revision_number,0) + 1'),
                ]);
        });

        $successSoText = $successSoNumber !== '' ? $successSoNumber : 'รายการนี้';
        return back()->with('success', 'ยกเลิกรถของ Sales Order ' . $successSoText . ' เรียบร้อย');
    }

    public function historyDb(int $ord_id)
    {
        $sql = "
            WITH x AS (
                SELECT
                    ord_id, customer_id, customer_name, sales_id, so_number, delivery_type,
                    part_number, part_desc, mfg_no,
                    qty, stock_qty, address,
                    due_date, window_at, window_text, ship_posted_at, due_date_remark,
                    attach_docs, attach_docs_other, tel, remark, edit_remark,
                    status, revision_number, revise_by, remark_void,
                    created_at,
                    created_at AS SysStartTime,
                    CAST('9999-12-31 23:59:59' AS datetime) AS SysEndTime
                FROM dbo.delivery_plan_data
                WHERE ord_id = ?
            )
            SELECT
                x.*,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(x.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                c.customernumber,
                s.sales_name,
                s.sales_code,
                rv.color_code,
                u.user_code AS revise_user_code,
                u.name      AS revise_user_name
            FROM x
            LEFT JOIN dbo.customer        c  ON c.id = x.customer_id
            LEFT JOIN dbo.sales_master    s  ON s.sales_id = x.sales_id
            LEFT JOIN dbo.revision_master rv ON rv.revision_number = x.revision_number
            LEFT JOIN dbo.users           u  ON u.id = x.revise_by
            ORDER BY x.created_at DESC;
        ";

        $rows = $this->conn()->select($sql, [$ord_id]);

        return response()->json([
            'ord_id' => $ord_id,
            'rows'   => array_map(fn($r) => (array) $r, $rows),
        ]);
    }

    private function divisionKeywordMap(): array
    {
        return [
            'ภควดี'     => 'D3',
            'ธนัชชา'    => 'D3',

            'ธัธลิญา'   => 'D5',
            'เฌอร์ลิญา'   => 'D5',

            'สุรศักดิ์' => 'D6',
            'คณัญญ์นิชา' => 'D6',

            'ศิรินภา'    => 'D7',
            'มนพัทธ์'    => 'D7',

            'สาธิต'      => 'D8',
            'สุธาสินี'   => 'D8',

            'วรเดชา'     => 'D9',
            'ลัดดาวัลย์' => 'D9',

            'ดิลก'       => 'D1',
            'ขวัญเรือน'  => 'D1',

            'ปรียาพรรณ' => 'D2',
            'นิตยา'      => 'D2',

            'กันยกร'     => 'PLN',
            'วางแผน'     => 'PLN',
        ];
    }

    private function divisionLabels(): array
    {
        return [
            'D1'  => 'D1 - ดิลก + ขวัญเรือน',
            'D2'  => 'D2 - ปรียาพรรณ + นิตยา',
            'D3'  => 'D3 - ภควดี + ธนัชชา',
            'D5'  => 'D5 - ธัธลิญา + เฌอร์ลิญา',
            'D6'  => 'D6 - สุรศักดิ์ + คณัญญ์นิชา',
            'D7'  => 'D7 - ศิรินภา + มนพัทธ์',
            'D8'  => 'D8 - สาธิต + สุธาสินี',
            'D9'  => 'D9 - วรเดชา + ลัดดาวัลย์',
            'PLN' => 'วางแผน - กันยกร',
            'EXPORT' => 'Export - งานพิเศษ',
        ];
    }

    private function divisionOrder(): array
    {
        return ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D8', 'D9', 'PLN', 'EXPORT'];
    }

    private function revisionColorMap(): array
    {
        $rows = $this->conn()->table('revision_master')
            ->select('revision_number', 'color_code')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $rev   = (int) ($r->revision_number ?? 0);
            $color = trim((string) ($r->color_code ?? ''));
            if ($color === '') continue;
            if (strtolower($color) === 'sky') $color = 'deepskyblue';
            $map[$rev] = $color;
        }

        $map[3] = '#ff008c';

        return $map;
    }

    private function deliveryPlanMailSlot(int $revision): array
    {
        return match (true) {
            $revision === 0 => [
                'code' => 'INITIAL',
                'text' => 'แจ้งแผนส่งมอบ',
                'badge' => 'ปกติ',
                'time' => '16:00 PM',
            ],
            $revision === 1 => [
                'code' => 'AUTO_10',
                'text' => 'แจ้งแก้ไขแผนส่งมอบ เพิ่มเติม 1',
                'badge' => 'เพิ่มเติม 1',
                'time' => '10:00 AM',
            ],
            $revision === 2 => [
                'code' => 'AUTO_13',
                'text' => 'แจ้งแก้ไขแผนส่งมอบ เพิ่มเติม 2',
                'badge' => 'เพิ่มเติม 2',
                'time' => '13:00 PM',
            ],
            $revision === 3 => [
                'code' => 'AUTO_16',
                'text' => 'แจ้งแก้ไขแผนส่งมอบ เพิ่มเติม 3',
                'badge' => 'เพิ่มเติม 3',
                'time' => '16:00 PM',
            ],
            $revision >= 4 && $revision <= 6 => [
                'code' => 'SALE_CO',
                'text' => "แจ้งแก้ไขแผนส่งมอบ เพิ่มเติม {$revision}",
                'badge' => "เพิ่มเติม {$revision}",
                'time' => 'ส่งเอง',
            ],
            default => [
                'code' => 'MANUAL',
                'text' => "แจ้งปรับปรุงแผนส่งมอบ Revision {$revision}",
                'badge' => "เพิ่มเติม {$revision}",
                'time' => now()->format('H:i A'),
            ],
        };
    }

    protected function divisionSearchMap(): array
    {
        return [
            'D1'  => ['D1', 'ดิลก', 'ขวัญเรือน'],
            'D2'  => ['D2', 'ปรียาพรรณ', 'นิตยา'],
            'D3'  => ['D3', 'ภควดี', 'ธนัชชา'],
            'D5'  => ['D5', 'ธัธลิญา', 'เฌอร์ลิญา'],
            'D6'  => ['D6', 'สุรศักดิ์', 'คณัญญ์นิชา'],
            'D7'  => ['D7', 'ศิรินภา', 'มนพัทธ์'],
            'D8'  => ['D8', 'สาธิต', 'สุธาสินี'],
            'D9'  => ['D9', 'วรเดชา', 'ลัดดาวัลย์'],
            'PLN' => ['PLN', 'วางแผน', 'กันยกร'],
            'EXPORT' => ['EXPORT', 'Export', 'งานพิเศษ', 'ส่งซ่อม', 'ส่งคืน'],
        ];
    }

    private function detectDivision(string $salesName): string
    {
        $salesName = trim($salesName);

        foreach ($this->divisionKeywordMap() as $needle => $div) {
            if ($needle !== '' && mb_stripos($salesName, $needle) !== false) {
                return $div;
            }
        }

        if (preg_match('/\bD[0-9]\b/i', $salesName, $m)) {
            return strtoupper($m[0]);
        }

        return $salesName !== '' ? $salesName : 'UNKNOWN';
    }

    protected function expandDivisionSearchTokens(string $keyword): array
    {
        $tokens = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        $map = $this->divisionSearchMap();

        $expanded = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            // ข้าม token ที่เป็นตัวเชื่อมล้วนๆ เช่น "-", "+", "/" ที่มาจาก label
            // เช่น "D2 - ปรียาพรรณ + นิตยา" — ไม่งั้นจะถูก AND บังคับให้ sales_name
            // ต้องมี "-" และ "+" อยู่ในชื่อด้วย ทำให้ไม่เจอข้อมูลทั้งที่มี
            if (!preg_match('/[\p{L}\p{N}]/u', $token)) {
                continue;
            }

            $matched = false;

            foreach ($map as $groupKeywords) {
                if (collect($groupKeywords)->contains(fn($v) => mb_strtolower($v) === mb_strtolower($token))) {
                    $expanded[] = $groupKeywords;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $expanded[] = [$token];
            }
        }

        return $expanded;
    }

    private function buildDivisionGroups(array $groups): array
    {
        $divGroups = [];

        foreach ($groups as $salesName => $items) {
            $div = $this->detectDivision((string) $salesName);

            foreach ($items ?? [] as $r) {
                $mode = strtoupper(trim((string) ($r->delivery_type ?? '')));
                $key = match ($mode) {
                    'ACID' => 'PLN',
                    'SPECIAL' => 'EXPORT',
                    default => $div,
                };
                $divGroups[$key] = $divGroups[$key] ?? [];
                $divGroups[$key][] = $r;
            }
        }

        $order = $this->divisionOrder();

        uksort($divGroups, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib ?: strcmp((string) $a, (string) $b);
        });

        return $divGroups;
    }

    protected function applyDivisionSalesFilter($q, string $divsales): void
    {
        $groups = $this->expandDivisionSearchTokens($divsales);

        if (empty($groups)) {
            return;
        }

        // บางกลุ่มในตารางไม่ได้มาจาก sales_name ของ employee จริง แต่มาจาก delivery_type:
        //   PLN (วางแผน - กันยกร) = delivery_type 'ACID'
        //   EXPORT (งานพิเศษ)      = delivery_type 'SPECIAL'
        // ต้องกรองด้วย delivery_type แทนการ match ชื่อ sales
        $matchesGroupKeywords = function (string $divKey) use ($groups): bool {
            $keywords = collect($this->divisionSearchMap()[$divKey] ?? [])
                ->map(fn($v) => mb_strtolower(trim((string) $v)))
                ->filter()
                ->all();

            return collect($groups)->contains(function ($group) use ($keywords) {
                foreach ($group as $keyword) {
                    if (in_array(mb_strtolower(trim((string) $keyword)), $keywords, true)) {
                        return true;
                    }
                }
                return false;
            });
        };

        if ($matchesGroupKeywords('PLN')) {
            $q->where('d.delivery_type', 'ACID');
            return;
        }

        if ($matchesGroupKeywords('EXPORT')) {
            $q->where('d.delivery_type', 'SPECIAL');
            return;
        }

        // เมื่อ filter เป็นชื่อ Division ปกติ — ตัด delivery_type domain อื่น (ACID/SPECIAL) ออก
        // เพราะ ACID เป็น domain ของ planner และ SPECIAL เป็น domain ของ export (มี group แยกเสมอ)
        $q->where(function ($w) {
            $w->whereNotIn('d.delivery_type', ['ACID', 'SPECIAL'])
                ->orWhereNull('d.delivery_type');
        });

        $q->where(function ($outer) use ($groups) {
            foreach ($groups as $group) {
                $outer->where(function ($w) use ($group) {
                    foreach ($group as $keyword) {
                        $kw = '%' . $keyword . '%';

                        $w->orWhere('s.sales_name', 'like', $kw)
                            ->orWhere('s.sales_code', 'like', $kw)
                            ->orWhere('e.login', 'like', $kw)
                            ->orWhere('e.name', 'like', $kw);
                    }
                });
            }
        });
    }

    private function resolveShipDateRange(Request $request): array
    {
        $shipFrom = trim((string) $request->query('ship_from', ''));
        $shipTo   = trim((string) $request->query('ship_to', ''));

        if ($shipFrom === '' && $shipTo === '') {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        $from = $this->normalizeDateInput($shipFrom);
        $to   = $this->normalizeDateInput($shipTo);

        if ($from === null || $to === null) {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        return [$from, $to];
    }

    private function normalizeDateInput(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeCarbonDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dateOnly($value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function docMap(): array
    {
        $map = $this->conn()
            ->table('attach_docs_master')
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [
                strtoupper(trim((string) $code)) => trim((string) $name),
            ])
            ->all();

        return array_merge([
            'DO' => 'Delivery sheet (ลูกค้า)',
        ], $map);
    }

    private function attachInquiryDisplayText($rows, array $docMap): void
    {
        foreach ($rows->items() as $row) {
            $other = trim((string) ($row->attach_docs_other ?? ''));
            $docNames = collect($this->normalizeDocCodes((string) ($row->attach_docs ?? '')))
                ->map(function ($code) use ($docMap, $other) {
                    $name = $docMap[$code] ?? $code;
                    return $code === 'OTHER' && $other !== '' ? $name . ': ' . $other : $name;
                })
                ->filter(fn($name) => trim((string) $name) !== '')
                ->values();

            $row->attach_docs_text = $docNames->implode(', ');
            $row->more_text = collect([
                $row->remark ?? null,
                $row->edit_remark ?? null,
            ])
                ->map(fn($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->implode(' | ');
        }
    }

    private function normalizeDocCodes(?string $value): array
    {
        return array_values(array_filter(array_map(
            fn($x) => strtoupper(trim((string) $x)),
            explode(',', (string) $value)
        )));
    }

    private function resolveReturnUrl(Request $request): string
    {
        $fallback = route('dp.inquiry');
        $returnUrl = trim((string) $request->input('return_url', ''));

        if ($returnUrl === '') {
            return $fallback;
        }

        $allowedBases = [
            rtrim(route('dp.inquiry'), '/'),
            rtrim(route('dp.dashboard.logistics-summary'), '/'),
            rtrim(route('dp.dashboard.truck-board'), '/'),
        ];
        $appUrl = rtrim((string) config('app.url'), '/');

        foreach ($allowedBases as $allowedBase) {
            if (str_starts_with($returnUrl, $allowedBase)) {
                return $returnUrl;
            }
        }

        if ($appUrl !== '' && str_starts_with($returnUrl, $appUrl)) {
            $path = parse_url($returnUrl, PHP_URL_PATH) ?? '';

            foreach ($allowedBases as $allowedBase) {
                $allowedPath = parse_url($allowedBase, PHP_URL_PATH) ?? '';
                if ($path !== '' && $allowedPath !== '' && str_starts_with($path, $allowedPath)) {
                    return $returnUrl;
                }
            }
        }

        return $fallback;
    }

    public function sendPlanMail(Request $request)
    {
        $user = auth()->user();
        $canSendMail = auth()->check()
            && (($user->is_superadmin ?? 0) == 1
                || (method_exists($user, 'hasRoleCode') && $user->hasRoleCode(['DPEMAIL', 'DPMAIL'])));

        if (!$canSendMail) {
            abort(403);
        }
        $validated = $request->validate([
            'ship_posted_at'  => ['required', 'date'],
            'revision_number' => ['required', 'integer', 'min:0'],
            'remark'          => ['nullable', 'string', 'max:500'],
            'force_send'      => ['nullable', 'in:0,1'],
        ], [
            'ship_posted_at.required'  => 'กรุณาระบุวันที่ส่งสินค้า',
            'revision_number.required' => 'กรุณาระบุ revision',
        ]);

        $shipDate  = Carbon::parse($validated['ship_posted_at'])->toDateString();
        $revision  = (int) $validated['revision_number'];
        $forceSend = (string) ($validated['force_send'] ?? '0') === '1';
        $isManualRevision = false;

        $to = collect(config('mail.delivery_plan.to', []))
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        $cc = collect(config('mail.delivery_plan.cc', []))
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        if (empty($to)) {
            return back()->withErrors([
                'mail' => 'ยังไม่ได้ตั้งค่า DP_MAIL_TO ใน .env หรือรูปแบบอีเมลไม่ถูกต้อง'
            ])->withInput();
        }

        $rows = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->selectRaw("
                d.ord_id,
                d.so_number,
                d.part_number,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(d.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                d.part_desc,
                d.mfg_no,
                d.qty,
                d.remark,
                d.address,
                d.delivery_type,
                d.due_date_remark,
                d.sell_by_line,
                d.line_qty,
                d.ship_posted_at,
                d.revision_number,
                p.onhand as stock_qty,
                p.f3 as part_type,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) AS sales_name,
                d.edit_remark,
                d.attach_docs,
                d.attach_docs_other
            ")
            ->whereRaw("CAST(d.ship_posted_at AS date) = ?", [$shipDate])
            ->when(
                $isManualRevision,
                fn($q) => $q->where('d.revision_number', '<=', $revision),
                fn($q) => $q->where('d.revision_number', $revision)
            )
            ->whereRaw("ISNULL(d.status,'') NOT IN ('VOID','CANCEL')")
            ->orderBy('d.sales_id')
            ->orderBy('c.name')
            ->orderBy('d.delivery_type')
            ->orderBy('d.part_number')
            ->get();


        if ($rows->isEmpty()) {
            return back()->withErrors([
                'mail' => "ไม่พบข้อมูลสำหรับวันที่ {$shipDate} และ revision {$revision}"
            ])->withInput();
        }

        $this->applyTrackingStockFgToRows($rows, 'stock_qty');

        $docMaster = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)]);

        $mfgTokens = collect($rows)
            ->pluck('mfg_no')
            ->filter()
            ->flatMap(function ($mfg) {
                return collect(preg_split('/\s*,\s*/', (string) $mfg))
                    ->map(fn($x) => trim((string) $x))
                    ->filter();
            })
            ->unique()
            ->values();

        $mfgNosByConnection = [
            'pgsqlmfgw' => collect(),
            'pgsqlmfgp' => collect(),
        ];

        foreach ($mfgTokens as $token) {
            $connection = str_starts_with((string) $token, '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
            $workorderNumber = (string) $token;

            if ($workorderNumber !== '') {
                $mfgNosByConnection[$connection]->push($workorderNumber);
            }
        }

        $packageMaps = [
            'pgsqlmfgw' => collect(),
            'pgsqlmfgp' => collect(),
        ];

        foreach ($mfgNosByConnection as $connection => $mfgNos) {
            $mfgNos = $mfgNos->unique()->values();

            if ($mfgNos->isNotEmpty()) {
                $packageMaps[$connection] = DB::connection($connection)
                    ->table('workorder')
                    ->select('workordernumber', 'fcat')
                    ->whereIn('workordernumber', $mfgNos->all())
                    ->get()
                    ->pluck('fcat', 'workordernumber');
            }
        }

        $resolvePackage = function (?string $mfgNo) use ($packageMaps) {
            if (blank($mfgNo)) {
                return '-';
            }

            $packs = collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->map(function ($token) use ($packageMaps) {
                    $connection = str_starts_with((string) $token, '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
                    $workorderNumber = (string) $token;

                    return trim((string) ($packageMaps[$connection]->get($workorderNumber) ?? ''));
                })
                ->filter()
                ->unique()
                ->values();

            return $packs->isNotEmpty() ? $packs->implode(', ') : '-';
        };

        $normalizeMfgDisplay = function (?string $mfgNo) {
            if (blank($mfgNo)) {
                return '-';
            }

            return collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->unique()
                ->implode(', ');
        };

        $u = auth()->user();

        $mailRows = $rows->values()->map(function ($r, $index) {
            $sellByLine = (int) ($r->sell_by_line ?? 0) === 1;
            $lineQty    = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : 0.0;
            $qty        = (float) ($r->qty ?? 0);

            if ($sellByLine && $lineQty > 0) {
                $isPieceQty = $qty == 0.0;
                $qtyDisplay = ($isPieceQty ? 'ชิ้น : ' : 'ระบุเส้น : ') . number_format($lineQty, 0);
            } else {
                $qtyDisplay = number_format($qty, 3);
            }

            return [
                'no'          => $index + 1,
                'so_number'   => $r->so_number,
                'customer'    => $r->customer_name,
                'part_desc'   => $r->part_desc,
                'mfg_no'      => $r->mfg_no,
                'qty'         => $r->qty,
                'qty_display' => $qtyDisplay,
                'remark'      => $r->remark,
                'address'     => $r->address,
            ];
        })->all();

        $groups = $rows
            ->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))
            ->all();

        $divLabel  = $this->divisionLabels();
        $divGroups = $this->buildDivisionGroups($groups);

        $pdfRows = [];

        $resolveAttachDocs = function ($attachDocs, $attachDocsOther) use ($docMaster) {
            $other = trim((string) ($attachDocsOther ?? ''));
            $codes = collect(preg_split('/\s*,\s*/', (string) $attachDocs))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->when($other !== '', fn($items) => $items->reject(fn($code) => $code === 'OTHER'))
                ->unique();

            $names = $codes->map(function ($code) use ($docMaster) {
                return $docMaster[$code] ?? $code; // ถ้าไม่มีใน master ใช้ code เดิม
            });

            if ($other !== '') {
                $names->push('อื่น ๆ: ' . $other);
            }

            $names = $names->unique()->values();

            return $names->isNotEmpty() ? $names->implode(', ') : '-';
        };

        foreach ($divGroups as $divCode => $items) {
            $groupTitle = $divLabel[$divCode] ?? $divCode;
            $itemNo = 0;

            $pdfRows[] = [
                'row_type'   => 'group',
                'group_code' => $divCode,
                'group_name' => $groupTitle,
            ];

            foreach ($items as $r) {
                $itemNo++;
                $isPieceQty = ((int) ($r->sell_by_line ?? 0) === 1)
                    && is_numeric($r->line_qty ?? null)
                    && (float) $r->line_qty > 0
                    && (float) ($r->qty ?? 0) == 0.0;

                $pdfRows[] = [
                    'row_type'        => 'item',
                    'item_no'         => $itemNo,
                    'revision_number' => $isManualRevision ? $revision : (int) ($r->revision_number ?? 0),
                    'customer'        => $r->customer_name ?? '-',
                    'package'         => $resolvePackage($r->mfg_no),
                    'type'            => filled($r->part_type ?? null) ? $r->part_type : '-',
                    'size_length'     => $r->part_desc ?? '-',
                    'mfg_no'          => $normalizeMfgDisplay($r->mfg_no),
                    'pieces'          => ((int) ($r->sell_by_line ?? 0) === 1 && is_numeric($r->line_qty ?? null) && (float) $r->line_qty > 0)
                        ? ($isPieceQty ? 'ชิ้น : ' : 'ระบุเส้น : ') . number_format((float) $r->line_qty, 0)
                        : '-',
                    'sales_qty'       => (float) ($r->qty ?? 0),
                    'stock_qty'       => (float) ($r->stock_qty ?? 0),
                    'production_qty'  => (float) ($r->qty ?? 0),
                    'logistics_qty'   => 0,
                    'logistics_note'  => $r->due_date_remark ?? '',
                    'priority'        => '',
                    'delivery_place'  => $r->address ?? '-',
                    'oe_no'           => $r->so_number ?? '-',
                    'problem_note'    => $revision > 0
                        ? ($r->edit_remark ?? '-')
                        : ($r->remark ?? '-'),
                    'action_plan'     => '',
                    'attach_docs'     => $resolveAttachDocs(
                        $r->attach_docs ?? null,
                        $r->attach_docs_other ?? null
                    ),
                ];
            }
        }

        $shipDateText = Carbon::parse($shipDate)->format('d/m/Y');
        $shipDateTitleText = Carbon::parse($shipDate)->locale('th')->translatedFormat('j F Y');

        $mailSlot = $this->deliveryPlanMailSlot($revision);
        $mailTypeText = $mailSlot['text'];
        $mailTypeCode = $mailSlot['code'];
        $revisionBadgeText = $mailSlot['badge'];
        $revisionBadgeTime = $mailSlot['time'];

        $subject = "[Delivery Plan] {$mailTypeText} วันที่ {$shipDateTitleText}";

        $existingLog = $this->conn()
            ->table('delivery_plan_mail_logs')
            ->whereRaw("CAST(ship_posted_at AS date) = ?", [$shipDate])
            ->where('revision_number', $revision)
            ->exists();

        if ($existingLog && !$forceSend) {
            return back()->withErrors([
                'mail' => "เคยส่งเมลวันที่ {$shipDate} revision {$revision} แล้ว หากต้องการส่งซ้ำกรุณาติ๊ก Force Send"
            ])->withInput();
        }

        $inquiryUrl = url('/dp/inquiry') . '?' . http_build_query([
            'ship_from'  => $shipDate,
            'ship_to'  => $shipDate,
            'revision_number' => $revision,
        ]);

        $mailData = [
            'subject' => $subject,
            'shipDateText' => $shipDateTitleText,
            'shipDateFile' => now()->format('Ymd_His'),
            'mailTypeText' => $mailTypeText,
            'total'        => count($mailRows),
            'rows'         => $mailRows,
            'inquiryUrl'   => $inquiryUrl,
            'mailRemark'   => $validated['remark'] ?? null,
        ];

        $pdfData = [
            'shipDateText'       => $shipDateText,
            'shipDateThaiText'   => Carbon::parse($shipDate)->locale('th')->translatedFormat('j F Y'),
            'shipDateFile'       => now()->format('Ymd_His'),
            'monthText'          => Carbon::parse($shipDate)->locale('th')->translatedFormat('F Y'),
            'targetText'         => '900 ตัน/เดือน',
            'preparedBy'         => '....................',
            'productionConfirm'  => '....................',
            'docReceiver'        => '....................',
            'footerCode'         => 'MK-03',
            'sumSalesQty'        => collect($pdfRows)->where('row_type', 'item')->sum('sales_qty'),
            'sumStockQty'        => collect($pdfRows)->where('row_type', 'item')->sum('stock_qty'),
            'sumProductionQty'   => collect($pdfRows)->where('row_type', 'item')->sum('production_qty'),
            'sumLogisticsQty'    => collect($pdfRows)->where('row_type', 'item')->sum('logistics_qty'),
            'pdfRows'            => $pdfRows,
            'totalBodyRows'      => count($pdfRows) + 1,
            'revisionBadgeText'  => $revisionBadgeText,
            'revisionBadgeTime'  => $revisionBadgeTime,
        ];

        $excelContent = Excel::raw(new InquiryByShipDateExport([
            'ship_from' => $shipDate,
            'ship_to' => $shipDate,
            'status' => 'ALL',
            $isManualRevision ? 'revision_max_number' : 'revision_number' => $revision,
            'display_revision_number' => $revision,
            'exclude_void_cancel' => 1,
        ]), \Maatwebsite\Excel\Excel::XLSX);

        $excelData = [
            'content' => $excelContent,
            'filename' => 'DeliveryPlan-' . ($pdfData['shipDateFile'] ?? now()->format('Ymd_His')) . '.xlsx',
        ];

        $mail = new DeliveryPlanMail($mailData, $pdfData, $excelData);

        try {
            $mailer = Mail::to($to);

            if (!empty($cc)) {
                $mailer->cc($cc);
            }

            $mailer->send($mail);

            /*if ($isManualRevision) {
                $this->conn()
                    ->table('delivery_plan_data')
                    ->whereRaw("CAST(ship_posted_at AS date) = ?", [$shipDate])
                    ->whereRaw("ISNULL(status,'') NOT IN ('VOID','CANCEL')")
                    ->where('revision_number', '<', $revision)
                    ->update([
                        'revision_number' => $revision,
                        'revise_by'        => $u->id ?? null,
                    ]);
            }*/

            $this->conn()->table('delivery_plan_mail_logs')->insert([
                'ship_posted_at'  => $shipDate,
                'revision_number' => $revision,
                'mail_type'       => $mailTypeCode,
                'to_emails'       => implode(',', $to),
                'cc_emails'       => !empty($cc) ? implode(',', $cc) : null,
                'total_items'     => count($mailRows),
                'sent_at'         => now(),
                'sent_by'         => $u->id ?? null,
                'sent_by_name'    => $u->name ?? $u->email ?? null,
                'subject'         => $subject,
                'remark'          => $validated['remark'] ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            return back()->with('success', "ส่งเมลเรียบร้อยแล้ว ({$shipDate} / revision {$revision})");
        } catch (\Throwable $e) {
            report($e);
            //dd($e);
            return back()->withErrors([
                'mail' => 'ส่งเมลไม่สำเร็จ กรุณาตรวจสอบการตั้งค่าเมลหรือสอบถามผู้ดูแลระบบ'

            ])->withInput();
        }
    }

    public function downloadPlanPdf(Request $request)
    {
        $validated = $request->validate([
            'ship_posted_at'  => ['required', 'date'],
            'revision_number' => ['required', 'integer', 'min:0'],
        ], [
            'ship_posted_at.required'  => 'กรุณาระบุวันที่ส่งสินค้า',
            'revision_number.required' => 'กรุณาระบุ revision',
        ]);

        $shipDate = Carbon::parse($validated['ship_posted_at'])->toDateString();
        $revision = (int) $validated['revision_number'];
        $pdfData = $this->buildDeliveryPlanPdfData($shipDate, $revision);

        $pdf = Pdf::loadView('pdf.delivery-plan-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        return $pdf->download('DeliveryPlan-' . Carbon::parse($shipDate)->format('Ymd') . '-R' . $revision . '.pdf');
    }

    private function buildDeliveryPlanPdfData(string $shipDate, int $revision): array
    {
        $isManualRevision = false;

        $rows = $this->conn()
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->selectRaw("
                d.ord_id,
                d.so_number,
                d.part_number,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(d.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                d.part_desc,
                d.mfg_no,
                d.qty,
                d.remark,
                d.address,
                d.delivery_type,
                d.due_date_remark,
                d.sell_by_line,
                d.line_qty,
                d.ship_posted_at,
                d.revision_number,
                p.onhand as stock_qty,
                p.f3 as part_type,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) AS sales_name,
                d.edit_remark,
                d.attach_docs,
                d.attach_docs_other
            ")
            ->whereRaw("CAST(d.ship_posted_at AS date) = ?", [$shipDate])
            ->when(
                $isManualRevision,
                fn($q) => $q->where('d.revision_number', '<=', $revision),
                fn($q) => $q->where('d.revision_number', $revision)
            )
            ->whereRaw("ISNULL(d.status,'') NOT IN ('VOID','CANCEL')")
            ->orderBy('d.sales_id')
            ->orderBy('c.name')
            ->orderBy('d.delivery_type')
            ->orderBy('d.part_number')
            ->get();

        abort_if($rows->isEmpty(), 404, "Delivery Plan PDF data not found for {$shipDate} revision {$revision}");

        $this->applyTrackingStockFgToRows($rows, 'stock_qty');

        $docMaster = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)]);

        $mfgTokens = collect($rows)
            ->pluck('mfg_no')
            ->filter()
            ->flatMap(function ($mfg) {
                return collect(preg_split('/\s*,\s*/', (string) $mfg))
                    ->map(fn($x) => trim((string) $x))
                    ->filter();
            })
            ->unique()
            ->values();

        $mfgNosByConnection = [
            'pgsqlmfgw' => collect(),
            'pgsqlmfgp' => collect(),
        ];

        foreach ($mfgTokens as $token) {
            $connection = str_starts_with((string) $token, '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
            $workorderNumber = (string) $token;

            if ($workorderNumber !== '') {
                $mfgNosByConnection[$connection]->push($workorderNumber);
            }
        }

        $packageMaps = [
            'pgsqlmfgw' => collect(),
            'pgsqlmfgp' => collect(),
        ];

        foreach ($mfgNosByConnection as $connection => $mfgNos) {
            $mfgNos = $mfgNos->unique()->values();

            if ($mfgNos->isNotEmpty()) {
                $packageMaps[$connection] = DB::connection($connection)
                    ->table('workorder')
                    ->select('workordernumber', 'fcat')
                    ->whereIn('workordernumber', $mfgNos->all())
                    ->get()
                    ->pluck('fcat', 'workordernumber');
            }
        }

        $resolvePackage = function (?string $mfgNo) use ($packageMaps) {
            if (blank($mfgNo)) {
                return '-';
            }

            $packs = collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->map(function ($token) use ($packageMaps) {
                    $connection = str_starts_with((string) $token, '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
                    $workorderNumber = (string) $token;

                    return trim((string) ($packageMaps[$connection]->get($workorderNumber) ?? ''));
                })
                ->filter()
                ->unique()
                ->values();

            return $packs->isNotEmpty() ? $packs->implode(', ') : '-';
        };

        $normalizeMfgDisplay = function (?string $mfgNo) {
            if (blank($mfgNo)) {
                return '-';
            }

            return collect(preg_split('/\s*,\s*/', (string) $mfgNo))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->unique()
                ->implode(', ');
        };

        $resolveAttachDocs = function ($attachDocs, $attachDocsOther) use ($docMaster) {
            $other = trim((string) ($attachDocsOther ?? ''));
            $codes = collect(preg_split('/\s*,\s*/', (string) $attachDocs))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->when($other !== '', fn($items) => $items->reject(fn($code) => $code === 'OTHER'))
                ->unique();

            $names = $codes->map(function ($code) use ($docMaster) {
                return $docMaster[$code] ?? $code;
            });

            if ($other !== '') {
                $names->push('อื่น ๆ: ' . $other);
            }

            $names = $names->unique()->values();

            return $names->isNotEmpty() ? $names->implode(', ') : '-';
        };

        $groups = $rows
            ->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))
            ->all();

        $divLabel  = $this->divisionLabels();
        $divGroups = $this->buildDivisionGroups($groups);
        $pdfRows = [];

        foreach ($divGroups as $divCode => $items) {
            $groupTitle = $divLabel[$divCode] ?? $divCode;
            $itemNo = 0;

            $pdfRows[] = [
                'row_type'   => 'group',
                'group_code' => $divCode,
                'group_name' => $groupTitle,
            ];

            foreach ($items as $r) {
                $itemNo++;
                $isPieceQty = ((int) ($r->sell_by_line ?? 0) === 1)
                    && is_numeric($r->line_qty ?? null)
                    && (float) $r->line_qty > 0
                    && (float) ($r->qty ?? 0) == 0.0;

                $pdfRows[] = [
                    'row_type'        => 'item',
                    'item_no'         => $itemNo,
                    'revision_number' => $isManualRevision ? $revision : (int) ($r->revision_number ?? 0),
                    'customer'        => $r->customer_name ?? '-',
                    'package'         => $resolvePackage($r->mfg_no),
                    'type'            => filled($r->part_type ?? null) ? $r->part_type : '-',
                    'size_length'     => $r->part_desc ?? '-',
                    'mfg_no'          => $normalizeMfgDisplay($r->mfg_no),
                    'pieces'          => ((int) ($r->sell_by_line ?? 0) === 1 && is_numeric($r->line_qty ?? null) && (float) $r->line_qty > 0)
                        ? ($isPieceQty ? 'ชิ้น : ' : 'ระบุเส้น : ') . number_format((float) $r->line_qty, 0)
                        : '-',
                    'sales_qty'       => (float) ($r->qty ?? 0),
                    'stock_qty'       => (float) ($r->stock_qty ?? 0),
                    'production_qty'  => (float) ($r->qty ?? 0),
                    'logistics_qty'   => 0,
                    'logistics_note'  => $r->due_date_remark ?? '',
                    'priority'        => '',
                    'delivery_place'  => $r->address ?? '-',
                    'oe_no'           => $r->so_number ?? '-',
                    'problem_note'    => $revision > 0
                        ? ($r->edit_remark ?? '-')
                        : ($r->remark ?? '-'),
                    'action_plan'     => '',
                    'attach_docs'     => $resolveAttachDocs(
                        $r->attach_docs ?? null,
                        $r->attach_docs_other ?? null
                    ),
                ];
            }
        }

        $mailSlot = $this->deliveryPlanMailSlot($revision);

        return [
            'shipDateText'       => Carbon::parse($shipDate)->format('d/m/Y'),
            'shipDateThaiText'   => Carbon::parse($shipDate)->locale('th')->translatedFormat('j F Y'),
            'shipDateFile'       => now()->format('Ymd_His'),
            'monthText'          => Carbon::parse($shipDate)->locale('th')->translatedFormat('F Y'),
            'targetText'         => '900 ตัน/เดือน',
            'preparedBy'         => '....................',
            'productionConfirm'  => '....................',
            'docReceiver'        => '....................',
            'footerCode'         => 'MK-03',
            'sumSalesQty'        => collect($pdfRows)->where('row_type', 'item')->sum('sales_qty'),
            'sumStockQty'        => collect($pdfRows)->where('row_type', 'item')->sum('stock_qty'),
            'sumProductionQty'   => collect($pdfRows)->where('row_type', 'item')->sum('production_qty'),
            'sumLogisticsQty'    => collect($pdfRows)->where('row_type', 'item')->sum('logistics_qty'),
            'pdfRows'            => $pdfRows,
            'totalBodyRows'      => count($pdfRows) + 1,
            'revisionBadgeText'  => $mailSlot['badge'],
            'revisionBadgeTime'  => $mailSlot['time'],
        ];
    }


    public function loadingBoard(Request $request)
    {
        $today = now()->toDateString();

        $latestAssignSub = $this->conn()->table('delivery_plan_truck_assign as a1')
            ->selectRaw('MAX(a1.id) as id, a1.ord_id')
            ->groupBy('a1.ord_id');

        $rows = $this->conn()->table('delivery_plan_data as dp')
            ->leftJoinSub($latestAssignSub, 'la', function ($join) {
                $join->on('la.ord_id', '=', 'dp.ord_id');
            })
            ->leftJoin('delivery_plan_truck_assign as ta', 'ta.id', '=', 'la.id')
            ->leftJoin('delivery_plan_truck_master as mt', 'mt.id', '=', 'ta.truck_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw('p.partnumber COLLATE DATABASE_DEFAULT'),
                    '=',
                    DB::raw('dp.part_number COLLATE DATABASE_DEFAULT')
                );
            })
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->selectRaw("
            dp.ord_id,
            UPPER(ISNULL(dp.status, 'NEW')) as status,
            dp.ship_posted_at,
            dp.window_at,
            dp.so_number,
            dp.part_number,
            dp.part_desc,
            dp.qty,
            dp.remark,
            dp.address,
            dp.delivery_type,
            dp.attach_docs,
            dp.attach_docs_other,

            COALESCE(
                NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                c.name COLLATE DATABASE_DEFAULT
            ) as customer_name,
            ISNULL(p.onhand, 0) as stock_fg,
            ISNULL(ta.assigned_weight, 0) as assigned_weight,

            ta.truck_source,
            ta.truck_id,
            ta.manual_plate_no,
            ta.manual_driver_name,
            ta.manual_driver_phone,
            ta.driver_staff_id,
            ta.driver_name,
            ta.driver_phone,
            ta.helper1_staff_id,
            ta.helper1_name,
            ta.helper2_staff_id,
            ta.helper2_name,
            ta.helper3_staff_id,
            ta.helper3_name,
            ta.helper4_staff_id,
            ta.helper4_name,
            ta.helper5_staff_id,
            ta.helper5_name,

            ta.remark as truck_remark,
            CASE
                WHEN ta.truck_source = 'MANUAL' AND ISNULL(ta.manual_plate_no, '') <> ''
                    THEN ta.manual_plate_no
                WHEN ta.truck_source = 'MASTER' AND ISNULL(mt.plate_no, '') <> ''
                    THEN mt.plate_no
                WHEN ta.truck_source = 'MASTER' AND ta.truck_id IS NOT NULL
                    THEN CONCAT('TRUCK #', ta.truck_id)
                ELSE NULL
            END as truck_label
        ")
            ->whereDate('dp.ship_posted_at', $today)
            ->whereIn(DB::raw("UPPER(ISNULL(dp.status, 'NEW'))"), ['NEW', 'ASSIGN'])
            ->orderByRaw("
            CASE
                WHEN UPPER(ISNULL(dp.status, 'NEW')) = 'ASSIGN' THEN 0
                WHEN UPPER(ISNULL(dp.status, 'NEW')) = 'NEW' THEN 1
                ELSE 9
            END
        ")
            ->orderBy('dp.ship_posted_at', 'asc')
            ->orderBy('dp.window_text', 'asc')
            ->orderBy('dp.so_number', 'asc')
            ->get();

        $totalCount   = $rows->count();
        $assignCount  = $rows->where('status', 'ASSIGN')->count();
        $newCount     = $rows->where('status', 'NEW')->count();
        $totalQty     = $rows->sum(fn($r) => (float) ($r->qty ?? 0));
        $totalWeight  = $rows->sum(function ($r) {
            $assigned = (float) ($r->assigned_weight ?? 0);
            return $assigned > 0 ? $assigned : (float) ($r->qty ?? 0);
        });

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->toArray();

        return view('formdp.loading-board', [
            'rows'        => $rows,
            'today'       => $today,
            'totalCount'  => $totalCount,
            'assignCount' => $assignCount,
            'newCount'    => $newCount,
            'totalQty'    => $totalQty,
            'totalWeight' => $totalWeight,
            'attachDocMap' => $attachDocMap,
        ]);
    }

    private function getAssignableTrucksByShipDate(?string $shipDate, int $tripNo = 1): \Illuminate\Support\Collection
    {
        $shipDate = trim((string) $shipDate);
        if ($shipDate === '') {
            return collect();
        }
        $tripNo = max(1, $tripNo);

        $masterRows = $this->conn()
            ->table('delivery_plan_truck_master as tm')
            ->where('tm.status', 'ACTIVE')
            ->orderBy('tm.plate_no')
            ->get()
            ->map(function ($t) use ($tripNo) {
                return (object) [
                    'row_key' => 'MASTER:' . $t->id,
                    'truck_pick_type' => 'MASTER',
                    'truck_id' => (int) $t->id,
                    'manual_plate_no' => null,
                    'plate_no' => trim((string) ($t->plate_no ?? '')),
                    'driver_name' => trim((string) ($t->driver_name ?? '')),
                    'driver_phone' => trim((string) ($t->driver_phone ?? '')),
                    'max_load' => (float) ($t->max_load ?? 0),
                    'car_length' => $t->car_length,
                    'remark' => trim((string) ($t->remark ?? '')),
                    'current_load' => 0,
                    'remaining_capacity' => (float) ($t->max_load ?? 0),
                    'capacity_unlimited' => (float) ($t->max_load ?? 0) <= 0,
                    'trip_no' => $tripNo,
                    'source_label' => 'ในระบบ',
                    'job_summary' => [],
                    'so_summary_text' => '',
                    'mfg_summary_text' => '',
                ];
            });

        $masterLoadRows = collect($this->conn()->select(
            "
            SELECT
                ta.truck_id,
                ISNULL(SUM(ISNULL(ta.assigned_weight, 0)), 0) AS current_load
            FROM delivery_plan_truck_assign ta
            INNER JOIN delivery_plan_data dp
                ON dp.ord_id = ta.ord_id
            WHERE ta.truck_source = 'MASTER'
            AND ta.truck_id IS NOT NULL
            AND CAST(ta.ship_posted_at AS date) = ?
            AND ISNULL(ta.trip_no, 1) = ?
            AND ta.closed_at IS NULL
            AND UPPER(ISNULL(dp.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')
            GROUP BY ta.truck_id
            ",
            [$shipDate, $tripNo]
        ))->keyBy('truck_id');

        $manualRows = collect($this->conn()->select(
            "
            SELECT
                ta.manual_plate_no,
                MAX(ta.manual_driver_name) AS driver_name,
                MAX(ta.manual_driver_phone) AS driver_phone,
                MAX(ta.manual_max_load) AS max_load,
                MAX(ta.manual_car_length) AS car_length,
                MAX(ta.manual_remark) AS remark,
                ISNULL(SUM(ISNULL(ta.assigned_weight, 0)), 0) AS current_load
            FROM delivery_plan_truck_assign ta
            INNER JOIN delivery_plan_data dp
                ON dp.ord_id = ta.ord_id
            WHERE ta.truck_source = 'MANUAL'
            AND ta.manual_plate_no IS NOT NULL
            AND LTRIM(RTRIM(ta.manual_plate_no)) <> ''
            AND CAST(ta.ship_posted_at AS date) = ?
            AND ISNULL(ta.trip_no, 1) = ?
            AND ta.closed_at IS NULL
            AND UPPER(ISNULL(dp.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')
            GROUP BY ta.manual_plate_no
            ",
            [$shipDate, $tripNo]
        ))->map(function ($r) use ($tripNo) {
            $maxLoad = (float) ($r->max_load ?? 0);
            $currentLoad = (float) ($r->current_load ?? 0);

            return (object) [
                'row_key' => 'MANUAL:' . trim((string) $r->manual_plate_no),
                'truck_pick_type' => 'MANUAL_TEMP',
                'truck_id' => null,
                'manual_plate_no' => trim((string) $r->manual_plate_no),
                'plate_no' => trim((string) $r->manual_plate_no),
                'driver_name' => trim((string) ($r->driver_name ?? '')),
                'driver_phone' => trim((string) ($r->driver_phone ?? '')),
                'max_load' => $maxLoad,
                'car_length' => $r->car_length,
                'remark' => trim((string) ($r->remark ?? '')),
                'current_load' => $currentLoad,
                'remaining_capacity' => $maxLoad <= 0 ? 0 : max(0, $maxLoad - $currentLoad),
                'capacity_unlimited' => $maxLoad <= 0,
                'trip_no' => $tripNo,
                'source_label' => 'รถนอกวันนี้',
                'job_summary' => [],
                'so_summary_text' => '',
                'mfg_summary_text' => '',
            ];
        })->filter(fn($r) => !empty($r->capacity_unlimited) || (float) $r->remaining_capacity > 0)->values();

        $usageRows = collect($this->conn()->select(
            "
            SELECT
                CASE
                    WHEN ta.truck_source = 'MASTER' AND ta.truck_id IS NOT NULL
                        THEN 'MASTER:' + CAST(ta.truck_id AS varchar(50))
                    WHEN ta.truck_source = 'MANUAL' AND ta.manual_plate_no IS NOT NULL
                        THEN 'MANUAL:' + LTRIM(RTRIM(ta.manual_plate_no))
                    ELSE ''
                END AS row_key,
                dp.so_number,
                dp.mfg_no,
                dp.address,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(c.name COLLATE DATABASE_DEFAULT)), ''),
                    '-'
                ) AS customer_name,
                ISNULL(wo.brand, '-') AS job_type,
                SUM(ISNULL(ta.assigned_weight, 0)) AS assigned_weight
            FROM delivery_plan_truck_assign ta
            INNER JOIN delivery_plan_data dp
                ON dp.ord_id = ta.ord_id
            LEFT JOIN customer c
                ON c.id = dp.customer_id
            LEFT JOIN workorder wo
                ON LTRIM(RTRIM(wo.workordernumber)) COLLATE DATABASE_DEFAULT
                = LTRIM(RTRIM(dp.mfg_no)) COLLATE DATABASE_DEFAULT
            WHERE CAST(ta.ship_posted_at AS date) = ?
            AND ISNULL(ta.trip_no, 1) = ?
            AND ta.closed_at IS NULL
            AND UPPER(ISNULL(dp.status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')
            GROUP BY
                CASE
                    WHEN ta.truck_source = 'MASTER' AND ta.truck_id IS NOT NULL
                        THEN 'MASTER:' + CAST(ta.truck_id AS varchar(50))
                    WHEN ta.truck_source = 'MANUAL' AND ta.manual_plate_no IS NOT NULL
                        THEN 'MANUAL:' + LTRIM(RTRIM(ta.manual_plate_no))
                    ELSE ''
                END,
                dp.so_number,
                dp.mfg_no,
                dp.address,
                dp.customer_name,
                c.name,
                wo.brand
            ",
            [$shipDate, $tripNo]
        ))->filter(fn($r) => trim((string) ($r->row_key ?? '')) !== '');

        $summaryMap = $usageRows->groupBy('row_key')->map(function ($rows) {
            $rows = collect($rows);

            return [
                'job_summary' => $rows->map(function ($r) {
                    return [
                        'so_number' => trim((string) ($r->so_number ?? '')),
                        'mfg_no' => trim((string) ($r->mfg_no ?? '')),
                        'address' => trim((string) ($r->address ?? '')),
                        'customer_name' => trim((string) ($r->customer_name ?? '')) ?: '-',
                        'job_type' => trim((string) ($r->job_type ?? '')) ?: '-',
                        'assigned_weight' => (float) ($r->assigned_weight ?? 0),
                    ];
                })->values()->all(),

                'so_summary_text' => $rows->pluck('so_number')
                    ->map(fn($x) => trim((string) $x))
                    ->filter()
                    ->unique()
                    ->implode(', '),

                'mfg_summary_text' => $rows->pluck('mfg_no')
                    ->map(fn($x) => trim((string) $x))
                    ->filter()
                    ->unique()
                    ->implode(', '),
            ];
        });

        $masterRows = $masterRows->map(function ($r) use ($masterLoadRows, $summaryMap) {
            $load = (float) ($masterLoadRows->get($r->truck_id)->current_load ?? 0);
            $summary = $summaryMap->get($r->row_key, []);

            $r->current_load = $load;
            $r->capacity_unlimited = (float) $r->max_load <= 0;
            $r->remaining_capacity = $r->capacity_unlimited
                ? 0
                : max(0, (float) $r->max_load - $load);
            $r->job_summary = $summary['job_summary'] ?? [];
            $r->so_summary_text = $summary['so_summary_text'] ?? '';
            $r->mfg_summary_text = $summary['mfg_summary_text'] ?? '';

            return $r;
        });

        $manualRows = $manualRows->map(function ($r) use ($summaryMap) {
            $summary = $summaryMap->get($r->row_key, []);

            $r->job_summary = $summary['job_summary'] ?? [];
            $r->so_summary_text = $summary['so_summary_text'] ?? '';
            $r->mfg_summary_text = $summary['mfg_summary_text'] ?? '';

            return $r;
        });

        return $masterRows
            ->concat($manualRows)
            ->sortBy([
                ['truck_pick_type', 'asc'],
                ['plate_no', 'asc'],
            ])
            ->values();
    }

    public function logisticsSummary(Request $request)
    {
        $shipDate = trim((string) $request->query('ship_date', now()->toDateString()));

        try {
            $shipDate = Carbon::parse($shipDate)->toDateString();
        } catch (\Throwable $e) {
            $shipDate = now()->toDateString();
        }

        $statusFilter = strtolower(trim((string) $request->query('status', 'all')));
        if (!in_array($statusFilter, ['all', 'unassigned', 'partial', 'completed', 'special'], true)) {
            $statusFilter = 'all';
        }

        $search = mb_strtolower(trim((string) $request->query('q', '')));
        $hideCompleted = (string) $request->query('hide_completed', '') === '1';
        $ordIdFilter = collect(is_array($request->query('ord_ids'))
            ? $request->query('ord_ids')
            : preg_split('/\s*,\s*/', (string) $request->query('ord_ids', ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $assignSumSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('ord_id, SUM(ISNULL(assigned_weight, 0)) as assigned_weight_sum')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->groupBy('ord_id');

        $rows = $this->conn()
            ->table('delivery_plan_data as dp')
            ->leftJoinSub($assignSumSub, 'tas', function ($join) {
                $join->on('tas.ord_id', '=', 'dp.ord_id');
            })
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->whereRaw('CAST(dp.ship_posted_at AS date) = ?', [$shipDate])
            ->when($ordIdFilter->isNotEmpty(), fn($q) => $q->whereIn('dp.ord_id', $ordIdFilter->all()))
            ->whereRaw("UPPER(ISNULL(dp.status, 'NEW')) NOT IN ('VOID', 'VOIDED', 'CANCEL', 'CANCELED', 'CANCELLED', 'POSTPONED')")
            // ซ่อนเฉพาะงานพิเศษที่ "ยังเปิดอยู่" (OPEN) เพื่อไม่ให้ซ้ำกับ special panel ด้านบน
            // ส่วนงานพิเศษที่ปิดแล้ว (CLOSED) ให้กลับมาแสดงในตารางหลักด้วย
            // ยกเว้น: ถ้าผู้ใช้ "ติ๊กเลือก" ord นั้นมาจัดรถพร้อมกัน (อยู่ใน ord_ids) ให้ผ่านเข้ามาได้
            ->where(function ($outer) use ($ordIdFilter) {
                $outer->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('delivery_plan_special_dispatch as sx')
                        ->whereColumn('sx.ord_id', 'dp.ord_id')
                        ->where('sx.status', 'OPEN');
                });
                if ($ordIdFilter->isNotEmpty()) {
                    $outer->orWhereIn('dp.ord_id', $ordIdFilter->all());
                }
            })
            ->selectRaw("
                dp.ord_id,
                dp.so_number,
                dp.ship_posted_at,
                dp.window_at,
                dp.mfg_no,
                dp.part_number,
                dp.part_desc,
                dp.qty,
                dp.line_qty,
                dp.sell_by_line,
                dp.address,
                dp.status,
                dp.remark,
                dp.edit_remark,
                dp.delivery_type,
                dp.attach_docs,
                dp.attach_docs_other,
                dp.revision_number,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
                ) AS sales_name,
                ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum
            ")
            ->orderBy('dp.sales_id')
            ->orderBy('c.name')
            ->orderBy('dp.delivery_type')
            ->orderBy('dp.part_number')
            ->orderBy('dp.ord_id')
            ->get();

        $docMaster = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code');

        $resolveAttachDocs = function ($attachDocs, $attachDocsOther) use ($docMaster): string {
            $other = trim((string) ($attachDocsOther ?? ''));
            $codes = collect(preg_split('/\s*,\s*/', (string) $attachDocs))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->when($other !== '', fn($items) => $items->reject(fn($code) => $code === 'OTHER'))
                ->unique();
            $names = $codes->map(fn($code) => $docMaster[$code] ?? $code);
            if ($other !== '') {
                $names->push('อื่น ๆ: ' . $other);
            }
            $names = $names->unique()->values();
            return $names->isNotEmpty() ? $names->implode(', ') : '';
        };

        $rows = collect($rows)
            ->map(function ($r) use ($resolveAttachDocs) {
                $qty = (float) ($r->qty ?? 0);
                $assigned = (float) ($r->assigned_weight_sum ?? 0);
                $lineQty = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : 0.0;
                $isPieceQty = (int) ($r->sell_by_line ?? 0) === 1 && $lineQty > 0 && $qty == 0.0;

                $r->qty = $qty;
                $r->assigned_weight_sum = $assigned;
                $r->remaining_weight = $qty > 0 ? max(0, $qty - $assigned) : 0;
                $r->line_qty_display = (int) ($r->sell_by_line ?? 0) === 1 ? $lineQty : null;
                $r->line_qty_unit = $isPieceQty ? 'ชิ้น' : 'เส้น';
                $r->line_text = (int) ($r->sell_by_line ?? 0) === 1 && $lineQty > 0
                    ? number_format($lineQty, 0) . ' ' . $r->line_qty_unit
                    : '-';
                $r->is_piece_qty = $isPieceQty;
                $r->attach_docs_text = $resolveAttachDocs($r->attach_docs ?? null, $r->attach_docs_other ?? null);

                return $r;
            });

        $inquiryOrderedGroups = $this->buildDivisionGroups(
            $rows->groupBy(fn($r) => trim((string) ($r->sales_name ?? 'UNKNOWN')))->all()
        );
        $rowsForGrouping = collect($inquiryOrderedGroups)
            ->flatMap(fn($items) => collect($items))
            ->values();

        $ordIdList = $rowsForGrouping->pluck('ord_id')->filter()->unique()->values()->all();
        $assignmentsByOrd = collect();
        if (!empty($ordIdList)) {
            $assignmentsByOrd = $this->conn()
                ->table('delivery_plan_truck_assign as ta')
                ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
                ->whereIn('ta.ord_id', $ordIdList)
                ->whereRaw("ta.id = (SELECT MAX(ta2.id) FROM delivery_plan_truck_assign ta2 WHERE ta2.ord_id = ta.ord_id)")
                ->select(
                    'ta.ord_id',
                    'ta.truck_source',
                    'ta.truck_id',
                    'ta.trip_no',
                    'ta.manual_plate_no',
                    'ta.manual_driver_name',
                    'ta.manual_driver_phone',
                    'ta.manual_max_load',
                    'ta.manual_car_length',
                    'ta.manual_remark',
                    'ta.driver_staff_id',
                    'ta.driver_name as ta_driver_name',
                    'ta.driver_phone as ta_driver_phone',
                    'ta.shipping_phone',
                    'ta.helper1_staff_id',
                    'ta.helper2_staff_id',
                    'ta.helper3_staff_id',
                    'ta.helper4_staff_id',
                    'ta.helper5_staff_id',
                    'tm.plate_no as tm_plate_no'
                )
                ->get()
                ->keyBy('ord_id');
        }

        $isSelectedBatch = $ordIdFilter->isNotEmpty();

        $grouped = $rowsForGrouping
            ->groupBy(function ($r) use ($isSelectedBatch) {
                return $isSelectedBatch ? 'selected-batch' : (int) ($r->ord_id ?? 0);
            })
            ->map(function ($items, $key) use ($shipDate, $assignmentsByOrd) {
                $first = $items->first();
                $totalWeight = $items->sum(fn($r) => (float) ($r->qty ?? 0));
                $assignedWeight = $items->sum(fn($r) => (float) ($r->assigned_weight_sum ?? 0));
                $remainingWeight = $totalWeight > 0 ? max(0, $totalWeight - $assignedWeight) : 0;
                $pieceCount = $items->sum(fn($r) => (float) ($r->is_piece_qty ? ($r->line_qty_display ?? 0) : 0));

                $hasAssignmentRow = $items->pluck('ord_id')
                    ->map(fn($id) => $assignmentsByOrd->get((int) $id))
                    ->filter()
                    ->isNotEmpty();
                $hasPiece = $pieceCount > 0;

                if ($totalWeight > 0 && round($remainingWeight) <= 0) {
                    $status = 'completed';
                } elseif ($hasPiece && $hasAssignmentRow) {
                    // ขายเป็นชิ้น/เส้น (qty=0) ที่จัดรถแล้ว ถือว่าจัดครบ
                    $status = 'completed';
                } elseif ($assignedWeight > 0 || $hasAssignmentRow) {
                    $status = 'partial';
                } else {
                    $status = 'unassigned';
                }

                $customerNames = $items->pluck('customer_name')->map(fn($v) => trim((string) $v))->filter()->unique()->values();
                $shipToList = $items->pluck('address')->map(fn($v) => trim((string) $v))->filter()->unique()->values();
                $searchText = mb_strtolower(collect([
                    $first->so_number ?? '',
                    $customerNames->implode(' '),
                    $shipToList->implode(' '),
                    $items->pluck('mfg_no')->implode(' '),
                    $items->pluck('part_number')->implode(' '),
                    $items->pluck('part_desc')->implode(' '),
                    $items->pluck('sales_name')->implode(' '),
                ])->implode(' '));

                $primaryAssignment = $items->pluck('ord_id')
                    ->map(fn($id) => $assignmentsByOrd->get((int) $id))
                    ->filter()
                    ->first();

                return (object) [
                    'key' => 'ord-' . (int) ($first->ord_id ?? $key),
                    'ord_id' => (int) ($first->ord_id ?? 0),
                    'so_number' => $first->so_number ?: '-',
                    'mfg_no' => $first->mfg_no ?: '-',
                    'ship_date' => $shipDate,
                    'window_at' => $items->pluck('window_at')->filter()->min(),
                    'customer_display' => $customerNames->count() > 1 ? 'หลายลูกค้า' : ($customerNames->first() ?: '-'),
                    'customer_count' => $customerNames->count(),
                    'sales_display' => $items->pluck('sales_name')->map(fn($v) => trim((string) $v))->filter()->unique()->implode(', ') ?: '-',
                    'ship_to_display' => $shipToList->count() > 1 ? 'หลายสถานที่ส่ง' : ($shipToList->first() ?: '-'),
                    'ship_to_count' => $shipToList->count(),
                    'item_count' => $items->count(),
                    'mfg_count' => $items->pluck('mfg_no')->flatMap(fn($v) => collect(explode(',', (string) $v))->map(fn($m) => trim($m))->filter())->unique()->count(),
                    'total_weight' => $totalWeight,
                    'assigned_weight' => $assignedWeight,
                    'remaining_weight' => $remainingWeight,
                    'piece_count' => $pieceCount,
                    'status' => $status,
                    'assignment' => $primaryAssignment,
                    'rows' => $items->values(),
                    'lines' => $items->map(function ($r) {
                        return [
                            'ord_id' => (int) ($r->ord_id ?? 0),
                            'mfg_no' => $r->mfg_no ?: '-',
                            'part' => $r->part_number ?: '-',
                            'desc' => $r->part_desc ?: '-',
                            'shipto' => $r->address ?: '-',
                            'qty_total' => (float) ($r->qty ?? 0),
                            'qty_assigned' => (float) ($r->assigned_weight_sum ?? 0),
                            'qty_remaining' => (float) ($r->remaining_weight ?? 0),
                            'sell_by_line' => (int) ($r->sell_by_line ?? 0),
                            'line_qty' => (float) ($r->line_qty_display ?? 0),
                            'line_text' => $r->line_text ?? '-',
                        ];
                    })->values()->all(),
                    'search_text' => $searchText,
                ];
            })
            ->values();

        $allGroups = $grouped;

        // โหมด batch: เป็นกลุ่มเดียวที่มัดรายการที่เลือกมา ต้องแสดงเสมอ
        // ไม่เอา filter ค้นหา/สถานะ/ซ่อนจัดครบ มา filter กลุ่มนี้ทิ้ง (ไม่งั้นฝั่งขวาว่าง)
        if (!$isSelectedBatch) {
            if ($search !== '') {
                $grouped = $grouped->filter(fn($g) => str_contains($g->search_text, $search))->values();
            }

            if ($hideCompleted) {
                $grouped = $grouped->where('status', '!=', 'completed')->values();
            }

            if ($statusFilter !== 'all') {
                $grouped = $grouped->where('status', $statusFilter)->values();
            }
        }

        $grouped = $grouped->values();

        // โหมดเลือกหลายรายการ (batch): เตรียม "คลังรายการ" ทั้งหมดในวันส่งเดียวกันที่ยังจัดรถได้
        // ไว้ให้ผู้ใช้กด + เพิ่มเข้าจัดรถคันเดียวกันได้จากในหน้านี้ (รวมรายการที่เลือกมาด้วย
        // เพื่อให้ถ้าเผลอลบออก สามารถกดเพิ่มกลับได้)
        $batchCandidates = collect();
        if ($isSelectedBatch) {
            $candAssignSub = $this->conn()
                ->table('delivery_plan_truck_assign')
                ->selectRaw('ord_id, SUM(ISNULL(assigned_weight, 0)) as assigned_weight_sum')
                ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
                ->groupBy('ord_id');

            $candRows = $this->conn()
                ->table('delivery_plan_data as dp')
                ->leftJoinSub($candAssignSub, 'tas', fn($j) => $j->on('tas.ord_id', '=', 'dp.ord_id'))
                ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
                ->whereRaw('CAST(dp.ship_posted_at AS date) = ?', [$shipDate])
                ->whereRaw("UPPER(ISNULL(dp.status, 'NEW')) NOT IN ('VOID', 'VOIDED', 'CANCEL', 'CANCELED', 'CANCELLED', 'POSTPONED')")
                // ไม่ตัดงานพิเศษที่เปิดอยู่ออกแล้ว — ให้ดึงมารวมจัดรถปกติได้ (จัดแล้ว special เดิมจะถูก mark SUPERSEDED อัตโนมัติ)
                ->selectRaw("
                    dp.ord_id,
                    dp.so_number,
                    dp.mfg_no,
                    dp.part_number,
                    dp.part_desc,
                    dp.qty,
                    dp.line_qty,
                    dp.sell_by_line,
                    dp.address,
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                        c.name COLLATE DATABASE_DEFAULT
                    ) as customer_name,
                    ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum,
                    (SELECT TOP 1 sx.dispatch_type
                       FROM delivery_plan_special_dispatch sx
                      WHERE sx.ord_id = dp.ord_id AND sx.status = 'OPEN'
                      ORDER BY sx.id DESC) as special_type
                ")
                ->orderBy('c.name')
                ->orderBy('dp.part_number')
                ->orderBy('dp.ord_id')
                ->get();

            $batchCandidates = collect($candRows)
                ->unique('ord_id')
                ->map(function ($r) {
                    $qty = (float) ($r->qty ?? 0);
                    $assigned = (float) ($r->assigned_weight_sum ?? 0);
                    $lineQty = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : 0.0;
                    $isPiece = (int) ($r->sell_by_line ?? 0) === 1 && $lineQty > 0 && $qty == 0.0;
                    $remaining = $qty > 0 ? max(0, $qty - $assigned) : 0;

                    $specialType = strtoupper(trim((string) ($r->special_type ?? '')));
                    $specialLabel = $specialType !== ''
                        ? (self::SPECIAL_DISPATCH_TYPES[$specialType] ?? 'งานพิเศษ')
                        : '';

                    return [
                        'ord_id' => (int) ($r->ord_id ?? 0),
                        'customer_name' => $r->customer_name ?: '-',
                        'so_number' => $r->so_number ?: '-',
                        'mfg_no' => $r->mfg_no ?: '-',
                        'part_number' => $r->part_number ?: '-',
                        'part_desc' => $r->part_desc ?: '-',
                        'address' => $r->address ?: '-',
                        'remaining' => (int) round($remaining),
                        'is_piece' => $isPiece,
                        'line_qty' => $isPiece ? (int) $lineQty : 0,
                        'line_unit' => $isPiece ? 'ชิ้น' : 'เส้น',
                        'line_text' => $isPiece ? (number_format($lineQty, 0) . ' ชิ้น') : '-',
                        'special_label' => $specialLabel,
                        'is_special' => $specialLabel !== '',
                        'is_assigned' => $assigned > 0,
                    ];
                })
                // จัดได้: มียอดคงเหลือ / ขายเป็นชิ้น-เส้น / หรือเป็นงานพิเศษ (ดึงมาแปลงเป็นจัดรถปกติได้)
                ->filter(fn($x) => $x['remaining'] > 0 || $x['is_piece'] || $x['is_special'])
                ->values();
        }

        $specialGroups = $this->getSpecialDispatchGroups($shipDate);
        $specialCount = $specialGroups->sum(fn($rows) => $rows->count());
        $trucks = $this->getAssignableTrucksByShipDate($shipDate);
        $specialDispatchTypes = self::SPECIAL_DISPATCH_TYPES;

        // รายการคนขับที่เคยบันทึก (distinct ตามชื่อ — เอา record ล่าสุดของแต่ละชื่อ)
        // ใช้สำหรับ autocomplete แทน localStorage เพื่อให้ทุก user เห็นรายการเดียวกัน
        // เพราะคนขับนอกมักจะวนกลับมาไม่กี่คน
        // master "พนักงานขับรถ" — ดูจากตาราง staff master (role_type=DRIVER, is_active=1)
        // ไม่ใช่จาก delivery_plan_truck_master.driver_name (เป็นแค่ text บนรถคันนั้น ๆ)
        $masterDriverKeys = $this->conn()
            ->table('delivery_plan_truck_staff_master')
            ->where('is_active', 1)
            ->where('role_type', 'DRIVER')
            ->get(['prefix_name', 'first_name', 'last_name'])
            ->map(fn($r) => mb_strtolower($this->fullStaffName($r)))
            ->filter(fn($k) => $k !== '')
            ->unique()
            ->flip()
            ->all();

        // ดึงทั้ง driver_name (text input ใหม่ + snapshot จาก staff) และ manual_driver_name (record รถนอกเก่า)
        // ใช้ COALESCE เลือกตัวที่มีค่าก่อน เพื่อให้ครอบคลุมข้อมูลย้อนหลังทั้งหมด
        // ประวัติทะเบียนรถนอก พร้อมคนขับ/เบอร์/max load/length ล่าสุดที่ใช้คู่กัน
        // เลือกทะเบียนแล้ว autofill ฟิลด์อื่นที่เกี่ยวข้องทันที
        $plateHistory = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw("
                LTRIM(RTRIM(manual_plate_no)) AS plate,
                LTRIM(RTRIM(ISNULL(COALESCE(NULLIF(driver_name, ''), manual_driver_name), ''))) AS driver,
                LTRIM(RTRIM(ISNULL(COALESCE(NULLIF(driver_phone, ''), manual_driver_phone), ''))) AS phone,
                ISNULL(manual_max_load, 0) AS max_load,
                ISNULL(manual_car_length, 0) AS car_length
            ")
            ->whereRaw("id IN (
                SELECT MAX(id) FROM delivery_plan_truck_assign
                WHERE LTRIM(RTRIM(ISNULL(manual_plate_no, ''))) <> ''
                GROUP BY LOWER(LTRIM(RTRIM(manual_plate_no)))
            )")
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn($r) => [
                'plate' => trim((string) ($r->plate ?? '')),
                'driver' => trim((string) ($r->driver ?? '')),
                'phone' => trim((string) ($r->phone ?? '')),
                'max_load' => (float) ($r->max_load ?? 0),
                'car_length' => (float) ($r->car_length ?? 0),
            ])
            ->filter(fn($p) => $p['plate'] !== '')
            ->values();

        $driverHistory = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw("
                LTRIM(RTRIM(COALESCE(NULLIF(driver_name, ''), manual_driver_name))) AS name,
                LTRIM(RTRIM(COALESCE(NULLIF(driver_phone, ''), manual_driver_phone))) AS phone
            ")
            ->whereRaw("id IN (
                SELECT MAX(id) FROM delivery_plan_truck_assign
                WHERE LTRIM(RTRIM(COALESCE(NULLIF(driver_name, ''), NULLIF(manual_driver_name, '')))) <> ''
                GROUP BY LOWER(LTRIM(RTRIM(COALESCE(NULLIF(driver_name, ''), manual_driver_name))))
            )")
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function ($r) use ($masterDriverKeys) {
                $name = trim((string) ($r->name ?? ''));
                $key = mb_strtolower($name);
                return [
                    'name' => $name,
                    'phone' => trim((string) ($r->phone ?? '')),
                    'is_master' => isset($masterDriverKeys[$key]),
                ];
            })
            ->filter(fn($d) => $d['name'] !== '')
            ->values();

        $summary = [
            'group_count' => $allGroups->count(),
            'visible_group_count' => $grouped->count(),
            'item_count' => $allGroups->sum('item_count'),
            'total_weight' => $allGroups->sum('total_weight'),
            'assigned_weight' => $allGroups->sum('assigned_weight'),
            'remaining_weight' => $allGroups->sum('remaining_weight'),
            'unassigned_count' => $allGroups->where('status', 'unassigned')->count(),
            'partial_count' => $allGroups->where('status', 'partial')->count(),
            'completed_count' => $allGroups->where('status', 'completed')->count(),
            'special_count' => $specialCount,
        ];

        $assignMailAlreadySent = $this->truckAssignMailAlreadySent($shipDate);

        return view('formdp.logistics-summary', compact(
            'shipDate',
            'statusFilter',
            'search',
            'hideCompleted',
            'summary',
            'grouped',
            'specialGroups',
            'trucks',
            'specialDispatchTypes',
            'driverHistory',
            'plateHistory',
            'assignMailAlreadySent',
            'batchCandidates'
        ));
    }

    public function truckBoard(Request $request)
    {
        $shipDate = trim((string) $request->query('ship_date', now()->toDateString()));

        try {
            $shipDate = Carbon::parse($shipDate)->toDateString();
        } catch (\Throwable $e) {
            $shipDate = now()->toDateString();
        }

        $latestAssignSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('MAX(id) as latest_assign_id, ord_id, ISNULL(trip_no, 1) as trip_no')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->groupBy('ord_id', DB::raw('ISNULL(trip_no, 1)'));

        $assignSumSub = $this->conn()
            ->table('delivery_plan_truck_assign')
            ->selectRaw('ord_id, ISNULL(trip_no, 1) as trip_no, SUM(ISNULL(assigned_weight,0)) as assigned_weight_sum')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->groupBy('ord_id', DB::raw('ISNULL(trip_no, 1)'));

        $rows = $this->conn()
            ->table('delivery_plan_data as dp')
            ->leftJoinSub($latestAssignSub, 'la', function ($join) {
                $join->on('la.ord_id', '=', 'dp.ord_id');
            })
            ->leftJoinSub($assignSumSub, 'tas', function ($join) {
                $join->on('tas.ord_id', '=', 'dp.ord_id')
                    ->on('tas.trip_no', '=', 'la.trip_no');
            })
            ->leftJoin('delivery_plan_truck_assign as ta', 'ta.id', '=', 'la.latest_assign_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("dp.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->selectRaw("
            dp.ord_id,
            dp.sales_id,
            dp.ship_posted_at,
            dp.window_at,
            dp.window_text,
            dp.so_number,
            dp.delivery_type,
            dp.part_number,
            dp.part_desc,
            p.f1 as part_f1,
            p.f2 as part_f2,
            p.f3 as part_f3,
            p.description as part_master_desc,
            dp.mfg_no,
            dp.qty,
            dp.line_qty,
            dp.sell_by_line,
            dp.address,
            dp.status,
            dp.remark,
            dp.edit_remark,
            dp.attach_docs,
            dp.attach_docs_other,
            dp.revision_number,

            COALESCE(
                NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                c.name COLLATE DATABASE_DEFAULT
            ) as customer_name,

            COALESCE(
                NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
            ) AS sales_name,

            ISNULL(tas.assigned_weight_sum, 0) as assigned_weight_sum,

            ta.id as truck_assign_id,
            ta.truck_source,
            ta.truck_id,
            ta.manual_plate_no,
            ta.manual_driver_name,
            ta.manual_driver_phone,
            ta.manual_max_load,
            ta.manual_car_length,
            ta.manual_remark,
            ta.assigned_weight,
            ta.assigned_at,
            ISNULL(ta.trip_no, 1) as trip_no,
            ta.closed_at,
            ta.closed_by,
            ta.closed_remark,

            ta.driver_staff_id,
            ta.driver_name,
            ta.driver_phone,
            ta.helper1_staff_id,
            ta.helper1_name,
            ta.helper2_staff_id,
            ta.helper2_name,
            ta.helper3_staff_id,
            ta.helper3_name,
            ta.helper4_staff_id,
            ta.helper4_name,
            ta.helper5_staff_id,
            ta.helper5_name,

            tm.plate_no as master_plate_no,
            tm.driver_name as master_driver_name,
            tm.driver_phone as master_driver_phone,
            tm.max_load as master_max_load,
            tm.car_length as master_car_length,
            tm.remark as master_remark
            ")
            ->whereRaw("CAST(dp.ship_posted_at AS date) = ?", [$shipDate])
            ->whereIn(DB::raw("UPPER(ISNULL(dp.status, 'NEW'))"), ['NEW', 'ASSIGN', 'CLOSED'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('delivery_plan_special_dispatch as sx')
                    ->whereColumn('sx.ord_id', 'dp.ord_id')
                    ->whereIn('sx.status', ['OPEN', 'CLOSED']);
            })
            ->orderByRaw("
            CASE
                WHEN ta.id IS NULL THEN 1
                ELSE 0
            END
        ")
            ->orderBy('ta.assigned_at')
            ->orderByRaw('ISNULL(ta.trip_no, 1)')
            ->orderBy('dp.window_at')
            ->orderBy('dp.so_number')
            ->get();

        $allMfgNos = [];

        foreach ($rows as $r) {
            $tokens = collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->values()
                ->all();

            foreach ($tokens as $mfg) {
                $allMfgNos[$mfg] = $mfg;
            }
        }

        $typeMap = [];
        if (!empty($allMfgNos)) {
            $workorders = DB::connection('pgsqlmfgw')
                ->table('workorder')
                ->select(['workordernumber', 'brand'])
                ->whereIn('workordernumber', array_values($allMfgNos))
                ->get();

            foreach ($workorders as $wo) {
                $typeMap[trim((string) $wo->workordernumber)] = trim((string) ($wo->brand ?? ''));
            }
        }

        $this->applyTrackingStockFgToRows($rows, 'stock_fg');

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)])
            ->toArray();

        $normalizeDocText = function ($attachDocs, $attachDocsOther) use ($attachDocMap) {
            $codes = collect(explode(',', (string) $attachDocs))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->unique()
                ->values();

            $names = $codes->map(fn($code) => $attachDocMap[$code] ?? $code)->values();

            $other = trim((string) $attachDocsOther);
            if ($other !== '') {
                $names->push('อื่นๆ: ' . $other);
            }

            return $names->filter()->unique()->implode(', ');
        };

        $rows = $rows->map(function ($r) use ($normalizeDocText, $typeMap) {
            $isManual = strtoupper(trim((string) ($r->truck_source ?? ''))) === 'MANUAL';

            $plateNo = $isManual
                ? trim((string) ($r->manual_plate_no ?? ''))
                : trim((string) ($r->master_plate_no ?? ''));

            $driverName = trim((string) ($r->driver_name ?? ''));
            if ($driverName === '') {
                $driverName = $isManual
                    ? trim((string) ($r->manual_driver_name ?? ''))
                    : trim((string) ($r->master_driver_name ?? ''));
            }

            $driverPhone = trim((string) ($r->driver_phone ?? ''));
            if ($driverPhone === '') {
                $driverPhone = $isManual
                    ? trim((string) ($r->manual_driver_phone ?? ''))
                    : trim((string) ($r->master_driver_phone ?? ''));
            }

            $maxLoad = $isManual
                ? (float) ($r->manual_max_load ?? 0)
                : (float) ($r->master_max_load ?? 0);

            $carLength = $isManual ? $r->manual_car_length : $r->master_car_length;

            $truckRemark = $isManual
                ? trim((string) ($r->manual_remark ?? ''))
                : trim((string) ($r->master_remark ?? ''));

            $mfgTokens = collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->values();

            $r->type_display = $mfgTokens
                ->map(fn($mfg) => $typeMap[$mfg] ?? null)
                ->filter()
                ->unique()
                ->implode(', ');

            if ($r->type_display === '') {
                $r->type_display = '-';
            }

            $r->line_qty_display = ((int) ($r->sell_by_line ?? 0) === 1)
                ? (float) ($r->line_qty ?? 0)
                : null;
            $r->line_qty_unit = ((int) ($r->sell_by_line ?? 0) === 1 && (float) ($r->qty ?? 0) == 0.0)
                ? 'ชิ้น'
                : 'เส้น';

            $r->truck_plate_display = $plateNo !== '' ? $plateNo : 'ยังไม่ขึ้นรถ';
            $tripNo = max(1, (int) ($r->trip_no ?? 1));
            $r->trip_no = $tripNo;
            $r->is_trip_closed = !empty($r->closed_at);
            $r->truck_group_key = $plateNo !== '' ? ($plateNo . '|trip:' . $tripNo) : '__UNASSIGNED__';
            $r->truck_driver_name = $driverName;
            $r->truck_driver_phone = $driverPhone;
            $r->helper1_name_display = trim((string) ($r->helper1_name ?? ''));
            $r->helper2_name_display = trim((string) ($r->helper2_name ?? ''));
            $r->helper3_name_display = trim((string) ($r->helper3_name ?? ''));
            $r->helper4_name_display = trim((string) ($r->helper4_name ?? ''));
            $r->helper5_name_display = trim((string) ($r->helper5_name ?? ''));
            $r->truck_max_load = $maxLoad;
            $r->truck_car_length = $carLength;
            $r->truck_remark_display = $truckRemark;
            $r->attach_docs_text = $normalizeDocText($r->attach_docs ?? null, $r->attach_docs_other ?? null);

            $qty = (float) ($r->qty ?? 0);
            $assignedSum = (float) ($r->assigned_weight_sum ?? 0);
            $r->display_weight = $assignedSum > 0 ? $assignedSum : $qty;

            return $r;
        });

        $truckGroups = $rows->groupBy('truck_group_key')->map(function ($items, $key) {
            $first = $items->first();

            $totalQty = $items->sum(fn($r) => (float) ($r->qty ?? 0));
            $totalAssigned = $items->sum(fn($r) => (float) ($r->display_weight ?? 0));
            $maxLoad = (float) ($first->truck_max_load ?? 0);

            $helperNames = collect([
                $items->pluck('helper1_name_display')->filter()->first(),
                $items->pluck('helper2_name_display')->filter()->first(),
                $items->pluck('helper3_name_display')->filter()->first(),
                $items->pluck('helper4_name_display')->filter()->first(),
                $items->pluck('helper5_name_display')->filter()->first(),
            ])->filter()->implode(', ');
            $truckLabel = $first->truck_plate_display ?? 'ยังไม่ขึ้นรถ';
            if ($key !== '__UNASSIGNED__') {
                $truckLabel .= ' / เที่ยว ' . max(1, (int) ($first->trip_no ?? 1));
            }

            return (object) [
                'truck_key'       => $key,
                'truck_label'     => $truckLabel,
                'truck_source'    => strtoupper((string) ($first->truck_source ?? '')),
                'truck_id'        => $first->truck_id ?? null,
                'manual_plate_no' => $first->manual_plate_no ?? null,
                'trip_no'         => max(1, (int) ($first->trip_no ?? 1)),
                'closed_at'       => $items->pluck('closed_at')->filter()->first(),
                'is_closed'       => $items->every(fn($r) => !empty($r->closed_at)),
                'driver_name'     => $first->truck_driver_name ?? '',
                'driver_phone'    => $first->truck_driver_phone ?? '',
                'helper_names'    => $helperNames,
                'max_load'        => $maxLoad,
                'car_length'      => $first->truck_car_length ?? null,
                'remark'          => $first->truck_remark_display ?? '',
                'item_count'      => $items->count(),
                'total_qty'       => $totalQty,
                'total_assigned'  => $totalAssigned,
                'remaining_load'  => $maxLoad > 0 ? max(0, $maxLoad - $totalAssigned) : null,
                'rows'            => $items->values(),
                'is_unassigned'   => $key === '__UNASSIGNED__',
                'first_assigned_at' => $items->pluck('assigned_at')->filter()->min(),
            ];
        });

        $truckGroups = $truckGroups
            ->sortBy([
                ['is_unassigned', 'asc'],
                ['first_assigned_at', 'asc'],
                ['truck_label', 'asc'],
            ])
            ->values();

        $summary = [
            'ship_date'        => $shipDate,
            'truck_count'      => $truckGroups->where('is_unassigned', false)->count(),
            'unassigned_count' => $truckGroups->where('is_unassigned', true)->sum('item_count'),
            'item_count'       => $rows->count(),
            'assign_count'     => $rows->where('truck_group_key', '!=', '__UNASSIGNED__')->count(),
            'new_count'        => $rows->where('truck_group_key', '__UNASSIGNED__')->count(),
            'total_qty'        => $rows->sum(fn($r) => (float) ($r->qty ?? 0)),
            'total_weight'     => $rows->sum(fn($r) => (float) ($r->display_weight ?? 0)),
        ];

        $specialGroups = $this->getSpecialDispatchGroups($shipDate);

        $assignMailAlreadySent = $this->truckAssignMailAlreadySent($shipDate);

        return view('formdp.truck-board', compact(
            'shipDate',
            'summary',
            'truckGroups',
            'specialGroups',
            'assignMailAlreadySent'
        ));
    }

    private function getSpecialDispatchGroups(string $shipDate)
    {
        $rows = $this->conn()
            ->table('delivery_plan_special_dispatch as sd')
            ->join('delivery_plan_data as dp', 'dp.ord_id', '=', 'sd.ord_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("dp.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->whereRaw('CAST(dp.ship_posted_at AS date) = ?', [$shipDate])
            ->whereRaw("UPPER(ISNULL(dp.status, 'NEW')) NOT IN ('VOID', 'VOIDED', 'CANCEL', 'CANCELED', 'CANCELLED')")
            ->whereIn('sd.status', ['OPEN', 'CLOSED'])
            ->whereRaw("sd.id = (
                SELECT MAX(sd2.id)
                FROM delivery_plan_special_dispatch sd2
                WHERE sd2.ord_id = sd.ord_id
                  AND sd2.status IN ('OPEN', 'CLOSED')
            )")
            ->leftJoin('employees as ec', 'ec.id', '=', 'sd.closed_by')
            ->selectRaw("
                sd.id,
                sd.ord_id,
                sd.dispatch_type,
                sd.status as special_status,
                sd.remark as special_remark,
                sd.action_at,
                sd.closed_at,
                sd.closed_by,
                sd.close_remark,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(ec.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(ec.login COLLATE DATABASE_DEFAULT)), '')
                ) AS closed_by_name,
                dp.status as dp_status,
                dp.sales_id,
                dp.ship_posted_at,
                dp.window_at,
                dp.so_number,
                dp.mfg_no,
                dp.part_number,
                dp.part_desc,
                dp.qty,
                dp.line_qty,
                dp.sell_by_line,
                dp.delivery_type,
                dp.address,
                dp.remark as dp_remark,
                dp.edit_remark,
                dp.attach_docs,
                dp.attach_docs_other,
                p.f1 as part_f1,
                p.f2 as part_f2,
                p.f3 as part_f3,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
                ) AS sales_name
            ")
            ->orderBy('sd.action_at')
            ->orderBy('dp.window_at')
            ->orderBy('dp.so_number')
            ->get();

        // เตรียม attach_docs master เพื่อแปลง code → ชื่อ
        $docMaster = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code');

        $rows = $rows->map(function ($r) use ($docMaster) {
            $type = strtoupper(trim((string) ($r->dispatch_type ?? '')));
            $r->dispatch_label = self::SPECIAL_DISPATCH_TYPES[$type] ?? $type;
            $r->is_open = strtoupper((string) ($r->special_status ?? '')) === 'OPEN';

            // package_text — รวม f1/f2/f3 เหมือนตารางหลัก
            $r->package_text = collect([$r->part_f1 ?? null, $r->part_f2 ?? null, $r->part_f3 ?? null])
                ->map(fn($v) => trim((string) $v))
                ->filter()
                ->implode(' / ');

            // attach_docs_text — แปลง code → ชื่อ + ต่อท้าย "อื่น ๆ: ..." ถ้ามี
            $other = trim((string) ($r->attach_docs_other ?? ''));
            $codes = collect(explode(',', (string) ($r->attach_docs ?? '')))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->when($other !== '', fn($items) => $items->reject(fn($code) => $code === 'OTHER'))
                ->unique();
            $names = $codes->map(fn($code) => $docMaster[$code] ?? $code);
            if ($other !== '') {
                $names->push('อื่น ๆ: ' . $other);
            }
            $r->attach_docs_text = $names->unique()->values()->implode(', ');

            return $r;
        });

        return $rows->groupBy('dispatch_type');
    }

    public function printTruckTrip(Request $request)
    {
        $data = $request->validate([
            'ship_date' => ['required', 'date'],
            'truck_source' => ['required', 'in:MASTER,MANUAL'],
            'truck_id' => ['nullable', 'integer'],
            'manual_plate_no' => ['nullable', 'string', 'max:50'],
            'trip_no' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $shipDate = Carbon::parse($data['ship_date'])->toDateString();
        $truckSource = strtoupper((string) $data['truck_source']);
        $truckId = (int) ($data['truck_id'] ?? 0);
        $manualPlateNo = trim((string) ($data['manual_plate_no'] ?? ''));
        $tripNo = max(1, (int) $data['trip_no']);

        $rows = $this->conn()
            ->table('delivery_plan_truck_assign as ta')
            ->join('delivery_plan_data as dp', 'dp.ord_id', '=', 'ta.ord_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("dp.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->where('ta.truck_source', $truckSource)
            ->whereRaw('CAST(ta.ship_posted_at AS date) = ?', [$shipDate])
            ->whereRaw('ISNULL(ta.trip_no, 1) = ?', [$tripNo])
            ->when($truckSource === 'MASTER', fn($q) => $q->where('ta.truck_id', $truckId))
            ->when($truckSource === 'MANUAL', fn($q) => $q->where('ta.manual_plate_no', $manualPlateNo))
            ->selectRaw("
                dp.ord_id,
                dp.ship_posted_at,
                dp.window_at,
                dp.so_number,
                dp.delivery_type,
                dp.part_number,
                dp.part_desc,
                p.f1 as part_f1,
                p.f2 as part_f2,
                p.f3 as part_f3,
                dp.mfg_no,
                dp.qty,
                dp.line_qty,
                dp.sell_by_line,
                dp.address,
                dp.remark,
                dp.edit_remark,
                dp.attach_docs,
                dp.attach_docs_other,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
                ) AS sales_name,
                ta.assigned_weight,
                ta.trip_no,
                ta.closed_at,
                ta.driver_name,
                ta.driver_phone,
                ta.helper1_name,
                ta.helper2_name,
                ta.helper3_name,
                ta.helper4_name,
                ta.helper5_name,
                ta.manual_plate_no,
                ta.manual_driver_name,
                ta.manual_driver_phone,
                ta.manual_max_load,
                ta.manual_car_length,
                ta.manual_remark,
                tm.plate_no as master_plate_no,
                tm.driver_name as master_driver_name,
                tm.driver_phone as master_driver_phone,
                tm.max_load as master_max_load,
                tm.car_length as master_car_length,
                tm.remark as master_remark
            ")
            ->orderBy('dp.window_at')
            ->orderBy('dp.so_number')
            ->orderBy('dp.ord_id')
            ->get();

        abort_if($rows->isEmpty(), 404, 'Truck trip not found');

        $mfgTokens = $rows->flatMap(function ($r) {
            return collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter();
        })->unique()->values()->all();

        $typeMap = [];
        if (!empty($mfgTokens)) {
            DB::connection('pgsqlmfgw')
                ->table('workorder')
                ->select(['workordernumber', 'brand'])
                ->whereIn('workordernumber', $mfgTokens)
                ->get()
                ->each(function ($wo) use (&$typeMap) {
                    $typeMap[trim((string) $wo->workordernumber)] = trim((string) ($wo->brand ?? ''));
                });
        }

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)])
            ->toArray();

        $rows = $rows->map(function ($r) use ($typeMap, $attachDocMap) {
            $isManual = trim((string) ($r->manual_plate_no ?? '')) !== '';
            $mfgList = collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter();

            $r->type_display = $mfgList
                ->map(fn($mfg) => $typeMap[$mfg] ?? null)
                ->filter()
                ->unique()
                ->implode(', ') ?: '-';

            $codes = collect(explode(',', (string) ($r->attach_docs ?? '')))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->unique();

            $otherDoc = trim((string) ($r->attach_docs_other ?? ''));
            $r->attach_docs_text = $codes
                ->map(fn($code) => $attachDocMap[$code] ?? $code)
                ->when($otherDoc !== '', fn($items) => $items->push($otherDoc))
                ->filter()
                ->unique()
                ->implode(', ');

            $r->package_text = collect([$r->part_f1 ?? null, $r->part_f2 ?? null, $r->part_f3 ?? null])
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->implode(' / ');

            $r->line_qty_display = ((int) ($r->sell_by_line ?? 0) === 1)
                ? (float) ($r->line_qty ?? 0)
                : null;
            $r->line_qty_unit = ((int) ($r->sell_by_line ?? 0) === 1 && (float) ($r->qty ?? 0) == 0.0)
                ? 'ชิ้น'
                : 'เส้น';

            $r->truck_plate_display = $isManual
                ? trim((string) ($r->manual_plate_no ?? ''))
                : trim((string) ($r->master_plate_no ?? ''));
            $r->truck_driver_name = trim((string) ($r->driver_name ?? '')) ?: trim((string) ($isManual ? $r->manual_driver_name : $r->master_driver_name));
            $r->truck_driver_phone = trim((string) ($r->driver_phone ?? '')) ?: trim((string) ($isManual ? $r->manual_driver_phone : $r->master_driver_phone));
            $r->truck_max_load = (float) ($isManual ? ($r->manual_max_load ?? 0) : ($r->master_max_load ?? 0));
            $r->truck_car_length = $isManual ? $r->manual_car_length : $r->master_car_length;
            $r->truck_remark = trim((string) ($isManual ? $r->manual_remark : $r->master_remark));

            return $r;
        });

        $first = $rows->first();
        $summary = (object) [
            'ship_date' => $shipDate,
            'trip_no' => $tripNo,
            'truck_label' => ($first->truck_plate_display ?: '-') . ' / เที่ยว ' . $tripNo,
            'driver_name' => $first->truck_driver_name ?: '-',
            'driver_phone' => $first->truck_driver_phone ?: '-',
            'max_load' => (float) ($first->truck_max_load ?? 0),
            'car_length' => $first->truck_car_length,
            'remark' => $first->truck_remark,
            'item_count' => $rows->count(),
            'total_qty' => $rows->sum(fn($r) => (float) ($r->qty ?? 0)),
            'total_assigned' => $rows->sum(fn($r) => (float) ($r->assigned_weight ?? 0)),
            'closed_at' => $rows->pluck('closed_at')->filter()->first(),
        ];

        return view('formdp.truck-trip-print', compact('rows', 'summary'));
    }

    public function downloadTruckTripPdf(Request $request)
    {
        $data = $this->buildTruckTripDocumentData($request);
        $pdf = Pdf::loadView('formdp.truck-trip-print', $data + ['exportMode' => 'pdf'])
            ->setPaper('a4', 'landscape');

        return $pdf->download($this->truckTripFilename($data['summary'], 'pdf'));
    }

    public function downloadTruckTripExcel(Request $request)
    {
        $data = $this->buildTruckTripDocumentData($request);

        return Excel::download(new class($data) implements \Maatwebsite\Excel\Concerns\FromView {
            public function __construct(private array $data) {}

            public function view(): \Illuminate\Contracts\View\View
            {
                return view('formdp.truck-trip-print', $this->data + ['exportMode' => 'excel']);
            }
        }, $this->truckTripFilename($data['summary'], 'xlsx'));
    }

    public function printAssignedTruckBoard(Request $request)
    {
        return view('formdp.truck-board-assigned-export', $this->buildAssignedTruckBoardDocumentData($request));
    }

    public function downloadAssignedTruckBoardPdf(Request $request)
    {
        $data = $this->buildAssignedTruckBoardDocumentData($request);
        $pdf = Pdf::loadView('formdp.truck-board-assigned-export', $data + ['exportMode' => 'pdf'])
            ->setPaper('a4', 'landscape');

        return $pdf->download('DeliveryPlan-Assigned-' . Carbon::parse($data['shipDate'])->format('Ymd') . '.pdf');
    }

    public function downloadAssignedTruckBoardExcel(Request $request)
    {
        $data = $this->buildAssignedTruckBoardDocumentData($request);

        return Excel::download(new class($data) implements \Maatwebsite\Excel\Concerns\FromView {
            public function __construct(private array $data) {}

            public function view(): \Illuminate\Contracts\View\View
            {
                return view('formdp.truck-board-assigned-export', $this->data + ['exportMode' => 'excel']);
            }
        }, 'DeliveryPlan-Assigned-' . Carbon::parse($data['shipDate'])->format('Ymd') . '.xlsx');
    }

    private function truckAssignMailAlreadySent(string $shipDate): bool
    {
        return $this->conn()
            ->table('delivery_plan_mail_logs')
            ->where('mail_type', 'ASSIGN')
            ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
            ->exists();
    }

    public function sendAssignedTruckBoardMail(Request $request)
    {
        $user = auth()->user();
        $canSend = auth()->check()
            && (($user->is_superadmin ?? 0) == 1
                || (method_exists($user, 'hasRoleCode') && $user->hasRoleCode('DPA')));

        if (!$canSend) {
            abort(403);
        }

        $validated = $request->validate([
            'ship_date'  => ['required', 'date'],
            'remark'     => ['nullable', 'string', 'max:500'],
            'force_send' => ['nullable', 'in:0,1'],
        ], [
            'ship_date.required' => 'กรุณาระบุวันที่ส่งสินค้า',
        ]);

        $shipDate = Carbon::parse($validated['ship_date'])->toDateString();
        $shipDateText = Carbon::parse($shipDate)->format('d-m-y');
        $forceSend = (string) ($validated['force_send'] ?? '0') === '1';

        // เคยส่งเมลจัดรถของวันนี้แล้ว → ให้ผู้ใช้ยืนยันก่อนส่งซ้ำ (ส่งกลับ flag ไปให้หน้า confirm)
        if ($this->truckAssignMailAlreadySent($shipDate) && !$forceSend) {
            return back()->with('assign_mail_confirm', $shipDateText);
        }

        $cleanEmails = fn($key) => collect(config($key, []))
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        $to  = $cleanEmails('mail.truck_assign.to');
        $cc  = $cleanEmails('mail.truck_assign.cc');
        $bcc = $cleanEmails('mail.truck_assign.bcc');

        if (empty($to)) {
            return back()->with('assign_mail_error', 'ยังไม่ได้ตั้งค่า ASSIGN_MAIL_TO ใน .env หรือรูปแบบอีเมลไม่ถูกต้อง');
        }

        $data = $this->buildAssignedTruckBoardDocumentData($request);
        $totalItems = (int) ($data['summary']->assigned_item_count ?? 0);

        if ($totalItems <= 0) {
            return back()->with('assign_mail_error', "ยังไม่มีรายการจัดรถของวันที่ {$shipDateText} จึงยังส่งเมลไม่ได้");
        }

        $shipDateFile = Carbon::parse($shipDate)->format('Ymd');
        $subject = 'RE: รายการแผนจัดรถส่งสินค้าประจำวันที่ ' . $shipDateText;

        $pdfContent = Pdf::loadView('formdp.truck-board-assigned-export', $data + ['exportMode' => 'pdf'])
            ->setPaper('a4', 'landscape')
            ->output();

        $excelContent = Excel::raw(new class($data) implements \Maatwebsite\Excel\Concerns\FromView {
            public function __construct(private array $data) {}

            public function view(): \Illuminate\Contracts\View\View
            {
                return view('formdp.truck-board-assigned-export', $this->data + ['exportMode' => 'excel']);
            }
        }, \Maatwebsite\Excel\Excel::XLSX);

        $mail = new AssignedTruckBoardMail(
            [
                'subject'      => $subject,
                'shipDateText' => $shipDateText,
                'total'        => $totalItems,
                'mailRemark'   => $validated['remark'] ?? null,
            ],
            $pdfContent,
            'DeliveryPlan-Assigned-' . $shipDateFile . '.pdf',
            $excelContent,
            'DeliveryPlan-Assigned-' . $shipDateFile . '.xlsx'
        );

        try {
            $mailer = Mail::to($to);
            if (!empty($cc)) {
                $mailer->cc($cc);
            }
            if (!empty($bcc)) {
                $mailer->bcc($bcc);
            }
            $mailer->send($mail);

            $this->conn()->table('delivery_plan_mail_logs')->insert([
                'ship_posted_at'  => $shipDate,
                'revision_number' => 0,
                'mail_type'       => 'ASSIGN',
                'to_emails'       => implode(',', $to),
                'cc_emails'       => !empty($cc) ? implode(',', $cc) : null,
                'total_items'     => $totalItems,
                'sent_at'         => now(),
                'sent_by'         => $user->id ?? null,
                'sent_by_name'    => $user->name ?? $user->email ?? null,
                'subject'         => $subject,
                'remark'          => $validated['remark'] ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            return back()->with('assign_mail_success', "ส่งเมลตารางจัดรถเรียบร้อยแล้ว (วันที่ {$shipDateText})");
        } catch (\Throwable $e) {
            report($e);
            return back()->with('assign_mail_error', 'ส่งเมลไม่สำเร็จ กรุณาตรวจสอบการตั้งค่าเมลหรือสอบถามผู้ดูแลระบบ');
        }
    }

    private function buildAssignedTruckBoardDocumentData(Request $request): array
    {
        $data = $request->validate([
            'ship_date' => ['required', 'date'],
        ]);

        $shipDate = Carbon::parse($data['ship_date'])->toDateString();

        $rows = $this->conn()
            ->table('delivery_plan_truck_assign as ta')
            ->join('delivery_plan_data as dp', 'dp.ord_id', '=', 'ta.ord_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("dp.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->whereRaw('CAST(ta.ship_posted_at AS date) = ?', [$shipDate])
            ->whereIn(DB::raw("UPPER(ISNULL(dp.status, 'NEW'))"), ['ASSIGN', 'CLOSED'])
            ->selectRaw("
                dp.ord_id, dp.ship_posted_at, dp.window_at, dp.so_number, dp.delivery_type,
                dp.part_number, dp.part_desc, p.f1 as part_f1, p.f2 as part_f2, p.f3 as part_f3,
                dp.mfg_no, dp.qty, dp.line_qty, dp.sell_by_line, dp.address, dp.remark,
                dp.edit_remark, dp.attach_docs, dp.attach_docs_other,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
                ) AS sales_name,
                ta.assigned_weight, ta.truck_source, ta.truck_id, ta.trip_no, ta.closed_at,
                ta.driver_name, ta.driver_phone, ta.manual_plate_no, ta.manual_driver_name,
                ta.manual_driver_phone, ta.manual_max_load, ta.manual_car_length, ta.manual_remark,
                ta.helper1_name, ta.helper2_name, ta.helper3_name,
                ta.helper4_name, ta.helper5_name,
                tm.plate_no as master_plate_no, tm.driver_name as master_driver_name,
                tm.driver_phone as master_driver_phone, tm.max_load as master_max_load,
                tm.car_length as master_car_length, tm.remark as master_remark
            ")
            ->orderBy('ta.assigned_at')
            ->orderByRaw('ISNULL(ta.trip_no, 1)')
            ->orderBy('dp.window_at')
            ->orderBy('dp.so_number')
            ->get();

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)])
            ->toArray();

        $rows = $rows->map(function ($r) use ($attachDocMap) {
            $isManual = strtoupper(trim((string) ($r->truck_source ?? ''))) === 'MANUAL';
            $plate = $isManual ? trim((string) ($r->manual_plate_no ?? '')) : trim((string) ($r->master_plate_no ?? ''));
            $tripNo = max(1, (int) ($r->trip_no ?? 1));

            $otherDoc = trim((string) ($r->attach_docs_other ?? ''));
            $r->attach_docs_text = collect(explode(',', (string) ($r->attach_docs ?? '')))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->unique()
                ->map(fn($code) => $attachDocMap[$code] ?? $code)
                ->when($otherDoc !== '', fn($items) => $items->push($otherDoc))
                ->filter()
                ->unique()
                ->implode(', ');

            $r->package_text = collect([$r->part_f1 ?? null, $r->part_f2 ?? null, $r->part_f3 ?? null])
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->implode(' / ');
            $r->truck_group_key = ($plate !== '' ? $plate : '-') . '|trip:' . $tripNo;
            $r->truck_label = ($plate !== '' ? $plate : '-') . ' / เที่ยว ' . $tripNo;
            $r->truck_driver_name = trim((string) ($r->driver_name ?? '')) ?: trim((string) ($isManual ? $r->manual_driver_name : $r->master_driver_name));
            $r->truck_driver_phone = trim((string) ($r->driver_phone ?? '')) ?: trim((string) ($isManual ? $r->manual_driver_phone : $r->master_driver_phone));
            $r->truck_max_load = (float) ($isManual ? ($r->manual_max_load ?? 0) : ($r->master_max_load ?? 0));
            $r->truck_remark = trim((string) ($isManual ? $r->manual_remark : $r->master_remark));

            return $r;
        });

        $truckGroups = $rows->groupBy('truck_group_key')->map(function ($items) {
            $first = $items->first();

            $helperNames = collect([
                $items->pluck('helper1_name')->map(fn($x) => trim((string) $x))->filter()->first(),
                $items->pluck('helper2_name')->map(fn($x) => trim((string) $x))->filter()->first(),
                $items->pluck('helper3_name')->map(fn($x) => trim((string) $x))->filter()->first(),
                $items->pluck('helper4_name')->map(fn($x) => trim((string) $x))->filter()->first(),
                $items->pluck('helper5_name')->map(fn($x) => trim((string) $x))->filter()->first(),
            ])->filter()->implode(', ');

            return (object) [
                'truck_label' => $first->truck_label,
                'driver_name' => $first->truck_driver_name ?: '-',
                'driver_phone' => $first->truck_driver_phone ?: '-',
                'helper_names' => $helperNames,
                'max_load' => (float) ($first->truck_max_load ?? 0),
                'remark' => $first->truck_remark ?: '-',
                'item_count' => $items->count(),
                'total_qty' => $items->sum(fn($r) => (float) ($r->qty ?? 0)),
                'total_assigned' => $items->sum(fn($r) => (float) ($r->assigned_weight ?? 0)),
                'rows' => $items->values(),
                'first_assigned_at' => $items->pluck('assigned_at')->filter()->min(),
            ];
        })
            ->sortBy('first_assigned_at')
            ->values();

        $specialGroups = $this->getSpecialDispatchGroups($shipDate);
        $specialCount = $specialGroups->flatten(1)->count();

        return [
            'shipDate' => $shipDate,
            'truckGroups' => $truckGroups,
            'specialGroups' => $specialGroups,
            'summary' => (object) [
                'item_count' => $rows->count() + $specialCount,
                'assigned_item_count' => $rows->count(),
                'special_count' => $specialCount,
                'truck_count' => $truckGroups->count(),
                'total_qty' => $rows->sum(fn($r) => (float) ($r->qty ?? 0)),
                'total_assigned' => $rows->sum(fn($r) => (float) ($r->assigned_weight ?? 0)),
            ],
        ];
    }

    private function truckTripFilename(object $summary, string $ext): string
    {
        $plate = preg_replace('/[^A-Za-z0-9ก-๙_-]+/u', '_', (string) ($summary->truck_label ?? 'truck'));
        return 'DeliveryPlan-' . Carbon::parse($summary->ship_date)->format('Ymd') . '-' . $plate . '.' . $ext;
    }

    private function buildTruckTripDocumentData(Request $request): array
    {
        $data = $request->validate([
            'ship_date' => ['required', 'date'],
            'truck_source' => ['required', 'in:MASTER,MANUAL'],
            'truck_id' => ['nullable', 'integer'],
            'manual_plate_no' => ['nullable', 'string', 'max:50'],
            'trip_no' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $shipDate = Carbon::parse($data['ship_date'])->toDateString();
        $truckSource = strtoupper((string) $data['truck_source']);
        $truckId = (int) ($data['truck_id'] ?? 0);
        $manualPlateNo = trim((string) ($data['manual_plate_no'] ?? ''));
        $tripNo = max(1, (int) $data['trip_no']);

        $rows = $this->conn()
            ->table('delivery_plan_truck_assign as ta')
            ->join('delivery_plan_data as dp', 'dp.ord_id', '=', 'ta.ord_id')
            ->leftJoin('delivery_plan_truck_master as tm', 'tm.id', '=', 'ta.truck_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'dp.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'dp.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("dp.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            })
            ->where('ta.truck_source', $truckSource)
            ->whereRaw('CAST(ta.ship_posted_at AS date) = ?', [$shipDate])
            ->whereRaw('ISNULL(ta.trip_no, 1) = ?', [$tripNo])
            ->when($truckSource === 'MASTER', fn($q) => $q->where('ta.truck_id', $truckId))
            ->when($truckSource === 'MANUAL', fn($q) => $q->where('ta.manual_plate_no', $manualPlateNo))
            ->selectRaw("
                dp.ord_id, dp.ship_posted_at, dp.window_at, dp.so_number, dp.delivery_type,
                dp.part_number, dp.part_desc, p.f1 as part_f1, p.f2 as part_f2, p.f3 as part_f3,
                dp.mfg_no, dp.qty, dp.line_qty, dp.sell_by_line, dp.address, dp.remark,
                dp.edit_remark, dp.attach_docs, dp.attach_docs_other,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    c.name COLLATE DATABASE_DEFAULT
                ) as customer_name,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(dp.sales_id AS nvarchar(20)))
                ) AS sales_name,
                ta.assigned_weight, ta.trip_no, ta.closed_at, ta.driver_name, ta.driver_phone,
                ta.helper1_name, ta.helper2_name, ta.helper3_name,
                ta.helper4_name, ta.helper5_name, ta.manual_plate_no,
                ta.manual_driver_name, ta.manual_driver_phone, ta.manual_max_load,
                ta.manual_car_length, ta.manual_remark, tm.plate_no as master_plate_no,
                tm.driver_name as master_driver_name, tm.driver_phone as master_driver_phone,
                tm.max_load as master_max_load, tm.car_length as master_car_length,
                tm.remark as master_remark
            ")
            ->orderBy('dp.window_at')
            ->orderBy('dp.so_number')
            ->orderBy('dp.ord_id')
            ->get();

        abort_if($rows->isEmpty(), 404, 'Truck trip not found');

        $mfgTokens = $rows->flatMap(function ($r) {
            return collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter();
        })->unique()->values()->all();

        $typeMap = [];
        if (!empty($mfgTokens)) {
            DB::connection('pgsqlmfgw')
                ->table('workorder')
                ->select(['workordernumber', 'brand'])
                ->whereIn('workordernumber', $mfgTokens)
                ->get()
                ->each(function ($wo) use (&$typeMap) {
                    $typeMap[trim((string) $wo->workordernumber)] = trim((string) ($wo->brand ?? ''));
                });
        }

        $attachDocMap = $this->conn()
            ->table('attach_docs_master')
            ->where('active', 1)
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [strtoupper(trim((string) $code)) => trim((string) $name)])
            ->toArray();

        $rows = $rows->map(function ($r) use ($typeMap, $attachDocMap) {
            $isManual = trim((string) ($r->manual_plate_no ?? '')) !== '';
            $mfgList = collect(explode(',', (string) ($r->mfg_no ?? '')))
                ->map(fn($x) => trim((string) $x))
                ->filter();

            $r->type_display = $mfgList->map(fn($mfg) => $typeMap[$mfg] ?? null)
                ->filter()->unique()->implode(', ') ?: '-';

            $otherDoc = trim((string) ($r->attach_docs_other ?? ''));
            $r->attach_docs_text = collect(explode(',', (string) ($r->attach_docs ?? '')))
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->unique()
                ->map(fn($code) => $attachDocMap[$code] ?? $code)
                ->when($otherDoc !== '', fn($items) => $items->push($otherDoc))
                ->filter()
                ->unique()
                ->implode(', ');

            $r->package_text = collect([$r->part_f1 ?? null, $r->part_f2 ?? null, $r->part_f3 ?? null])
                ->map(fn($x) => trim((string) $x))
                ->filter()
                ->implode(' / ');

            $r->line_qty_display = ((int) ($r->sell_by_line ?? 0) === 1)
                ? (float) ($r->line_qty ?? 0)
                : null;
            $r->line_qty_unit = ((int) ($r->sell_by_line ?? 0) === 1 && (float) ($r->qty ?? 0) == 0.0)
                ? 'ชิ้น'
                : 'เส้น';

            $r->truck_plate_display = $isManual ? trim((string) ($r->manual_plate_no ?? '')) : trim((string) ($r->master_plate_no ?? ''));
            $r->truck_driver_name = trim((string) ($r->driver_name ?? '')) ?: trim((string) ($isManual ? $r->manual_driver_name : $r->master_driver_name));
            $r->truck_driver_phone = trim((string) ($r->driver_phone ?? '')) ?: trim((string) ($isManual ? $r->manual_driver_phone : $r->master_driver_phone));
            $r->truck_max_load = (float) ($isManual ? ($r->manual_max_load ?? 0) : ($r->master_max_load ?? 0));
            $r->truck_car_length = $isManual ? $r->manual_car_length : $r->master_car_length;
            $r->truck_remark = trim((string) ($isManual ? $r->manual_remark : $r->master_remark));

            return $r;
        });

        $first = $rows->first();

        $helperNames = collect([
            $rows->pluck('helper1_name')->map(fn($x) => trim((string) $x))->filter()->first(),
            $rows->pluck('helper2_name')->map(fn($x) => trim((string) $x))->filter()->first(),
            $rows->pluck('helper3_name')->map(fn($x) => trim((string) $x))->filter()->first(),
            $rows->pluck('helper4_name')->map(fn($x) => trim((string) $x))->filter()->first(),
            $rows->pluck('helper5_name')->map(fn($x) => trim((string) $x))->filter()->first(),
        ])->filter()->implode(', ');

        $summary = (object) [
            'ship_date' => $shipDate,
            'trip_no' => $tripNo,
            'truck_label' => ($first->truck_plate_display ?: '-') . ' / เที่ยว ' . $tripNo,
            'driver_name' => $first->truck_driver_name ?: '-',
            'driver_phone' => $first->truck_driver_phone ?: '-',
            'helper_names' => $helperNames,
            'max_load' => (float) ($first->truck_max_load ?? 0),
            'car_length' => $first->truck_car_length,
            'remark' => $first->truck_remark,
            'item_count' => $rows->count(),
            'total_qty' => $rows->sum(fn($r) => (float) ($r->qty ?? 0)),
            'total_assigned' => $rows->sum(fn($r) => (float) ($r->assigned_weight ?? 0)),
            'closed_at' => $rows->pluck('closed_at')->filter()->first(),
        ];

        return compact('rows', 'summary');
    }

    private function normalizeMaterial($f3): ?string
    {
        if ($f3 === null) {
            return null;
        }

        $value = strtoupper(trim((string) $f3));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', '', $value);

        if (in_array($value, ['0', '0.0', '0.00', '0.000', 'NULL', '-'], true)) {
            return null;
        }

        $value = str_replace('STAINLESS', '', $value);
        $value = str_replace('SUS', '', $value);
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function calcLineWeightKg(
        ?int $lineQty,
        $f1,
        $f2,
        $f3,
        ?string $description = null,
        ?string $partNumber = null
    ): ?float {
        $qty = (int) ($lineQty ?? 0);
        $dia = is_numeric($f1) ? (float) $f1 : 0.0;
        $lengthMm = is_numeric($f2) ? (float) $f2 : 0.0;
        $material = $this->normalizeMaterial($f3);

        $desc = strtoupper(trim((string) ($description ?? '')));
        $pn   = strtoupper(trim((string) ($partNumber ?? '')));

        if ($qty <= 0 || $dia <= 0 || $lengthMm <= 0 || $material === null) {
            return null;
        }

        // คิดเฉพาะ Bar / NCR
        $isBarLike =
            str_contains($desc, 'BAR') ||
            str_contains($desc, 'NCR') ||
            str_starts_with($pn, 'FI') ||
            str_starts_with($pn, 'FB') ||
            str_starts_with($pn, 'NC') ||
            str_starts_with($pn, 'FJ');

        if (!$isBarLike) {
            return null;
        }

        // กัน shape ที่ไม่ใช่เส้นกลม
        $blockedWords = [
            'ANGLE',
            'PIPE',
            'BOX',
            'PLATE',
            'RECTANGULAR',
            'SQUARE',
            'FLAT BAR',
            'TRIANGLE',
            'OVAL',
            'HOUSE SHAPE',
            'WEDGE',
            'RUBBER',
            'FRAME',
        ];

        foreach ($blockedWords as $word) {
            if (str_contains($desc, $word)) {
                return null;
            }
        }

        $lengthM = $lengthMm / 1000;

        return $qty * (($dia * $dia * 3.14 * 0.00793 * $lengthM) / 4);
    }

    private function getTruckUsageSummaryByShipDate(string $shipDate): array
    {
        $rows = $this->conn()
            ->table('delivery_plan_truck_assign as ta')
            ->join('delivery_plan_data as dp', 'dp.ord_id', '=', 'ta.ord_id')
            ->leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')
            ->leftJoin('workorder as wo', function ($join) {
                $join->on(
                    DB::raw('wo.workordernumber COLLATE DATABASE_DEFAULT'),
                    '=',
                    DB::raw('dp.mfg_no COLLATE DATABASE_DEFAULT')
                );
            })
            ->whereRaw('CAST(ta.ship_posted_at AS date) = ?', [$shipDate])
            ->selectRaw("
            ta.truck_source,
            ta.truck_id,
            ta.manual_plate_no,
            COALESCE(
                NULLIF(LTRIM(RTRIM(dp.customer_name COLLATE DATABASE_DEFAULT)), ''),
                NULLIF(LTRIM(RTRIM(c.name COLLATE DATABASE_DEFAULT)), ''),
                '-'
            ) as customer_name,
            ISNULL(wo.brand, '-') as job_type,
            ISNULL(dp.so_number, '-') as so_number,
            ISNULL(dp.mfg_no, '-') as mfg_no,
            SUM(ISNULL(ta.assigned_weight,0)) as assigned_weight
        ")
            ->groupBy(
                'ta.truck_source',
                'ta.truck_id',
                'ta.manual_plate_no',
                'dp.customer_name',
                'c.name',
                'wo.brand',
                'dp.so_number',
                'dp.mfg_no'
            )
            ->get();

        $map = [];

        foreach ($rows as $r) {
            $key = strtoupper((string)$r->truck_source) === 'MANUAL'
                ? 'MANUAL:' . trim((string)$r->manual_plate_no)
                : 'MASTER:' . (int)$r->truck_id;

            $map[$key]['job_summary'][] = [
                'customer_name' => (string)$r->customer_name,
                'job_type' => (string)$r->job_type,
                'assigned_weight' => (float)$r->assigned_weight,
            ];

            $map[$key]['so_set'][(string)$r->so_number] = true;
            $map[$key]['mfg_set'][(string)$r->mfg_no] = true;
        }

        foreach ($map as $key => $item) {
            $map[$key]['so_summary_text'] = implode(', ', array_keys($item['so_set'] ?? []));
            $map[$key]['mfg_summary_text'] = implode(', ', array_keys($item['mfg_set'] ?? []));
        }

        return $map;
    }

    private function fullStaffName(object $r): string
    {
        return trim(collect([
            $r->prefix_name ?? null,
            $r->first_name ?? null,
            $r->last_name ?? null,
        ])->filter(fn($x) => trim((string) $x) !== '')->implode(' '));
    }

    private function getTruckStaffMasterOptions(): array
    {
        $rows = $this->conn()
            ->table('delivery_plan_truck_staff_master')
            ->where('is_active', 1)
            ->orderBy('role_type')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $drivers = [];
        $helpers = [];

        foreach ($rows as $r) {
            $item = [
                'id' => (int) $r->id,
                'emp_code' => trim((string) ($r->emp_code ?? '')),
                'name' => $this->fullStaffName($r),
                'phone' => trim((string) ($r->phone ?? '')),
                'position_name' => trim((string) ($r->position_name ?? '')),
                'department_name' => trim((string) ($r->department_name ?? '')),
            ];

            if (strtoupper(trim((string) $r->role_type)) === 'DRIVER') {
                $drivers[] = $item;
            } else {
                $helpers[] = $item;
            }
        }

        return [
            'drivers' => $drivers,
            'helpers' => $helpers,
        ];
    }

    private function getTruckStaffDefaultsData(?int $truckId, ?string $manualPlateNo, ?string $shipDate): array
    {
        $truckId = (int) ($truckId ?? 0);
        $manualPlateNo = trim((string) $manualPlateNo);
        $shipDate = trim((string) $shipDate);

        $driver = null;
        $helpers = array_fill(0, 5, null);
        $applyLastAssignStaff = function ($last) use (&$driver, &$helpers) {
            if (!$last) {
                return false;
            }

            if (!empty($last->driver_name) || !empty($last->driver_staff_id)) {
                $driver = [
                    'id' => $last->driver_staff_id ? (int) $last->driver_staff_id : null,
                    'name' => trim((string) ($last->driver_name ?? '')),
                    'phone' => trim((string) ($last->driver_phone ?? '')),
                ];
            }

            $helpers = [
                [
                    'id' => $last->helper1_staff_id ? (int) $last->helper1_staff_id : null,
                    'name' => trim((string) ($last->helper1_name ?? '')),
                ],
                [
                    'id' => $last->helper2_staff_id ? (int) $last->helper2_staff_id : null,
                    'name' => trim((string) ($last->helper2_name ?? '')),
                ],
                [
                    'id' => $last->helper3_staff_id ? (int) $last->helper3_staff_id : null,
                    'name' => trim((string) ($last->helper3_name ?? '')),
                ],
                [
                    'id' => $last->helper4_staff_id ? (int) $last->helper4_staff_id : null,
                    'name' => trim((string) ($last->helper4_name ?? '')),
                ],
                [
                    'id' => $last->helper5_staff_id ? (int) $last->helper5_staff_id : null,
                    'name' => trim((string) ($last->helper5_name ?? '')),
                ],
            ];

            return true;
        };

        if ($truckId > 0) {
            $last = null;
            if ($shipDate !== '') {
                $last = $this->conn()
                    ->table('delivery_plan_truck_assign')
                    ->where('truck_source', 'MASTER')
                    ->where('truck_id', $truckId)
                    ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
                    ->orderByDesc('id')
                    ->first([
                        'driver_staff_id',
                        'driver_name',
                        'driver_phone',
                        'helper1_staff_id',
                        'helper1_name',
                        'helper2_staff_id',
                        'helper2_name',
                        'helper3_staff_id',
                        'helper3_name',
                        'helper4_staff_id',
                        'helper4_name',
                        'helper5_staff_id',
                        'helper5_name',
                    ]);
            }

            if ($applyLastAssignStaff($last)) {
                return [
                    'driver' => $driver,
                    'helpers' => $helpers,
                ];
            }

            $rows = $this->conn()
                ->table('delivery_plan_truck_staff_map as m')
                ->join('delivery_plan_truck_staff_master as s', 's.id', '=', 'm.staff_id')
                ->where('m.truck_id', $truckId)
                ->where('m.is_active', 1)
                ->where('s.is_active', 1)
                ->orderBy('m.assign_role')
                ->orderBy('m.seq_no')
                ->get([
                    'm.assign_role',
                    'm.seq_no',
                    's.id',
                    's.emp_code',
                    's.prefix_name',
                    's.first_name',
                    's.last_name',
                    's.phone',
                ]);

            foreach ($rows as $r) {
                $item = [
                    'id' => (int) $r->id,
                    'name' => $this->fullStaffName($r),
                    'phone' => trim((string) ($r->phone ?? '')),
                ];

                if (strtoupper(trim((string) $r->assign_role)) === 'DRIVER') {
                    $driver = $item;
                } else {
                    $seq = max(1, (int) ($r->seq_no ?? 1));
                    if ($seq >= 1 && $seq <= 5) {
                        $helpers[$seq - 1] = $item;
                    }
                }
            }

            if (!$driver) {
                $truck = $this->conn()
                    ->table('delivery_plan_truck_master')
                    ->where('id', $truckId)
                    ->first(['driver_name', 'driver_phone']);

                $driverName = trim((string) ($truck->driver_name ?? ''));
                $driverPhone = trim((string) ($truck->driver_phone ?? ''));

                if ($driverName !== '') {
                    $driverRow = $this->conn()
                        ->table('delivery_plan_truck_staff_master')
                        ->where('role_type', 'DRIVER')
                        ->where('is_active', 1)
                        ->whereRaw(
                            "LTRIM(RTRIM(ISNULL(prefix_name, '') + ' ' + ISNULL(first_name, '') + ' ' + ISNULL(last_name, ''))) = ?",
                            [$driverName]
                        )
                        ->first();

                    $driver = [
                        'id' => $driverRow ? (int) $driverRow->id : null,
                        'name' => $driverRow ? $this->fullStaffName($driverRow) : $driverName,
                        'phone' => $driverRow ? trim((string) ($driverRow->phone ?? '')) : $driverPhone,
                    ];
                }
            }
        }

        if ($truckId <= 0 && $manualPlateNo !== '' && $shipDate !== '') {
            $last = $this->conn()
                ->table('delivery_plan_truck_assign')
                ->where('truck_source', 'MANUAL')
                ->where('manual_plate_no', $manualPlateNo)
                ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
                ->orderByDesc('id')
                ->first([
                    'driver_staff_id',
                    'driver_name',
                    'driver_phone',
                    'helper1_staff_id',
                    'helper1_name',
                    'helper2_staff_id',
                    'helper2_name',
                    'helper3_staff_id',
                    'helper3_name',
                    'helper4_staff_id',
                    'helper4_name',
                    'helper5_staff_id',
                    'helper5_name',
                ]);

            if ($last) {
                $applyLastAssignStaff($last);
            }
        }

        return [
            'driver' => $driver,
            'helpers' => $helpers,
        ];
    }

    private function getTruckStaffSnapshotFromRequest(Request $request): array
    {
        $driverId  = (int) $request->input('driver_staff_id', 0);
        $helper1Id = (int) $request->input('helper1_staff_id', 0);
        $helper2Id = (int) $request->input('helper2_staff_id', 0);
        $helper3Id = (int) $request->input('helper3_staff_id', 0);
        $helper4Id = (int) $request->input('helper4_staff_id', 0);
        $helper5Id = (int) $request->input('helper5_staff_id', 0);

        $ids = collect([$driverId, $helper1Id, $helper2Id, $helper3Id, $helper4Id, $helper5Id])
            ->filter(fn($x) => (int) $x > 0)
            ->unique()
            ->values();

        $staffMap = $ids->isEmpty()
            ? collect()
            : $this->conn()
            ->table('delivery_plan_truck_staff_master')
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        $driver = $staffMap->get($driverId);
        $helper1 = $staffMap->get($helper1Id);
        $helper2 = $staffMap->get($helper2Id);
        $helper3 = $staffMap->get($helper3Id);
        $helper4 = $staffMap->get($helper4Id);
        $helper5 = $staffMap->get($helper5Id);

        return [
            'driver_staff_id' => $driver ? (int) $driver->id : null,
            'driver_name' => $driver ? $this->fullStaffName($driver) : null,
            'driver_phone' => $driver ? trim((string) ($driver->phone ?? '')) : null,

            'helper1_staff_id' => $helper1 ? (int) $helper1->id : null,
            'helper1_name' => $helper1 ? $this->fullStaffName($helper1) : null,

            'helper2_staff_id' => $helper2 ? (int) $helper2->id : null,
            'helper2_name' => $helper2 ? $this->fullStaffName($helper2) : null,

            'helper3_staff_id' => $helper3 ? (int) $helper3->id : null,
            'helper3_name' => $helper3 ? $this->fullStaffName($helper3) : null,

            'helper4_staff_id' => $helper4 ? (int) $helper4->id : null,
            'helper4_name' => $helper4 ? $this->fullStaffName($helper4) : null,

            'helper5_staff_id' => $helper5 ? (int) $helper5->id : null,
            'helper5_name' => $helper5 ? $this->fullStaffName($helper5) : null,
        ];
    }

    private function validateUniqueTruckStaffSelection(Request $request): void
    {
        // เด็กรถเท่านั้นที่ตรวจซ้ำกัน — คนขับเป็น text input (ไม่ใช่ staff_id) จึงไม่ต้องตรวจ
        $fields = [
            'helper1_staff_id' => 'เด็กรถ 1',
            'helper2_staff_id' => 'เด็กรถ 2',
            'helper3_staff_id' => 'เด็กรถ 3',
            'helper4_staff_id' => 'เด็กรถ 4',
            'helper5_staff_id' => 'เด็กรถ 5',
        ];

        $seen = [];
        foreach ($fields as $field => $label) {
            $id = (int) $request->input($field, 0);
            if ($id <= 0) {
                continue;
            }

            if (isset($seen[$id])) {
                throw ValidationException::withMessages([
                    $field => ["{$label} ซ้ำกับ {$seen[$id]} กรุณาเลือกพนักงานคนละคน"],
                ]);
            }

            $seen[$id] = $label;
        }
    }

    public function closeTruckTrip(Request $request)
    {
        $data = $request->validate([
            'truck_source' => ['required', 'in:MASTER,MANUAL'],
            'truck_id' => ['nullable', 'integer'],
            'manual_plate_no' => ['nullable', 'string', 'max:50'],
            'ship_date' => ['required', 'date'],
            'trip_no' => ['required', 'integer', 'min:1', 'max:99'],
            'closed_remark' => ['nullable', 'string', 'max:500'],
        ]);

        $truckSource = strtoupper((string) $data['truck_source']);
        $shipDate = Carbon::parse($data['ship_date'])->toDateString();
        $tripNo = max(1, (int) $data['trip_no']);
        $userId = auth()->check() ? (int) auth()->id() : null;
        $remark = trim((string) ($data['closed_remark'] ?? ''));

        $this->conn()->transaction(function () use ($truckSource, $data, $shipDate, $tripNo, $userId, $remark) {
            $q = $this->conn()
                ->table('delivery_plan_truck_assign')
                ->where('truck_source', $truckSource)
                ->whereRaw('CAST(ship_posted_at AS date) = ?', [$shipDate])
                ->whereRaw('ISNULL(trip_no, 1) = ?', [$tripNo]);

            if ($truckSource === 'MASTER') {
                $truckId = (int) ($data['truck_id'] ?? 0);
                if ($truckId <= 0) {
                    throw ValidationException::withMessages(['truck_id' => ['ไม่พบรถในระบบที่ต้องการปิดรอบ']]);
                }
                $q->where('truck_id', $truckId);
            } else {
                $plate = trim((string) ($data['manual_plate_no'] ?? ''));
                if ($plate === '') {
                    throw ValidationException::withMessages(['manual_plate_no' => ['ไม่พบทะเบียนรถนอกที่ต้องการปิดรอบ']]);
                }
                $q->where('manual_plate_no', $plate);
            }

            $assignments = $q->select(['id', 'ord_id', 'closed_at'])->get();
            if ($assignments->isEmpty()) {
                throw ValidationException::withMessages(['trip_no' => ['ไม่พบรายการของรอบรถนี้']]);
            }

            if ($assignments->every(fn($r) => !empty($r->closed_at))) {
                throw ValidationException::withMessages(['trip_no' => ['รอบรถนี้ปิดไปแล้ว']]);
            }

            $assignmentIds = $assignments->pluck('id')->all();
            $ordIds = $assignments->pluck('ord_id')->filter()->unique()->values()->all();

            $this->conn()
                ->table('delivery_plan_truck_assign')
                ->whereIn('id', $assignmentIds)
                ->update([
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'closed_remark' => $remark !== '' ? $remark : null,
                ]);

            if (!empty($ordIds)) {
                $this->conn()
                    ->table('delivery_plan_data')
                    ->whereIn('ord_id', $ordIds)
                    ->whereRaw("UPPER(ISNULL(status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')")
                    ->update([
                        'status' => 'CLOSED',
                        'revise_by' => $userId,
                    ]);
            }
        });

        return back()->with('success', 'ปิดรอบรถเรียบร้อย');
    }

    public function markSpecialDispatch(Request $request, int $ordId)
    {
        $data = $request->validate([
            'dispatch_type' => ['required', 'in:' . implode(',', array_keys(self::SPECIAL_DISPATCH_TYPES))],
            'remark' => ['nullable', 'string', 'max:500'],
            'return_url' => ['nullable', 'string', 'max:1000'],
            'ord_ids' => ['nullable', 'array'],
            'ord_ids.*' => ['integer', 'distinct'],
        ]);

        $dispatchType = strtoupper((string) $data['dispatch_type']);
        $status = $dispatchType === 'POSTPONED' ? 'POSTPONED' : 'SPECIAL';
        $userId = auth()->check() ? (int) auth()->id() : null;
        $remark = trim((string) ($data['remark'] ?? ''));
        $ordIds = collect($data['ord_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($ordIds->isEmpty() && $ordId > 0) {
            $ordIds = collect([$ordId]);
        }

        if ($ordIds->isEmpty()) {
            throw ValidationException::withMessages(['ord_ids' => ['กรุณาเลือกรายการอย่างน้อย 1 รายการ']]);
        }

        $this->conn()->transaction(function () use ($ordIds, $dispatchType, $status, $userId, $remark) {
            $rows = $this->conn()
                ->table('delivery_plan_data')
                ->whereIn('ord_id', $ordIds->all())
                ->get(['ord_id', 'status']);

            if ($rows->count() !== $ordIds->count()) {
                throw ValidationException::withMessages(['ord_ids' => ['พบรายการไม่ครบตามที่เลือก']]);
            }

            $blocked = $rows->first(function ($row) {
                $status = $this->normalizePlanStatus($row->status ?? null);
                return $this->isVoidedPlanStatus($status) || $status === 'CLOSED';
            });
            if ($blocked) {
                throw ValidationException::withMessages(['ord_ids' => ['มีรายการที่ปิดหรือยกเลิกแล้ว']]);
            }

            $this->conn()
                ->table('delivery_plan_special_dispatch')
                ->whereIn('ord_id', $ordIds->all())
                ->where('status', 'OPEN')
                ->update([
                    'status' => 'SUPERSEDED',
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'close_remark' => 'Replaced by new special dispatch action',
                ]);

            $this->conn()
                ->table('delivery_plan_truck_assign')
                ->whereIn('ord_id', $ordIds->all())
                ->delete();

            $specialRows = $ordIds->map(fn($oneOrdId) => [
                'ord_id' => $oneOrdId,
                'dispatch_type' => $dispatchType,
                'status' => 'OPEN',
                'remark' => $remark !== '' ? $remark : null,
                'action_by' => $userId,
                'action_at' => now(),
            ])
                ->all();

            $this->conn()
                ->table('delivery_plan_special_dispatch')
                ->insert($specialRows);

            $this->conn()
                ->table('delivery_plan_data')
                ->whereIn('ord_id', $ordIds->all())
                ->whereRaw("UPPER(ISNULL(status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED','CLOSED')")
                ->update([
                    'status' => $status,
                    'revise_by' => $userId,
                ]);
        });

        return redirect($data['return_url'] ?? url()->previous())->with('success', 'บันทึกช่องทางพิเศษแล้ว');
    }

    public function closeSpecialDispatch(Request $request, int $ordId)
    {
        $data = $request->validate([
            'close_remark' => ['nullable', 'string', 'max:500'],
            'return_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = auth()->check() ? (int) auth()->id() : null;
        $remark = trim((string) ($data['close_remark'] ?? ''));

        $this->conn()->transaction(function () use ($ordId, $userId, $remark) {
            $special = $this->conn()
                ->table('delivery_plan_special_dispatch')
                ->where('ord_id', $ordId)
                ->where('status', 'OPEN')
                ->orderByDesc('id')
                ->first();

            if (!$special) {
                throw ValidationException::withMessages(['ord_id' => ['ไม่พบงานพิเศษที่เปิดอยู่']]);
            }

            if (strtoupper((string) $special->dispatch_type) === 'POSTPONED') {
                throw ValidationException::withMessages(['ord_id' => ['งานเลื่อนต้องให้ DP revise/แก้วันส่งใหม่']]);
            }

            $this->conn()
                ->table('delivery_plan_special_dispatch')
                ->where('id', $special->id)
                ->update([
                    'status' => 'CLOSED',
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'close_remark' => $remark !== '' ? $remark : null,
                ]);

            $this->conn()
                ->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->whereRaw("UPPER(ISNULL(status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')")
                ->update([
                    'status' => 'CLOSED',
                    'revise_by' => $userId,
                ]);
        });

        return redirect($data['return_url'] ?? url()->previous())->with('success', 'ปิดงานพิเศษแล้ว');
    }

    public function reopenSpecialDispatch(Request $request, int $ordId)
    {
        $data = $request->validate([
            'return_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = auth()->check() ? (int) auth()->id() : null;

        $this->conn()->transaction(function () use ($ordId, $userId) {
            $special = $this->conn()
                ->table('delivery_plan_special_dispatch')
                ->where('ord_id', $ordId)
                ->where('status', 'CLOSED')
                ->orderByDesc('id')
                ->first();

            if (!$special) {
                throw ValidationException::withMessages(['ord_id' => ['ไม่พบงานพิเศษที่ปิดอยู่']]);
            }

            $this->conn()
                ->table('delivery_plan_special_dispatch')
                ->where('id', $special->id)
                ->update([
                    'status' => 'OPEN',
                    'closed_at' => null,
                    'closed_by' => null,
                    'close_remark' => null,
                ]);

            $dispatchType = strtoupper((string) ($special->dispatch_type ?? ''));
            $dpStatus = $dispatchType === 'POSTPONED' ? 'POSTPONED' : 'SPECIAL';

            $this->conn()
                ->table('delivery_plan_data')
                ->where('ord_id', $ordId)
                ->whereRaw("UPPER(ISNULL(status,'')) NOT IN ('VOID','VOIDED','CANCEL','CANCELED','CANCELLED')")
                ->update([
                    'status' => $dpStatus,
                    'revise_by' => $userId,
                ]);
        });

        return redirect($data['return_url'] ?? url()->previous())->with('success', 'เปิดงานพิเศษกลับแล้ว');
    }

    public function truckStaffOptions(Request $request)
    {
        return response()->json($this->getTruckStaffMasterOptions());
    }

    public function truckStaffDefaults(Request $request)
    {
        $truckId = (int) $request->query('truck_id', 0);
        $manualPlateNo = trim((string) $request->query('manual_plate_no', ''));
        $shipDate = trim((string) $request->query('ship_posted_at', ''));

        return response()->json(
            $this->getTruckStaffDefaultsData($truckId ?: null, $manualPlateNo, $shipDate)
        );
    }

    private function erpSaleOrderSyncScriptPath(): string
    {
        return trim((string) config('services.erp.saleorder_sync_script', ''));
    }

    private function erpWorkOrderSyncScriptPath(): string
    {
        return trim((string) config('services.erp.workorder_sync_script', ''));
    }

    private function erpDeliveryPlanSyncScripts()
    {
        return collect([
            ['label' => 'Sale Order', 'path' => $this->erpSaleOrderSyncScriptPath()],
            ['label' => 'Work Order', 'path' => $this->erpWorkOrderSyncScriptPath()],
        ])->filter(fn($script) => trim((string) ($script['path'] ?? '')) !== '')
            ->values();
    }

    private function erpSaleOrderSyncTimeout(): int
    {
        return max(60, (int) config('services.erp.saleorder_sync_timeout', 900));
    }

    private function storeErpSaleOrderSyncLastRun(array $data): void
    {
        Storage::disk('local')->put(
            self::ERP_SALEORDER_SYNC_RESULT_PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
