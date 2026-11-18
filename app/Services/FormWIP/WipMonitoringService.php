<?php

namespace App\Services\FormWIP;

use App\Repositories\FormWIP\WipRepository;
use App\Support\FormDP\ProductionWeightTolerance;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * WIP & Stock Pending Monitoring (Prototype)
 *
 * แบ่งน้ำหนักของแต่ละ WO ออกเป็นกอง (bucket) ตามการไหลของงานในแต่ละขั้น — ไม่บวกยอดซ้ำข้ามขั้น:
 *   ค้างที่ขั้น i      = output ของดีขั้น (i-1) − output ของดีขั้น i − NCR ขั้น i   (ขั้นแรกใช้ยอดเบิก usage เป็น input)
 *   Waiting Production = qty WO − ยอดที่เริ่มแล้ว
 *   Waiting Packaging  = output ขั้นก่อน PK − output PK                       (เฉพาะ route ที่จบด้วย PK)
 *   Waiting FG Stock   = output ขั้นสุดท้าย (WMSOne) − ยอดรับเข้า FG (ERP workorder.received)
 *   Completed          = ยอดรับเข้า FG แล้ว (ERP)
 *   Hold / Rework      = ยอด NCR (receivetype = 3) + WIP ทั้งหมดของ WO ที่ suspended
 * Waiting QC: ยังไม่มีข้อมูลใน WMSOne (WC QA แทบไม่ถูกใช้) จึงยังไม่คำนวณ
 */
class WipMonitoringService
{
    private const CACHE_TTL_SECONDS = 600;
    private const CACHE_VERSION = 'v5';
    /** snapshot เก่ากว่านี้ (นาที) จะกลับไป query สดแทน — sync ตั้งไว้ทุก 15 นาที */
    private const SNAPSHOT_MAX_AGE_MINUTES = 60;
    /** sync ดึง WO ที่เปิดย้อนหลังสูงสุดเท่ากับตัวเลือกบนหน้าจอ */
    public const SYNC_DAYS = 730;
    private const PACK_WC = 'PK';
    private const NOISE_KG = 1.0;

    public const STATUSES = [
        'WAITING_PRODUCTION' => ['label' => 'Waiting Production', 'th' => 'รอเข้าผลิต', 'class' => 'secondary', 'icon' => 'fa-hourglass-start'],
        'IN_PROCESS' => ['label' => 'In Process', 'th' => 'กำลังผลิต', 'class' => 'primary', 'icon' => 'fa-gears'],
        'WAITING_PACKAGING' => ['label' => 'Waiting Packaging', 'th' => 'รอแพ็ค', 'class' => 'info', 'icon' => 'fa-box-open'],
        'WAITING_QC' => ['label' => 'Waiting QC', 'th' => 'รอ QC', 'class' => 'light', 'icon' => 'fa-clipboard-check'],
        'WAITING_FG' => ['label' => 'Waiting FG Stock', 'th' => 'รอรับเข้า FG', 'class' => 'warning', 'icon' => 'fa-warehouse'],
        'HOLD' => ['label' => 'Hold / Rework', 'th' => 'ติดปัญหา/รอแก้ไข', 'class' => 'danger', 'icon' => 'fa-triangle-exclamation'],
        'COMPLETED' => ['label' => 'Completed', 'th' => 'รับเข้า FG แล้ว', 'class' => 'success', 'icon' => 'fa-circle-check'],
    ];

    /** bucket key ที่นับเป็น WIP คงค้าง (ไม่รวม completed) */
    public const WIP_BUCKETS = ['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG', 'HOLD'];

    /** เกณฑ์ Aging (วัน) — ค่า default; override ราย Work Center ได้ใน AGING_BY_WC */
    public const AGING_DEFAULT = ['normal' => 7, 'monitor' => 15, 'warning' => 30];
    public const AGING_BY_WC = [
        // 'AN' => ['normal' => 3, 'monitor' => 7, 'warning' => 14],
    ];

    public const AGING_BANDS = [
        'NORMAL' => ['label' => 'Normal', 'class' => 'success'],
        'MONITOR' => ['label' => 'Monitor', 'class' => 'info'],
        'WARNING' => ['label' => 'Warning', 'class' => 'warning'],
        'CRITICAL' => ['label' => 'Critical', 'class' => 'danger'],
    ];

    public function __construct(private WipRepository $repo)
    {
    }

    /** ข้อมูลที่ผ่าน filter แล้ว ใช้ร่วมกันทั้ง dashboard และ export */
    private function filteredData(array $filters): array
    {
        $dataset = $this->loadDataset($filters['site'], $filters['days'], (bool) $filters['refresh']);

        $deptMaps = $this->departmentMaps(array_unique(array_column($dataset['rows'], 'site')));

        $scoped = collect($dataset['rows'])
            ->filter(fn($r) => $filters['scope'] !== 'pk' || $r['ends_with_pk'])
            ->map(fn($r) => $this->withDepartments($r, $deptMaps[$r['site']] ?? []));

        // summary ใช้ filter ทุกตัว ยกเว้น status (เพื่อให้การ์ดยังแสดงครบเวลาคลิก drill-down)
        $forSummary = $scoped->filter(fn($r) => $this->passes($r, array_merge($filters, ['status' => ''])))->values();
        $rows = $this->sortRows(
            $forSummary->filter(fn($r) => $filters['status'] === '' || $this->matchesStatus($r, $filters['status'])),
            $filters['sort'] ?? 'aging'
        );

        return [$dataset, $scoped, $forSummary, $rows];
    }

