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
    private const CACHE_VERSION = 'v3';
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

    public function getDashboardData(array $filters, int $page, int $perPage = 50): array
    {
        $dataset = $this->loadDataset($filters['site'], $filters['days'], (bool) $filters['refresh']);

        $scoped = collect($dataset['rows'])
            ->filter(fn($r) => $filters['scope'] !== 'pk' || $r['ends_with_pk']);

        // dashboard summary ใช้ filter ทุกตัว ยกเว้น status (เพื่อให้การ์ดยังแสดงครบเวลาคลิก drill-down)
        $forSummary = $scoped->filter(fn($r) => $this->passes($r, array_merge($filters, ['status' => ''])))->values();
        $rows = $forSummary->filter(fn($r) => $filters['status'] === '' || $this->matchesStatus($r, $filters['status']))
            ->sortByDesc(fn($r) => [$r['aging_days'], $r['wip_total']])
            ->values();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => route('wip.index'), 'query' => request()->except('page', 'refresh')]
        );

        return [
            'filters' => $filters,
            'rows' => $paginator,
            'statusCards' => $this->statusCards($forSummary),
            'wcSummary' => $this->wcSummary($forSummary),
            'machineSummary' => $this->machineSummary($forSummary),
            'agingSummary' => $this->agingSummary($forSummary),
            'wcOptions' => $scoped->flatMap(fn($r) => array_column($r['steps'], 'wc_code'))->unique()->sort()->values(),
            'totalWipKg' => $forSummary->sum('wip_total'),
            'fetchedAt' => $dataset['fetched_at'],
            'dataErrors' => $dataset['errors'],
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
            'erpLines' => $erpLines,
            'erpError' => $erpError,
            'statuses' => self::STATUSES,
            'agingBands' => self::AGING_BANDS,
        ];
    }

    // ------------------------------------------------------------------ dataset

    private function loadDataset(string $siteFilter, int $days, bool $refresh): array
    {
        $sites = $siteFilter === 'ALL' ? array_keys(WipRepository::SITES) : [$siteFilter];
        $rows = [];
        $errors = [];
        $fetchedAt = null;

        foreach ($sites as $site) {
            $key = 'wip:' . self::CACHE_VERSION . ":{$site}:{$days}";
            if ($refresh) {
                Cache::forget($key);
            }

            try {
                $data = Cache::remember($key, self::CACHE_TTL_SECONDS, fn() => $this->buildSiteRows($site, $days));
                $rows = array_merge($rows, $data['rows']);
                $fetchedAt = $fetchedAt === null ? $data['fetched_at'] : min($fetchedAt, $data['fetched_at']);
            } catch (\Throwable $e) {
                $errors[] = "โหลดข้อมูล {$site} ไม่สำเร็จ";
                Log::warning('WIP dataset load failed', ['site' => $site, 'message' => $e->getMessage()]);
            }
        }

        return ['rows' => $rows, 'fetched_at' => $fetchedAt, 'errors' => $errors];
    }

    private function buildSiteRows(string $site, int $days): array
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
        ])->all();

        $n = count($steps);
        $erpReceived = (float) ($erp->erp_received ?? 0);
        $endsWithPk = $n > 0 && strtoupper($steps[$n - 1]['wc_code']) === self::PACK_WC;
        $procEnd = $endsWithPk ? $n - 2 : $n - 1;   // ขั้นผลิตสุดท้ายก่อน PK

        $buckets = array_fill_keys(array_merge(self::WIP_BUCKETS, ['COMPLETED']), 0.0);
        $currentIdx = null;

        if ($n > 0) {
            $finalOut = $steps[$n - 1]['good_qty'];
            $maxOut = max(array_column($steps, 'good_qty'));
            $started = max($steps[0]['input_qty'], $maxOut);
            // ถือว่าผลิตครบ ถ้า output ขั้นสุดท้าย หรือยอดรับเข้า FG ใน ERP ครบตาม tolerance
            $finalDone = ProductionWeightTolerance::isComplete(max($finalOut, $erpReceived), $target);

            // In Process: ค้างระหว่างขั้น (ไม่รวมขั้น PK)
            for ($i = 0; $i <= $procEnd; $i++) {
                $upstream = $i === 0
                    ? max($steps[0]['input_qty'], $steps[0]['good_qty'] + $steps[0]['ncr_qty'])
                    : $steps[$i - 1]['good_qty'];
                $balance = max(0.0, $upstream - $steps[$i]['good_qty'] - $steps[$i]['ncr_qty']);
                // ส่วนต่างเล็ก ๆ หลังขั้นนั้นรับแล้ว = yield loss ไม่ใช่ WIP
                if ($steps[$i]['good_qty'] > 0 && $balance <= ProductionWeightTolerance::allowedShortageKg($upstream)) {
                    $balance = 0.0;
                }
                if ($finalDone) {
                    $balance = 0.0;
                }
                $steps[$i]['balance'] = $balance;
                $buckets['IN_PROCESS'] += $balance;
            }

            if ($endsWithPk && $procEnd >= 0) {
                $upstream = $steps[$procEnd]['good_qty'];
                $pack = max(0.0, $upstream - $finalOut - $steps[$n - 1]['ncr_qty']);
                if (($finalOut > 0 && $pack <= ProductionWeightTolerance::allowedShortageKg($upstream)) || $finalDone) {
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
            'aging_days' => $agingDays,
            'aging_band' => $this->agingBand($agingDays, $currentWc),
        ];
    }

    private function agingBand(int $days, string $wc): string
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
        if ($f['wc'] !== '' && $r['current_wc'] !== $f['wc']
            && !collect($r['steps'])->contains(fn($s) => $s['wc_code'] === $f['wc'] && $s['balance'] > 0)) {
            return false;
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
                $acc[$key] ??= ['wc_code' => $key, 'wc_name' => $s['wc_name'], 'in_process' => 0.0, 'waiting_pack' => 0.0, 'waiting_prod' => 0.0, 'wo' => []];
                if ($s['balance'] > 0) {
                    $acc[$key][$isPack ? 'waiting_pack' : 'in_process'] += $s['balance'];
                    $acc[$key]['wo'][$r['site'] . $r['workorder_id']] = true;
                }
                if ($i === 0 && $r['buckets']['WAITING_PRODUCTION'] > 0) {
                    $acc[$key]['waiting_prod'] += $r['buckets']['WAITING_PRODUCTION'];
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
