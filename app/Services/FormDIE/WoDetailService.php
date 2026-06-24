<?php

namespace App\Services\FormDIE;

use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use App\Repositories\FormDIE\WoDetailRepository;

class WoDetailService
{
    /** step ถือว่า "เสร็จ" เมื่อ (fg+ncr) ไม่น้อยกว่า 98% ของยอดเปิดแผน */
    private const STEP_TOLERANCE = 0.02;

    public function __construct(
        private WoDetailRepository $repo,
        private DieUsageEquipmentRepository $dieRepo,
    ) {}

    /**
     * รายการ Customer Claim ในช่วงวันที่ พร้อม map กลับไปยัง workordernumber
     * เพื่อให้คลิกจาก dashboard ไปหน้า WO Detail ได้
     *
     * @param array  $sites รายการ site code: 'W' / 'P'
     */
    public function recentClaims(array $sites, string $from, string $to): array
    {
        $rows = [];
        foreach ($sites as $site) {
            [$claimConn, $mfgConn] = $this->claimConnsFor($site);

            try {
                $claims = $this->repo->getRecentClaims($from, $to, $claimConn);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$claims) {
                continue;
            }

            // map Sales Order → workordernumber (อาจมีหลาย WO ต่อ 1 SO หรือไม่มีเลย)
            $orders = array_map(fn ($c) => (string) ($c->ordnumber ?? ''), $claims);
            $woMap = [];
            try {
                foreach ($this->repo->getWorkordersBySalesOrders($orders, $mfgConn) as $w) {
                    $key = strtoupper(trim((string) ($w->ordnumber ?? '')));
                    if ($key !== '') {
                        $woMap[$key][] = (string) $w->workordernumber;
                    }
                }
            } catch (\Throwable $e) {
                $woMap = [];
            }

            foreach ($claims as $c) {
                $ord = strtoupper(trim((string) ($c->ordnumber ?? '')));
                $returnNumber = strtoupper(trim((string) ($c->returnnumber ?? '')));
                $workorders = array_values(array_unique($woMap[$ord] ?? []));
                // โชว์เคลมทุกใบที่เป็น EC แม้ยังผูก WO (MFG) ไม่ได้ — ฝั่ง UI จะขึ้น "— ไม่พบ WO —"
                if (!str_starts_with($returnNumber, 'EC')) {
                    continue;
                }

                $rows[] = [
                    'site'         => strtoupper($site),
                    'transdate'    => $c->transdate ?? null,
                    'returnnumber' => $c->returnnumber ?? null,
                    'invnumber'    => $c->invnumber ?? null,
                    'sales_order'  => $c->ordnumber ?? null,
                    'customer_id'  => $c->customernumber ?? null,
                    'customer'     => $c->customer_name ?? null,
                    'qty'          => (float) ($c->qty ?? 0),
                    'amount'       => (float) ($c->amount ?? 0),
                    'notes'        => $c->notes ?? null,
                    'workorders'   => $workorders,
                ];
            }
        }

        // เรียงวันที่ล่าสุดก่อน (กรณีรวมหลาย site)
        usort($rows, fn ($a, $b) => strcmp((string) $b['transdate'], (string) $a['transdate']));

        return [
            'from'  => $from,
            'to'    => $to,
            'count' => count($rows),
            'rows'  => $rows,
        ];
    }

    /**
     * map site code → [connection ฐานบัญชี (claim), connection ฐาน MFG (workorder)]
     */
    private function claimConnsFor(string $site): array
    {
        return strtoupper(trim($site)) === 'P'
            ? ['pgsqlp', 'pgsqlmfgp']
            : ['pgsqlw', 'pgsqlmfgw'];
    }