    private function sortRows(Collection $rows, string $sort): Collection
    {
        $sorted = match ($sort) {
            'wip' => $rows->sortByDesc(fn($r) => [$r['wip_total'], $r['aging_days']]),
            'due' => $rows->sortBy(fn($r) => [$r['reqdate'] ?: '9999-12-31', -$r['wip_total']]),
            'open' => $rows->sortByDesc(fn($r) => [(string) $r['dateopen'], $r['wo_no']]),
            'wo' => $rows->sortBy(fn($r) => [$r['site'], $r['wo_no']]),
            default => $rows->sortByDesc(fn($r) => [$r['aging_days'], $r['wip_total']]),
        };

        return $sorted->values();
    }

    /** แผนกของ Work Center ต่อ site (cache 6 ชม. — master เปลี่ยนน้อย) */
    private function departmentMaps(array $sites): array
    {
        $maps = [];
        foreach ($sites as $site) {
            try {
                $maps[$site] = Cache::remember("wip:dept-map:{$site}", 21600, fn() => $this->repo->workcenterDepartments($site));
            } catch (\Throwable $e) {
                Log::warning('WIP department map failed', ['site' => $site, 'message' => $e->getMessage()]);
                $maps[$site] = [];
            }
        }

        return $maps;
    }

    private function withDepartments(array $r, array $map): array
    {
        foreach ($r['steps'] as $i => $s) {
            $r['steps'][$i]['dept'] = $map[$s['wc_code']] ?? '-';
        }
        $r['current_dept'] = $r['current_wc'] !== '-' ? ($map[$r['current_wc']] ?? '-') : '-';

        return $r;
    }

    /** ตัวเลือกใน dropdown ของ filter (คำนวณจากข้อมูลชุดเดียวกับหน้าจอ) */
    private function filterOptions(Collection $scoped): array
    {
        $distinct = fn(callable $pick) => $scoped->map($pick)
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => $v !== '' && $v !== '-')
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $steps = $scoped->flatMap(fn($r) => $r['steps']);

