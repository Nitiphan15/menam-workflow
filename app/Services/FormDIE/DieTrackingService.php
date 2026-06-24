<?php

namespace App\Services\FormDIE;

use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use App\Repositories\FormDIE\WorkorderBlockRepository;
use Carbon\Carbon;

class DieTrackingService
{
    public function __construct(
        private WorkorderBlockRepository $workorderRepo,
        private DieUsageEquipmentRepository $dieRepo,
        private WoDetailService $woDetailService,
    ) {}

    public function byWorkorder(string $wo, ?string $category = null, ?string $description = null): array
    {
        $wo     = mb_strtoupper(trim($wo));
        $blocks = $this->workorderRepo->getBlocksByWorkorder($wo);
        $dies   = $this->dieRepo->getByWorkorder($wo, $category, $description);
        foreach ($dies as $d) {
            $d->site = str_starts_with($wo, '+') ? 'P' : 'W';
        }
        $this->attachAnalyticsKg($dies);

        $detailMapped = $this->rowsFromWoDetail($wo);
        if ($detailMapped) {
            $rows = $detailMapped['rows'];
            $completeness = $detailMapped['completeness'];
        } else {
            $mapped = $this->rowsFromBlockPlan($blocks, $dies);
            $rows = $mapped['rows'];
            $completeness = $mapped['completeness'];
        }
        $woKg = (float) ($dies[0]->wo_fg_kg ?? 0);
        foreach ($rows as &$row) {
            $row['wo_fg_kg'] = $woKg;
        }
        unset($row);

        if ($category) {
            $rows = $this->filterRowsByCategory($rows, $category);
        }
        if ($description) {
            $rows = $this->filterRowsByDescription($rows, $description);
        }

        return [
            'mode'         => 'workorder',
            'wo'           => $wo,
            'rows'         => $rows,
            'summary'      => $this->summary($dies),
            'charts'       => $this->buildCharts($dies, 'workorder'),
            'completeness' => $completeness,
            'exceptions'   => $this->buildExceptions($rows, $completeness),
            'readiness'    => $this->buildReadiness($rows, $completeness),
        ];
    }

    public function dieMaster($connection, array $filters = []): array
    {
        $rows = [];
        foreach ($this->normConns($connection) as $conn) {
            $site = $this->siteOf($conn);
            $part = $this->dieRepo->getDieMaster($conn, $filters);
            foreach ($part as $r) { $r->site = $site; }
            $part = $this->attachDieKg($part, $conn);
            $part = $this->attachCurrentLocationAndMachine($part, $conn);
            $rows = array_merge($rows, $part);
        }
        return ['rows' => $rows];
    }