    public function detail(string $wo): array
    {
        $wo     = mb_strtoupper(trim($wo));
        $woConn = WoDetailRepository::connectionFor($wo);

        $header = $this->repo->getHeader($wo, $woConn);
        if (!$header) {
            return ['error' => 'workorder not found', 'wo' => $wo];
        }

        $woId      = (int) $header->id;
        $bom       = $this->repo->getBom($woId, $woConn);
        $matUsage  = $this->repo->getMatUsage($woId, $woConn);
        $steps     = $this->repo->getWorkcenters($woId, $woConn);
        $receives  = $this->repo->getReceives($woId, $woConn);
        $machine   = $this->repo->getMachineTests($woId, $woConn);
        $tests     = $this->repo->getQualityTests($woId, $woConn);
        $salesOrder = trim((string) ($header->ordnumber ?? ''));
        $claimConn = str_starts_with($wo, '+') ? 'pgsqlp' : 'pgsqlw';
        $claimLookupError = null;
        try {
            $customerClaims = $this->repo->getCustomerClaims($salesOrder, $claimConn);
        } catch (\Throwable $e) {
            $customerClaims = [];
            $claimLookupError = $e->getMessage();
        }

        $wcIds     = array_unique(array_map(fn($s) => (int) $s->workcenter_id, $steps));
        $specs     = $this->repo->getTestSpecs($wcIds, $woConn);
        $specsByWc = [];
        foreach ($specs as $s) $specsByWc[(int) $s->workcenter_id] = $s;

        // Group by workseq
        $receivesByWs = [];
        $machineByWs  = [];
        $testsByWs    = [];
        foreach ($receives as $r) $receivesByWs[(int) $r->workseq][] = $r;
        foreach ($machine  as $m) $machineByWs[(int) $m->workseq][]  = $m;
        foreach ($tests    as $t) $testsByWs[(int) $t->workseq][]    = $t;

        // Die used per workseq (ใช้จาก ERP)
        $dies = $this->normalizeDieBlocks($this->dieRepo->getByWorkorder($wo));

        $planQty = (float) $header->plan_qty;

        // Build step rows
        $stepRows = [];
        $prevGoodQty = $planQty;
        $hasPreviousStep = false;
        // de-dup ไดร์ข้าม step: ไดร์ 1 ตัวถูก assign ได้ step เดียว (กันโผล่ซ้ำในงานที่มีรีดหลาย step)
        // เรียงตาม workseq น้อย→มาก step แรกที่ขนาดเข้าเกณฑ์ได้สิทธิ์ก่อน
        $usedDieKeys = [];
        foreach ($steps as $s) {
            $wsNo = (int) $s->workseq;
            $wcId = (int) $s->workcenter_id;
            $stepReceives = $receivesByWs[$wsNo] ?? [];
            $plannedBlocks = $this->plannedBlocksForStep($s);
            $stepDiesByBlock = $this->diesByBlockForStep(
                $dies,
                $plannedBlocks,
                $stepReceives,
                $machineByWs[$wsNo] ?? [],
                $testsByWs[$wsNo] ?? [],
                $usedDieKeys,
                $wsNo,
            );
            $receiveWindow = $this->receiveWindow($stepReceives);
            $fgQty  = 0;
            $ncrQty = 0;
            foreach ($stepReceives as $r) {
                $q = (float) ($r->qty ?? 0);
                if ((int) $r->receivetype === 3) {
                    // receivetype = 3 = NCR / rework เท่านั้น
                    $ncrQty += $q;
                } else {
                    // type 0 = WIP, 1 = รับของดี, 2/4 = อื่นๆ → นับเป็นของดีที่รับเข้า
                    $fgQty += $q;
                }
            }

            // Block plan
            $blocks = [];
            foreach ($plannedBlocks as $plannedBlock) {
                $blockNo = (int) $plannedBlock['block_no'];
                $blocks[] = [
                    'block_no'   => $blockNo,
                    'size_plan'  => $plannedBlock['size_plan'],
                    'material'   => $plannedBlock['material'],
                    'dies'       => array_map(fn($d) => $this->makeDieUsageRow($d), $stepDiesByBlock[$blockNo] ?? []),
                ];
            }

            // คือ "แผนกรีด" ไหม? เช็ค desc มีคำว่า "รีด" หรือมี block data
            $isDrawing = (mb_strpos((string) $s->wc_desc, 'รีด') !== false) || count($blocks) > 0;

            // เกณฑ์เสร็จ: ขาดได้ไม่เกิน 2% เทียบ baseline (step ก่อนหน้า / WO ที่เปิด); ถ้าเกินถือว่าเสร็จ
            // variance เก็บแบบมีเครื่องหมาย (− = รับน้อยกว่า baseline, + = เกิน) เพื่อให้เห็นทิศทาง
            $received = $fgQty + $ncrQty;
            $statusQty = $received;
            $statusBaseline = $prevGoodQty;
            $variance = $statusBaseline > 0 ? ($statusQty - $statusBaseline) / $statusBaseline : 1;
            $planVariance = $planQty > 0 ? ($fgQty - $planQty) / $planQty : 1;
            $stepStatus = 'pending';
            if ($statusQty > 0)                                          $stepStatus = 'in_progress';
            if ($statusQty > 0 && $variance >= -self::STEP_TOLERANCE) $stepStatus = 'done';

            $stepRows[] = [
                'workseq'        => $wsNo,
                'workcenter'     => $s->workcenternumber,
                'workcenter_desc'=> $s->wc_desc,
                'is_drawing'     => $isDrawing,
                'msize_plan'     => $s->msize,
                'msize_in_plan'  => $s->msizein,
                'msize_tol'      => '+' . $s->msizetolp . '/' . $s->msizetolm,
                'step_desc'      => $s->step_desc,
                'esthour'        => $s->esthour,
                'notes'          => $s->notes,
                'fg_qty'         => $fgQty,
                'ncr_qty'        => $ncrQty,
                'received'       => $received,
                'baseline_qty'   => $statusBaseline,
                'baseline_label' => $hasPreviousStep ? 'FG step ก่อนหน้า' : 'แผน',
                'variance_pct'   => round($variance * 100, 2),
                'plan_variance_pct' => round($planVariance * 100, 2),
                'step_status'    => $stepStatus,
                'receive_count'  => count($stepReceives),
                'receive_start'  => $receiveWindow['start'],
                'receive_end'    => $receiveWindow['end'],
                'progress_pct'   => $statusBaseline > 0 ? round(($statusQty / $statusBaseline) * 100, 1) : 0,
                'blocks'         => $isDrawing ? $blocks : [],
                'station_machines' => $this->stationMachines(
                    $stepReceives,
                    $machineByWs[$wsNo] ?? [],
                ),
                'machine_tests'  => array_map(fn($m) => $this->makeMachineRow($m), $machineByWs[$wsNo] ?? []),
                'quality_tests'  => array_map(fn($t) => $this->makeQualityRow($t, $specsByWc[$wcId] ?? null), $testsByWs[$wsNo] ?? []),
                'spec'           => isset($specsByWc[$wcId]) ? $this->makeSpecLabels($specsByWc[$wcId]) : null,
            ];

            if ($received > 0) {
                $prevGoodQty = $fgQty;
                $hasPreviousStep = true;
            }
        }

        // ถ้ามี step ถัดไปที่รับของแล้ว แปลว่า step นี้ "ส่งของไปต่อแล้ว" = เสร็จ
        // (กันเคส step กลางขาดเกิน 2% เล็กน้อยจาก scale loss แต่จริงๆ ไหลต่อไปหมดแล้ว)
        // ไล่จากท้ายมาหน้า: ถ้าเจอ step ที่รับของ จะ mark ทุก step ก่อนหน้าที่รับของแล้วเป็น done
        $hasDownstreamReceive = false;
        for ($i = count($stepRows) - 1; $i >= 0; $i--) {
            if ($hasDownstreamReceive && $stepRows[$i]['received'] > 0) {
                $stepRows[$i]['step_status'] = 'done';
            }
            if ($stepRows[$i]['received'] > 0) {
                $hasDownstreamReceive = true;
            }
        }

        // Overall WO status / Totals: ใช้ step สุดท้าย (เรียงตาม workseq) มาตัดสิน
        $lastStep = end($stepRows) ?: null;

        // FG output ของ WO = ยอดดีของ "step สุดท้าย" เท่านั้น (ไม่บวกทุก step ที่นับซ้ำ
        // เช่นแผน 1,000 บวกทุก step ได้ 6,733). NCR = รวม rework ทุก step
        $totalFg  = $lastStep ? (float) $lastStep['fg_qty'] : 0;
        $totalNcr = 0;
        foreach ($stepRows as $row) {
            $totalNcr += $row['ncr_qty'];
        }
        $yield = ($totalFg + $totalNcr) > 0
            ? round(($totalFg / ($totalFg + $totalNcr)) * 100, 2)
            : 0;
        $woStatus = 'pending';
        if ($header->dateclose) {
            $woStatus = 'closed';
        } elseif ($lastStep && $lastStep['step_status'] === 'done') {
            $woStatus = 'done';
        } elseif (array_filter($stepRows, fn($r) => $r['received'] > 0)) {
            $woStatus = 'in_progress';
        }
        $claimNumbers = [];
        $claimQty = 0.0;
        $claimAmountByNumber = [];
        foreach ($customerClaims as $claim) {
            $claimNumber = trim((string) ($claim->returnnumber ?? ''));
            if ($claimNumber !== '') {
                $claimNumbers[$claimNumber] = true;
                $claimAmountByNumber[$claimNumber] = (float) ($claim->amount ?? 0);
            }
            $claimQty += abs((float) ($claim->qty ?? 0));
        }

        return [
            'wo'        => $wo,
            'site'      => str_starts_with($wo, '+') ? 'P' : 'W',
            'header'    => $header,
            'bom'       => $bom,
            'mat_usage' => $matUsage,
            'steps'     => $stepRows,
            'customer_claim' => [
                'sales_order' => $salesOrder,
                'status' => $claimLookupError ? 'ERROR' : ($customerClaims ? 'CLAIM' : ($salesOrder !== '' ? 'NO_CLAIM' : 'NO_SALES_ORDER')),
                'label' => $claimLookupError ? 'ตรวจสอบ Claim ไม่สำเร็จ' : ($customerClaims ? 'พบ Customer Claim' : ($salesOrder !== '' ? 'ยังไม่พบ Claim' : 'ไม่พบ Sales Order')),
                'lookup_error' => $claimLookupError,
                'claim_count' => count($claimNumbers),
                'claim_qty' => $claimQty,
                'claim_amount' => array_sum($claimAmountByNumber),
                'rows' => array_map(fn($claim) => (array) $claim, $customerClaims),
            ],
            'summary'   => [
                'plan_qty'    => $planQty,
                'fg_total'    => $totalFg,
                'ncr_total'   => $totalNcr,
                'received'    => $totalFg + $totalNcr,
                'yield_pct'   => $yield,
                'step_count'  => count($stepRows),
                'wo_status'   => $woStatus,
                'last_step'   => $lastStep ? $lastStep['workseq'] : null,
                'last_variance_pct' => $lastStep['variance_pct'] ?? null,
            ],
        ];
    }