        return [
            'dept' => $steps->pluck('dept')->filter(fn($v) => $v && $v !== '-')->unique()->sort()->values(),
            'wc' => $steps->map(fn($s) => ['code' => $s['wc_code'], 'name' => $s['wc_name'], 'dept' => $s['dept'] ?? '-'])
                ->unique('code')->sortBy('code', SORT_NATURAL)->values(),
            'machine' => $steps->filter(fn($s) => !empty($s['machine']))
                ->map(fn($s) => ['machine' => (string) $s['machine'], 'wc' => $s['wc_code']])
                ->unique(fn($m) => $m['wc'] . '|' . $m['machine'])->sortBy(fn($m) => $m['wc'] . ' ' . $m['machine'], SORT_NATURAL)->values(),
            'customer' => $distinct(fn($r) => $r['customer']),
            'grade' => $distinct(fn($r) => $r['grade']),
            'size' => $distinct(fn($r) => $r['size'])->sortBy(fn($v) => is_numeric($v) ? (float) $v : PHP_FLOAT_MAX)->values(),
            'suggest' => $this->suggestions($scoped),
        ];
    }

    /**
     * รายการสำหรับ autocomplete ช่องค้นหา / Product (สร้างจากข้อมูลชุดเดียวกับหน้าจอ)
     * WO มี url → เลือกแล้วเปิดหน้ารายละเอียดได้ทันที
     */
    private function suggestions(Collection $scoped): array
    {
        $wo = $scoped->map(fn($r) => [
            'value' => $r['wo_no'],
            'desc' => trim($r['part_number'] . ' · ' . $r['customer']),
            'group' => 'wo',
            'url' => route('wip.detail', ['site' => strtolower($r['site']), 'workorder' => $r['workorder_id']]),
        ]);

        $product = $scoped->groupBy('part_number')->map(fn($g, $pn) => [
            'value' => (string) $pn,
            'desc' => \Illuminate\Support\Str::limit((string) $g->first()['part_desc'], 70) . ' · ' . $g->count() . ' WO',
            'group' => 'product',
        ])->filter(fn($p) => $p['value'] !== '');

        $customer = $scoped->groupBy('customer')->map(fn($g, $c) => [
            'value' => (string) $c, 'desc' => $g->count() . ' WO', 'group' => 'customer',
        ])->filter(fn($c) => $c['value'] !== '' && $c['value'] !== '-');

        $so = $scoped->filter(fn($r) => $r['ord_number'] !== '')->groupBy('ord_number')->map(fn($g, $so) => [
            'value' => (string) $so, 'desc' => $g->pluck('wo_no')->take(3)->implode(', ') . ($g->count() > 3 ? ' …' : ''), 'group' => 'so',
        ]);

        return $wo->concat($product->values())->concat($customer->values())->concat($so->values())->values()->all();
    }

    /** ข้อมูลสำหรับ Export Excel (ทุกแถวตาม filter ไม่แบ่งหน้า) */
    public function getExportData(array $filters): array
    {
        [$dataset, , $forSummary, $rows] = $this->filteredData($filters);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'statusCards' => $this->statusCards($forSummary),
            'wcSummary' => $this->wcSummary($forSummary),
            'agingSummary' => $this->agingSummary($forSummary),
            'totalWipKg' => $forSummary->sum('wip_total'),
            'fetchedAt' => $dataset['fetched_at'],
            'statuses' => self::STATUSES,
            'agingBands' => self::AGING_BANDS,
        ];
    }

    public function getDashboardData(array $filters, int $page, int $perPage = 50, ?string $path = null): array
    {
        [$dataset, $scoped, $forSummary, $rows] = $this->filteredData($filters);

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $path ?? route('wip.list'), 'query' => request()->except('page', 'refresh')]
        );

        // Dashboard: 10 รายการที่มี WIP ค้างและค้างนานสุด (ไม่ขึ้นกับ filter สถานะ)
        $topCritical = $forSummary
            ->filter(fn($r) => $r['wip_total'] > 0)
            ->sortByDesc(fn($r) => [$r['aging_days'], $r['wip_total']])
            ->take(10)
            ->values();

        return [
            'filters' => $filters,
            'rows' => $paginator,
            'topCritical' => $topCritical,
            'statusCards' => $this->statusCards($forSummary),
            'wcSummary' => $this->wcSummary($forSummary),
            'machineSummary' => $this->machineSummary($forSummary),
            'postProduction' => $this->postProductionBands($forSummary),
            'trend' => $this->trend($filters),
            'agingSummary' => $this->agingSummary($forSummary),
            'options' => $this->filterOptions($scoped),
            'totalWipKg' => $forSummary->sum('wip_total'),
            'fetchedAt' => $dataset['fetched_at'],
            'dataErrors' => $dataset['errors'],
            'dataSources' => $dataset['sources'],
            'statuses' => self::STATUSES,
            'agingBands' => self::AGING_BANDS,
        ];
    }

    public function getDetailData(string $site, int $workorderId): array
    {
        $wo = $this->repo->workorderById($site, $workorderId);
        abort_if(!$wo, 404);

        $steps = $this->repo->steps($site, [$workorderId]);
        $erp = $this->repo->erpReceipts($site, [$wo->workordernumber]);
        $row = $this->buildRow($site, $wo, $steps, $erp->get(strtoupper(trim((string) $wo->workordernumber))));

        $snapshot = app(WipSnapshotService::class);
        $row = $this->applyStatusSince([$row], $snapshot->statusSinceMap($site, $workorderId))[0];
        $statusHistory = $snapshot->history($site, $workorderId);

        $erpLines = collect();
        $erpError = null;
        try {
            $erpLines = $this->repo->erpReceiptLines($site, $wo->workordernumber);
        } catch (\Throwable $e) {
            $erpError = 'โหลดข้อมูลรับเข้า FG จาก ERP ไม่สำเร็จ';
            Log::warning('WIP detail ERP receipt lines failed', ['site' => $site, 'wo' => $wo->workordernumber, 'message' => $e->getMessage()]);
        }

        return [
            'row' => $row,
            'history' => $this->repo->receiveHistory($site, $workorderId),
            'statusHistory' => $statusHistory,
            'snapshotReady' => $snapshot->tablesReady(),
            'erpLines' => $erpLines,
            'erpError' => $erpError,
            'statuses' => self::STATUSES,
            'agingBands' => self::AGING_BANDS,
        ];
    }

    // ------------------------------------------------------------------ dataset

    /**
     * แหล่งข้อมูล:
     *  - snapshot (ตาราง wip_wo_current ที่ sync ทุก 15 นาที) ถ้ามีตารางและ sync สำเร็จล่าสุดไม่เกิน SNAPSHOT_MAX_AGE_MINUTES
     *  - live (query WMSOne + ERP ตรง แล้ว cache 10 นาที) ถ้ายังไม่มี snapshot หรือผู้ใช้กด "อัปเดต"
     * ทั้งสองแบบ Aging จะนับจากวันที่ WO เข้าสถานะปัจจุบัน (status_since) ถ้ามีข้อมูลใน snapshot
     */
    private function loadDataset(string $siteFilter, int $days, bool $refresh): array
    {
        $sites = $siteFilter === 'ALL' ? array_keys(WipRepository::SITES) : [$siteFilter];
        $snapshot = app(WipSnapshotService::class);
        $rows = [];
        $errors = [];
        $fetchedAt = null;
        $sources = [];

        foreach ($sites as $site) {
            try {
                $lastRun = $refresh ? null : $snapshot->freshRun($site, self::SNAPSHOT_MAX_AGE_MINUTES);

                if ($lastRun) {
                    $siteRows = $snapshot->openRows($site, $days);
                    $siteFetchedAt = (string) $lastRun->finished_at;
                    $sources[$site] = 'snapshot';
                } else {
                    $key = 'wip:' . self::CACHE_VERSION . ":{$site}:{$days}";
                    if ($refresh) {
                        Cache::forget($key);
                    }
                    $data = Cache::remember($key, self::CACHE_TTL_SECONDS, fn() => $this->computeSiteRows($site, $days));
                    $siteRows = $this->applyStatusSince($data['rows'], $snapshot->statusSinceMap($site));
                    $siteFetchedAt = $data['fetched_at'];
                    $sources[$site] = 'live';
                }

                $rows = array_merge($rows, $siteRows);
                $fetchedAt = $fetchedAt === null ? $siteFetchedAt : min($fetchedAt, $siteFetchedAt);
            } catch (\Throwable $e) {
                $errors[] = "โหลดข้อมูล {$site} ไม่สำเร็จ";
                Log::warning('WIP dataset load failed', ['site' => $site, 'message' => $e->getMessage()]);
            }
        }

        return ['rows' => $rows, 'fetched_at' => $fetchedAt, 'errors' => $errors, 'sources' => $sources];
    }

    /**
     * ใช้วันที่เข้าสถานะ (จาก snapshot) เป็นฐาน Aging — เฉพาะ WO ที่สถานะยังตรงกับใน snapshot
     *
     * @param array $map workorder_id => ['status' => ..., 'status_since' => ...]
     */
    public function applyStatusSince(array $rows, array $map): array
    {
        foreach ($rows as &$r) {
            $snap = $map[$r['workorder_id']] ?? null;
            if ($snap && $snap['status'] === $r['status'] && !empty($snap['status_since'])) {
                $r['status_since'] = $snap['status_since'];
                $r['aging_ref'] = $snap['status_since'];
                $r['aging_source'] = 'status';
            }
            $r['aging_days'] = $r['aging_ref'] ? max(0, (int) Carbon::parse($r['aging_ref'])->startOfDay()->diffInDays(today(), false)) : 0;
            $r['aging_band'] = $this->agingBand($r['aging_days'], $r['current_wc']);
        }
        unset($r);

        return $rows;
    }

    /** คำนวณแถวของทุก WO ที่เปิดอยู่จาก WMSOne + ERP (ใช้ทั้งหน้าจอแบบ live และงาน sync snapshot) */
    public function computeSiteRows(string $site, int $days): array
    {
        $workorders = $this->repo->openWorkorders($site, $days);
        $stepsByWo = $this->repo->steps($site, $workorders->pluck('id')->map(fn($v) => (int) $v)->all())
            ->groupBy('workorder_id');

        $erp = collect();
        try {
            $erp = $this->repo->erpReceipts($site, $workorders->pluck('workordernumber')
                ->map(fn($v) => strtoupper(trim((string) $v)))->all());
        } catch (\Throwable $e) {
            Log::warning('WIP ERP receipts failed', ['site' => $site, 'message' => $e->getMessage()]);
        }

        $rows = $workorders->map(fn($wo) => $this->buildRow(
            $site,
            $wo,
            $stepsByWo->get($wo->id, collect()),
            $erp->get(strtoupper(trim((string) $wo->workordernumber)))
        ))->values()->all();

        return ['rows' => $rows, 'fetched_at' => now()->toDateTimeString()];
    }

    // ------------------------------------------------------------------ core calculation

    private function buildRow(string $site, object $wo, Collection $stepRows, ?object $erp): array
    {
        $target = (float) $wo->qty;
        $steps = $stepRows->sortBy('workseq')->values()->map(fn($s) => [
            'seq' => (int) $s->workseq,
            'wc_code' => trim((string) $s->wc_code),
            'wc_name' => (string) $s->wc_name,
            'size_in' => $s->msizein,
            'size_out' => $s->msize,
            'input_qty' => (float) $s->input_qty,
            'good_qty' => (float) $s->good_qty,
            'ncr_qty' => (float) $s->ncr_qty,
            'receive_count' => (int) $s->receive_count,
            'first_stamp' => $s->first_stamp,
            'last_stamp' => $s->last_stamp,
            'last_usage' => $s->last_usage,
            'machine' => $s->machine,
            'balance' => 0.0,
            'done' => false,
            'done_by' => null,
        ])->all();

        $n = count($steps);

        // เกณฑ์ "ขั้นนี้ครบ" ให้ตรงกับ Production Status Tracking (Form Tracking):
        //  1) ยอดรับของขั้น (ทุก receivetype รวม NCR) ≥ Qty WO − ค่ายอมรับตามช่วงน้ำหนัก (ProductionWeightTolerance)
        //  2) ถ้าขั้นหลังครบแล้ว ขั้นก่อนหน้าทั้งหมดถือว่าครบ (completePassedSteps)
        $lastDoneIdx = -1;
        foreach ($steps as $i => $s) {
            if (ProductionWeightTolerance::isComplete($s['good_qty'] + $s['ncr_qty'], $target)) {
                $steps[$i]['done'] = true;
                $steps[$i]['done_by'] = 'weight';
                $lastDoneIdx = $i;
            }
        }
        for ($i = 0; $i < $lastDoneIdx; $i++) {
            if (!$steps[$i]['done']) {
                $steps[$i]['done'] = true;
                $steps[$i]['done_by'] = 'passed';
            }
        }
        $erpReceived = (float) ($erp->erp_received ?? 0);
        $endsWithPk = $n > 0 && strtoupper($steps[$n - 1]['wc_code']) === self::PACK_WC;
        $procEnd = $endsWithPk ? $n - 2 : $n - 1;   // ขั้นผลิตสุดท้ายก่อน PK

        $buckets = array_fill_keys(array_merge(self::WIP_BUCKETS, ['COMPLETED']), 0.0);
        $currentIdx = null;

        if ($n > 0) {
            $finalOut = $steps[$n - 1]['good_qty'];
            $maxOut = max(array_column($steps, 'good_qty'));
            $started = max($steps[0]['input_qty'], $maxOut);
            // WO ผลิตครบ = ขั้นสุดท้ายครบตามเกณฑ์ Tracking หรือยอดรับเข้า FG ใน ERP ครบตามเกณฑ์เดียวกัน
            $finalDone = $steps[$n - 1]['done'] || ProductionWeightTolerance::isComplete($erpReceived, $target);

            // In Process: ค้างระหว่างขั้น (ไม่รวมขั้น PK)
            for ($i = 0; $i <= $procEnd; $i++) {
                $upstream = $i === 0
                    ? max($steps[0]['input_qty'], $steps[0]['good_qty'] + $steps[0]['ncr_qty'])
                    : $steps[$i - 1]['good_qty'];
                $balance = max(0.0, $upstream - $steps[$i]['good_qty'] - $steps[$i]['ncr_qty']);
                // ขั้นที่ครบแล้วตามเกณฑ์ Tracking → ส่วนต่างที่เหลือคือ yield loss ไม่ใช่ WIP
                if ($steps[$i]['done'] || $finalDone) {
                    $balance = 0.0;
                }
                // งาน partial: ส่วนต่างที่อยู่ในค่ายอมรับของยอดที่ส่งเข้ามา ถือเป็น yield loss
                if ($steps[$i]['good_qty'] > 0 && $balance <= ProductionWeightTolerance::allowedShortageKg($upstream)) {
                    $balance = 0.0;
                }
                $steps[$i]['balance'] = $balance;
                $buckets['IN_PROCESS'] += $balance;
            }

            if ($endsWithPk && $procEnd >= 0) {
                $upstream = $steps[$procEnd]['good_qty'];
                $pack = max(0.0, $upstream - $finalOut - $steps[$n - 1]['ncr_qty']);
                if (($finalOut > 0 && $pack <= ProductionWeightTolerance::allowedShortageKg($upstream)) || $finalDone || $steps[$n - 1]['done']) {
                    $pack = 0.0;
                }
                $steps[$n - 1]['balance'] = $pack;
                $buckets['WAITING_PACKAGING'] = $pack;
            }

            $buckets['WAITING_PRODUCTION'] = ($finalDone || ProductionWeightTolerance::isComplete($started, $target))
                ? 0.0
                : max(0.0, $target - $started);
            $buckets['WAITING_FG'] = max(0.0, $finalOut - $erpReceived);
            // NCR ของ WO ที่ผลิตครบแล้ว = ประวัติ/scrap ไม่ใช่ของค้าง → ไม่นับเป็น Hold
            $buckets['HOLD'] = $finalDone ? 0.0 : array_sum(array_column($steps, 'ncr_qty'));

            // ยอดรวมทุกสถานะต้องไม่เกินน้ำหนัก WO (หรือยอดที่ผลิต/รับเข้าจริง ถ้ามากกว่า)
            // เกิดได้เมื่อ ERP รับเข้ามากกว่า output ขั้นสุดท้ายใน WMSOne → ตัดส่วนเกินจาก รอเข้าผลิต → กำลังผลิต → รอแพ็ค
            $cap = max($target, $finalOut, $erpReceived);
            $excess = $buckets['WAITING_PRODUCTION'] + $buckets['IN_PROCESS'] + $buckets['WAITING_PACKAGING']
                + $buckets['WAITING_FG'] + $buckets['HOLD'] + $erpReceived - $cap;
            if ($excess > self::NOISE_KG) {
                $cut = min($excess, $buckets['WAITING_PRODUCTION']);
                $buckets['WAITING_PRODUCTION'] -= $cut;
                $excess -= $cut;

                if ($excess > 0 && $buckets['IN_PROCESS'] > 0) {
                    $cut = min($excess, $buckets['IN_PROCESS']);
                    $ratio = ($buckets['IN_PROCESS'] - $cut) / $buckets['IN_PROCESS'];
                    for ($i = 0; $i <= $procEnd; $i++) {
                        $steps[$i]['balance'] *= $ratio;
                    }
                    $buckets['IN_PROCESS'] -= $cut;
                    $excess -= $cut;
                }

                if ($excess > 0 && $buckets['WAITING_PACKAGING'] > 0) {
                    $cut = min($excess, $buckets['WAITING_PACKAGING']);
                    $buckets['WAITING_PACKAGING'] -= $cut;
                    $steps[$n - 1]['balance'] = $buckets['WAITING_PACKAGING'];
                }
            }
        } else {
            // ไม่มี routing → ถือว่ายังไม่เริ่ม
            $buckets['WAITING_PRODUCTION'] = max(0.0, $target - $erpReceived);
        }
        $buckets['COMPLETED'] = $erpReceived;

        if ($wo->suspended) {
            foreach (['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING'] as $k) {
                $buckets['HOLD'] += $buckets[$k];
                $buckets[$k] = 0.0;
            }
        }

        foreach ($buckets as $k => $v) {
            $buckets[$k] = $v < self::NOISE_KG ? 0.0 : round($v, 2);
        }

        // สถานะหลักของ WO
        if ($wo->suspended) {
            $status = 'HOLD';
        } else {
            $candidates = array_intersect_key($buckets, array_flip(['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG']));
            arsort($candidates);
            $status = reset($candidates) > 0 ? array_key_first($candidates) : ($buckets['HOLD'] > 0 ? 'HOLD' : 'COMPLETED');
        }

        // ตำแหน่งงานปัจจุบัน + วันอ้างอิงสำหรับ Aging
        $refDate = $wo->dateopen;
        switch ($status) {
            case 'IN_PROCESS':
                $balances = array_column(array_slice($steps, 0, $procEnd + 1), 'balance');
                $currentIdx = array_search(max($balances), $balances, true);
                $refDate = $this->maxDate([
                    $steps[$currentIdx]['last_stamp'],
                    $steps[$currentIdx]['last_usage'],
                    $currentIdx > 0 ? $steps[$currentIdx - 1]['last_stamp'] : null,
                ]) ?? $wo->dateopen;
                break;
            case 'WAITING_PACKAGING':
                $currentIdx = $n - 1;
                $refDate = $this->maxDate([$steps[$procEnd]['last_stamp'] ?? null, $steps[$n - 1]['last_stamp']]) ?? $wo->dateopen;
                break;
            case 'WAITING_FG':
                $currentIdx = $n - 1;
                $refDate = $this->maxDate([$steps[$n - 1]['last_stamp'], $erp->last_fg_date ?? null]) ?? $wo->dateopen;
                break;
            case 'WAITING_PRODUCTION':
                $currentIdx = $n > 0 ? 0 : null;
                break;
            case 'HOLD':
                $refDate = $wo->suspenddate ?: ($this->lastActivity($steps) ?? $wo->dateopen);
                break;
            case 'COMPLETED':
                $refDate = ($erp->last_fg_date ?? null) ?: ($this->lastActivity($steps) ?? $wo->dateopen);
                break;
        }

        $currentWc = $currentIdx !== null ? $steps[$currentIdx]['wc_code'] : '-';
        $agingDays = $refDate ? (int) Carbon::parse($refDate)->startOfDay()->diffInDays(today(), false) : 0;
        $agingDays = max(0, $agingDays);
        $wipTotal = round(array_sum(array_intersect_key($buckets, array_flip(self::WIP_BUCKETS))), 2);

        return [
            'site' => $site,
            'workorder_id' => (int) $wo->id,
            'wo_no' => (string) $wo->workordernumber,
            'part_number' => (string) $wo->partnumber,
            'part_desc' => trim((string) ($wo->part_desc ?: $wo->wo_desc)),
            'customer' => (string) $wo->customer,
            'size' => trim((string) $wo->fsize),
            'grade' => trim((string) $wo->fcat),
            'ord_number' => trim((string) $wo->ordnumber),
            'qty' => $target,
            'dateopen' => $wo->dateopen,
            'reqdate' => $wo->reqdate,
            'dateclose' => $wo->dateclose,
            'suspended' => (bool) $wo->suspended,
            'steps' => $steps,
            'step_count' => $n,
            'ends_with_pk' => $endsWithPk,
            'route_text' => implode(' → ', array_column($steps, 'wc_code')),
            'buckets' => $buckets,
            'wip_total' => $wipTotal,
            'status' => $status,
            'current_wc' => $currentWc,
            'current_machine' => $currentIdx !== null ? ($steps[$currentIdx]['machine'] ?: '-') : '-',
            'production_start' => $steps ? $this->minDate(array_column($steps, 'first_stamp')) : null,
            'final_output' => $n > 0 ? $steps[$n - 1]['good_qty'] : 0.0,
            'ncr_total' => round(array_sum(array_column($steps, 'ncr_qty')), 2),
            'erp_received' => $erpReceived,
            'erp_found' => $erp !== null,
            'first_fg_date' => $erp->first_fg_date ?? null,
            'last_fg_date' => $erp->last_fg_date ?? null,
            'last_tx' => $this->lastActivity($steps),
            'aging_ref' => $refDate,
            'aging_source' => 'activity',   // activity = ประมาณจาก transaction ล่าสุด, status = วันที่เข้าสถานะจริง (snapshot)
            'status_since' => null,
            'activity_ref' => $refDate,     // ค่าเดิมจาก transaction ใช้เป็นจุดเริ่มต้นตอน sync ครั้งแรก
            'aging_days' => $agingDays,
            'aging_band' => $this->agingBand($agingDays, $currentWc),
        ];
    }

    public function agingBand(int $days, string $wc): string
    {
        $t = self::AGING_BY_WC[$wc] ?? self::AGING_DEFAULT;

        return match (true) {
            $days <= $t['normal'] => 'NORMAL',
            $days <= $t['monitor'] => 'MONITOR',
            $days <= $t['warning'] => 'WARNING',
            default => 'CRITICAL',
        };
    }

    private function lastActivity(array $steps): ?string
    {
        return $this->maxDate(array_merge(array_column($steps, 'last_stamp'), array_column($steps, 'last_usage')));
    }

    private function maxDate(array $values): ?string
    {
        $values = array_filter($values);

        return $values ? (string) max($values) : null;
    }

    private function minDate(array $values): ?string
    {
        $values = array_filter($values);

        return $values ? (string) min($values) : null;
    }

    // ------------------------------------------------------------------ filters & summaries

    private function matchesStatus(array $r, string $status): bool
    {
        // drill-down ตามการ์ด: แสดง WO ที่มีน้ำหนักค้างใน bucket นั้น (รองรับ partial)
        return ($r['buckets'][$status] ?? 0) > 0 || $r['status'] === $status;
    }

    private function passes(array $r, array $f): bool
    {
        // ตำแหน่งงาน: ผ่านถ้าขั้นปัจจุบัน หรือมีงานค้างอยู่ที่ขั้นนั้น
        $at = function (callable $match) use ($r): bool {
            foreach ($r['steps'] as $s) {
                if ($match($s) && ($s['balance'] > 0 || $s['wc_code'] === $r['current_wc'])) {
                    return true;
                }
            }

            return false;
        };

        if ($f['wc'] !== '' && $r['current_wc'] !== $f['wc'] && !$at(fn($s) => $s['wc_code'] === $f['wc'])) {
            return false;
        }
        if (($f['dept'] ?? '') !== '' && ($r['current_dept'] ?? '') !== $f['dept'] && !$at(fn($s) => ($s['dept'] ?? '') === $f['dept'])) {
            return false;
        }
        if (($f['machine'] ?? '') !== '' && $r['current_machine'] !== $f['machine'] && !$at(fn($s) => (string) $s['machine'] === $f['machine'])) {
            return false;
        }
        if (($f['product'] ?? '') !== '' && !str_contains(mb_strtoupper($r['part_number'] . ' ' . $r['part_desc']), mb_strtoupper($f['product']))) {
            return false;
        }
        if (($f['customer'] ?? '') !== '' && $r['customer'] !== $f['customer']) {
            return false;
        }
        if (($f['grade'] ?? '') !== '' && $r['grade'] !== $f['grade']) {
            return false;
        }
        if (($f['size'] ?? '') !== '' && $r['size'] !== $f['size']) {
            return false;
        }
        if (!empty($f['date_from']) || !empty($f['date_to'])) {
            $open = $r['dateopen'] ? Carbon::parse($r['dateopen'])->toDateString() : null;
            if (!$open || (!empty($f['date_from']) && $open < $f['date_from']) || (!empty($f['date_to']) && $open > $f['date_to'])) {
                return false;
            }
        }
        if (($f['due'] ?? '') !== '') {
            $due = $r['reqdate'] ? Carbon::parse($r['reqdate'])->startOfDay() : null;
            $ok = match ($f['due']) {
                'overdue' => $due && $due->lt(today()),
                '7' => $due && $due->betweenIncluded(today(), today()->addDays(7)),
                '30' => $due && $due->betweenIncluded(today(), today()->addDays(30)),
                'none' => $due === null,
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }
        if ($f['aging'] !== '' && $r['aging_band'] !== $f['aging']) {
            return false;
        }
        if ($f['only_wip'] && $r['wip_total'] <= 0) {
            return false;
        }
        if ($f['q'] !== '') {
            $hay = mb_strtoupper(implode(' ', [$r['wo_no'], $r['part_number'], $r['part_desc'], $r['customer'], $r['size'], $r['grade'], $r['ord_number']]));
            foreach (preg_split('/\s+/', mb_strtoupper($f['q'])) as $term) {
                if ($term !== '' && !str_contains($hay, $term)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function statusCards(Collection $rows): array
    {
        $cards = [];
        foreach (self::STATUSES as $key => $meta) {
            $withQty = $rows->filter(fn($r) => ($r['buckets'][$key] ?? 0) > 0);
            $cards[$key] = $meta + [
                'kg' => $key === 'WAITING_QC' ? null : $withQty->sum(fn($r) => $r['buckets'][$key]),
                'wo_count' => $withQty->count(),
                'critical' => $withQty->where('status', $key)->where('aging_band', 'CRITICAL')->count(),
            ];
        }

        return $cards;
    }

    private function wcSummary(Collection $rows): Collection
    {
        $acc = [];
        foreach ($rows as $r) {
            if ($r['suspended']) {
                continue;
            }
            foreach ($r['steps'] as $i => $s) {
                $isPack = $r['ends_with_pk'] && $i === count($r['steps']) - 1;
                $key = $s['wc_code'];
                $acc[$key] ??= [
                    'wc_code' => $key, 'wc_name' => $s['wc_name'], 'dept' => $s['dept'] ?? '-',
                    'in_process' => 0.0, 'waiting_pack' => 0.0, 'waiting_prod' => 0.0,
                    'bands' => array_fill_keys(array_keys(self::AGING_BANDS), 0.0), 'wo' => [],
                ];
                $kg = 0.0;
                if ($s['balance'] > 0) {
                    $acc[$key][$isPack ? 'waiting_pack' : 'in_process'] += $s['balance'];
                    $kg += $s['balance'];
                }
                if ($i === 0 && $r['buckets']['WAITING_PRODUCTION'] > 0) {
                    $acc[$key]['waiting_prod'] += $r['buckets']['WAITING_PRODUCTION'];
                    $kg += $r['buckets']['WAITING_PRODUCTION'];
                }
                if ($kg > 0) {
                    // Heatmap: น้ำหนักที่ค้างอยู่ที่ WC นี้ แยกตามระดับ Aging ของ WO
                    $acc[$key]['bands'][$r['aging_band']] += $kg;
                    $acc[$key]['wo'][$r['site'] . $r['workorder_id']] = true;
                }
            }
        }

        return collect($acc)
            ->map(fn($x) => array_merge($x, [
                'wo_count' => count($x['wo']),
                'total' => $x['in_process'] + $x['waiting_pack'] + $x['waiting_prod'],
            ]))
            ->filter(fn($x) => $x['total'] > 0)
            ->sortByDesc('total')
            ->values();
    }

    /** แถวท้าย heatmap: งานที่ผลิตเสร็จแล้วแต่ยังไม่อยู่ที่ WC (รอรับเข้า FG) และ Hold */
    private function postProductionBands(Collection $rows): array
    {
        $out = [];
        foreach (['WAITING_FG', 'HOLD'] as $status) {
            $bands = array_fill_keys(array_keys(self::AGING_BANDS), 0.0);
            $wo = 0;
            foreach ($rows as $r) {
                if (($r['buckets'][$status] ?? 0) > 0) {
                    $bands[$r['aging_band']] += $r['buckets'][$status];
                    $wo++;
                }
            }
            $out[$status] = ['bands' => $bands, 'total' => array_sum($bands), 'wo_count' => $wo];
        }

        return $out;
    }

    /** แนวโน้ม WIP รายวันจาก wip_daily_snapshots (ใช้ site / route / ช่วงวันเปิด WO แบบเดียวกับหน้าจอ) */
    private function trend(array $filters): array
    {
        $sites = $filters['site'] === 'ALL' ? array_keys(WipRepository::SITES) : [$filters['site']];
        $points = app(WipSnapshotService::class)->dailyTrend($sites, $filters['scope'] === 'pk', $filters['days'], 90);

        return [
            'labels' => $points->map(fn($p) => Carbon::parse($p->snapshot_date)->format('d/m'))->values()->all(),
            'series' => [
                'WAITING_PRODUCTION' => $points->map(fn($p) => round($p->wp / 1000, 2))->values()->all(),
                'IN_PROCESS' => $points->map(fn($p) => round($p->ip / 1000, 2))->values()->all(),
                'WAITING_PACKAGING' => $points->map(fn($p) => round($p->pkg / 1000, 2))->values()->all(),
                'WAITING_FG' => $points->map(fn($p) => round($p->fg / 1000, 2))->values()->all(),
                'HOLD' => $points->map(fn($p) => round($p->hd / 1000, 2))->values()->all(),
            ],
            'days' => $points->count(),
            'first' => $points->first()->snapshot_date ?? null,
        ];
    }

    /** งานค้างรายเครื่อง — งานที่ยังไม่มีการรับในขั้นนั้นจะยังไม่รู้เครื่อง จึงแยกยอดไว้ต่างหาก */
    private function machineSummary(Collection $rows): array
    {
        $acc = [];
        $unassignedKg = 0.0;
        $unassignedWo = 0;
        foreach ($rows as $r) {
            if ($r['suspended']) {
                continue;
            }
            foreach ($r['steps'] as $s) {
                if ($s['balance'] <= 0) {
                    continue;
                }
                if (empty($s['machine'])) {
                    $unassignedKg += $s['balance'];
                    $unassignedWo++;
                    continue;
                }
                $key = $s['wc_code'] . '|' . $s['machine'];
                $acc[$key] ??= ['wc_code' => $s['wc_code'], 'machine' => $s['machine'], 'kg' => 0.0, 'wo_count' => 0];
                $acc[$key]['kg'] += $s['balance'];
                $acc[$key]['wo_count']++;
            }
        }

        return [
            'top' => collect($acc)->sortByDesc('kg')->take(15)->values(),
            'unassigned_kg' => $unassignedKg,
            'unassigned_wo' => $unassignedWo,
        ];
    }

    private function agingSummary(Collection $rows): array
    {
        $wip = $rows->filter(fn($r) => $r['wip_total'] > 0);
        $out = [];
        foreach (self::AGING_BANDS as $key => $meta) {
            $band = $wip->where('aging_band', $key);
            $out[$key] = $meta + ['wo_count' => $band->count(), 'kg' => $band->sum('wip_total')];
        }

        return $out;
    }
}
