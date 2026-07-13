<?php

namespace App\Services\FormDP;

use App\Models\FormDP\DeliveryConfirmation;
use App\Services\FormDP\DeliveryConfirmationService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionStatusTrackingService
{
    private const STALE_RECEIVE_DAYS = 2;

    private const MFG_CONNECTIONS = [
        'WIRE' => 'pgsqlmfgw',
        'PLUS' => 'pgsqlmfgp',
    ];

    public function getDashboardData(array $filters, int $perPage, int $page): array
    {
        $dataError = null;

        try {
            $rows = $this->fetchProductionRows($filters)
                ->filter(fn($row) => $this->passesFilters($row, $filters))
                ->sortBy($this->inquiryLikeSort())
                ->values();

            $this->attachDeliveryConfirmations($rows);
        } catch (\Throwable $e) {
            $rows = collect();
            $dataError = $e->getMessage();
        }

        $postponeCount = $rows->filter(fn($r) => strtoupper((string) ($r->confirmation_status ?? '')) === 'POSTPONE')->count();
        $confirmationFilter = strtolower(trim((string) ($filters['confirmation_filter'] ?? 'all')));
        if ($confirmationFilter === 'postpone') {
            $rows = $rows->filter(fn($r) => strtoupper((string) ($r->confirmation_status ?? '')) === 'POSTPONE')->values();
        }

        $summary = $this->buildSummary($rows);
        $summary['postpone'] = $postponeCount;

        return [
            'filters' => $filters,
            'rows' => $this->paginateCollection($rows, $perPage, $page, [
                'path' => route('dp.production-status'),
                'query' => request()->query(),
            ]),
            'summary' => $summary,
            'insights' => $this->buildInsights($rows),
            'allRowsCount' => $rows->count(),
            'perPage' => $perPage,
            'dataError' => $dataError ? $this->friendlyConnectionError($dataError) : null,
            'usingDemoData' => $dataError !== null,
            'siteOptions' => [
                ['value' => '', 'label' => 'All sites'],
                ['value' => 'WIRE', 'label' => 'Wire'],
                ['value' => 'PLUS', 'label' => 'Plus'],
            ],
            'riskOptions' => [
                ['value' => '', 'label' => 'All risk'],
                ['value' => 'HIGH', 'label' => 'High'],
                ['value' => 'NORMAL', 'label' => 'Normal'],
                ['value' => 'NO_ROUTE', 'label' => 'No route'],
            ],
        ];
    }

    private function inquiryLikeSort(): array
    {
        return [
            ['sales_id', 'asc'],
            ['customer', 'asc'],
            ['delivery_type', 'asc'],
            ['part_number', 'asc'],
            ['ship_date_sort', 'asc'],
            ['priority', 'asc'],
            ['mfg_no', 'asc'],
        ];
    }

    private function exportSort(): array
    {
        return [
            ['ship_date', 'desc'],
            ['division_sort', 'asc'],
            ['division', 'asc'],
            ['sales_id', 'asc'],
            ['customer', 'asc'],
            ['delivery_type', 'asc'],
            ['part_number', 'asc'],
            ['priority', 'asc'],
            ['mfg_no', 'asc'],
        ];
    }

    public function getDetailData(string $mfgNo, ?string $site = null, ?string $returnUrl = null, $ordId = null, ?string $soNumber = null, array $detailFallback = []): array
    {
        $mfgNo = $this->normalizeMfgNo($mfgNo);
        $site = strtoupper(trim((string) $site));
        $dataError = null;
        $deliveryRows = collect();

        try {
            try {
                $deliveryRows = $this->fetchDeliveryRowsForMfg($mfgNo, $ordId, $soNumber);
                if ($deliveryRows->isEmpty() && trim((string) $soNumber) !== '') {
                    $deliveryRows = $this->fetchDeliveryRowsForMfg($mfgNo, null, $soNumber);
                }
                if ($deliveryRows->isEmpty() && (is_numeric($ordId) || trim((string) $soNumber) !== '')) {
                    $deliveryRows = $this->fetchDeliveryRowsForMfg($mfgNo);
                }
            } catch (\Throwable $e) {
                Log::warning('Production status detail Delivery Plan lookup failed', [
                    'mfg_no' => $mfgNo,
                    'site' => $site,
                    'message' => $e->getMessage(),
                ]);
            }

            [$connection, $workorder] = $this->findWorkorder($mfgNo, $site);
            if (!$connection || !$workorder) {
                if ($deliveryRows->isNotEmpty()) {
                    $fallbackSite = $site ?: 'WIRE';
                    $siteDeliveryRows = collect($this->deliveryMfgMap($deliveryRows)->get(
                        $this->siteMfgKey($fallbackSite, $mfgNo),
                        $deliveryRows
                    ));
                    $tracking = $this->rowFromDeliveryOnly($fallbackSite, $mfgNo, $siteDeliveryRows);
                    $workorder = (object) [
                        'id' => null,
                        'workordernumber' => $mfgNo,
                        'site' => $tracking->site,
                    ];
                    $steps = collect();
                } else {
                    abort(404);
                }
            } else {
                $steps = $this->fetchDetailSteps($connection, (int) $workorder->id);
                $tracking = $this->rowFromWorkorder($workorder, collect([$workorder->id => $steps]), $this->deliveryMfgMap($deliveryRows));
            }

            $tracking = $this->applyDetailFallback($tracking, $detailFallback);
            $steps = collect($tracking->steps ?? $steps);
        } catch (\Throwable $e) {
            Log::warning('Production status detail load failed', [
                'mfg_no' => $mfgNo,
                'site' => $site,
                'message' => $e->getMessage(),
            ]);
            $workorder = (object) [
                'id' => null,
                'workordernumber' => $mfgNo,
                'site' => $site,
            ];
            $tracking = $this->emptyTrackingRow($mfgNo, $site);
            $tracking = $this->applyDetailFallback($tracking, $detailFallback);
            $steps = collect();
            $dataError = $e->getMessage();
        }

        return [
            'mfgNo' => $mfgNo,
            'site' => $tracking->site,
            'tracking' => $tracking,
            'workorder' => $workorder,
            'deliveryRows' => $deliveryRows,
            'steps' => collect($steps),
            'returnUrl' => $this->safeReturnUrl($returnUrl),
            'dataError' => $dataError ? $this->friendlyConnectionError($dataError) : null,
            'usingDemoData' => false,
        ];
    }

    public function exportResponse(array $filters): StreamedResponse
    {
        $rows = $this->fetchProductionRows($filters)
            ->filter(fn($row) => $this->passesFilters($row, $filters))
            ->sortBy($this->exportSort())
            ->values();

        $this->attachDeliveryConfirmations($rows);

        $fileName = 'production_status_tracking_' . now('Asia/Bangkok')->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'Run No.',
                'Division',
                'Due date',
                'วันที่ส่ง',
                'ช่วงเวลารับส่ง',
                'ลูกค้า',
                'Part No',
                'Part Desc',
                'MFG No',
                'จำนวนส่ง',
                'ขายระบุเส้น/ชิ้น',
                'หน่วย',
                'StockFG',
                'SO',
                'สถานที่ส่ง',
                'หมายเหตุ',
                'สถานะผลิต',
                'ขั้นตอนปัจจุบัน',
                'Step',
                'Progress %',
                'ขั้นตอนที่เหลือ',
                'Risk Status',
                'NCR Count',
                'Machine Down',
                'Delivery Confirmation',
                'New Delivery Date',
                'Confirmed At',
                'Confirmed By',
                'Confirm Remark',
            ]);

            $currentGroupKey = null;
            $runNo = 0;
            $divisionLabels = $this->divisionLabels();
            $seenRowGroup = [];
            foreach ($rows as $row) {
                $shipDateKey = $this->dateOnly($row->ship_date ?? null) ?? '(ไม่ระบุวันที่ส่ง)';
                $divisionCode = trim((string) ($row->division ?? ''));
                if ($divisionCode === '') {
                    $divisionCode = 'UNKNOWN';
                }
                $groupKey = $shipDateKey . '|' . $divisionCode;
                if ($groupKey !== $currentGroupKey) {
                    $label = $divisionLabels[$divisionCode] ?? $divisionCode;
                    fputcsv($handle, ['== Division: ' . $label . ' ==']);
                    $currentGroupKey = $groupKey;
                }

                $runNo++;

                // Mirror web view: qty + line_qty are shown once per delivery group (row->group_key).
                $rowGroupKey = trim((string) ($row->group_key ?? ''));
                $showGroupOnce = $rowGroupKey === '' || !isset($seenRowGroup[$rowGroupKey]);
                if ($rowGroupKey !== '') {
                    $seenRowGroup[$rowGroupKey] = true;
                }

                fputcsv($handle, [
                    $runNo,
                    $row->division ?? '',
                    $this->dateOnly($row->dp_due_date ?? null) ?? '',
                    $this->dateOnly($row->ship_date ?? null) ?? '',
                    $this->timeOnly($row->window_at ?? null) ?? '',
                    $row->customer ?? '',
                    $this->csvText($row->part_number ?? $row->item ?? ''),
                    $row->item_desc ?? '',
                    $this->csvText($row->mfg_no ?? ''),
                    $showGroupOnce && is_numeric($row->qty_kg ?? null) ? number_format((float) $row->qty_kg, 3, '.', '') : '',
                    $showGroupOnce && is_numeric($row->line_qty ?? null) && (float) $row->line_qty > 0 ? number_format((float) $row->line_qty, 0, '.', '') : '',
                    $row->sale_type ?? '',
                    is_numeric($row->stock_fg ?? null) ? number_format((float) $row->stock_fg, 3, '.', '') : '',
                    $this->csvText($row->so_number ?? ''),
                    $row->ship_to ?? '',
                    $row->dp_remark ?? '',
                    $row->delivery_status ?? '',
                    $row->current_process ?? '',
                    $this->csvText($row->step_text ?? ''),
                    is_numeric($row->progress_pct ?? null) ? number_format((float) $row->progress_pct, 1, '.', '') : '',
                    $row->remaining_process instanceof Collection ? $row->remaining_process->implode(' -> ') : '',
                    $row->risk_display ?? ($row->risk_status ?? ''),
                    (int) ($row->ncr_count ?? 0),
                    (int) ($row->breakdown_count ?? 0),
                    DeliveryConfirmation::statusLabel($row->confirmation_status ?? null),
                    !empty($row->confirmation_new_delivery_date)
                        ? Carbon::parse($row->confirmation_new_delivery_date)->format('Y-m-d')
                        : '',
                    !empty($row->confirmation_confirmed_at)
                        ? Carbon::parse($row->confirmation_confirmed_at)->format('Y-m-d H:i')
                        : '',
                    (string) ($row->confirmation_confirmed_by ?? ''),
                    (string) ($row->confirmation_remark ?? ''),
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    private function attachDeliveryConfirmations(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        try {
            $service = app(DeliveryConfirmationService::class);
            $mfgNos = $rows
                ->map(fn($r) => $this->normalizeMfgNo((string) ($r->mfg_no ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $sites = $rows
                ->map(fn($r) => strtoupper((string) ($r->site ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $map = $service->latestMap($mfgNos, $sites);
        } catch (\Throwable $e) {
            Log::warning('attachDeliveryConfirmations failed', ['message' => $e->getMessage()]);
            return;
        }

        foreach ($rows as $row) {
            $key = strtoupper((string) ($row->site ?? '')) . '|' . $this->normalizeMfgNo((string) ($row->mfg_no ?? ''));
            $entry = $map->get($key);

            $row->confirmation_status = $entry->confirmation_status ?? null;
            $row->confirmation_status_label = DeliveryConfirmation::statusLabel($entry->confirmation_status ?? null);
            $row->confirmation_badge_class = DeliveryConfirmation::statusBadgeClass($entry->confirmation_status ?? null);
            $row->confirmation_new_delivery_date = $entry->new_delivery_date ?? null;
            $row->confirmation_confirmed_at = $entry->confirmed_at ?? null;
            $row->confirmation_confirmed_by = $entry->confirmed_by_name ?? ($entry->confirmed_by_login ?? null);
            $row->confirmation_remark = $entry->remark ?? null;
        }
    }

    private function fetchProductionRows(array $filters): Collection
    {
        $deliveryRows = $this->withDeliveryStockFg($this->fetchDeliveryPlanRows($filters));
        $deliveryMfgMap = $this->deliveryMfgMap($deliveryRows);
        $mfgNosBySite = $this->deliveryMfgNosBySite($deliveryRows);

        if ($deliveryMfgMap->isEmpty()) {
            return collect();
        }

        $selectedSite = strtoupper(trim((string) ($filters['site'] ?? '')));
        $connections = self::MFG_CONNECTIONS;
        if ($selectedSite !== '' && isset($connections[$selectedSite])) {
            $connections = [$selectedSite => $connections[$selectedSite]];
        }

        $rows = collect();
        $foundKeys = collect();

        foreach ($connections as $site => $connection) {
            $siteMfgNos = collect($mfgNosBySite[$site] ?? [])->values()->all();
            if (empty($siteMfgNos)) {
                continue;
            }

            $workorders = $this->fetchSummaryWorkorders($connection, $site, $siteMfgNos);
            $stepMap = $this->fetchSummarySteps($connection, $workorders->pluck('id')->all());

            $foundKeys = $foundKeys->merge(
                $workorders->map(fn($workorder) => $this->siteMfgKey($site, $this->normalizeMfgNo($workorder->mfg_no ?? '')))
            );

            $rows = $rows->merge(
                $workorders->flatMap(fn($workorder) => $this->rowsFromWorkorderDeliveryLines($workorder, $stepMap, $deliveryMfgMap))
            );
        }

        $fallbackRows = collect();
        foreach ($mfgNosBySite as $site => $mfgNos) {
            if ($selectedSite !== '' && $site !== $selectedSite) {
                continue;
            }

            foreach (collect($mfgNos) as $rawMfgNo) {
                $mfgNo = $this->normalizeMfgNo($rawMfgNo);
                $key = $this->siteMfgKey($site, $mfgNo);
                if ($mfgNo === '' || $foundKeys->contains($key)) {
                    continue;
                }

                $deliveryRowsForMfg = collect($deliveryMfgMap->get($key, collect()));
                if ($deliveryRowsForMfg->isNotEmpty()) {
                    $fallbackRows = $fallbackRows->merge($this->rowsFromDeliveryOnlyLines($site, $mfgNo, $deliveryRowsForMfg));
                    $foundKeys->push($key);
                }
            }
        }

        return $rows->merge($fallbackRows)->values();
    }

    private function fetchDeliveryPlanRows(array $filters): Collection
    {
        $from = Carbon::parse($filters['ship_from'])->startOfDay();
        $to = Carbon::parse($filters['ship_to'])->endOfDay();
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $mfgKw = trim((string) ($filters['mfg'] ?? ''));
        $soKw = trim((string) ($filters['so'] ?? ''));
        $customerKw = trim((string) ($filters['customer'] ?? ''));
        $itemKw = trim((string) ($filters['item'] ?? ''));
        $deliveryStatus = strtoupper(trim((string) ($filters['delivery_status'] ?? 'NEW')));

        $query = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->select([
                'd.ord_id',
                'd.mfg_no',
                'd.so_number',
                'd.delivery_type',
                'd.sales_id',
                'd.part_number',
                'd.part_desc',
                'd.qty',
                'd.sell_by_line',
                'd.line_qty',
                'd.status',
                'd.due_date',
                'd.ship_posted_at',
                'd.window_at',
                'd.address',
                'd.remark',
                'd.due_date_remark',
                'd.edit_remark',
                'c.customernumber',
                DB::raw("COALESCE(NULLIF(LTRIM(RTRIM(c.name)), ''), '') as customer_name"),
                DB::raw("
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                        NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                        NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                        CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                    ) as sales_name
                "),
            ])
            ->whereNotNull('d.mfg_no')
            ->whereRaw("NULLIF(LTRIM(RTRIM(d.mfg_no)), '') IS NOT NULL")
            ->whereBetween('d.ship_posted_at', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ]);

        if ($deliveryStatus === '' || $deliveryStatus === 'ALL') {
            $query->where(function ($q) {
                $q->whereNull('d.status')
                    ->orWhere('d.status', '!=', 'VOID');
            });
        } else {
            $query->where('d.status', $deliveryStatus);
        }

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('d.mfg_no', 'like', $like)
                    ->orWhere('d.so_number', 'like', $like)
                    ->orWhere('d.part_number', 'like', $like)
                    ->orWhere('d.part_desc', 'like', $like)
                    ->orWhere('c.name', 'like', $like)
                    ->orWhere('c.customernumber', 'like', $like)
                    ->orWhere('s.sales_name', 'like', $like)
                    ->orWhere('e.name', 'like', $like)
                    ->orWhere('e.login', 'like', $like)
                    ->orWhereRaw('CAST(d.sales_id AS nvarchar(20)) LIKE ?', [$like]);
            });
        }

        if ($mfgKw !== '') {
            $query->where('d.mfg_no', 'like', '%' . $mfgKw . '%');
        }

        if ($soKw !== '') {
            $query->where('d.so_number', 'like', '%' . $soKw . '%');
        }

        if ($customerKw !== '') {
            $like = '%' . $customerKw . '%';
            $query->where(function ($q) use ($like) {
                $q->where('c.name', 'like', $like)
                    ->orWhere('c.customernumber', 'like', $like);
            });
        }

        if ($itemKw !== '') {
            $like = '%' . $itemKw . '%';
            $query->where(function ($q) use ($like) {
                $q->where('d.part_number', 'like', $like)
                    ->orWhere('d.part_desc', 'like', $like);
            });
        }

        return $query
            ->orderBy('d.ship_posted_at')
            ->orderBy('d.ord_id')
            ->get();
    }

    private function fetchDeliveryRowsForMfg(string $mfgNo, $ordId = null, ?string $soNumber = null): Collection
    {
        $normalized = $this->normalizeMfgNo($mfgNo);
        if ($normalized === '') {
            return collect();
        }

        $like = '%' . $normalized . '%';

        $query = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->select([
                'd.ord_id',
                'd.mfg_no',
                'd.so_number',
                'd.delivery_type',
                'd.sales_id',
                'd.part_number',
                'd.part_desc',
                'd.qty',
                'd.sell_by_line',
                'd.line_qty',
                'd.status',
                'd.due_date',
                'd.ship_posted_at',
                'd.window_at',
                'd.address',
                'd.remark',
                'd.due_date_remark',
                'd.edit_remark',
                'c.customernumber',
                DB::raw("COALESCE(NULLIF(LTRIM(RTRIM(c.name)), ''), '') as customer_name"),
                DB::raw("
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                        NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                        NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                        CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                    ) as sales_name
                "),
            ])
            ->whereNotNull('d.mfg_no')
            ->where('d.mfg_no', 'like', $like)
            ->where(function ($q) {
                $q->whereNull('d.status')
                    ->orWhereNotIn('d.status', ['CANCEL', 'CANCELLED']);
            });

        if (is_numeric($ordId)) {
            $query->where('d.ord_id', (int) $ordId);
        }

        $soNumber = trim((string) $soNumber);
        if ($soNumber !== '') {
            $query->where('d.so_number', $soNumber);
        }

        return $query
            ->orderByDesc('d.ship_posted_at')
            ->get()
            ->filter(fn($row) => $this->splitMfgNos($row->mfg_no ?? '')->contains($normalized))
            ->values();
    }

    private function withDeliveryStockFg(Collection $deliveryRows): Collection
    {
        $partNumbers = $deliveryRows
            ->pluck('part_number')
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($partNumbers->isEmpty()) {
            return $deliveryRows->each(fn($row) => $row->stock_fg = 0.0);
        }

        $stockMap = [];
        foreach (['pgsqlw', 'pgsqlp'] as $connection) {
            try {
                $stockRows = DB::connection($connection)
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->whereIn('p.partnumber', $partNumbers->all())
                    ->groupBy('p.partnumber')
                    ->selectRaw('p.partnumber, SUM(CASE WHEN su.onhand THEN sus.qty ELSE 0 END) AS balance_qty')
                    ->get();

                foreach ($stockRows as $stockRow) {
                    $partNumber = trim((string) ($stockRow->partnumber ?? ''));
                    if ($partNumber === '') {
                        continue;
                    }
                    $stockMap[$partNumber] = ($stockMap[$partNumber] ?? 0.0) + (float) ($stockRow->balance_qty ?? 0);
                }
            } catch (\Throwable $e) {
                Log::warning('Production status StockFG lookup failed', [
                    'connection' => $connection,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $deliveryRows->each(function ($row) use ($stockMap) {
            $partNumber = trim((string) ($row->part_number ?? ''));
            $row->stock_fg = $stockMap[$partNumber] ?? 0.0;
        });
    }


    private function deliveryMfgMap(Collection $deliveryRows): Collection
    {
        $map = collect();

        foreach ($deliveryRows as $row) {
            foreach ($this->splitMfgTokens($row->mfg_no ?? '') as $token) {
                $site = str_starts_with($token, '+') ? 'PLUS' : 'WIRE';
                $mfgNo = $this->normalizeMfgNo($token);
                $key = $this->siteMfgKey($site, $mfgNo);

                if ($mfgNo === '') {
                    continue;
                }

                if (!$map->has($key)) {
                    $map->put($key, collect());
                }

                $map->put($key, $map->get($key)->push($row));
            }
        }

        return $map;
    }

    private function deliveryMfgNosBySite(Collection $deliveryRows): array
    {
        $bySite = [
            'WIRE' => collect(),
            'PLUS' => collect(),
        ];

        foreach ($deliveryRows as $row) {
            foreach ($this->splitMfgTokens($row->mfg_no ?? '') as $token) {
                $site = str_starts_with($token, '+') ? 'PLUS' : 'WIRE';
                $mfgNo = $this->normalizeMfgNo($token);

                if ($mfgNo !== '') {
                    $bySite[$site]->push($site === 'PLUS' ? '+' . $mfgNo : $mfgNo);
                    if ($site === 'PLUS') {
                        $bySite[$site]->push($mfgNo);
                    }
                }
            }
        }

        return [
            'WIRE' => $bySite['WIRE']->unique()->values(),
            'PLUS' => $bySite['PLUS']->unique()->values(),
        ];
    }

    private function splitMfgNos($value): Collection
    {
        return $this->splitMfgTokens($value)
            ->map(fn($item) => $this->normalizeMfgNo($item))
            ->filter()
            ->unique()
            ->values();
    }

    private function splitMfgTokens($value): Collection
    {
        $tokens = collect();
        $lastPrefix = null;
        $lastNumber = null;
        $lastPlus = false;
        $value = $this->stripCoilSuffix($value);

        foreach (explode(',', (string) ($value ?? '')) as $rawPart) {
            $subParts = $this->splitPlusJoinedMfgPart($rawPart);

            foreach ($subParts as $rawSubPart) {
                $part = strtoupper(trim($this->stripCoilSuffix($rawSubPart), " \t\n\r\0\x0B'\""));
                if ($part === '') {
                    continue;
                }

                $isPlus = str_starts_with($part, '+');
                $clean = ltrim($part, '+');
                $clean = preg_replace('/\s*\([^)]*\)\s*/', '', $clean) ?? $clean;
                $clean = trim($clean);

                if (preg_match('/^([A-Z]+)(\d+)$/', $clean, $m)) {
                    $lastPrefix = $m[1];
                    $lastNumber = $m[2];
                    $lastPlus = $isPlus;
                    $tokens->push(($isPlus ? '+' : '') . $clean);
                    continue;
                }

                if (preg_match('/^([A-Z]+)(\d+)-([A-Z]+)?(\d+)$/', $clean, $m)) {
                    $lastPrefix = $m[1];
                    $lastPlus = $isPlus;
                    $endPrefix = $m[3] ?: $m[1];
                    $expandedRows = strtoupper($endPrefix) === $m[1]
                        ? $this->expandMfgRange($m[1], $m[2], $m[4])
                        : [$m[1] . $m[2], $endPrefix . $m[4]];
                    foreach ($expandedRows as $expanded) {
                        $tokens->push(($isPlus ? '+' : '') . $expanded);
                    }
                    $lastNumber = preg_replace('/^\D+/', '', end($expandedRows)) ?: $m[2];
                    continue;
                }

                if ($lastPrefix && preg_match('/^(\d+)$/', $clean, $m)) {
                    $expanded = $this->expandShortMfg($lastPrefix, $m[1], $lastNumber);
                    $tokens->push(($lastPlus ? '+' : '') . $expanded);
                    $lastNumber = preg_replace('/^\D+/', '', $expanded) ?: $lastNumber;
                    continue;
                }

                if ($lastPrefix && preg_match('/^(\d+)-(\d+)$/', $clean, $m)) {
                    $expandedRows = $this->expandMfgRange($lastPrefix, $m[1], $m[2], $lastNumber);
                    foreach ($expandedRows as $expanded) {
                        $tokens->push(($lastPlus ? '+' : '') . $expanded);
                    }
                    $lastNumber = preg_replace('/^\D+/', '', end($expandedRows)) ?: $lastNumber;
                    continue;
                }

                $tokens->push(($isPlus ? '+' : '') . $clean);
            }
        }

        return $tokens->filter()->unique()->values();
    }

    private function stripCoilSuffix($value): string
    {
        $value = (string) ($value ?? '');

        return trim(preg_replace('/\s+COIL\s*\.?\s*\d+(?:\s*[-,]\s*\d+)*/i', '', $value) ?? $value);
    }

    private function splitPlusJoinedMfgPart($value): array
    {
        $part = trim((string) ($value ?? ''));
        if ($part === '') {
            return [];
        }

        $isPlusMfg = str_starts_with($part, '+');
        $body = $isPlusMfg ? substr($part, 1) : $part;
        if (!str_contains($body, '+')) {
            return [$part];
        }

        $chunks = array_values(array_filter(array_map('trim', explode('+', $body)), fn($item) => $item !== ''));
        if (empty($chunks)) {
            return [$part];
        }

        $chunks[0] = ($isPlusMfg ? '+' : '') . $chunks[0];

        return $chunks;
    }

    private function expandMfgRange(string $prefix, string $start, string $end, ?string $reference = null): array
    {
        $expandedStart = $this->expandShortMfg($prefix, $start, $reference);
        $startNumber = preg_replace('/^\D+/', '', $expandedStart) ?: $start;
        $expandedEnd = $this->expandShortMfg($prefix, $end, $startNumber);
        $startInt = (int) $startNumber;
        $endInt = (int) preg_replace('/^\D+/', '', $expandedEnd);
        $width = strlen($startNumber);

        if ($endInt < $startInt || ($endInt - $startInt) > 50) {
            return [$expandedStart, $expandedEnd];
        }

        $rows = [];
        for ($i = $startInt; $i <= $endInt; $i++) {
            $rows[] = $prefix . str_pad((string) $i, $width, '0', STR_PAD_LEFT);
        }

        return $rows;
    }

    private function expandShortMfg(string $prefix, string $shortNumber, ?string $reference): string
    {
        if ($reference === null || strlen($shortNumber) >= strlen($reference)) {
            return $prefix . $shortNumber;
        }

        return $prefix . substr($reference, 0, strlen($reference) - strlen($shortNumber)) . $shortNumber;
    }

    private function fetchSummaryWorkorders(string $connection, string $site, array $mfgNos): Collection
    {
        if (empty($mfgNos)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($mfgNos), '?'));

        $sql = <<<'SQL'
SELECT
  wo.id,
  ?::text                                     AS site,
  wo.workordernumber                          AS mfg_no,
  c.name                                      AS customer,
  p.partnumber                                AS part_number,
  COALESCE(wo.description, '')                AS workorder_desc,
  p.partnumber || ' ' || COALESCE(wo.description, '') AS item,
  wo.reqdate                                  AS due_date,
  wo.priority                                 AS priority,

  CASE
    WHEN done_step.cnt >= total.cnt THEN 'Completed'
    ELSE COALESCE(cur_wc.workcenternumber, 'Not Started')
  END                                         AS current_process,

  done_step.cnt::text || '/' || total.cnt::text AS step_text,
  done_step.cnt                               AS done_steps,
  total.cnt                                   AS total_steps,

  CASE
    WHEN done_step.cnt >= total.cnt THEN 100.0
    ELSE ROUND(done_step.cnt * 100.0 / NULLIF(total.cnt, 0), 1)
  END                                         AS progress_pct,

  CASE
    WHEN done_step.cnt >= total.cnt THEN '-'
    ELSE COALESCE(remain.steps, '-')
  END                                         AS remaining_process_text,

  CASE
    WHEN done_step.cnt >= total.cnt THEN 'Completed'
    WHEN wo.reqdate >= CURRENT_DATE THEN 'On Track'
    ELSE 'Delayed'
  END                                         AS delivery_status,

  CASE
    WHEN done_step.cnt >= total.cnt THEN 'Normal'
    WHEN ncr_open.cnt > 0 OR bd_open.cnt > 0 THEN 'At Risk'
    ELSE 'Normal'
  END                                         AS risk_status,

  COALESCE(ncr_open.cnt, 0)                  AS ncr_count,
  COALESCE(bd_open.cnt, 0)                   AS breakdown_count

FROM workorder wo
JOIN customer c ON c.id = wo.customer_id
JOIN parts    p ON p.id = wo.parts_id

JOIN LATERAL (
  SELECT COUNT(DISTINCT workseq) AS cnt
  FROM workorderworkcenter
  WHERE workorder_id = wo.id
) total ON true

JOIN LATERAL (
  SELECT COUNT(DISTINCT received_step.workseq) AS cnt
  FROM (
    SELECT workseq, workcenter_id, SUM(qty) AS received_qty
    FROM workorderreceive
    WHERE workorder_id = wo.id
    GROUP BY workseq, workcenter_id
  ) received_step
  WHERE (
    COALESCE(wo.qty, 0) > 0
    AND COALESCE(received_step.received_qty, 0) >= (COALESCE(wo.qty, 0) * 0.98)
  ) OR (
    COALESCE(wo.qty, 0) <= 0
    AND COALESCE(received_step.received_qty, 0) > 0
  )
) done_step ON true

LEFT JOIN LATERAL (
  SELECT wc.workcenternumber
  FROM workorderreceive wr
  JOIN workcenter wc ON wc.id = wr.workcenter_id
  WHERE wr.workorder_id = wo.id
  ORDER BY wr.receivestamp DESC
  LIMIT 1
) cur_wc ON true

LEFT JOIN LATERAL (
  SELECT STRING_AGG(wc.workcenternumber, ' -> ' ORDER BY wowc.workseq) AS steps
  FROM workorderworkcenter wowc
  JOIN workcenter wc ON wc.id = wowc.workcenter_id
  WHERE wowc.workorder_id = wo.id
    AND NOT EXISTS (
      SELECT 1 FROM workorderreceive wr
      WHERE wr.workorder_id = wo.id
        AND wr.workcenter_id = wowc.workcenter_id
        AND wr.workseq = wowc.workseq
      GROUP BY wr.workseq, wr.workcenter_id
      HAVING (
        COALESCE(wo.qty, 0) > 0
        AND COALESCE(SUM(wr.qty), 0) >= (COALESCE(wo.qty, 0) * 0.98)
      ) OR (
        COALESCE(wo.qty, 0) <= 0
        AND COALESCE(SUM(wr.qty), 0) > 0
      )
    )
) remain ON true

LEFT JOIN LATERAL (
  SELECT COUNT(*) AS cnt
  FROM ncrwowc nw
  JOIN ncr ON ncr.id = nw.ncr_id AND ncr.rejected = false
  JOIN workorderworkcenter wowc ON wowc.id = nw.wowc_id
  WHERE wowc.workorder_id = wo.id
) ncr_open ON true

LEFT JOIN LATERAL (
  SELECT COUNT(*) AS cnt
  FROM breakdown bd
  JOIN workmachine wm ON wm.id = bd.workmachine_id
  JOIN workorderreceive wr ON wr.workmachine_id = wm.id
    AND wr.workorder_id = wo.id
  WHERE bd.done = false
) bd_open ON true

WHERE wo.approved = true
  AND wo.workordernumber IN (__MFG_PLACEHOLDERS__)
ORDER BY wo.reqdate ASC, wo.priority ASC
SQL;

        $sql = str_replace('__MFG_PLACEHOLDERS__', $placeholders, $sql);

        return collect(DB::connection($connection)->select($sql, array_merge([$site], $mfgNos)));
    }

    private function fetchSummarySteps(string $connection, array $workorderIds): Collection
    {
        if (empty($workorderIds)) {
            return collect();
        }

        $rows = collect();
        foreach (array_chunk($workorderIds, 200) as $chunk) {
            $detailRows = DB::connection($connection)
                ->table('workorderworkcenter as wowc')
                ->join('workorder as wo', 'wo.id', '=', 'wowc.workorder_id')
                ->join('workcenter as wc', 'wc.id', '=', 'wowc.workcenter_id')
                ->leftJoin(DB::raw("(
                    SELECT
                        wr.workorder_id,
                        wr.workcenter_id,
                        wr.workseq,
                        SUM(wr.qty) AS qty,
                        MAX(wr.receivestamp) AS receivestamp,
                        MAX(wm.machinenumber) AS workmachine
                    FROM workorderreceive wr
                    LEFT JOIN workmachine wm ON wm.id = wr.workmachine_id
                    WHERE wr.workorder_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    GROUP BY wr.workorder_id, wr.workcenter_id, wr.workseq
                ) r"), function ($join) {
                    $join->on('r.workorder_id', '=', 'wowc.workorder_id')
                        ->on('r.workcenter_id', '=', 'wowc.workcenter_id')
                        ->on('r.workseq', '=', 'wowc.workseq');
                })
                ->whereIn('wowc.workorder_id', $chunk)
                ->orderBy('wowc.workorder_id')
                ->orderBy('wowc.workseq')
                ->select([
                    'wowc.workorder_id',
                    'wowc.workseq',
                    'wc.workcenternumber',
                    'wc.description as workcenter_name',
                    'wo.qty as target_qty',
                ])
                ->selectRaw('COALESCE(r.qty, 0) as received_qty')
                ->selectRaw('r.workmachine')
                ->selectRaw('r.receivestamp')
                ->addBinding($chunk, 'join')
                ->get();

            $rows = $rows->merge($detailRows);
        }

        return $rows->groupBy('workorder_id');
    }

    private function fetchDetailSteps(string $connection, int $workorderId): Collection
    {
        $sql = <<<'SQL'
SELECT
  wowc.workorder_id,
  wowc.workseq                              AS seq,
  wc.workcenternumber                       AS code,
  wc.description                            AS name,
  wowc.msizein                              AS size_in,
  wowc.msize                                AS size_out,
  wo.qty                                    AS target_qty,

  COALESCE(r.qty, 0)                        AS received_qty,
  COALESCE(r.workmachine, '-')              AS machine,
  r.receivestamp                            AS received_time,

  CASE
    WHEN COALESCE(r.qty, 0) >= (COALESCE(wo.qty, 0) * 0.98) AND COALESCE(wo.qty, 0) > 0 THEN 'Completed'
    WHEN COALESCE(wo.qty, 0) <= 0 AND COALESCE(r.qty, 0) > 0 THEN 'Completed'
    WHEN COALESCE(r.qty, 0) > 0 THEN 'In Progress'
    ELSE 'Pending'
  END                                       AS status,

  COALESCE(ncr_s.cnt, 0)                    AS ncr_count

FROM workorderworkcenter wowc
JOIN workorder wo ON wo.id = wowc.workorder_id
JOIN workcenter wc ON wc.id = wowc.workcenter_id

LEFT JOIN (
  SELECT
    wr.workcenter_id,
    wr.workseq,
    SUM(wr.qty)                             AS qty,
    MAX(wr.receivestamp)                    AS receivestamp,
    MAX(wm.machinenumber)                   AS workmachine
  FROM workorderreceive wr
  LEFT JOIN workmachine wm ON wm.id = wr.workmachine_id
  WHERE wr.workorder_id = ?
  GROUP BY wr.workcenter_id, wr.workseq
) r ON r.workcenter_id = wowc.workcenter_id
    AND r.workseq = wowc.workseq

LEFT JOIN (
  SELECT nw.wowc_id, COUNT(*) AS cnt
  FROM ncrwowc nw
  JOIN ncr ON ncr.id = nw.ncr_id AND ncr.rejected = false
  GROUP BY nw.wowc_id
) ncr_s ON ncr_s.wowc_id = wowc.id

WHERE wowc.workorder_id = ?
ORDER BY wowc.workseq
SQL;

        return $this->completePassedSteps(
            collect(DB::connection($connection)->select($sql, [$workorderId, $workorderId]))
                ->map(fn($step) => $this->normalizeStep($step))
                ->values()
        );
    }

    private function findWorkorder(string $mfgNo, string $site): array
    {
        $connections = self::MFG_CONNECTIONS;
        if ($site !== '' && isset($connections[$site])) {
            $connections = [$site => $connections[$site]];
        }

        foreach ($connections as $siteLabel => $connection) {
            $candidates = $this->workorderNumberCandidates($mfgNo, $siteLabel);

            $workorder = DB::connection($connection)
                ->table('workorder as wo')
                ->join('customer as c', 'c.id', '=', 'wo.customer_id')
                ->join('parts as p', 'p.id', '=', 'wo.parts_id')
                ->whereIn('wo.workordernumber', $candidates)
                ->select([
                    'wo.id',
                    'wo.workordernumber as mfg_no',
                    'wo.reqdate as due_date',
                    'wo.priority',
                    'c.name as customer',
                    'p.partnumber as part_number',
                    'wo.description as workorder_desc',
                ])
                ->selectRaw("p.partnumber || ' ' || COALESCE(wo.description, '') AS item")
                ->selectRaw('? as site', [$siteLabel])
                ->first();

            if ($workorder) {
                return [$connection, $workorder];
            }
        }

        return [null, null];
    }

    private function workorderNumberCandidates(string $mfgNo, string $site): array
    {
        $normalized = $this->normalizeMfgNo($mfgNo);

        if ($site === 'PLUS') {
            return ['+' . $normalized, $normalized];
        }

        return [$normalized];
    }

    private function rowFromWorkorder(object $workorder, Collection $stepMap, ?Collection $deliveryMfgMap = null): object
    {
        $steps = collect($stepMap->get($workorder->id, collect()))
            ->map(fn($step) => $this->normalizeStep($step))
            ->values();
        $steps = $this->completePassedSteps($steps);
        $rawMfgNo = trim((string) ($workorder->mfg_no ?? $workorder->workordernumber ?? ''));
        $mfgNo = $this->normalizeMfgNo($rawMfgNo);
        $site = strtoupper((string) ($workorder->site ?? ''));
        $displayMfgNo = strtoupper((string) ($workorder->site ?? '')) === 'PLUS'
            ? '+' . $mfgNo
            : $mfgNo;
        $deliveryRows = $deliveryMfgMap ? collect($deliveryMfgMap->get($this->siteMfgKey($site, $mfgNo), collect())) : collect();
        $deliveryRow = $deliveryRows->first();
        $deliveryQty = $this->deliveryQuantitySummary($deliveryRows, $site, $mfgNo);
        $steps = $this->stepsForDeliveryTarget($steps, $deliveryQty['target_qty_kg']);
        $division = $this->detectDivision((string) ($deliveryRow->sales_name ?? ''));
        $partNumber = trim((string) ($deliveryRow->part_number ?? $workorder->part_number ?? ''));
        $partDesc = trim((string) ($deliveryRow->part_desc ?? ''));
        $fallbackItem = trim((string) ($workorder->item ?? ''));
        $item = $partNumber !== '' ? $partNumber : $fallbackItem;
        $deliveryDueDate = $this->dateOnly($deliveryRow->due_date ?? null);
        $shipDate = $this->dateOnly($deliveryRow->ship_posted_at ?? null);
        $workorderDueDate = $this->dateOnly($workorder->due_date ?? null);
        $deadlineDate = $shipDate ?: ($deliveryDueDate ?: $workorderDueDate);

        $remaining = $steps->isNotEmpty()
            ? $steps
                ->filter(fn($step) => $step->status !== 'Completed')
                ->map(fn($step) => $this->stepLabel($step))
                ->values()
            : $this->remainingFromText($workorder->remaining_process_text ?? null);

        $totalSteps = $steps->isNotEmpty() ? $steps->count() : (int) ($workorder->total_steps ?? 0);
        $doneSteps = $steps->isNotEmpty()
            ? $steps->where('status', 'Completed')->count()
            : (int) ($workorder->done_steps ?? 0);
        $progress = $totalSteps > 0 ? round(($doneSteps * 100) / $totalSteps, 1) : 0.0;
        $latestCompleted = $steps
            ->filter(fn($step) => $step->status === 'Completed' && !empty($step->received_time))
            ->sortByDesc(fn($step) => (string) $step->received_time)
            ->first();
        $currentProcess = $workorder->current_process
            ?? ($doneSteps >= $totalSteps && $totalSteps > 0
                ? 'Completed'
                : ($latestCompleted ? $latestCompleted->code : 'Not Started'));
        $stepText = $workorder->step_text ?? ($totalSteps > 0 ? $doneSteps . '/' . $totalSteps : '');
        $deliveryStatus = $doneSteps >= $totalSteps && $totalSteps > 0
            ? 'Completed'
            : (($this->daysToDate($deadlineDate) ?? 0) >= 0 ? 'On Track' : 'Delayed');
        $ncrCount = (int) ($workorder->ncr_count ?? $steps->sum('ncr_count'));
        $riskStatus = (string) ($workorder->risk_status ?? ($ncrCount > 0 ? 'At Risk' : 'Normal'));
        $movement = $this->movementSummary($steps, $progress);

        return (object) [
            'workorder_id' => (int) ($workorder->id ?? 0),
            'ord_id' => null,
            'mfg_no' => $displayMfgNo,
            'site' => $site,
            'sales_id' => $deliveryRow->sales_id ?? null,
            'customer' => trim((string) ($deliveryRow->customer_name ?? $workorder->customer ?? '')),
            'customernumber' => trim((string) ($deliveryRow->customernumber ?? '')),
            'division' => $division,
            'division_sort' => $this->divisionSort($division),
            'sales_name' => trim((string) ($deliveryRow->sales_name ?? '')),
            'item' => $item,
            'part_number' => $partNumber,
            'item_desc' => $partDesc,
            'qty' => $deliveryRow->qty ?? null,
            'qty_kg' => $deliveryQty['qty_kg'],
            'target_qty_kg' => $deliveryQty['target_qty_kg'],
            'line_qty' => $deliveryQty['line_qty'],
            'qty_display' => $deliveryQty['qty_display'],
            'delivery_type' => $this->deliveryModeCode($deliveryRow->delivery_type ?? ''),
            'delivery_mode' => $this->deliveryModeCode($deliveryRow->delivery_type ?? ''),
            'delivery_mode_label' => $this->deliveryModeLabel($deliveryRow->delivery_type ?? ''),
            'delivery_mode_note' => $this->deliveryModeNote($deliveryRow->delivery_type ?? ''),
            'delivery_mode_badge_class' => $this->deliveryModeBadgeClass($deliveryRow->delivery_type ?? ''),
            'sale_type' => $deliveryQty['sale_type'],
            'stock_fg' => $this->deliveryStockFgSummary($deliveryRows),
            'so_number' => trim((string) ($deliveryRow->so_number ?? '')),
            'dp_status' => $this->dpStatusSummary($deliveryRows),
            'dp_due_date' => $deliveryDueDate,
            'window_at' => $deliveryRow->window_at ?? null,
            'ship_to' => trim((string) ($deliveryRow->address ?? '')),
            'dp_remark' => $this->dpRemarkSummary($deliveryRows),
            'ship_date' => $shipDate,
            'ship_date_sort' => $shipDate ?: '9999-12-31',
            'dp_ord_ids' => $deliveryRows->pluck('ord_id')->filter()->unique()->values(),
            'group_key' => $this->deliveryGroupKey($deliveryRows),
            'due_date' => $deadlineDate,
            'due_date_sort' => $deadlineDate ?: '9999-12-31',
            'days_to_due' => $this->daysToDate($deadlineDate),
            'current_process' => (string) $currentProcess,
            'step_text' => (string) $stepText,
            'progress_pct' => $progress,
            'remaining_process' => $remaining,
            'remaining_count' => $remaining->count(),
            'delivery_status' => (string) $deliveryStatus,
            'risk_status' => $riskStatus === 'At Risk' ? 'HIGH' : 'NORMAL',
            'risk_display' => $riskStatus,
            'risk_rank' => $riskStatus === 'At Risk' ? 90 : 10,
            'priority' => (int) ($workorder->priority ?? 999999),
            'ncr_count' => $ncrCount,
            'breakdown_count' => (int) ($workorder->breakdown_count ?? 0),
            'last_receive_at' => $movement['last_receive_at'],
            'last_receive_process' => $movement['last_receive_process'],
            'days_since_receive' => $movement['days_since_receive'],
            'movement_status' => $movement['movement_status'],
            'steps' => $steps,
            'source_note' => 'ManuCost PostgreSQL',
        ];
    }

    private function rowsFromWorkorderDeliveryLines(object $workorder, Collection $stepMap, Collection $deliveryMfgMap): Collection
    {
        $baseRow = $this->rowFromWorkorder($workorder, $stepMap, $deliveryMfgMap);
        $site = strtoupper((string) ($workorder->site ?? ''));
        $mfgNo = $this->normalizeMfgNo($workorder->mfg_no ?? $workorder->workordernumber ?? '');
        $deliveryRows = collect($deliveryMfgMap->get($this->siteMfgKey($site, $mfgNo), collect()));

        if ($deliveryRows->isEmpty()) {
            return collect([$baseRow]);
        }

        return $deliveryRows
            ->map(fn($deliveryRow) => $this->withDeliveryLineFields($baseRow, $deliveryRow))
            ->values();
    }

    private function rowsFromDeliveryOnlyLines(string $site, string $mfgNo, Collection $deliveryRows): Collection
    {
        if ($deliveryRows->isEmpty()) {
            return collect();
        }

        return $deliveryRows
            ->map(fn($deliveryRow) => $this->rowFromDeliveryOnly($site, $mfgNo, collect([$deliveryRow])))
            ->values();
    }

    private function withDeliveryLineFields(object $baseRow, object $deliveryRow): object
    {
        $row = clone $baseRow;
        $deliveryRows = collect([$deliveryRow]);
        $deliveryQty = $this->deliveryQuantitySummary($deliveryRows, $row->site ?? null, $row->mfg_no ?? null);
        $division = $this->detectDivision((string) ($deliveryRow->sales_name ?? ''));
        $partNumber = trim((string) ($deliveryRow->part_number ?? $row->part_number ?? ''));

        $row->ord_id = $deliveryRow->ord_id ?? null;
        $row->sales_id = $deliveryRow->sales_id ?? null;
        $row->customer = trim((string) ($deliveryRow->customer_name ?? ''));
        $row->customernumber = trim((string) ($deliveryRow->customernumber ?? ''));
        $row->division = $division;
        $row->division_sort = $this->divisionSort($division);
        $row->sales_name = trim((string) ($deliveryRow->sales_name ?? ''));
        $row->item = $partNumber !== '' ? $partNumber : ($row->item ?? '');
        $row->part_number = $partNumber;
        $row->item_desc = trim((string) ($deliveryRow->part_desc ?? ''));
        $row->qty = $deliveryRow->qty ?? null;
        $row->qty_kg = $deliveryQty['qty_kg'];
        $row->target_qty_kg = $deliveryQty['target_qty_kg'];
        $row->line_qty = $deliveryQty['line_qty'];
        $row->qty_display = $deliveryQty['qty_display'];
        $row->delivery_type = $this->deliveryModeCode($deliveryRow->delivery_type ?? '');
        $row->delivery_mode = $row->delivery_type;
        $row->delivery_mode_label = $this->deliveryModeLabel($deliveryRow->delivery_type ?? '');
        $row->delivery_mode_note = $this->deliveryModeNote($deliveryRow->delivery_type ?? '');
        $row->delivery_mode_badge_class = $this->deliveryModeBadgeClass($deliveryRow->delivery_type ?? '');
        $row->sale_type = $deliveryQty['sale_type'];
        $row->stock_fg = $this->deliveryStockFgSummary($deliveryRows);
        $row->so_number = trim((string) ($deliveryRow->so_number ?? ''));
        $row->dp_status = $this->dpStatusSummary($deliveryRows);
        $row->dp_due_date = $this->dateOnly($deliveryRow->due_date ?? null);
        $row->window_at = $deliveryRow->window_at ?? null;
        $row->ship_to = trim((string) ($deliveryRow->address ?? ''));
        $row->dp_remark = $this->dpRemarkSummary($deliveryRows);
        $row->ship_date = $this->dateOnly($deliveryRow->ship_posted_at ?? null);
        $row->ship_date_sort = $row->ship_date ?: '9999-12-31';
        $row->dp_ord_ids = collect([$deliveryRow->ord_id ?? null])->filter()->unique()->values();
        $row->group_key = $this->deliveryGroupKey($deliveryRows);
        $deadlineDate = $row->ship_date ?: ($row->dp_due_date ?: ($row->due_date ?? null));
        $row->due_date = $deadlineDate;
        $row->due_date_sort = $deadlineDate ?: '9999-12-31';
        $row->days_to_due = $this->daysToDate($deadlineDate);
        $row->steps = $this->stepsForDeliveryTarget(collect($row->steps ?? []), $deliveryQty['target_qty_kg']);
        $this->refreshProductionSummaryFromSteps($row);
        if (($row->delivery_status ?? '') !== 'Completed') {
            $row->delivery_status = (($row->days_to_due ?? 0) >= 0) ? 'On Track' : 'Delayed';
        }

        return $row;
    }

    private function rowFromDeliveryOnly(string $site, string $mfgNo, Collection $deliveryRows): object
    {
        $deliveryRow = $deliveryRows->first();
        $deliveryQty = $this->deliveryQuantitySummary($deliveryRows, $site, $mfgNo);
        $displayMfgNo = strtoupper($site) === 'PLUS' ? '+' . $mfgNo : $mfgNo;
        $partNumber = trim((string) ($deliveryRow->part_number ?? ''));
        $division = $this->detectDivision((string) ($deliveryRow->sales_name ?? ''));
        $shipDate = $this->dateOnly($deliveryRow->ship_posted_at ?? null);
        $deliveryDueDate = $this->dateOnly($deliveryRow->due_date ?? null);
        $deadlineDate = $shipDate ?: $deliveryDueDate;

        return (object) [
            'workorder_id' => 0,
            'ord_id' => null,
            'mfg_no' => $displayMfgNo,
            'site' => strtoupper($site),
            'sales_id' => $deliveryRow->sales_id ?? null,
            'customer' => trim((string) ($deliveryRow->customer_name ?? '')),
            'customernumber' => trim((string) ($deliveryRow->customernumber ?? '')),
            'division' => $division,
            'division_sort' => $this->divisionSort($division),
            'sales_name' => trim((string) ($deliveryRow->sales_name ?? '')),
            'item' => $partNumber,
            'part_number' => $partNumber,
            'item_desc' => trim((string) ($deliveryRow->part_desc ?? '')),
            'qty' => $deliveryRow->qty ?? null,
            'qty_kg' => $deliveryQty['qty_kg'],
            'target_qty_kg' => $deliveryQty['target_qty_kg'],
            'line_qty' => $deliveryQty['line_qty'],
            'qty_display' => $deliveryQty['qty_display'],
            'delivery_type' => $this->deliveryModeCode($deliveryRow->delivery_type ?? ''),
            'delivery_mode' => $this->deliveryModeCode($deliveryRow->delivery_type ?? ''),
            'delivery_mode_label' => $this->deliveryModeLabel($deliveryRow->delivery_type ?? ''),
            'delivery_mode_note' => $this->deliveryModeNote($deliveryRow->delivery_type ?? ''),
            'delivery_mode_badge_class' => $this->deliveryModeBadgeClass($deliveryRow->delivery_type ?? ''),
            'sale_type' => $deliveryQty['sale_type'],
            'stock_fg' => $this->deliveryStockFgSummary($deliveryRows),
            'so_number' => trim((string) ($deliveryRow->so_number ?? '')),
            'dp_status' => $this->dpStatusSummary($deliveryRows),
            'dp_due_date' => $deliveryDueDate,
            'window_at' => $deliveryRow->window_at ?? null,
            'ship_to' => trim((string) ($deliveryRow->address ?? '')),
            'dp_remark' => $this->dpRemarkSummary($deliveryRows),
            'ship_date' => $shipDate,
            'ship_date_sort' => $shipDate ?: '9999-12-31',
            'dp_ord_ids' => $deliveryRows->pluck('ord_id')->filter()->unique()->values(),
            'group_key' => $this->deliveryGroupKey($deliveryRows),
            'due_date' => $deadlineDate,
            'due_date_sort' => $deadlineDate ?: '9999-12-31',
            'days_to_due' => $this->daysToDate($deadlineDate),
            'current_process' => 'ไม่พบข้อมูลผลิต',
            'step_text' => '0/0',
            'progress_pct' => 0.0,
            'remaining_process' => collect(['ไม่พบ Routing']),
            'remaining_count' => 1,
            'delivery_status' => 'ไม่พบ Routing',
            'risk_status' => 'NO_ROUTE',
            'risk_display' => 'ตรวจสอบ',
            'risk_rank' => 80,
            'priority' => 999999,
            'ncr_count' => 0,
            'breakdown_count' => 0,
            'last_receive_at' => null,
            'last_receive_process' => '-',
            'days_since_receive' => null,
            'movement_status' => 'ไม่พบ Routing',
            'steps' => collect(),
            'source_note' => 'ไม่พบ Routing ใน ManuCost',
        ];
    }

    private function normalizeStep(object $step): object
    {
        $receivedQty = (float) ($step->received_qty ?? 0);
        $targetQty = (float) ($step->target_qty ?? 0);
        $status = (string) ($step->status ?? '');
        if ($status === '') {
            $status = $this->isStepQuantityComplete($receivedQty, $targetQty) ? 'Completed' : ($receivedQty > 0 ? 'In Progress' : 'Pending');
        }

        return (object) [
            'workorder_id' => (int) ($step->workorder_id ?? 0),
            'seq' => (int) ($step->seq ?? $step->workseq ?? 0),
            'code' => strtoupper(trim((string) ($step->code ?? $step->workcenternumber ?? ''))),
            'name' => trim((string) ($step->name ?? $step->workcenter_name ?? '')),
            'size_in' => $step->size_in ?? null,
            'size_out' => $step->size_out ?? null,
            'status' => $status,
            'receive_count' => $status === 'Completed' ? 1 : 0,
            'received_qty' => $receivedQty,
            'target_qty' => $targetQty,
            'machine' => trim((string) ($step->machine ?? $step->workmachine ?? '-')) ?: '-',
            'received_time' => $step->received_time ?? $step->receivestamp ?? null,
            'ncr_count' => (int) ($step->ncr_count ?? 0),
        ];
    }

    private function completePassedSteps(Collection $steps): Collection
    {
        $maxCompletedSeq = $steps
            ->filter(fn($step) => ($step->status ?? '') === 'Completed')
            ->max('seq');

        if (!is_numeric($maxCompletedSeq)) {
            return $steps;
        }

        return $steps
            ->map(function ($step) use ($maxCompletedSeq) {
                if ((int) ($step->seq ?? 0) < (int) $maxCompletedSeq && ($step->status ?? '') !== 'Completed') {
                    $step = clone $step;
                    $step->status = 'Completed';
                    $step->receive_count = 1;
                }

                return $step;
            })
            ->values();
    }

    private function stepsForDeliveryTarget(Collection $steps, $targetQty): Collection
    {
        if ($steps->isEmpty()) {
            return $steps;
        }

        $targetQty = is_numeric($targetQty) ? (float) $targetQty : 0.0;

        return $this->completePassedSteps($steps
            ->map(function ($step) use ($targetQty) {
                $step = clone $step;
                if ($targetQty > 0) {
                    $step->target_qty = $targetQty;
                }
                $receivedQty = (float) ($step->received_qty ?? 0);
                $step->status = $this->isStepQuantityComplete($receivedQty, (float) ($step->target_qty ?? 0))
                    ? 'Completed'
                    : ($receivedQty > 0 ? 'In Progress' : 'Pending');
                $step->receive_count = $step->status === 'Completed' ? 1 : 0;

                return $step;
            })
            ->values());
    }

    private function refreshProductionSummaryFromSteps(object $row): void
    {
        $steps = collect($row->steps ?? []);
        if ($steps->isEmpty()) {
            return;
        }

        $remaining = $steps
            ->filter(fn($step) => ($step->status ?? '') !== 'Completed')
            ->map(fn($step) => $this->stepLabel($step))
            ->values();
        $totalSteps = $steps->count();
        $doneSteps = $steps->where('status', 'Completed')->count();
        $progress = $totalSteps > 0 ? round(($doneSteps * 100) / $totalSteps, 1) : 0.0;
        $latestCompleted = $steps
            ->filter(fn($step) => ($step->status ?? '') === 'Completed' && !empty($step->received_time))
            ->sortByDesc(fn($step) => (string) $step->received_time)
            ->first();

        $row->remaining_process = $remaining;
        $row->remaining_count = $remaining->count();
        $row->progress_pct = $progress;
        $row->step_text = $totalSteps > 0 ? $doneSteps . '/' . $totalSteps : '';
        $row->current_process = $doneSteps >= $totalSteps && $totalSteps > 0
            ? 'Completed'
            : ($latestCompleted ? $latestCompleted->code : 'Not Started');
        $row->delivery_status = $doneSteps >= $totalSteps && $totalSteps > 0
            ? 'Completed'
            : (($this->daysToDate($row->due_date ?? null) ?? 0) >= 0 ? 'On Track' : 'Delayed');

        $movement = $this->movementSummary($steps, $progress);
        $row->last_receive_at = $movement['last_receive_at'];
        $row->last_receive_process = $movement['last_receive_process'];
        $row->days_since_receive = $movement['days_since_receive'];
        $row->movement_status = $movement['movement_status'];
    }

    private function isStepQuantityComplete(float $receivedQty, float $targetQty): bool
    {
        if ($receivedQty <= 0) {
            return false;
        }

        if ($targetQty <= 0) {
            return true;
        }

        return $receivedQty >= ($targetQty * 0.98);
    }

    private function passesFilters(object $row, array $filters): bool
    {
        $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
        if ($keyword !== '') {
            $haystack = mb_strtolower(implode(' ', [
                $row->mfg_no ?? '',
                $row->customer ?? '',
                $row->division ?? '',
                $row->sales_name ?? '',
                $row->item ?? '',
                $row->dp_status ?? '',
                $row->current_process ?? '',
                $row->movement_status ?? '',
                $row->remaining_process instanceof Collection ? $row->remaining_process->implode(' ') : '',
            ]));

            if (!str_contains($haystack, $keyword)) {
                return false;
            }
        }

        $mfgKw = mb_strtolower(trim((string) ($filters['mfg'] ?? '')));
        if ($mfgKw !== '' && !str_contains(mb_strtolower((string) ($row->mfg_no ?? '')), $mfgKw)) {
            return false;
        }

        $soKw = mb_strtolower(trim((string) ($filters['so'] ?? '')));
        if ($soKw !== '' && !str_contains(mb_strtolower((string) ($row->so_number ?? '')), $soKw)) {
            return false;
        }

        $customerKw = mb_strtolower(trim((string) ($filters['customer'] ?? '')));
        if ($customerKw !== '') {
            $hay = mb_strtolower(((string) ($row->customer ?? '')) . ' ' . ((string) ($row->customernumber ?? '')));
            if (!str_contains($hay, $customerKw)) {
                return false;
            }
        }

        $itemKw = mb_strtolower(trim((string) ($filters['item'] ?? '')));
        if ($itemKw !== '') {
            $hay = mb_strtolower(((string) ($row->item ?? '')) . ' ' . ((string) ($row->item_desc ?? '')));
            if (!str_contains($hay, $itemKw)) {
                return false;
            }
        }

        $riskStatus = strtoupper(trim((string) ($filters['risk_status'] ?? '')));
        if ($riskStatus !== '' && strtoupper((string) $row->risk_status) !== $riskStatus) {
            return false;
        }

        $deliveryType = strtoupper(trim((string) ($filters['delivery_type'] ?? 'ALL')));
        if ($deliveryType !== '' && $deliveryType !== 'ALL') {
            if (strtoupper((string) ($row->delivery_type ?? $row->delivery_mode ?? '')) !== $deliveryType) {
                return false;
            }
        }

        $processFilter = trim((string) ($filters['process_filter'] ?? ''));
        if ($processFilter !== '' && strcasecmp(trim((string) ($row->current_process ?? '')), $processFilter) !== 0) {
            return false;
        }

        $completionFilter = strtolower(trim((string) ($filters['completion_filter'] ?? 'all')));
        if ($completionFilter === 'open') {
            return (float) ($row->progress_pct ?? 0) < 100
                || strtoupper((string) ($row->delivery_status ?? '')) !== 'COMPLETED';
        }
        if ($completionFilter === 'completed') {
            return (float) ($row->progress_pct ?? 0) >= 100
                && strtoupper((string) ($row->delivery_status ?? '')) === 'COMPLETED';
        }

        $statusFilter = strtolower(trim((string) ($filters['status_filter'] ?? 'all')));
        if ($statusFilter === 'delayed' && strtoupper((string) ($row->delivery_status ?? '')) !== 'DELAYED') {
            return false;
        }
        if ($statusFilter === 'at_risk' && strtoupper((string) ($row->risk_display ?? '')) !== 'AT RISK') {
            return false;
        }

        $movementFilter = strtolower(trim((string) ($filters['movement_filter'] ?? 'all')));
        if ($movementFilter !== '' && $movementFilter !== 'all') {
            $movementStatus = trim((string) ($row->movement_status ?? ''));
            $movementMap = [
                'stale' => 'งานนิ่ง',
                'not_started' => 'ยังไม่เริ่ม',
                'moving' => 'เคลื่อนไหว',
                'completed' => 'เสร็จแล้ว',
                'no_route' => 'ไม่พบ Routing',
            ];

            if (($movementMap[$movementFilter] ?? null) !== $movementStatus) {
                return false;
            }
        }

        return true;
    }

    private function buildSummary(Collection $rows): array
    {
        $openRows = $rows->filter(fn($row) => (float) ($row->progress_pct ?? 0) < 100
            || strtoupper((string) ($row->delivery_status ?? '')) !== 'COMPLETED');

        return [
            'total' => $rows->count(),
            'open' => $openRows->count(),
            'completed' => $rows->filter(fn($row) => (float) ($row->progress_pct ?? 0) >= 100
                && strtoupper((string) ($row->delivery_status ?? '')) === 'COMPLETED')->count(),
            'delayed' => $rows->filter(fn($row) => strtoupper((string) ($row->delivery_status ?? '')) === 'DELAYED')->count(),
            'at_risk' => $rows->filter(fn($row) => strtoupper((string) ($row->risk_display ?? '')) === 'AT RISK')->count(),
            'acid' => $rows->filter(fn($row) => strtoupper((string) ($row->delivery_type ?? $row->delivery_mode ?? '')) === 'ACID')->count(),
            'special' => $rows->filter(fn($row) => strtoupper((string) ($row->delivery_type ?? $row->delivery_mode ?? '')) === 'SPECIAL')->count(),
            'overdue' => $openRows->filter(fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due < 0)->count(),
            'due_soon' => $openRows->filter(fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due >= 1 && (int) $row->days_to_due <= 3)->count(),
            'high' => $rows->where('risk_status', 'HIGH')->count(),
            'medium' => 0,
            'normal' => $rows->where('risk_status', 'NORMAL')->count(),
            'no_route' => $rows->where('risk_status', 'NO_ROUTE')->count(),
            'avg_progress' => $rows->count() > 0 ? round((float) $rows->avg('progress_pct'), 1) : 0,
        ];
    }

    private function buildInsights(Collection $rows): array
    {
        $total = max(1, $rows->count());

        $statusSummary = collect(['Delayed', 'On Track', 'Completed'])
            ->map(function ($status) use ($rows, $total) {
                $count = $rows->where('delivery_status', $status)->count();

                return [
                    'label' => $status,
                    'count' => $count,
                    'pct' => round(($count / $total) * 100, 1),
                ];
            })
            ->values();

        $processSummary = $rows
            ->groupBy(fn($row) => trim((string) ($row->current_process ?? '')) ?: 'Unknown')
            ->map(fn($items, $process) => [
                'process' => $process,
                'count' => $items->count(),
                'avg_progress' => round((float) $items->avg('progress_pct'), 1),
                'at_risk' => $items->where('risk_display', 'At Risk')->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(8)
            ->values();

        $watchlist = $rows
            ->filter(fn($row) => $this->watchScore($row) > 0)
            ->map(function ($row) {
                $row->watch_score = $this->watchScore($row);
                $row->watch_reasons = $this->watchReasons($row);
                return $row;
            })
            ->sortBy([
                ['watch_score', 'desc'],
                ['due_date_sort', 'asc'],
                ['ship_date_sort', 'asc'],
            ])
            ->take(8)
            ->values();

        $progressBands = collect([
            ['label' => '0-25%', 'min' => 0, 'max' => 25],
            ['label' => '26-50%', 'min' => 26, 'max' => 50],
            ['label' => '51-75%', 'min' => 51, 'max' => 75],
            ['label' => '76-99%', 'min' => 76, 'max' => 99],
            ['label' => '100%', 'min' => 100, 'max' => 100],
        ])->map(function ($band) use ($rows, $total) {
            $count = $rows->filter(function ($row) use ($band) {
                $progress = (float) ($row->progress_pct ?? 0);
                return $progress >= $band['min'] && $progress <= $band['max'];
            })->count();

            return [
                'label' => $band['label'],
                'count' => $count,
                'pct' => round(($count / $total) * 100, 1),
            ];
        });

        $siteSummary = $rows
            ->groupBy('site')
            ->map(fn($items, $site) => [
                'site' => $site ?: 'UNKNOWN',
                'count' => $items->count(),
                'open' => $items->filter(fn($row) => (float) ($row->progress_pct ?? 0) < 100
                    || strtoupper((string) ($row->delivery_status ?? '')) !== 'COMPLETED')->count(),
            ])
            ->values();

        $dataQualitySummary = collect([
            [
                'label' => 'MFG ทั้งหมด',
                'class' => 'primary',
                'count' => $rows->count(),
                'pct' => 100.0,
            ],
            [
                'label' => 'เจอ Route',
                'class' => 'success',
                'count' => $rows->where('risk_status', '!=', 'NO_ROUTE')->count(),
                'pct' => round(($rows->where('risk_status', '!=', 'NO_ROUTE')->count() / $total) * 100, 1),
            ],
            [
                'label' => 'ไม่พบ Routing',
                'class' => 'danger',
                'count' => $rows->where('risk_status', 'NO_ROUTE')->count(),
                'pct' => round(($rows->where('risk_status', 'NO_ROUTE')->count() / $total) * 100, 1),
            ],
            [
                'label' => 'WIRE',
                'class' => 'info',
                'count' => $rows->where('site', 'WIRE')->count(),
                'pct' => round(($rows->where('site', 'WIRE')->count() / $total) * 100, 1),
            ],
            [
                'label' => 'PLUS',
                'class' => 'warning',
                'count' => $rows->where('site', 'PLUS')->count(),
                'pct' => round(($rows->where('site', 'PLUS')->count() / $total) * 100, 1),
            ],
            [
                'label' => 'UNKNOWN',
                'class' => 'secondary',
                'count' => $rows->filter(fn($row) => trim((string) ($row->site ?? '')) === ''
                    || strtoupper(trim((string) ($row->division ?? ''))) === 'UNKNOWN')->count(),
                'pct' => round(($rows->filter(fn($row) => trim((string) ($row->site ?? '')) === ''
                    || strtoupper(trim((string) ($row->division ?? ''))) === 'UNKNOWN')->count() / $total) * 100, 1),
            ],
        ]);

        $movementDefinitions = collect([
            ['value' => 'stale', 'label' => 'งานนิ่ง', 'status' => 'งานนิ่ง', 'class' => 'danger'],
            ['value' => 'not_started', 'label' => 'ยังไม่เริ่ม', 'status' => 'ยังไม่เริ่ม', 'class' => 'warning'],
            ['value' => 'moving', 'label' => 'เคลื่อนไหว', 'status' => 'เคลื่อนไหว', 'class' => 'primary'],
            ['value' => 'completed', 'label' => 'เสร็จแล้ว', 'status' => 'เสร็จแล้ว', 'class' => 'success'],
            ['value' => 'no_route', 'label' => 'ไม่พบ Routing', 'status' => 'ไม่พบ Routing', 'class' => 'secondary'],
        ]);

        $movementSummary = $movementDefinitions
            ->map(function ($item) use ($rows, $total) {
                $count = $rows->where('movement_status', $item['status'])->count();

                return array_merge($item, [
                    'count' => $count,
                    'pct' => round(($count / $total) * 100, 1),
                ]);
            })
            ->values();

        $divisionSummary = $rows
            ->groupBy(fn($row) => trim((string) ($row->division ?? 'UNKNOWN')) ?: 'UNKNOWN')
            ->map(function ($items, $division) {
                $open = $items->filter(fn($row) => (float) ($row->progress_pct ?? 0) < 100
                    || strtoupper((string) ($row->delivery_status ?? '')) !== 'COMPLETED')->count();

                return [
                    'division' => $division,
                    'division_sort' => $items->min('division_sort') ?? $this->divisionSort((string) $division),
                    'total' => $items->count(),
                    'open' => $open,
                    'delayed' => $items->filter(fn($row) => strtoupper((string) ($row->delivery_status ?? '')) === 'DELAYED')->count(),
                    'no_route' => $items->where('risk_status', 'NO_ROUTE')->count(),
                    'stale' => $items->where('movement_status', 'งานนิ่ง')->count(),
                    'at_risk' => $items->filter(fn($row) => strtoupper((string) ($row->risk_display ?? '')) === 'AT RISK')->count(),
                    'avg_progress' => round((float) $items->avg('progress_pct'), 1),
                ];
            })
            ->sortBy([
                ['division_sort', 'asc'],
                ['division', 'asc'],
            ])
            ->values();

        $dueBuckets = collect([
            ['label' => 'Overdue', 'class' => 'danger', 'filter' => fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due < 0],
            ['label' => 'Today', 'class' => 'warning', 'filter' => fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due === 0],
            ['label' => '1-3 Days', 'class' => 'primary', 'filter' => fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due >= 1 && (int) $row->days_to_due <= 3],
            ['label' => '4-7 Days', 'class' => 'info', 'filter' => fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due >= 4 && (int) $row->days_to_due <= 7],
            ['label' => '> 7 Days', 'class' => 'secondary', 'filter' => fn($row) => is_numeric($row->days_to_due) && (int) $row->days_to_due > 7],
        ])->map(function ($bucket) use ($rows, $total) {
            $count = $rows->filter($bucket['filter'])->count();

            return [
                'label' => $bucket['label'],
                'class' => $bucket['class'],
                'count' => $count,
                'pct' => round(($count / $total) * 100, 1),
            ];
        });

        // Mutually exclusive: a row falls into exactly one bucket.
        // Priority: Ready/Done > Expedite > Keep Watching.
        $isReady = fn($row) => (float) ($row->progress_pct ?? 0) >= 100
            && ($row->delivery_status ?? '') === 'Completed';
        $isExpedite = fn($row) => !$isReady($row)
            && (($row->delivery_status ?? '') === 'Delayed'
                || ($row->risk_display ?? '') === 'At Risk'
                || ($row->risk_status ?? '') === 'NO_ROUTE'
                || ($row->movement_status ?? '') === 'งานนิ่ง'
                || (is_numeric($row->days_to_due) && (int) $row->days_to_due < 0 && (float) ($row->progress_pct ?? 0) < 100));
        $isKeepWatching = fn($row) => !$isReady($row) && !$isExpedite($row);

        $actionSummary = collect([
            [
                'label' => 'Expedite',
                'class' => 'danger',
                'count' => $rows->filter($isExpedite)->count(),
            ],
            [
                'label' => 'Keep Watching',
                'class' => 'warning',
                'count' => $rows->filter($isKeepWatching)->count(),
            ],
            [
                'label' => 'Ready / Done',
                'class' => 'success',
                'count' => $rows->filter($isReady)->count(),
            ],
        ])->map(fn($item) => array_merge($item, [
            'pct' => round(((int) $item['count'] / $total) * 100, 1),
        ]));

        $unitSummary = $rows
            ->groupBy(fn($row) => trim((string) ($row->sale_type ?? '-')) ?: '-')
            ->map(fn($items, $unit) => [
                'unit' => $unit,
                'count' => $items->count(),
                'qty_kg' => (float) $items->sum('qty_kg'),
                'line_qty' => (float) $items->sum('line_qty'),
                'pct' => round(($items->count() / $total) * 100, 1),
            ])
            ->sortByDesc('count')
            ->values();

        $autocompleteSources = [
            'mfg' => $rows->pluck('mfg_no')
                ->map(fn($v) => trim((string) $v))
                ->filter(fn($v) => $v !== '')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'so' => $rows->pluck('so_number')
                ->map(fn($v) => trim((string) $v))
                ->filter(fn($v) => $v !== '')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'customer' => $rows->pluck('customer')
                ->map(fn($v) => trim((string) $v))
                ->filter(fn($v) => $v !== '')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'item' => $rows->map(function ($row) {
                $code = trim((string) ($row->item ?? ''));
                $desc = trim((string) ($row->item_desc ?? ''));
                if ($code === '' && $desc === '') {
                    return '';
                }
                return $code !== '' && $desc !== '' ? $code . ' — ' . $desc : ($code ?: $desc);
            })
                ->filter(fn($v) => $v !== '')
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];

        return [
            'statusSummary' => $statusSummary,
            'siteSummary' => $siteSummary,
            'processSummary' => $processSummary,
            'watchlist' => $watchlist,
            'progressBands' => $progressBands,
            'dueBuckets' => $dueBuckets,
            'actionSummary' => $actionSummary,
            'unitSummary' => $unitSummary,
            'dataQualitySummary' => $dataQualitySummary,
            'movementSummary' => $movementSummary,
            'divisionSummary' => $divisionSummary,
            'autocompleteSources' => $autocompleteSources,
        ];
    }

    private function emptyTrackingRow(string $mfgNo, string $site): object
    {
        return (object) [
            'workorder_id' => 0,
            'mfg_no' => $mfgNo,
            'site' => $site,
            'customer' => '',
            'customernumber' => '',
            'division' => 'UNKNOWN',
            'division_sort' => 999,
            'sales_name' => '',
            'item' => '',
            'part_number' => '',
            'item_desc' => '',
            'qty' => null,
            'qty_kg' => 0.0,
            'target_qty_kg' => 0.0,
            'line_qty' => 0.0,
            'qty_display' => '-',
            'sale_type' => '-',
            'delivery_type' => '',
            'delivery_mode' => '',
            'delivery_mode_label' => '',
            'delivery_mode_note' => '',
            'delivery_mode_badge_class' => 'light text-dark border',
            'stock_fg' => 0.0,
            'so_number' => '',
            'dp_status' => '-',
            'dp_due_date' => null,
            'window_at' => null,
            'ship_to' => '',
            'dp_remark' => '',
            'ship_date' => null,
            'ship_date_sort' => '9999-12-31',
            'dp_ord_ids' => collect(),
            'group_key' => '',
            'due_date' => null,
            'due_date_sort' => '9999-12-31',
            'days_to_due' => null,
            'current_process' => '-',
            'step_text' => '',
            'progress_pct' => 0,
            'remaining_process' => collect(),
            'remaining_count' => 0,
            'delivery_status' => '-',
            'risk_status' => 'NORMAL',
            'risk_display' => '-',
            'risk_rank' => 0,
            'priority' => 0,
            'ncr_count' => 0,
            'breakdown_count' => 0,
            'last_receive_at' => null,
            'last_receive_process' => '-',
            'days_since_receive' => null,
            'movement_status' => '-',
            'steps' => collect(),
            'source_note' => 'No live data',
        ];
    }

    private function applyDetailFallback(object $tracking, array $fallback): object
    {
        $value = fn(string $key) => trim((string) ($fallback[$key] ?? ''));
        $isBlank = fn($current) => trim((string) ($current ?? '')) === '' || trim((string) ($current ?? '')) === '-';

        if ($value('dp_qty') !== '' && $isBlank($tracking->qty_display ?? null)) {
            $tracking->qty_display = $value('dp_qty');
        }
        if ($value('dp_sale_type') !== '' && $isBlank($tracking->sale_type ?? null)) {
            $tracking->sale_type = $value('dp_sale_type');
        }
        if ($value('dp_status') !== '' && $isBlank($tracking->dp_status ?? null)) {
            $tracking->dp_status = $value('dp_status');
        }
        if ($value('delivery_type') !== '' && $isBlank($tracking->delivery_type ?? null)) {
            $tracking->delivery_type = $this->deliveryModeCode($value('delivery_type'));
            $tracking->delivery_mode = $tracking->delivery_type;
            $tracking->delivery_mode_label = $this->deliveryModeLabel($tracking->delivery_type);
            $tracking->delivery_mode_note = $this->deliveryModeNote($tracking->delivery_type);
            $tracking->delivery_mode_badge_class = $this->deliveryModeBadgeClass($tracking->delivery_type);
        }

        $shipDate = $this->dateOnly($value('ship_date'));
        if ($shipDate) {
            $tracking->ship_date = $shipDate;
            $tracking->ship_date_sort = $shipDate;
        }

        $deadline = $this->dateOnly($value('deadline'));
        if ($deadline) {
            $tracking->due_date = $deadline;
            $tracking->due_date_sort = $deadline;
            $tracking->days_to_due = $this->daysToDate($deadline);
        }

        return $tracking;
    }

    private function filterYear(array $filters): int
    {
        $from = $filters['ship_from'] ?? now('Asia/Bangkok')->toDateString();

        try {
            return (int) Carbon::parse($from)->format('Y');
        } catch (\Throwable $e) {
            return (int) now('Asia/Bangkok')->format('Y');
        }
    }

    private function remainingFromText($value): Collection
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '-') {
            return collect();
        }

        return collect(explode(' -> ', $value))
            ->map(fn($item) => trim($item))
            ->filter()
            ->values();
    }

    private function dpStatusSummary(Collection $deliveryRows): string
    {
        $statuses = $deliveryRows
            ->map(fn($row) => strtoupper(trim((string) ($row->status ?? 'NEW'))) ?: 'NEW')
            ->unique()
            ->values();

        return $statuses->isNotEmpty() ? $statuses->implode(' / ') : '-';
    }

    private function deliveryModeCode($deliveryType): string
    {
        $mode = strtoupper(trim((string) $deliveryType));

        return $mode === 'ACID' ? 'ACID' : ($mode === 'SO' ? 'SO' : $mode);
    }

    private function deliveryModeLabel($deliveryType): string
    {
        return match ($this->deliveryModeCode($deliveryType)) {
            'ACID' => 'งานกัดกรด',
            'SPECIAL' => 'งานพิเศษ',
            default => '',
        };
    }

    private function deliveryModeNote($deliveryType): string
    {
        return match ($this->deliveryModeCode($deliveryType)) {
            'ACID' => 'งานส่งกัดกรด',
            'SPECIAL' => 'งานพิเศษ (ส่งซ่อม/ส่งคืน) - ผู้รับผิดชอบ Export',
            default => '',
        };
    }

    private function deliveryModeBadgeClass($deliveryType): string
    {
        return match ($this->deliveryModeCode($deliveryType)) {
            'ACID' => 'warning text-dark border border-warning-subtle',
            'SPECIAL' => 'info text-dark border border-info-subtle',
            default => 'light text-dark border',
        };
    }

    /**
     * Build a group key based on delivery_plan_data.ord_id.
     * Two tracking rows belong to the same group ONLY when they came from
     * the same ord_id — typically when one DP record has a comma-separated
     * mfg_no like "W2600873, W2600875, W2600876". Different ord_ids
     * (even on the same SO / revision) are treated as separate groups.
     */
    private function deliveryGroupKey(Collection $deliveryRows): string
    {
        $ids = $deliveryRows
            ->pluck('ord_id')
            ->filter()
            ->map(fn($v) => (string) $v)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return '';
        }

        return 'O:' . $ids->implode('-');
    }

    private function dpRemarkSummary(Collection $deliveryRows): string
    {
        return $deliveryRows
            ->flatMap(fn($row) => [
                trim((string) ($row->remark ?? '')),
                trim((string) ($row->edit_remark ?? '')),
                trim((string) ($row->due_date_remark ?? '')),
            ])
            ->filter()
            ->unique()
            ->values()
            ->implode(' | ');
    }

    private function movementSummary(Collection $steps, float $progress): array
    {
        $latestStep = $steps
            ->filter(fn($step) => !empty($step->received_time))
            ->sortByDesc(fn($step) => (string) $step->received_time)
            ->first();

        if ($progress >= 100.0) {
            return [
                'last_receive_at' => $latestStep->received_time ?? null,
                'last_receive_process' => $latestStep ? $this->stepLabel($latestStep) : '-',
                'days_since_receive' => $latestStep ? $this->daysSince($latestStep->received_time) : null,
                'movement_status' => 'เสร็จแล้ว',
            ];
        }

        if (!$latestStep) {
            return [
                'last_receive_at' => null,
                'last_receive_process' => '-',
                'days_since_receive' => null,
                'movement_status' => 'ยังไม่เริ่ม',
            ];
        }

        $daysSince = $this->daysSince($latestStep->received_time);

        return [
            'last_receive_at' => $latestStep->received_time,
            'last_receive_process' => $this->stepLabel($latestStep),
            'days_since_receive' => $daysSince,
            'movement_status' => $daysSince !== null && $daysSince > self::STALE_RECEIVE_DAYS ? 'งานนิ่ง' : 'เคลื่อนไหว',
        ];
    }

    private function deliveryQuantitySummary(Collection $deliveryRows, ?string $site = null, ?string $mfgNo = null): array
    {
        $qtyKg = 0.0;
        $targetQtyKg = 0.0;
        $lineQty = 0.0;
        $parts = collect();
        $types = collect();

        foreach ($deliveryRows as $row) {
            $sellByLine = (int) ($row->sell_by_line ?? 0) === 1;
            $rowQty = is_numeric($row->qty ?? null) ? (float) $row->qty : 0.0;
            $rowLineQty = is_numeric($row->line_qty ?? null) ? (float) $row->line_qty : 0.0;
            $targetRowQty = $this->apportionedDeliveryQuantity($row, $rowQty, $site, $mfgNo);

            if ($sellByLine && $rowLineQty > 0) {
                $unit = $rowQty == 0.0 ? 'ชิ้น' : 'เส้น';
                $lineQty += $rowLineQty;
                $types->push($unit);
                if ($rowQty > 0.0) {
                    $qtyKg += $rowQty;
                    $targetQtyKg += $targetRowQty;
                    $parts->put('kg', (float) ($parts->get('kg', 0) + $rowQty));
                } else {
                    $parts->put($unit, (float) ($parts->get($unit, 0) + $rowLineQty));
                }
                continue;
            }

            $qtyKg += $rowQty;
            $targetQtyKg += $targetRowQty;
            $types->push('kg');
            $parts->put('kg', (float) ($parts->get('kg', 0) + $rowQty));
        }

        $displayParts = $parts
            ->filter(fn($value) => (float) $value > 0)
            ->map(fn($value, $unit) => $this->formatQuantity((float) $value, (string) $unit))
            ->values();

        $uniqueTypes = $types->filter()->unique()->values();

        return [
            'qty_kg' => $qtyKg,
            'target_qty_kg' => $targetQtyKg,
            'line_qty' => $lineQty,
            'qty_display' => $displayParts->isNotEmpty() ? $displayParts->implode(' / ') : '-',
            'sale_type' => $uniqueTypes->count() > 1 ? 'Mixed' : ($uniqueTypes->first() ?: '-'),
        ];
    }

    private function apportionedDeliveryQuantity(object $row, float $quantity, ?string $site, ?string $mfgNo): float
    {
        if ($quantity == 0.0) {
            return 0.0;
        }

        $normalizedMfgNo = $this->normalizeMfgNo($mfgNo ?? '');
        if ($normalizedMfgNo === '') {
            return $quantity;
        }

        $tokens = $this->splitMfgTokens($row->mfg_no ?? '');
        $matched = $tokens->contains(function ($token) use ($site, $normalizedMfgNo) {
            $tokenSite = str_starts_with($token, '+') ? 'PLUS' : 'WIRE';

            return ($site === null || strtoupper($site) === $tokenSite)
                && $this->normalizeMfgNo($token) === $normalizedMfgNo;
        });

        if (!$matched || $tokens->count() <= 1) {
            return $quantity;
        }

        return $quantity / $tokens->count();
    }

    private function deliveryStockFgSummary(Collection $deliveryRows): float
    {
        return (float) $deliveryRows
            ->mapWithKeys(function ($row) {
                $partNumber = trim((string) ($row->part_number ?? ''));

                return $partNumber === '' ? [] : [$partNumber => (float) ($row->stock_fg ?? 0)];
            })
            ->sum();
    }

    private function formatQuantity(float $value, string $unit): string
    {
        $decimals = $unit === 'kg' && floor($value) !== $value ? 2 : 0;

        return number_format($value, $decimals) . ' ' . $unit;
    }

    private function watchScore(object $row): int
    {
        $score = 0;
        $progress = (float) ($row->progress_pct ?? 0);
        $daysToDue = is_numeric($row->days_to_due) ? (int) $row->days_to_due : null;

        if (($row->risk_display ?? '') === 'At Risk') {
            $score += 45;
        }
        if (($row->risk_status ?? '') === 'NO_ROUTE') {
            $score += 55;
        }
        if (($row->movement_status ?? '') === 'งานนิ่ง') {
            $score += 30;
        }
        if (($row->movement_status ?? '') === 'ยังไม่เริ่ม') {
            $score += 15;
        }
        if (($row->delivery_status ?? '') === 'Delayed') {
            $score += 40;
        }
        if ($daysToDue !== null && $daysToDue < 0 && $progress < 100) {
            $score += min(35, 15 + abs($daysToDue));
        }
        if ($daysToDue !== null && $daysToDue >= 0 && $daysToDue <= 3 && $progress < 100) {
            $score += 20;
        }
        if ($progress < 50) {
            $score += 12;
        } elseif ($progress < 75) {
            $score += 6;
        }
        if ((int) ($row->remaining_count ?? 0) >= 3) {
            $score += 6;
        }
        if ((int) ($row->ncr_count ?? 0) > 0) {
            $score += 10;
        }
        if ((int) ($row->breakdown_count ?? 0) > 0) {
            $score += 10;
        }

        return $score;
    }

    private function watchReasons(object $row): array
    {
        $reasons = [];
        $progress = (float) ($row->progress_pct ?? 0);
        $daysToDue = is_numeric($row->days_to_due) ? (int) $row->days_to_due : null;

        if (($row->delivery_status ?? '') === 'Delayed') {
            $reasons[] = 'ล่าช้า';
        }
        if (($row->risk_status ?? '') === 'NO_ROUTE') {
            $reasons[] = 'ไม่พบ Routing';
        }
        if (($row->movement_status ?? '') === 'งานนิ่ง') {
            $reasons[] = 'งานนิ่ง';
        }
        if (($row->movement_status ?? '') === 'ยังไม่เริ่ม') {
            $reasons[] = 'ยังไม่เริ่ม';
        }
        if ($daysToDue !== null && $daysToDue < 0 && $progress < 100) {
            $reasons[] = 'เลยกำหนด ' . abs($daysToDue) . ' วัน';
        } elseif ($daysToDue !== null && $daysToDue >= 0 && $daysToDue <= 3 && $progress < 100) {
            $reasons[] = 'ครบกำหนด ' . $daysToDue . ' วัน';
        }
        if (($row->risk_display ?? '') === 'At Risk') {
            $reasons[] = 'เสี่ยง';
        }
        if ((int) ($row->ncr_count ?? 0) > 0) {
            $reasons[] = 'NCR ' . (int) $row->ncr_count;
        }
        if ((int) ($row->breakdown_count ?? 0) > 0) {
            $reasons[] = 'Machine Down';
        }
        if ($progress < 75 && $progress < 100) {
            $reasons[] = 'คืบหน้า ' . number_format($progress, 1) . '%';
        }
        if ((int) ($row->remaining_count ?? 0) > 0) {
            $reasons[] = 'เหลือ ' . (int) $row->remaining_count;
        }

        return array_slice(array_values(array_unique($reasons)), 0, 4);
    }

    private function normalizeMfgNo($value): string
    {
        return strtoupper(ltrim(trim((string) ($value ?? ''), " \t\n\r\0\x0B'\""), '+'));
    }

    private function siteMfgKey(string $site, string $mfgNo): string
    {
        return strtoupper(trim($site)) . '|' . $this->normalizeMfgNo($mfgNo);
    }

    private function detectDivision(string $salesName): string
    {
        $salesName = trim($salesName);
        foreach ($this->divisionKeywordMap() as $needle => $division) {
            if ($needle !== '' && mb_stripos($salesName, $needle) !== false) {
                return $division;
            }
        }

        if (preg_match('/\bD[0-9]\b/i', $salesName, $m)) {
            return strtoupper($m[0]);
        }

        return $salesName !== '' ? $salesName : 'UNKNOWN';
    }

    private function divisionKeywordMap(): array
    {
        return [
            // Thai sales-name keywords (copied from DeliveryPlanInquiryController)
            'ภควดี'      => 'D3',
            'ธนัชชา'     => 'D3',
            'ธัธลิญา'    => 'D5',
            'เฌอร์ลิญา'  => 'D5',
            'สุรศักดิ์'  => 'D6',
            'คณัญญ์นิชา' => 'D6',
            'ศิรินภา'    => 'D7',
            'มนพัทธ์'    => 'D7',
            'สาธิต'      => 'D8',
            'สุธาสินี'   => 'D8',
            'วรเดชา'     => 'D9',
            'ลัดดาวัลย์' => 'D9',
            'ดิลก'       => 'D1',
            'ขวัญเรือน'  => 'D1',
            'ปรียาพรรณ'  => 'D2',
            'นิตยา'      => 'D2',
            'กันยกร'     => 'PLN',
            'วางแผน'     => 'PLN',
            // Code fallbacks
            'D1' => 'D1',
            'DIV1' => 'D1',
            'DIVISION1' => 'D1',
            'D2' => 'D2',
            'DIV2' => 'D2',
            'DIVISION2' => 'D2',
            'D3' => 'D3',
            'DIV3' => 'D3',
            'DIVISION3' => 'D3',
            'D5' => 'D5',
            'DIV5' => 'D5',
            'DIVISION5' => 'D5',
            'D6' => 'D6',
            'DIV6' => 'D6',
            'DIVISION6' => 'D6',
            'D7' => 'D7',
            'DIV7' => 'D7',
            'DIVISION7' => 'D7',
            'D8' => 'D8',
            'DIV8' => 'D8',
            'DIVISION8' => 'D8',
            'D9' => 'D9',
            'DIV9' => 'D9',
            'DIVISION9' => 'D9',
            'PLN' => 'PLN',
            'PLAN' => 'PLN',
            'PLANNER' => 'PLN',
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
            'PLN' => 'PLN - วางแผน - กันยกร',
        ];
    }

    private function divisionSort(string $division): int
    {
        $order = ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D8', 'D9', 'PLN'];
        $index = array_search(strtoupper(trim($division)), $order, true);

        return $index === false ? 999 : $index;
    }

    private function stepLabel($step): string
    {
        if (!$step) {
            return '-';
        }

        $code = trim((string) ($step->code ?? ''));
        $name = trim((string) ($step->name ?? ''));

        return trim($code . ($name !== '' ? ' - ' . $name : ''));
    }

    private function daysToDate($value): ?int
    {
        if (empty($value)) {
            return null;
        }

        try {
            return now('Asia/Bangkok')->startOfDay()->diffInDays(Carbon::parse((string) $value)->startOfDay(), false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function daysSince($value): ?int
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay()->diffInDays(now('Asia/Bangkok')->startOfDay(), false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dateOnly($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function timeOnly($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function friendlyConnectionError(string $message): string
    {
        if (str_contains($message, 'Encryption not supported on the client')) {
            return 'Cannot connect to SQL Server Delivery Plan because the SQL Server driver cannot complete the encryption handshake.';
        }

        return 'Cannot load live production data right now.';
    }

    private function safeReturnUrl(?string $returnUrl): string
    {
        $returnUrl = trim((string) $returnUrl);
        if ($returnUrl !== '' && str_starts_with($returnUrl, url('/'))) {
            return $returnUrl;
        }

        return route('dp.production-status');
    }

    private function csvText($value): string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? '' : "\t" . $value;
    }

    private function paginateCollection(Collection $items, int $perPage, int $page, array $options = []): LengthAwarePaginator
    {
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($results, $total, $perPage, $page, $options);
    }
}