    /**
     * แมป workordernumber => FG kg (ยอดผลิตของดี)
     * $woConn ต้องเป็น connection ฝั่ง MFG (pgsqlmfgw / pgsqlmfgp)
     */
    public function fgKgByWorkorders(array $workordernumbers, string $woConn): array
    {
        $map = [];
        foreach ($this->repo->getFgKgByWorkorders($workordernumbers, $woConn) as $row) {
            // key เป็นตัวพิมพ์ใหญ่เสมอ ให้ caller lookup ด้วย strtoupper()
            $map[strtoupper((string) $row->workordernumber)] = (float) ($row->fg_kg ?? 0);
        }

        return $map;
    }

    /**
     * เครื่องผลิตจริงล่าสุดต่อ WO (ฝั่ง MFG) — คืน map: WO(ตัวพิมพ์ใหญ่) => ['machine_number','machine_desc']
     */
    public function latestMachineByWorkorders(array $workordernumbers, string $woConn, bool $drawingOnly = false): array
    {
        $map = [];
        foreach ($this->repo->getLatestMachineByWorkorders($workordernumbers, $woConn, $drawingOnly) as $row) {
            $map[strtoupper((string) $row->workordernumber)] = [
                'machine_number' => $row->machine_number ?? null,
                'machine_desc'   => $row->machine_desc ?? null,
            ];
        }

        return $map;
    }