    /**
     * "ไดร์ไหนอยู่บนเครื่องไหน ณ ปัจจุบัน" — จัดกลุ่มไดร์ที่กำลังเบิกใช้ตามเครื่องผลิต
     * @param array $sites ['W'] / ['P'] / ['W','P']
     */
    public function currentMachineDies(array $sites, array $filters = []): array
    {
        $groups = []; // key = site|machine
        $total  = 0;

        foreach ($sites as $site) {
            $site   = strtoupper($site) === 'P' ? 'P' : 'W';
            $conn   = $site === 'P' ? 'pgsqlpcmp' : 'pgsqlpcmw';
            $woConn = $site === 'P' ? 'pgsqlmfgp' : 'pgsqlmfgw';

            $dies = $this->dieRepo->getDeployedDies($conn, $filters);
            if (!$dies) continue;

            $wos = array_values(array_unique(array_filter(array_map(
                fn($d) => strtoupper(trim((string) ($d->workordernumber ?? ''))),
                $dies
            ))));
            // หยิบเฉพาะเครื่องของ step รีด (DRAWING) → เครื่องที่ไม่ใช่รีดจะไม่โผล่ ไม่ต้อง filter ชื่อเครื่องซ้ำ
            $machineByWo = $this->woDetailService->latestMachineByWorkorders($wos, $woConn, true);

            foreach ($dies as $d) {
                $total++;
                $wo = strtoupper(trim((string) ($d->workordernumber ?? '')));
                $m  = $wo ? ($machineByWo[$wo] ?? null) : null;
                $machineNo = $m['machine_number'] ?? null;

                $key = $site . '|' . ($machineNo ?? '__none__');
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'site'           => $site,
                        'machine_number' => $machineNo,
                        'machine_desc'   => $m['machine_desc'] ?? null,
                        'dies'           => [],
                    ];
                }
                $groups[$key]['dies'][] = [
                    'equipnumber'     => $d->equipnumber,
                    'die_description' => $d->die_description,
                    'equipcategory'   => $d->equipcategory,
                    'current_dept'    => $d->current_dept,
                    'workordernumber' => $d->workordernumber,
                    'last_move_date'  => $d->last_move_date,
                ];
            }
        }

        $groupList = array_values($groups);
        foreach ($groupList as &$g) { $g['count'] = count($g['dies']); }
        unset($g);

        // เครื่องที่ระบุได้มาก่อน เรียงจำนวนไดร์มาก→น้อย; กลุ่ม "ไม่ทราบเครื่อง" ไว้ท้ายสุด
        usort($groupList, function ($a, $b) {
            $an = $a['machine_number'] === null ? 1 : 0;
            $bn = $b['machine_number'] === null ? 1 : 0;
            if ($an !== $bn) return $an - $bn;
            return $b['count'] <=> $a['count'];
        });

        return [
            'total_dies'    => $total,
            'machine_count' => count(array_filter($groupList, fn($g) => $g['machine_number'] !== null)),
            'groups'        => $groupList,
        ];
    }

    /**
     * เติมข้อมูล "ณ ตอนนี้" ให้แต่ละ die ในตาราง master:
     *  (A) current_class / last_move_date = ตำแหน่ง/แผนกปัจจุบัน (ฐาน PCM)
     *  (B) current_machine / current_machine_desc = เครื่องผลิตจริงจาก WO ผลิตล่าสุด (ฐาน MFG)
     */
    private function attachCurrentLocationAndMachine(array $rows, string $connection): array
    {
        if (empty($rows)) return $rows;

        $equipnumbers = array_values(array_filter(array_map(fn($r) => $r->equipnumber ?? null, $rows)));
        if (empty($equipnumbers)) return $rows;

        // (A) ตำแหน่ง/แผนกปัจจุบัน
        $locByDie = [];
        foreach ($this->dieRepo->getCurrentClassByDies($equipnumbers, $connection) as $l) {
            $locByDie[$l->equipnumber] = $l;
        }

        // (B) WO ผลิตล่าสุดต่อ die → หา machine จากฐาน MFG
        $lastWoByDie = [];
        foreach ($this->dieRepo->getDieLastProdWo($equipnumbers, $connection) as $w) {
            $lastWoByDie[$w->equipnumber] = (string) $w->workordernumber;
        }
        $woConn = $connection === 'pgsqlpcmp' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $machineByWo = $this->woDetailService->latestMachineByWorkorders(
            array_values(array_unique($lastWoByDie)),
            $woConn
        );

        foreach ($rows as $r) {
            $en  = $r->equipnumber ?? '';
            $loc = $locByDie[$en] ?? null;
            $r->current_class  = $loc->current_class ?? null;
            $r->last_move_date = $loc->last_move_date ?? null;

            // ไดร์ "ยังเบิกอยู่บนเครื่อง" ก็ต่อเมื่อ transfer ล่าสุดส่งไปแผนกจริง (to_class_id > 0)
            // ถ้าคืนเข้า Repair (0) หรือกลับ Store (-1) แล้ว = ไม่ได้อยู่บนเครื่อง → ไม่โชว์เครื่อง
            $toClass   = $loc->to_class_id ?? null;
            $isOnMachine = $toClass !== null && (int) $toClass > 0;

            $lastWo = $lastWoByDie[$en] ?? null;
            $m = ($isOnMachine && $lastWo) ? ($machineByWo[strtoupper($lastWo)] ?? null) : null;
            $r->last_prod_wo         = $isOnMachine ? $lastWo : null;
            $r->current_machine      = $m['machine_number'] ?? null;
            $r->current_machine_desc = $m['machine_desc'] ?? null;
        }

        return $rows;
    }

    /**
     * เติม total_kg (FG kg ที่ die ตัวนั้นเคยช่วยผลิต) ให้แต่ละแถวใน master list
     */
    private function attachDieKg(array $rows, string $connection): array
    {
        if (empty($rows)) return $rows;

        $woConn = $connection === 'pgsqlpcmp' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $equipnumbers = array_values(array_filter(array_map(fn($r) => $r->equipnumber ?? null, $rows)));
        $pairs = $this->dieRepo->getDieWorkorders($equipnumbers, $connection);

        $wos = array_values(array_unique(array_map(fn($p) => $p->workordernumber, $pairs)));
        $kgByWo = $this->woDetailService->fgKgByWorkorders($wos, $woConn); // key เป็นตัวพิมพ์ใหญ่

        $kgByDie = [];
        foreach ($pairs as $p) {
            $kg = $kgByWo[strtoupper((string) $p->workordernumber)] ?? 0;
            $kgByDie[$p->equipnumber] = ($kgByDie[$p->equipnumber] ?? 0) + $kg;
        }

        foreach ($rows as $r) {
            $r->total_kg = $kgByDie[$r->equipnumber ?? ''] ?? 0;
        }

        return $rows;
    }

    /**
     * เติม wo_fg_kg (FG kg ของ WO นั้น) ให้แต่ละแถว — ใช้ตอน export
     */
    public function attachWoKg(array $rows, string $connection): array
    {
        if (empty($rows)) return $rows;

        // จัดกลุ่ม WO ตาม site ของแต่ละแถว (รองรับ multi-site); แถวที่ไม่มี site ใช้ตาม connection ที่ส่งมา
        $defaultSite = $connection === 'pgsqlpcmp' ? 'P' : 'W';
        $wosBySite = ['W' => [], 'P' => []];
        foreach ($rows as $r) {
            $wo = $r['workordernumber'] ?? null;
            if (!$wo) continue;
            $site = (($r['site'] ?? $defaultSite) === 'P') ? 'P' : 'W';
            $wosBySite[$site][] = $wo;
        }

        $kgByWo = [];
        foreach (['W' => 'pgsqlmfgw', 'P' => 'pgsqlmfgp'] as $site => $woConn) {
            $wos = array_values(array_unique(array_filter($wosBySite[$site])));
            if (empty($wos)) continue;
            foreach ($this->woDetailService->fgKgByWorkorders($wos, $woConn) as $k => $v) {
                $kgByWo[$site . '|' . $k] = $v;
            }
        }

        foreach ($rows as &$row) {
            $wo = $row['workordernumber'] ?? null;
            $site = (($row['site'] ?? $defaultSite) === 'P') ? 'P' : 'W';
            $row['wo_fg_kg'] = $wo ? ($kgByWo[$site . '|' . strtoupper((string) $wo)] ?? null) : null;
        }
        unset($row);

        return $rows;
    }

    public function categories(string $connection, string $equiptype = 'ไดร์'): array
    {
        return ['rows' => $this->dieRepo->getCategories($connection, $equiptype)];
    }

    public function suppliers(string $connection, string $equiptype = 'ไดร์'): array
    {
        return ['rows' => $this->dieRepo->getSuppliers($connection, $equiptype)];
    }

    public function equipTypes(string $connection): array
    {
        return ['rows' => $this->dieRepo->getEquipTypes($connection)];
    }

    public function statuses(string $connection, string $equiptype = 'ไดร์'): array
    {
        return ['rows' => $this->dieRepo->getStatuses($connection, $equiptype)];
    }

    public function dieProfile(string $equipnumber, string $connection): array
    {
        $info    = $this->dieRepo->getDieInfo($equipnumber, $connection);
        $history = $this->dieRepo->getDieProfile($equipnumber, $connection);

        $totalMeter   = 0;
        $totalTrans   = count($history);
        $uniqueWO     = [];
        $uniqueDept   = [];
        $currentClass = null;
        $lastMoveDate = null;
        foreach ($history as $h) {
            // meter สะสมของไดร์ = ผลรวมเฉพาะรอบ "ใช้ผลิตจริง" (incremental) — แถวเบิก/ซ่อมเป็นเลข odometer ไม่นับซ้ำ
            if (!empty($h->is_production)) {
                $totalMeter += (float) ($h->meter ?? 0);
            }
            // นับ WO ผลิตจริง (prod_wo = WO จากแถวเบิก) ไม่ใช่ f3 บนแถวคืน (RA14-16)
            if (!empty($h->prod_wo)) $uniqueWO[$h->prod_wo] = true;
            // แผนกที่เบิกไดร์ไปใช้ = to_class ของแถวเบิก
            if (!empty($h->to_class)) $uniqueDept[$h->to_class] = true;
            // ตำแหน่งล่าสุด = แถวแรก (history เรียง transdate DESC) — แปลงแถวซ่อม/คืน floor ให้สื่อความหมาย
            if ($lastMoveDate === null) {
                $currentClass = $this->currentClassLabel($h);
                $lastMoveDate = $h->transdate ?? null;
            }
        }

        // kg ที่ die ตัวนี้เคยช่วยผลิต = sum ของ FG kg ต่อ workorder (นับ 1 ครั้งต่อ WO)
        $woConn  = $connection === 'pgsqlpcmp' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $kgByWo  = $this->woDetailService->fgKgByWorkorders(array_keys($uniqueWO), $woConn);
        $totalKg = array_sum($kgByWo);

        $health = $this->buildKgUsage($info, $totalKg, count($uniqueWO));
        $maintenance = $this->buildMaintenanceKg($info, $totalKg);

        return [
            'info'    => $info,
            'history' => $history,
            'summary' => [
                'total_meter'   => $totalMeter,
                'total_kg'      => $totalKg,
                'total_trans'   => $totalTrans,
                'unique_wo'     => count($uniqueWO),
                'unique_dept'   => count($uniqueDept),
                'current_class' => $currentClass,
                'last_move'     => $lastMoveDate,
                'health'        => $health,
                'maintenance'   => $maintenance,
                'timeline'      => $this->buildProfileTimeline($history),
                'lifecycle_kg'  => $this->buildKgLifecycle($history, $kgByWo),
            ],
        ];
    }

    public function currentLocations($connection, array $filters = []): array
    {
        $rows = [];
        foreach ($this->normConns($connection) as $conn) {
            $site = $this->siteOf($conn);
            foreach ($this->dieRepo->getCurrentLocations($conn, $filters) as $r) {
                $r->site = $site;
                $rows[] = $r;
            }
        }
        return ['rows' => $rows];
    }

    public function topConsumers(string $start, string $end, string $connection, ?string $category = null): array
    {
        $rows = $this->dieRepo->getTopConsumers($start, $end, $connection, $category);

        // เติม total_kg (FG kg สะสมที่ผลิตจาก WO ของแผนกนั้น) — kg อยู่คนละ DB (workorder) ต้อง look up แล้วรวมใน PHP
        $woConn = $connection === 'pgsqlpcmp' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $allWos = [];
        foreach ($rows as $r) {
            // wos ถูกคั่นด้วย chr(31) (\x1f) จาก string_agg — แยกแบบปลอดภัย (เลข WO มี comma/freetext ปนได้)
            $r->wo_list = empty($r->wos)
                ? []
                : array_values(array_unique(array_filter(array_map('trim', explode("\x1f", $r->wos)))));
            foreach ($r->wo_list as $w) {
                $allWos[strtoupper($w)] = $w;
            }
        }

        $kgByWo = $this->woDetailService->fgKgByWorkorders(array_values($allWos), $woConn); // key เป็นตัวพิมพ์ใหญ่

        foreach ($rows as $r) {
            $kg = 0.0;
            foreach ($r->wo_list as $w) {
                $kg += (float) ($kgByWo[strtoupper($w)] ?? 0); // นับ WO ละ 1 ครั้งต่อแผนก
            }
            $r->total_kg = $kg;
            unset($r->wos, $r->wo_list);
        }

        return ['rows' => $rows];
    }

    /**
     * Widget "ไดร์ Output สูงสุด" — top 10 ไดร์ตาม FG กก. สะสมในช่วงที่เลือก
     * (แทน widget "Die นิ่ง" เดิมที่เต็มไปด้วยไดร์ที่ไม่เคยเบิก)
     */
    public function topOutputDies(string $start, string $end, string $connection, ?string $category = null): array
    {
        $rows = $this->dieRepo->getTopOutputDies($start, $end, $connection, $category);

        $woConn = $connection === 'pgsqlpcmp' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $allWos = [];
        foreach ($rows as $r) {
            $r->wo_list = empty($r->wos)
                ? []
                : array_values(array_unique(array_filter(array_map('trim', explode("\x1f", $r->wos)))));
            foreach ($r->wo_list as $w) {
                $allWos[strtoupper($w)] = $w;
            }
        }

        $kgByWo = $this->woDetailService->fgKgByWorkorders(array_values($allWos), $woConn);

        foreach ($rows as $r) {
            $kg = 0.0;
            foreach ($r->wo_list as $w) {
                $kg += (float) ($kgByWo[strtoupper($w)] ?? 0); // FG kg ต่อ WO (นับ WO ละครั้ง)
            }
            $r->total_kg = $kg;
            unset($r->wos, $r->wo_list);
        }

        // re-sort ตาม kg แล้วเอา top 10 (SQL เรียงด้วย meter เพราะ kg อยู่คนละ DB)
        usort($rows, fn($a, $b) => ($b->total_kg <=> $a->total_kg));

        return ['rows' => array_slice($rows, 0, 10)];
    }

    public function idleDies(int $days, string $connection, ?string $category = null): array
    {
        return ['rows' => $this->dieRepo->getIdleDies($days, $connection, $category)];
    }

    public function materialTrace(string $site, ?string $heatno, ?string $coilno, ?string $category = null): array
    {
        $site    = $site === 'P' ? 'P' : 'W';
        $woConn  = $site === 'P' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $dieConn = $site === 'P' ? 'pgsqlpcmp' : 'pgsqlpcmw';

        $receives = $this->workorderRepo->findByMaterial($woConn, $heatno, $coilno);

        $woNumbers = array_unique(array_map(fn($r) => $r->workordernumber, $receives));
        $diesByWo = [];
        foreach ($woNumbers as $woNum) {
            $dies = $this->dieRepo->getByWorkorder($woNum, $category);
            foreach ($dies as $d) {
                $key = $d->workordernumber;
                if (!isset($diesByWo[$key])) $diesByWo[$key] = [];
                $diesByWo[$key][] = $d;
            }
        }

        $rows = [];
        foreach ($receives as $r) {
            $rows[] = [
                'workordernumber'  => $r->workordernumber,
                'ordnumber'        => $r->ordnumber,
                'brand'            => $r->brand,
                'fsize'            => $r->fsize,
                'flen'             => $r->flen,
                'fcat'             => $r->fcat,
                'workseq'          => $r->workseq,
                'workcenter'       => $r->workcenternumber,
                'workcenter_desc'  => $r->workcenter_desc,
                'machine_number'   => $r->machinenumber ?? null,
                'machine_desc'     => $r->machine_desc ?? null,
                'used_machine'     => trim((string) ($r->machinenumber ?? '') . (($r->machine_desc ?? null) ? ' - ' . $r->machine_desc : '')),
                'heatno'           => $r->heatno,
                'coilno'           => $r->coilno,
                'receive_qty'      => $r->receive_qty,
                'receivestamp'     => $r->receivestamp,
                'receiveby'        => $r->receiveby,
                'wipitemnumber'    => $r->wipitemnumber,
                'warehousenumber'  => $r->warehousenumber,
                'dies_used'        => array_map(fn($d) => [
                    'equipnumber'     => $d->equipnumber,
                    'die_description' => $d->die_description,
                    'equipcategory'   => $d->equipcategory,
                    'block_no'        => $d->block_no,
                    'block_desc'      => $d->block_desc,
                    'size_in'         => $d->size_in,
                    'size_out'        => $d->size_out,
                    'ordered_size'    => $d->ordered_size ?? $d->size_in,
                    'present_diameter'=> $d->present_diameter ?? $d->size_out,
                    'reduction_area_angle' => $d->reduction_area_angle ?? null,
                    'bearing_length'  => $d->bearing_length ?? null,
                    'meter'           => $d->meter,
                    'transdate'       => $d->transdate,
                    'issued_by_name'  => $d->issued_by_name ?? null,
                    'requester_name'  => $d->requester_name ?? null,
                ], $diesByWo[$r->workordernumber] ?? []),
            ];
        }

        return [
            'site'   => $site,
            'heatno' => $heatno,
            'coilno' => $coilno,
            'rows'   => $rows,
        ];
    }

    /**
     * แปลง connection ที่อาจเป็น string เดียว หรือ array (multi-site W+P) → array ของ connection
     */
    private function normConns($connection): array
    {
        if (is_array($connection)) {
            $c = array_values(array_filter($connection));
            return empty($c) ? ['pgsqlpcmw'] : $c;
        }
        return [(string) $connection];
    }

    private function siteOf(string $connection): string
    {
        return $connection === 'pgsqlpcmp' ? 'P' : 'W';
    }

    public function byDate(string $dateFrom, ?string $dateTo = null, $connection = 'pgsqlpcmw', ?string $category = null, ?string $description = null): array
    {
        $dateTo = $dateTo ?: $dateFrom;
        // วันเดียว = ใช้ getByDate ปกติ, ช่วง = getByDateRange; รองรับ multi-site โดย query ทุก connection แล้ว merge
        $dies = [];
        foreach ($this->normConns($connection) as $conn) {
            $site = $this->siteOf($conn);
            $part = $dateFrom === $dateTo
                ? $this->dieRepo->getByDate($dateFrom, $conn, $category, $description)
                : $this->dieRepo->getByDateRange($dateFrom, $dateTo, $conn, $category, $description);
            foreach ($part as $d) { $d->site = $site; $dies[] = $d; }
        }
        $dies = $this->drawingOnly($dies);
        $this->attachAnalyticsKg($dies);
        $rows = array_map(fn($d) => $this->makeRow(null, $d), $dies);
        $heatmap = $this->buildHeatmap($dies);
        $this->attachDrawingBlocksToRows($rows, $heatmap);
        // ใช้ heatmap ที่ตรงกับ block_no ของตาราง (รวม block fallback จาก PCM เช่น B5/B6)
        $heatmap = $this->rebuildHeatmapFromRows($rows);

        return [
            'mode'      => 'date',
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'rows'      => $rows,
            'summary'   => $this->summary($dies),
            'charts'    => $this->buildCharts($dies, 'date'),
            'heatmap'   => $heatmap,
            'exceptions'=> $this->buildExceptions($rows),
        ];
    }

    public function byWeek(int $year, int $week, $connection = 'pgsqlpcmw', ?string $category = null, ?string $description = null): array
    {
        $start = Carbon::now()->setISODate($year, $week)->startOfWeek();
        $end   = $start->copy()->endOfWeek();
        $dies  = [];
        foreach ($this->normConns($connection) as $conn) {
            $site = $this->siteOf($conn);
            foreach ($this->dieRepo->getByDateRange($start->toDateString(), $end->toDateString(), $conn, $category, $description) as $d) {
                $d->site = $site;
                $dies[] = $d;
            }
        }
        $dies = $this->drawingOnly($dies);
        $this->attachAnalyticsKg($dies);
        $rows = array_map(fn($d) => $this->makeRow(null, $d), $dies);
        $heatmap = $this->buildHeatmap($dies);
        $this->attachDrawingBlocksToRows($rows, $heatmap);
        // ใช้ heatmap ที่ตรงกับ block_no ของตาราง (รวม block fallback จาก PCM เช่น B5/B6)
        $heatmap = $this->rebuildHeatmapFromRows($rows);

        return [
            'mode'    => 'week',
            'year'    => $year,
            'week'    => $week,
            'start'   => $start->toDateString(),
            'end'     => $end->toDateString(),
            'rows'    => $rows,
            'summary' => $this->summary($dies),
            'charts'  => $this->buildCharts($dies, 'week'),
            'heatmap' => $heatmap,
            'exceptions'=> $this->buildExceptions($rows),
        ];
    }

    public function compareDateRanges(string $start, string $end, $connection = 'pgsqlpcmw', ?string $category = null): array
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();
        if ($startDate->gt($endDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $days = $startDate->diffInDays($endDate) + 1;
        $prevEnd = $startDate->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        $current = $this->byDate($startDate->toDateString(), $endDate->toDateString(), $connection, $category);
        $previous = $this->byDate($prevStart->toDateString(), $prevEnd->toDateString(), $connection, $category);

        return [
            'current' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'summary' => $current['summary'],
            ],
            'previous' => [
                'start' => $prevStart->toDateString(),
                'end' => $prevEnd->toDateString(),
                'summary' => $previous['summary'],
            ],
            'delta' => $this->buildSummaryDelta($current['summary'], $previous['summary']),
        ];
    }

    private function makeRow($block, $die): array
    {
        return [
            'site'            => $die->site ?? null,
            'workordernumber' => $die->workordernumber ?? ($block->workordernumber ?? null),
            'workseq'         => $block->workseq        ?? null,
            'workcenter'      => $block->workcenternumber ?? null,
            'workcenter_desc' => $block->workcenter_desc  ?? null,
            'block_no'        => $die->block_no ?? ($block->block_no ?? null),
            'source_block_no' => $die->source_block_no ?? null,
            'block_name'      => $block->block_name ?? null,
            'block_size'      => $block->block_size ?? null,
            'transnumber'     => $die->transnumber ?? null,
            'transdate'       => $die->transdate   ?? null,
            'block_desc'      => $die->block_desc  ?? null,
            'qty'             => $die->qty   ?? null,
            'meter'           => $die->meter ?? null,
            'wo_fg_kg'        => $die->wo_fg_kg ?? null,
            'txn_type'        => $die->txn_type ?? null,
            'txn_status'      => $die->txn_status ?? null,
            'is_production'   => isset($die->is_production) ? (int) $die->is_production : null,
            'requester_id'    => $die->requester_id ?? null,
            'requester_name'  => $die->requester_name ?? null,
            'employee_id'     => $die->employee_id ?? null,
            'issued_by_name'  => $die->issued_by_name ?? null,
            'updated_by_id'   => $die->updated_by_id ?? null,
            'updated_by_name' => $die->updated_by_name ?? null,
            'machine_number'  => $die->machine_number ?? null,
            'machine_desc'    => $die->machine_desc ?? null,
            'used_machine'    => $die->used_machine ?? null,
            'equipnumber'     => $die->equipnumber     ?? null,
            'die_description' => $die->die_description ?? null,
            'equiptype'       => $die->equiptype       ?? null,
            'equipcategory'   => $die->equipcategory   ?? null,
            'size_in'         => $die->size_in  ?? null,
            'size_out'        => $die->size_out ?? null,
            'ordered_size'    => $die->ordered_size ?? ($die->size_in ?? null),
            'present_diameter'=> $die->present_diameter ?? ($die->size_out ?? null),
            'reduction_area_angle' => $die->reduction_area_angle ?? ($die->extra_f3 ?? null),
            'bearing_length'  => $die->bearing_length ?? ($die->extra_f4 ?? null),
            'extra_f3'        => $die->extra_f3 ?? null,
            'extra_f4'        => $die->extra_f4 ?? null,
            'status'          => $die->status   ?? null,
            'from_class'      => $die->from_class ?? null,
            'to_class'        => $die->to_class   ?? null,
            'match_method'    => $die->match_method ?? null,
            'match_size_diff' => $die->match_size_diff ?? null,
        ];
    }

    private function filterRowsByCategory(array $rows, string $category): array
    {
        $cats = \App\Repositories\FormDIE\DieUsageEquipmentRepository::splitCats($category);
        if (empty($cats)) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($cats) {
            if (empty($row['equipnumber'])) {
                return false;
            }

            return in_array((string) ($row['equipcategory'] ?? ''), $cats, true);
        }));
    }

    private function filterRowsByDescription(array $rows, string $description): array
    {
        $needle = mb_strtolower(trim($description));
        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($needle) {
            if (empty($row['equipnumber'])) {
                return false;
            }

            return mb_strpos(mb_strtolower((string) ($row['die_description'] ?? '')), $needle) !== false;
        }));
    }

    private function rowsFromWoDetail(string $wo): ?array
    {
        $detail = $this->woDetailService->detail($wo);
        if (!empty($detail['error']) || empty($detail['steps'])) {
            return null;
        }

        $rows = [];
        $expectedBlocks = [];
        $coveredBlocks = [];
        foreach ($detail['steps'] as $step) {
            if (empty($step['is_drawing']) || empty($step['blocks'])) {
                continue;
            }

            foreach ($step['blocks'] as $block) {
                $blockNo = (int) ($block['block_no'] ?? 0);
                if ($blockNo <= 0) {
                    continue;
                }

                $key = (int) ($step['workseq'] ?? 0) . ':' . $blockNo;
                $expectedBlocks[$key] = [
                    'workseq' => $step['workseq'] ?? null,
                    'workcenter' => $step['workcenter'] ?? null,
                    'workcenter_desc' => $step['workcenter_desc'] ?? null,
                    'block_no' => $blockNo,
                    'block_name' => $block['material'] ?? null,
                    'block_size' => $block['size_plan'] ?? null,
                ];

                $blockObj = (object) [
                    'workordernumber' => $wo,
                    'workseq' => $step['workseq'] ?? null,
                    'workcenternumber' => $step['workcenter'] ?? null,
                    'workcenter_desc' => $step['workcenter_desc'] ?? null,
                    'block_no' => $blockNo,
                    'block_name' => $block['material'] ?? null,
                    'block_size' => $block['size_plan'] ?? null,
                ];

                $matched = $block['dies'] ?? [];
                if (empty($matched)) {
                    $rows[] = $this->makeRow($blockObj, null);
                    continue;
                }

                $coveredBlocks[$key] = true;
                foreach ($matched as $die) {
                    $dieObj = (object) array_merge([
                        'workordernumber' => $wo,
                        'block_no' => $blockNo,
                    ], $die);
                    $rows[] = $this->makeRow($blockObj, $dieObj);
                }
            }
        }

        if (empty($expectedBlocks)) {
            return null;
        }

        return [
            'rows' => $rows,
            'completeness' => $this->makeCompleteness($expectedBlocks, $coveredBlocks),
        ];
    }

    private function rowsFromBlockPlan(array $blocks, array $dies): array
    {
        $diesByBlock = [];
        foreach ($dies as $d) {
            $diesByBlock[(int) $d->block_no][] = $d;
        }

        $rows = [];
        $expectedBlocks = [];
        $coveredBlocks = [];
        foreach ($blocks as $b) {
            $blockNo = (int) $b->block_no;
            $key = (int) ($b->workseq ?? 0) . ':' . $blockNo;
            $expectedBlocks[$key] = [
                'workseq' => $b->workseq ?? null,
                'workcenter' => $b->workcenternumber ?? null,
                'workcenter_desc' => $b->workcenter_desc ?? null,
                'block_no' => $blockNo,
                'block_name' => $b->block_name ?? null,
                'block_size' => $b->block_size ?? null,
            ];

            $matched = $diesByBlock[$blockNo] ?? [];
            if (empty($matched)) {
                $rows[] = $this->makeRow($b, null);
            } else {
                $coveredBlocks[$key] = true;
                foreach ($matched as $d) {
                    $rows[] = $this->makeRow($b, $d);
                }
            }
        }

        return [
            'rows' => $rows,
            'completeness' => $this->makeCompleteness($expectedBlocks, $coveredBlocks),
        ];
    }

    private function makeCompleteness(array $expectedBlocks, array $coveredBlocks): array
    {
        $missingBlocks = [];
        foreach ($expectedBlocks as $key => $block) {
            if (!isset($coveredBlocks[$key])) {
                $missingBlocks[] = $block;
            }
        }

        return [
            'expected_block_count' => count($expectedBlocks),
            'covered_block_count'  => count($coveredBlocks),
            'missing'              => $missingBlocks,
        ];
    }

    private function buildExceptions(array $rows, ?array $completeness = null): array
    {
        $items = [];

        $missingByStep = [];
        foreach (($completeness['missing'] ?? []) as $missing) {
            $key = (string) ($missing['workseq'] ?? '-');
            if (!isset($missingByStep[$key])) {
                $missingByStep[$key] = [
                    'workseq' => $missing['workseq'] ?? null,
                    'workcenter' => $missing['workcenter'] ?? null,
                    'workcenter_desc' => $missing['workcenter_desc'] ?? null,
                    'blocks' => [],
                ];
            }
            $missingByStep[$key]['blocks'][] = 'B' . ($missing['block_no'] ?? '?');
        }

        foreach ($missingByStep as $missing) {
            $blocksText = implode(', ', array_values(array_unique($missing['blocks'])));
            $items[] = [
                'severity' => 'warning',
                'type' => 'missing_block',
                'title' => 'Missing die issue',
                'detail' => 'Workseq ' . ($missing['workseq'] ?? '-') . ' missing ' . $blocksText,
                'workseq' => $missing['workseq'] ?? null,
                'block_no' => null,
            ];
        }

        foreach ($rows as $row) {
            if (!empty($row['equipnumber']) && ($row['status'] ?? 'USABLE') !== 'USABLE') {
                $items[] = [
                    'severity' => $row['status'] === 'SCRAP' ? 'danger' : 'warning',
                    'type' => 'die_status',
                    'title' => 'Die status is ' . ($row['status'] ?? '-'),
                    'detail' => trim(($row['equipnumber'] ?? '') . ' ' . ($row['die_description'] ?? '')),
                    'workordernumber' => $row['workordernumber'] ?? null,
                    'equipnumber' => $row['equipnumber'] ?? null,
                    'block_no' => $row['block_no'] ?? null,
                ];
            }

            if ($this->isSizeMismatch($row)) {
                $items[] = [
                    'severity' => 'warning',
                    'type' => 'size_mismatch',
                    'title' => 'Block size may not match die',
                    'detail' => 'B' . ($row['block_no'] ?? '-') . ': block ' . ($row['block_size'] ?? '-') . ', ordered ' . ($row['ordered_size'] ?? $row['size_in'] ?? '-') . ', present ' . ($row['present_diameter'] ?? $row['size_out'] ?? '-'),
                    'workordernumber' => $row['workordernumber'] ?? null,
                    'equipnumber' => $row['equipnumber'] ?? null,
                    'block_no' => $row['block_no'] ?? null,
                ];
            }

        }

        $counts = ['danger' => 0, 'warning' => 0, 'info' => 0];
        foreach ($items as $item) {
            $counts[$item['severity']] = ($counts[$item['severity']] ?? 0) + 1;
        }

        return [
            'counts' => $counts,
            'items' => array_slice($items, 0, 30),
        ];
    }

    private function buildReadiness(array $rows, array $completeness): array
    {
        $byBlock = [];
        foreach ($rows as $row) {
            $blockNo = (int) ($row['block_no'] ?? 0);
            if ($blockNo <= 0) {
                continue;
            }
            $key = (int) ($row['workseq'] ?? 0) . ':' . $blockNo;
            if (!isset($byBlock[$key])) {
                $byBlock[$key] = [
                    'block_no' => $blockNo,
                    'block_name' => $row['block_name'] ?? null,
                    'block_size' => $row['block_size'] ?? null,
                    'workseq' => $row['workseq'] ?? null,
                    'workcenter' => $row['workcenter'] ?? null,
                    'status' => 'ready',
                    'notes' => [],
                    'dies' => [],
                ];
            }

            if (empty($row['equipnumber'])) {
                $byBlock[$key]['status'] = 'missing';
                $byBlock[$key]['notes'][] = 'No die transfer';
                continue;
            }

            $byBlock[$key]['dies'][] = $row['equipnumber'];
            if (($row['status'] ?? 'USABLE') !== 'USABLE') {
                $byBlock[$key]['status'] = 'check';
                $byBlock[$key]['notes'][] = 'Die status ' . ($row['status'] ?? '-');
            }
            if ($this->isSizeMismatch($row)) {
                $byBlock[$key]['status'] = 'check';
                $byBlock[$key]['notes'][] = 'Size mismatch';
            }
        }

        $ready = 0;
        $check = 0;
        $missing = 0;
        foreach ($byBlock as &$block) {
            $block['dies'] = array_values(array_unique($block['dies']));
            $block['notes'] = array_values(array_unique($block['notes']));
            if ($block['status'] === 'ready') $ready++;
            elseif ($block['status'] === 'missing') $missing++;
            else $check++;
        }
        unset($block);
        usort($byBlock, fn($a, $b) => [($a['workseq'] ?? 0), ($a['block_no'] ?? 0)] <=> [($b['workseq'] ?? 0), ($b['block_no'] ?? 0)]);

        return [
            'expected' => $completeness['expected_block_count'] ?? count($byBlock),
            'ready' => $ready,
            'check' => $check,
            'missing' => $missing,
            'blocks' => array_values($byBlock),
        ];
    }

    /**
     * การ์ดซ้าย (เดิม Health Score) — วัดด้วย kg ที่ผลิตสะสม + สถานะจริงของ die
     * ไม่มีคะแนนสมมติอีกต่อไป
     */
    private function buildKgUsage(?object $info, float $totalKg, int $woCount): array
    {
        $status = $info->status ?? null;
        $level = match (true) {
            $status === 'SCRAP'              => 'danger',
            $status && $status !== 'USABLE'  => 'watch',
            default                          => 'good',
        };

        return [
            'total_kg' => $totalKg,
            'wo_count' => $woCount,
            'status'   => $status,
            'level'    => $level,
            'label'    => $status ?: 'USABLE',
        ];
    }

    /**
     * การ์ดขวา (เดิม Maintenance Due) — kg ที่ผลิตสะสม แบบไม่มี limit
     * die เสียเมื่อไหร่ user เปลี่ยน status เอง จึงตัดสินจากสถานะจริง ไม่ใช่ threshold
     */
    private function buildMaintenanceKg(?object $info, float $totalKg): array
    {
        $status = $info->status ?? 'USABLE';
        $due = $status !== 'USABLE';

        return [
            'total_kg' => $totalKg,
            'no_limit' => true,
            'status'   => $due ? 'due' : 'ok',
            'label'    => $due ? $status : 'OK',
            'note'     => 'ไม่มี limit — เปลี่ยนสถานะ die เมื่อชำรุด',
        ];
    }

    /**
     * Lifecycle curve แบบ kg — group ตาม workorder (kg เป็นค่าต่อ WO ไม่ใช่ต่อครั้งที่เบิก)
     * คืน list เรียงตามวันที่: [{date, wo, kg, meter}]
     * เส้นสะสมของ kg จะรวมได้เท่ากับ total_kg พอดี (ไม่นับซ้ำต่อ transfer)
     */
    private function buildKgLifecycle(array $history, array $kgByWo): array
    {
        $byWo = [];
        foreach ($history as $h) {
            // ใช้ WO ผลิตจริง (prod_wo) เพื่อให้ kg (จากแถวเบิก) กับ meter (จากแถวคืน) มารวมที่ WO เดียวกัน
            $wo = $h->prod_wo ?? null;
            if (empty($wo)) {
                continue; // ข้ามรายการที่ไม่มี WO ผลิต (เช่น แถวซ่อม)
            }
            $date = is_string($h->transdate) ? substr($h->transdate, 0, 10) : (string) ($h->transdate ?? '');
            if (!isset($byWo[$wo])) {
                $byWo[$wo] = ['wo' => $wo, 'date' => $date, 'kg' => (float) ($kgByWo[strtoupper((string) $wo)] ?? 0), 'meter' => 0.0];
            }
            // ใช้วันที่เก่าที่สุดของ WO เป็นจุดบนเส้นเวลา
            if ($date !== '' && ($byWo[$wo]['date'] === '' || $date < $byWo[$wo]['date'])) {
                $byWo[$wo]['date'] = $date;
            }
            // meter ต่อ WO นับเฉพาะรอบใช้ผลิตจริง (กันเลขสะสมจากแถวเบิก/ซ่อม)
            if (!empty($h->is_production)) {
                $byWo[$wo]['meter'] += (float) ($h->meter ?? 0);
            }
        }

        $rows = array_values($byWo);
        usort($rows, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows;
    }

    private function buildProfileTimeline(array $history): array
    {
        // ส่งครบทุกรายการ (history ถูก cap ที่ 200 อยู่แล้วใน getDieProfile)
        // ฝั่ง UI แสดงในกรอบที่มี scroll
        return array_map(fn($h) => [
            'date' => $h->transdate ?? null,
            'transnumber' => $h->transnumber ?? null,
            'workordernumber' => $h->workordernumber ?? null,
            'prod_wo' => $h->prod_wo ?? null,
            'from_class' => $h->from_class ?? null,
            'to_class' => $h->to_class ?? null,
            'from_class_id' => $h->from_class_id ?? null,
            'to_class_id' => $h->to_class_id ?? null,
            'meter' => (float) ($h->meter ?? 0),
            // ประเภทรายการ: production = ใช้ผลิตจริง, issue = เบิกออก, repair = ส่งซ่อม
            'txn_type' => $h->txn_type ?? null,
            'txn_status' => $h->txn_status ?? null,
            'is_production' => isset($h->is_production) ? (int) $h->is_production : 0,
            'block_desc' => $h->block_desc ?? null,
        ], $history);
    }

    private function buildSummaryDelta(array $current, array $previous): array
    {
        $keys = ['total_trans', 'unique_die', 'total_kg', 'unique_wo', 'unique_dept'];
        $delta = [];
        foreach ($keys as $key) {
            $cur = (float) ($current[$key] ?? 0);
            $prev = (float) ($previous[$key] ?? 0);
            $delta[$key] = [
                'current' => $cur,
                'previous' => $prev,
                'change' => $cur - $prev,
                'percent' => $prev == 0.0 ? null : round((($cur - $prev) / $prev) * 100, 1),
            ];
        }
        return $delta;
    }

    private function isSizeMismatch(array $row): bool
    {
        if (empty($row['equipnumber']) || $row['block_size'] === null || $row['size_in'] === null) {
            return false;
        }
        $blockSize = $this->numericValue($row['block_size']);
        $sizeIn = $this->numericValue($row['size_in']);
        if ($blockSize === null || $sizeIn === null) {
            return false;
        }
        return abs($blockSize - $sizeIn) > 0.01;
    }

    private function numericValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/-?\d+(?:\.\d+)?/', (string) $value, $m)) {
            return (float) $m[0];
        }
        return null;
    }

    /**
     * แปลงปลายทางของ transfer ล่าสุดเป็น label ตำแหน่งปัจจุบัน
     * - มีชื่อ workcenter → ใช้ชื่อนั้น (ไดร์อยู่ที่แผนกนั้น)
     * - ส่งซ่อม (FIXABLE / to_class_id = -1) → 'ส่งซ่อม'
     * - คืนเข้า floor (to_class_id = 0) → 'คลัง (ว่าง)'
     */
    private function currentClassLabel($h): ?string
    {
        $toClass = $h->to_class ?? null;
        if (!empty($toClass)) {
            return $toClass;
        }
        $toId   = isset($h->to_class_id) ? (int) $h->to_class_id : null;
        if ($toId === 0) {
            return 'Repair';
        }
        if ($toId === -1) {
            return 'Store';
        }
        return null;
    }

    private function summary(array $dies): array
    {
        $totalMeter   = 0;
        $totalKg      = 0.0;
        $uniqueDie    = [];
        $uniqueWO     = [];
        $uniqueDept   = [];
        $countedKgWo  = [];
        foreach ($dies as $d) {
            // นับ meter เฉพาะการใช้ผลิตจริง (USABLE + คืนเข้า floor) — กันแถวเบิก/ซ่อมที่เก็บ meter เป็นเลขสะสม
            if (!empty($d->is_production)) {
                $totalMeter += (float) ($d->meter ?? 0);
            }
            if (!empty($d->equipnumber))     $uniqueDie[$d->equipnumber] = true;
            if (!empty($d->workordernumber)) $uniqueWO[$d->workordernumber] = true;
            // แผนกที่ใช้ไดร์ = to_class (แผนกปลายทางที่เบิกไดร์ไปใช้)
            if (!empty($d->to_class))        $uniqueDept[$d->to_class] = true;
            $wo = strtoupper(trim((string) ($d->workordernumber ?? '')));
            $site = (string) ($d->site ?? 'W');
            $kgKey = $site . '|' . $wo;
            if ($wo !== '' && !isset($countedKgWo[$kgKey])) {
                $totalKg += (float) ($d->wo_fg_kg ?? 0);
                $countedKgWo[$kgKey] = true;
            }
        }

        return [
            'total_trans'  => count($dies),
            'unique_die'   => count($uniqueDie),
            'total_meter'  => $totalMeter,
            'total_kg'     => $totalKg,
            'unique_wo'    => count($uniqueWO),
            'unique_dept'  => count($uniqueDept),
        ];
    }

    private function buildHeatmap(array $dies): array
    {
        $blocks = [];
        $matrix = ['DRAWING' => []];
        $details = [];
        $drawingWos = [];
        $usageByKey = [];

        foreach ($dies as $d) {
            if (stripos((string) ($d->to_class ?? ''), 'DRAWING') === false) continue;
            $wo = strtoupper(trim((string) ($d->workordernumber ?? '')));
            $die = strtoupper(trim((string) ($d->equipnumber ?? '')));
            if ($wo !== '') {
                $drawingWos[$wo] = true;
                if ($die !== '') {
                    $usageByKey[$wo . '|' . $die][] = $d;
                }
            }
        }

        foreach (array_slice(array_keys($drawingWos), 0, 60) as $wo) {
            try {
                $detail = $this->woDetailService->detail($wo);
            } catch (\Throwable) {
                continue;
            }

            foreach ($detail['steps'] ?? [] as $step) {
                if (empty($step['is_drawing'])) continue;
                foreach ($step['blocks'] ?? [] as $block) {
                    $blockNo = (int) ($block['block_no'] ?? 0);
                    if ($blockNo <= 0) continue;
                    foreach ($block['dies'] ?? [] as $die) {
                        $usageKey = strtoupper($wo) . '|' . strtoupper(trim((string) ($die['equipnumber'] ?? '')));
                        if (empty($usageByKey[$usageKey])) continue;
                        $usage = array_shift($usageByKey[$usageKey]);
                        $blocks[$blockNo] = true;
                        $matrix['DRAWING'][$blockNo] = ($matrix['DRAWING'][$blockNo] ?? 0) + 1;
                        $details['DRAWING'][$blockNo][] = [
                            'equipnumber' => $die['equipnumber'] ?? null,
                            'die_description' => $die['die_description'] ?? null,
                            'status' => $usage->txn_status ?? $die['txn_status'] ?? $die['status'] ?? null,
                            'master_status' => $die['status'] ?? null,
                            'transnumber' => $usage->transnumber ?? $die['transnumber'] ?? null,
                            'txn_type' => $usage->txn_type ?? $die['txn_type'] ?? null,
                            'workordernumber' => $wo,
                            'transdate' => $usage->transdate ?? $die['transdate'] ?? null,
                            'ordered_size' => $die['ordered_size'] ?? $die['size_in'] ?? null,
                            'present_diameter' => $die['present_diameter'] ?? $die['size_out'] ?? null,
                        ];
                    }
                }
            }
        }

        $blockList = array_keys($blocks);
        sort($blockList);
        return [
            'workcenters' => $blockList ? ['DRAWING'] : [],
            'blocks'      => $blockList,
            'matrix'      => $matrix,
            'details'     => $details,
        ];
    }

    /**
     * สร้าง heatmap จาก rows สุดท้าย (block_no เดียวกับที่แสดงในตาราง) เพื่อให้ตาราง ↔ heatmap ตรงกันเสมอ
     * รวมทั้ง block ที่ได้จาก WO detail และ block fallback จาก PCM; แถว "ไม่จับคู่" (block_no ว่าง) จะไม่ถูกนับ
     */
    private function rebuildHeatmapFromRows(array $rows): array
    {
        $matrix  = ['DRAWING' => []];
        $details = [];
        $blocks  = [];

        foreach ($rows as $row) {
            if (stripos((string) ($row['to_class'] ?? ''), 'DRAWING') === false) continue;
            $blockNo = (int) ($row['block_no'] ?? 0);
            if ($blockNo <= 0) continue; // ไม่จับคู่ / ไม่มี block → ไม่นับใน heatmap

            $blocks[$blockNo] = true;
            $matrix['DRAWING'][$blockNo] = ($matrix['DRAWING'][$blockNo] ?? 0) + 1;
            $details['DRAWING'][$blockNo][] = [
                'equipnumber'      => $row['equipnumber'] ?? null,
                'die_description'  => $row['die_description'] ?? null,
                'status'           => $row['txn_status'] ?? $row['status'] ?? null,
                'master_status'    => $row['status'] ?? null,
                'transnumber'      => $row['transnumber'] ?? null,
                'txn_type'         => $row['txn_type'] ?? null,
                'workordernumber'  => $row['workordernumber'] ?? null,
                'transdate'        => $row['transdate'] ?? null,
                'ordered_size'     => $row['ordered_size'] ?? $row['size_in'] ?? null,
                'present_diameter' => $row['present_diameter'] ?? $row['size_out'] ?? null,
            ];
        }

        $blockList = array_keys($blocks);
        sort($blockList);

        return [
            'workcenters' => $blockList ? ['DRAWING'] : [],
            'blocks'      => $blockList,
            'matrix'      => $matrix,
            'details'     => $details,
        ];
    }

    private function attachDrawingBlocksToRows(array &$rows, array $heatmap): void
    {
        $blocksByUsage = [];
        foreach (($heatmap['details']['DRAWING'] ?? []) as $blockNo => $items) {
            foreach ($items as $item) {
                $key = strtoupper(trim((string) ($item['workordernumber'] ?? '')))
                    . '|' . strtoupper(trim((string) ($item['equipnumber'] ?? '')));
                if ($key !== '|') {
                    $blocksByUsage[$key] = (int) $blockNo;
                }
            }
        }

        foreach ($rows as &$row) {
            if (stripos((string) ($row['to_class'] ?? ''), 'DRAWING') === false) continue;
            $key = strtoupper(trim((string) ($row['workordernumber'] ?? '')))
                . '|' . strtoupper(trim((string) ($row['equipnumber'] ?? '')));
            if (isset($blocksByUsage[$key])) {
                $row['block_no'] = $blocksByUsage[$key];
                $row['block_source'] = 'wo_detail';
            }
        }
        unset($row);
    }

    private function drawingOnly(array $dies): array
    {
        return array_values(array_filter(
            $dies,
            fn($die) => stripos((string) ($die->to_class ?? ''), 'DRAWING') !== false
        ));
    }

    private function buildCharts(array $dies, string $mode): array
    {
        $byBlock    = [];
        $byCategory = [];
        $byDept     = [];
        $byDate     = [];
        $byDie      = [];
        $countedKg  = [];

        foreach ($dies as $d) {
            $blockNo = (int) ($d->block_no ?? 0);
            if ($blockNo > 0) $byBlock[$blockNo] = ($byBlock[$blockNo] ?? 0) + 1;

            $cat = $d->equipcategory ?? '(ไม่ระบุ)';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;

            $dept = $d->to_class ?? '(ไม่ระบุ)';
            $woKey = strtoupper(trim((string) ($d->workordernumber ?? '')));
            $site = (string) ($d->site ?? 'W');
            $kgKey = $site . '|' . $dept . '|' . $woKey;
            if ($woKey !== '' && !isset($countedKg[$kgKey])) {
                $kg = (float) ($d->wo_fg_kg ?? 0);
                $byDept[$dept] = ($byDept[$dept] ?? 0) + $kg;
                $date = is_string($d->transdate) ? substr($d->transdate, 0, 10) : (string) $d->transdate;
                $byDate[$date] = ($byDate[$date] ?? 0) + $kg;
                $countedKg[$kgKey] = true;
            }

            $die = $d->equipnumber ?? '(ไม่ระบุ)';
            $byDie[$die] = ($byDie[$die] ?? 0) + 1;
        }

        ksort($byBlock);
        ksort($byDate);
        arsort($byDie);

        return [
            'by_block'    => $byBlock,
            'by_category' => $byCategory,
            'by_dept'     => $byDept,
            'by_date'     => $byDate,
            'top_die'     => array_slice($byDie, 0, 10, true),
        ];
    }

    private function attachAnalyticsKg(array $dies): void
    {
        $wosBySite = ['W' => [], 'P' => []];
        foreach ($dies as $d) {
            $wo = strtoupper(trim((string) ($d->workordernumber ?? '')));
            if ($wo === '') continue;
            $site = ($d->site ?? null) === 'P' || str_starts_with($wo, '+') ? 'P' : 'W';
            $wosBySite[$site][$wo] = $wo;
        }

        $kgMaps = [
            'W' => $this->woDetailService->fgKgByWorkorders(array_values($wosBySite['W']), 'pgsqlmfgw'),
            'P' => $this->woDetailService->fgKgByWorkorders(array_values($wosBySite['P']), 'pgsqlmfgp'),
        ];

        foreach ($dies as $d) {
            $wo = strtoupper(trim((string) ($d->workordernumber ?? '')));
            $site = ($d->site ?? null) === 'P' || str_starts_with($wo, '+') ? 'P' : 'W';
            $d->wo_fg_kg = (float) ($kgMaps[$site][$wo] ?? 0);
        }
    }
}