    private function makeMachineRow($m): array
    {
        $powders = [];
        $oils    = [];
        $osize   = [];
        for ($i = 1; $i <= 11; $i++) {
            $pKey = 'b' . $i . 'powder';
            $oKey = 'b' . $i . 'oil';
            $sKey = 'osize' . $i;
            $powders[$i] = trim((string) ($m->$pKey ?? '')) ?: null;
            $oils[$i]    = trim((string) ($m->$oKey ?? '')) ?: null;
            $osize[$i]   = ($m->$sKey ?? null) > 0 ? (float) $m->$sKey : null;
        }
        return [
            'transdate'   => $m->transdate,
            'machine_number' => $m->machinenumber ?? null,
            'machine_desc' => $m->machine_desc ?? null,
            'speed'       => $m->speed,
            'resin_perc'  => $m->resinperc,
            'temp'        => $m->temp,
            'heater'      => [$m->heater1, $m->heater2, $m->heater3],
            'powder'      => $powders,
            'oil'         => $oils,
            'osize_actual'=> $osize,
            'docnumber'   => $m->docnumber,
            'approved'    => (bool) $m->approved,
        ];
    }

    private function makeQualityRow($t, $spec): array
    {
        $v  = [];
        $qv = [];
        for ($i = 1; $i <= 20; $i++) {
            $vKey  = 'v' . $i;
            $qvKey = 'qv' . $i;
            $v[$i]  = $t->$vKey  !== null && $t->$vKey  !== 0  ? (float) $t->$vKey  : null;
            $qv[$i] = $t->$qvKey !== null && $t->$qvKey !== 0  ? (float) $t->$qvKey : null;
        }
        $qcApproved = (bool) ($t->approved ?? false)
            || ((int) ($t->qc_id ?? -1) > 0)
            || !empty($t->qctime);

        return [
            'transdate'    => $t->transdate,
            'docnumber'    => $t->docnumber,
            'wipitemnumber'=> $t->wipitemnumber,
            'approved'     => $qcApproved,
            'approved_flag'=> (bool) ($t->approved ?? false),
            'qc_id'        => $t->qc_id ?? null,
            'qc_name'      => $t->qc_name ?? null,
            'qctime'       => $t->qctime ?? null,
            'v'            => $v,
            'qv'           => $qv,
            'notes'        => $t->notes,
        ];
    }

    private function makeSpecLabels($spec): array
    {
        $vLabels  = [];
        $qvLabels = [];
        for ($i = 1; $i <= 10; $i++) {
            $vt   = 'v' . $i . 'text';
            $vo   = 'v' . $i . 'oper';
            $vu   = 'v' . $i . 'upper';
            $vl   = 'v' . $i . 'lower';
            $qvt  = 'qv' . $i . 'text';
            $qvo  = 'qv' . $i . 'oper';
            $qvu  = 'qv' . $i . 'upper';
            $qvl  = 'qv' . $i . 'lower';
            $vText  = trim((string) ($spec->$vt  ?? ''));
            $qvText = trim((string) ($spec->$qvt ?? ''));
            if ($vText !== '') {
                $vLabels[$i] = [
                    'text'  => $vText,
                    'oper'  => $spec->$vo  ?? null,
                    'upper' => $spec->$vu  ?? null,
                    'lower' => $spec->$vl  ?? null,
                ];
            }
            if ($qvText !== '') {
                $qvLabels[$i] = [
                    'text'  => $qvText,
                    'oper'  => $spec->$qvo ?? null,
                    'upper' => $spec->$qvu ?? null,
                    'lower' => $spec->$qvl ?? null,
                ];
            }
        }
        return ['v' => $vLabels, 'qv' => $qvLabels];
    }

    private function makeDieUsageRow($d): array
    {
        return [
            'workordernumber' => $d->workordernumber ?? null,
            'block_no'        => $d->block_no ?? null,
            'source_block_no' => $d->source_block_no ?? null,
            'block_desc'      => $d->block_desc ?? null,
            'transnumber'     => $d->transnumber ?? null,
            'equipnumber'     => $d->equipnumber,
            'die_description' => $d->die_description,
            'equiptype'       => $d->equiptype ?? null,
            'equipcategory'   => $d->equipcategory,
            'size_in'         => $d->size_in,
            'size_out'        => $d->size_out,
            'ordered_size'    => $d->ordered_size ?? $d->size_in,
            'present_diameter'=> $d->present_diameter ?? $d->size_out,
            'reduction_area_angle' => $d->reduction_area_angle ?? null,
            'bearing_length'  => $d->bearing_length ?? null,
            'transdate'       => $d->transdate,
            'qty'             => $d->qty ?? null,
            'meter'           => $d->meter,
            'requester_id'    => $d->requester_id ?? null,
            'requester_name'  => $d->requester_name ?? null,
            'employee_id'     => $d->employee_id ?? null,
            'issued_by_name'  => $d->issued_by_name ?? null,
            'updated_by_id'   => $d->updated_by_id ?? null,
            'updated_by_name' => $d->updated_by_name ?? null,
            'machine_number'  => $d->machine_number ?? null,
            'machine_desc'    => $d->machine_desc ?? null,
            'used_machine'    => $d->used_machine ?? null,
            'status'          => $d->status ?? null,
            'txn_status'      => $d->txn_status ?? null,
            'txn_type'        => $d->txn_type ?? null,
            'from_class'      => $d->from_class ?? null,
            'to_class'        => $d->to_class ?? null,
            'inferred_block'  => (bool) ($d->inferred_block ?? false),
            'round_key'       => $d->round_key ?? null,
            'match_method'    => $d->match_method ?? null,
            'match_size_diff' => $d->match_size_diff ?? null,
        ];
    }

    private function normalizeDieBlocks(array $dies): array
    {
        usort($dies, fn($a, $b) => strcmp((string) ($a->transdate ?? ''), (string) ($b->transdate ?? '')));

        $groups = [];
        foreach ($dies as $idx => $d) {
            $date = substr((string) ($d->transdate ?? ''), 0, 10);
            $prefix = $this->dieRoundPrefix((string) ($d->block_desc ?? ''));
            $key = $date . '|' . $prefix;
            $groups[$key][] = $idx;
            $d->inferred_block = false;
        }

        foreach ($groups as $baseKey => $indexes) {
            $used = [];
            $roundIndexes = [];
            $roundNo = 1;
            $lastExplicitBlock = 0;

            foreach ($indexes as $idx) {
                $blockNo = (int) ($dies[$idx]->block_no ?? 0);
                $startsNewRound = $blockNo > 0
                    && !empty($roundIndexes)
                    && (isset($used[$blockNo]) || ($lastExplicitBlock > 0 && $blockNo < $lastExplicitBlock));

                if ($startsNewRound) {
                    $this->assignRoundBlocks($dies, $roundIndexes, $baseKey, $roundNo);
                    $roundIndexes = [];
                    $used = [];
                    $roundNo++;
                    $lastExplicitBlock = 0;
                }

                $roundIndexes[] = $idx;
                if ($blockNo > 0) {
                    $used[$blockNo] = true;
                    $lastExplicitBlock = $blockNo;
                }
            }

            if (!empty($roundIndexes)) {
                $this->assignRoundBlocks($dies, $roundIndexes, $baseKey, $roundNo);
            }
        }

        return $dies;
    }

    private function diesByBlockForStep(
        array $dies,
        array $plannedBlocks,
        array $receives,
        array $machines,
        array $tests,
        array &$usedDieKeys = [],
        int $stepWorkseq = 0,
    ): array
    {
        if (empty($plannedBlocks)) {
            return [];
        }

        $anchorStamp = $this->stepAnchorStamp($receives, $machines, $tests);
        $roundCoverage = $this->roundCoverageForBlocks($dies, $plannedBlocks);
        $byBlock = [];

        foreach ($plannedBlocks as $plannedBlock) {
            $blockNo = (int) ($plannedBlock['block_no'] ?? 0);
            if ($blockNo <= 0) {
                continue;
            }

            $candidates = [];
            foreach ($dies as $d) {
                $key = $this->dieUsageKey($d);
                // ไดร์ที่ถูก assign ไป step/block อื่นแล้ว (รวมข้าม step) ข้ามไป
                if (isset($usedDieKeys[$key])) {
                    continue;
                }

                $score = $this->scoreDieForBlock($d, $plannedBlock, $anchorStamp, $roundCoverage, $stepWorkseq);
                if ($score === null) {
                    continue;
                }

                // เก็บคีย์ต้นฉบับไว้ mark used (block_no ของ clone จะถูกเขียนทับภายหลัง)
                $candidates[] = ['score' => $score, 'die' => $d, 'key' => $key];
            }

            usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
            if (empty($candidates)) {
                continue;
            }

            $matched = clone $candidates[0]['die'];
            $matched->source_block_no = $matched->block_no ?? null;
            $matched->block_no = $blockNo;
            $matched->match_method = 'size_block_score';
            $matched->match_size_diff = $this->dieSizeDiff($matched, $plannedBlock['size_plan'] ?? null);
            $this->attachStepMachine($matched, $receives, $machines);
            $usedDieKeys[$candidates[0]['key']] = true;
            $byBlock[$blockNo] = [$matched];
        }

        return $byBlock;
    }

    private function scoreDieForBlock($die, array $plannedBlock, ?string $anchorStamp, array $roundCoverage, int $stepWorkseq = 0): ?float
    {
        // ตัวดักอนาคต: ถ้า production เติม hint "step N" ใน block_desc (เช่น "b1, กะเช้า, step 6")
        // ให้เชื่อ step ที่ระบุเป็นหลัก — ตรง step = บังคับเลือก, ไม่ตรง = ตัดทิ้ง; ถ้าไม่มี hint ใช้ logic ขนาดเดิม
        $stepHint = $this->dieStepHint($die);
        if ($stepHint !== null && $stepWorkseq > 0) {
            if ($stepHint !== $stepWorkseq) {
                return null;
            }
            // ตรง step ที่ระบุ → คะแนนดีสุด (ชนะ candidate อื่นที่ match ด้วยขนาดเฉย ๆ)
            return -1000000.0;
        }

        $blockNo = (int) ($plannedBlock['block_no'] ?? 0);
        $dieBlockNo = (int) ($die->block_no ?? 0);
        $sizePlan = $plannedBlock['size_plan'] ?? null;
        $sizeDiff = $this->dieSizeDiff($die, $sizePlan);
        $blockDelta = $dieBlockNo > 0 ? abs($dieBlockNo - $blockNo) : 8;

        if ($sizeDiff === null) {
            if ($blockDelta !== 0) {
                return null;
            }

            $sizeDiff = 0.0;
        } else {
            $tolerance = $blockDelta === 0
                ? max(0.08, ((float) $sizePlan) * 0.025)
                : max(0.03, ((float) $sizePlan) * 0.015);
            if ($sizeDiff > $tolerance) {
                return null;
            }
        }

        if ($blockDelta > 1) {
            return null;
        }

        $roundKey = (string) ($die->round_key ?? '');
        $coverage = $roundCoverage[$roundKey] ?? 0;
        $dieStamp = $this->normalizeTimestamp($die->transdate ?? null);
        $datePenalty = $this->datePenalty($dieStamp, $anchorStamp);

        return ($blockDelta * 100)
            + ($sizeDiff * 1000)
            + $datePenalty
            - ($coverage * 50)
            - ($blockDelta === 0 ? 20 : 0);
    }

    private function attachStepMachine(object $die, array $receives, array $machines): void
    {
        $machine = $this->firstMachine($machines) ?: $this->firstMachine($receives);
        if (!$machine) {
            return;
        }

        $die->machine_number = $machine['number'];
        $die->machine_desc = $machine['description'];
        $die->used_machine = trim($machine['number'] . ($machine['description'] ? ' - ' . $machine['description'] : ''));
    }

    private function firstMachine(array $rows): ?array
    {
        foreach ($rows as $row) {
            $number = trim((string) ($row->machinenumber ?? ''));
            $description = trim((string) ($row->machine_desc ?? ''));
            if ($number !== '' || $description !== '') {
                return ['number' => $number, 'description' => $description];
            }
        }

        return null;
    }

    private function stationMachines(array ...$groups): array
    {
        $machines = [];
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                $number = trim((string) ($row->machinenumber ?? ''));
                $description = trim((string) ($row->machine_desc ?? ''));
                if ($number === '' && $description === '') {
                    continue;
                }

                $key = mb_strtolower($number . '|' . $description);
                $machines[$key] = [
                    'number' => $number ?: null,
                    'description' => $description ?: null,
                    'label' => trim($number . ($description !== '' ? ' - ' . $description : '')),
                ];
            }
        }

        return array_values($machines);
    }

    private function roundCoverageForBlocks(array $dies, array $plannedBlocks): array
    {
        $coverage = [];

        foreach ($dies as $d) {
            $roundKey = (string) ($d->round_key ?? '');
            if ($roundKey === '') {
                continue;
            }

            foreach ($plannedBlocks as $plannedBlock) {
                if ($this->scoreDieForBlock($d, $plannedBlock, null, []) !== null) {
                    $coverage[$roundKey] = ($coverage[$roundKey] ?? 0) + 1;
                    break;
                }
            }
        }

        return $coverage;
    }

    private function dieSizeDiff($die, $sizePlan): ?float
    {
        if ($sizePlan === null || $sizePlan === '' || !is_numeric($sizePlan)) {
            return null;
        }

        $sizePlan = (float) $sizePlan;
        $values = [
            $die->ordered_size ?? null,
            $die->size_in ?? null,
            $die->present_diameter ?? null,
            $die->size_out ?? null,
        ];
        $diffs = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $diffs[] = abs((float) $value - $sizePlan);
            }
        }

        return $diffs ? min($diffs) : null;
    }

    private function datePenalty(?string $dieStamp, ?string $anchorStamp): float
    {
        if (!$dieStamp || !$anchorStamp) {
            return 0.0;
        }

        $dieDay = strtotime(substr($dieStamp, 0, 10));
        $anchorDay = strtotime(substr($anchorStamp, 0, 10));
        if (!$dieDay || !$anchorDay) {
            return 0.0;
        }

        $days = abs($dieDay - $anchorDay) / 86400;
        return min(30.0, $days * 2.0);
    }

    private function dieUsageKey($die): string
    {
        return implode('|', [
            (string) ($die->transnumber ?? ''),
            (string) ($die->equipnumber ?? ''),
            (string) ($die->block_no ?? ''),
            (string) ($die->transdate ?? ''),
        ]);
    }

    /**
     * ดึง "step hint" ที่ production เติมต่อท้าย block_desc เช่น "b1, กะเช้า, step 6" → 6
     * ใช้เพื่อระบุว่าไดร์ตัวนี้เป็นของ workseq ไหนแน่นอน (กรณีงานมีรีดหลาย step)
     * คืน null ถ้ายังไม่มี hint (ใช้ logic ขนาดเดิม)
     */
    private function dieStepHint($die): ?int
    {
        $desc = (string) ($die->block_desc ?? '');
        if ($desc === '') {
            return null;
        }
        // จับ "step 6" / "step6" / "STEP 6" (มี/ไม่มีช่องว่าง, ไม่สนตัวพิมพ์)
        if (preg_match('/step[[:space:]]*([0-9]{1,2})/i', $desc, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function plannedBlocksForStep($step): array
    {
        $blocks = [];
        for ($i = 1; $i <= 12; $i++) {
            $sizeKey = 'block' . $i . 'size';
            $matKey = 'block' . $i;
            $size = (float) ($step->$sizeKey ?? 0);
            $mat = trim((string) ($step->$matKey ?? ''));
            if ($size > 0 || $mat !== '') {
                $blocks[] = [
                    'block_no' => $i,
                    'size_plan' => $size > 0 ? $size : null,
                    'material' => $mat ?: null,
                ];
            }
        }

        return $blocks;
    }

    private function receiveWindow(array $receives): array
    {
        $stamps = [];
        foreach ($receives as $receive) {
            $stamp = $this->normalizeTimestamp($receive->receivestamp ?? null);
            if ($stamp !== null) {
                $stamps[] = $stamp;
            }
        }

        sort($stamps);

        return [
            'start' => $stamps[0] ?? null,
            'end'   => $stamps ? $stamps[count($stamps) - 1] : null,
        ];
    }

    private function assignRoundBlocks(array &$dies, array $indexes, string $baseKey, int $roundNo): void
    {
        $used = [];
        foreach ($indexes as $idx) {
            $dies[$idx]->round_key = $baseKey . '#' . $roundNo;
            $blockNo = (int) ($dies[$idx]->block_no ?? 0);
            if ($blockNo > 0) {
                $used[$blockNo] = true;
            }
        }

        $nextBlock = 1;
        foreach ($indexes as $idx) {
            $blockNo = (int) ($dies[$idx]->block_no ?? 0);
            if ($blockNo > 0) {
                continue;
            }
            while (isset($used[$nextBlock]) && $nextBlock <= 12) {
                $nextBlock++;
            }
            if ($nextBlock <= 12) {
                $dies[$idx]->block_no = $nextBlock;
                $dies[$idx]->inferred_block = true;
                $used[$nextBlock] = true;
                $nextBlock++;
            }
        }
    }

    private function stepAnchorStamp(array $receives, array $machines, array $tests): ?string
    {
        $dates = [];
        foreach ($machines as $m) {
            if (!empty($m->transdate)) $dates[] = $this->normalizeTimestamp($m->transdate, true);
        }
        foreach ($tests as $t) {
            if (!empty($t->transdate)) $dates[] = $this->normalizeTimestamp($t->transdate, true);
        }
        foreach ($receives as $r) {
            if (!empty($r->receivestamp)) $dates[] = $this->normalizeTimestamp($r->receivestamp, true);
        }
        $dates = array_values(array_filter($dates));
        sort($dates);

        return $dates[0] ?? null;
    }

    private function normalizeTimestamp($value, bool $dateOnlyEndOfDay = false): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        $text = str_replace('T', ' ', $text);
        if (strlen($text) <= 10) {
            return substr($text, 0, 10) . ($dateOnlyEndOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return substr($text, 0, 19);
    }

    private function dieRoundPrefix(string $description): string
    {
        $prefix = preg_replace('/(?<![A-Za-z0-9])(?:block|b)\s*[0-9]{1,2}(?=[^\d]|$)/iu', ' ', $description);
        $prefix = preg_replace('/[\s,.;:]+/u', ' ', (string) $prefix);

        return mb_strtolower(trim((string) $prefix));
    }
}
