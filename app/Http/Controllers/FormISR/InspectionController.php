<?php

namespace App\Http\Controllers\FormISR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Users\User;
use App\Models\FormISR\IsrManualDecision;
use App\Services\ISR\RemarkSpecExtractor;

class InspectionController extends Controller
{
    /**
     * หน้า search + แสดง Inspection Report
     */
    public function index(Request $request)
    {
        $mfgNo = trim((string) $request->query('mfg_no', ''));

        $vm = null;
        if ($mfgNo !== '') {
            $vm = $this->buildViewModel($mfgNo);
        }

        return view('formisr.index', [
            'mfgNo' => $mfgNo,
            'vm'    => $vm,
            'manual_decision' => $vm['manual_decision'] ?? null,

        ]);
    }

    public function workcenterTests(Request $request)
    {
        $site = strtolower((string) $request->query('site', 'wire')) === 'plus' ? 'plus' : 'wire';
        $connectionName = $site === 'plus' ? 'pgsqlmfgp' : 'pgsqlmfgw';
        $conn = DB::connection($connectionName);

        $q = trim((string) $request->query('q', ''));
        $selectedId = (int) $request->query('workcentertestval_id', 0);

        $activitySub = $conn->table('workcentertest as wct')
            ->leftJoin('workcentertestitems as wcti', 'wcti.workcentertest_id', '=', 'wct.id')
            ->selectRaw('
                wct.workcenter_id,
                COUNT(DISTINCT wct.id) AS test_count,
                COUNT(wcti.id) AS item_count,
                MAX(wct.lastupdate) AS last_test_at
            ')
            ->groupBy('wct.workcenter_id');

        $query = $conn->table('workcentertestval as wtv')
            ->join('workcenter as wc', 'wc.id', '=', 'wtv.workcenter_id')
            ->leftJoinSub($activitySub, 'act', function ($join) {
                $join->on('act.workcenter_id', '=', 'wtv.workcenter_id');
            })
            ->select([
                'wtv.*',
                'wc.workcenternumber',
                'wc.description as workcenter_description',
                'wc.capacity',
                'wc.workhour',
                'act.test_count',
                'act.item_count',
                'act.last_test_at',
            ])
            ->orderBy('wc.workcenternumber')
            ->orderByDesc('wtv.id');

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(function ($where) use ($q, $like) {
                if (ctype_digit($q)) {
                    $where->orWhere('wtv.id', (int) $q)
                        ->orWhere('wtv.workcenter_id', (int) $q);
                }

                $where->orWhere('wc.workcenternumber', 'ILIKE', $like)
                    ->orWhere('wc.description', 'ILIKE', $like)
                    ->orWhere('wtv.valseq', 'ILIKE', $like);
            });
        }

        if ($selectedId > 0) {
            $query->where('wtv.id', $selectedId);
        }

        $configs = $query->limit(80)->get();
        $extends = $configs->isEmpty()
            ? collect()
            : $conn->table('workcentertestvalextend')
                ->whereIn('workcentertestval_id', $configs->pluck('id')->all())
                ->get()
                ->keyBy('workcentertestval_id');

        $rows = $configs->map(function ($config) use ($extends) {
            $extend = $extends->get($config->id);
            $slots = $this->normalizeWorkcenterTestSlots($config, $extend);

            return [
                'id' => (int) $config->id,
                'workcenter_id' => (int) $config->workcenter_id,
                'workcenter_number' => trim((string) ($config->workcenternumber ?? '')),
                'workcenter_description' => trim((string) ($config->workcenter_description ?? '')),
                'capacity' => $config->capacity ?? null,
                'workhour' => $config->workhour ?? null,
                'valseq' => (string) ($config->valseq ?? ''),
                'reqf' => (string) ($config->reqf ?? ''),
                'test_count' => (int) ($config->test_count ?? 0),
                'item_count' => (int) ($config->item_count ?? 0),
                'last_test_at' => $config->last_test_at ?? null,
                'slot_count' => count($slots),
                'required_count' => collect($slots)->where('required', true)->count(),
                'slots' => $slots,
            ];
        })->values();

        return view('formisr.workcenter-tests', [
            'rows' => $rows,
            'filters' => [
                'site' => $site,
                'q' => $q,
                'workcentertestval_id' => $selectedId > 0 ? $selectedId : null,
            ],
            'summary' => [
                'config_count' => $rows->count(),
                'slot_count' => $rows->sum('slot_count'),
                'required_count' => $rows->sum('required_count'),
                'actual_test_count' => $rows->sum('test_count'),
                'actual_item_count' => $rows->sum('item_count'),
            ],
        ]);
    }

    /**
     * สร้าง ViewModel สำหรับหน้า Inspection Report ตาม MFG No
     */
    protected function normalizeWorkcenterTestSlots($config, $extend = null): array
    {
        $keys = array_values(array_filter(array_map(
            static fn($key) => trim((string) $key),
            explode(':', (string) ($config->valseq ?? ''))
        )));

        $requiredMap = [];
        foreach (explode(':', (string) ($config->reqf ?? '')) as $token) {
            $token = trim((string) $token);
            if ($token === '' || !str_contains($token, '_')) {
                continue;
            }

            [$key, $flag] = explode('_', $token, 2);
            $requiredMap[strtolower($key)] = strtolower($flag);
        }

        $rows = [];
        foreach ($keys as $position => $key) {
            $key = strtolower($key);
            $source = $this->slotSourceObject($key, $config, $extend);
            $labelField = $key . 'text';
            $operField = $key . 'oper';
            $upperField = $key . 'upper';
            $lowerField = $key . 'lower';
            $tabField = $key . 'tab';

            $flag = $requiredMap[$key] ?? null;
            $label = trim((string) ($source->{$labelField} ?? ''));
            $operator = trim((string) ($source->{$operField} ?? ''));

            $rows[] = [
                'position' => $position + 1,
                'key' => $key,
                'group' => $this->slotGroup($key),
                'label' => $label !== '' ? $label : strtoupper($key),
                'required_flag' => $flag,
                'required' => $flag === 'm',
                'operator' => $operator,
                'lower' => $source->{$lowerField} ?? null,
                'upper' => $source->{$upperField} ?? null,
                'tab' => $source->{$tabField} ?? null,
                'source_table' => $source === $extend ? 'workcentertestvalextend' : 'workcentertestval',
            ];
        }

        return $rows;
    }

    protected function slotSourceObject(string $key, $config, $extend)
    {
        if ($extend && preg_match('/^(q?v)(\d+)$/', $key, $matches) && (int) $matches[2] >= 11) {
            return $extend;
        }

        return $config;
    }

    protected function slotGroup(string $key): string
    {
        if (str_starts_with($key, 'qv')) {
            return 'QC Numeric';
        }
        if (str_starts_with($key, 'qt')) {
            return 'QC Text';
        }
        if (str_starts_with($key, 'qb')) {
            return 'QC Boolean';
        }
        if (str_starts_with($key, 'v')) {
            return 'Numeric';
        }
        if (str_starts_with($key, 't')) {
            return 'Text';
        }
        if (str_starts_with($key, 'b')) {
            return 'Boolean';
        }

        return 'Other';
    }

    protected function buildViewModel(string $mfgNo): ?array
    {
        // --------------------------------------------------
        // 0) เลือก connection ตาม MFG No
        // --------------------------------------------------
        $hasPlusPrefix = str_starts_with($mfgNo, '+');

        if ($hasPlusPrefix) {
            $mfgConn = DB::connection('pgsqlmfgp');
            $qaConn  = DB::connection('pgsqlmfgp');
        } else {
            $mfgConn = DB::connection('pgsqlmfgw');
            $qaConn  = DB::connection('pgsqlmfgw');
        }

        // ตัดเครื่องหมาย + ออกก่อนเอาไปค้นใน workorder
        $searchMfgNo = ltrim($mfgNo, '+');

        // --------------------------------------------------
        // 1) หา Workorder ตาม MFG No
        // --------------------------------------------------
        $wo = $mfgConn->table('workorder')
            ->where('workordernumber', $searchMfgNo)
            ->first();

        if (!$wo) {

            if (!str_contains($searchMfgNo, '(')) {
                $alt = $searchMfgNo . '(C)';

                $wo = $mfgConn->table('workorder')
                    ->where('workordernumber', $alt)
                    ->first();

                if ($wo) {
                    $searchMfgNo = $alt;
                }
            }
        }

        if (!$wo) return null;

        // --------------------------------------------------
        // 2) หา workseq สูงสุด (step สุดท้าย)
        // --------------------------------------------------
        $maxSeq = (int) $mfgConn->table('workorderworkcenter as wowc')
            ->where('wowc.workorder_id', $wo->id)
            ->max('wowc.workseq');

        if ($maxSeq <= 1) {
            // ถ้ามีแค่ step เดียวหรือไม่มี step เลย ก็ไม่ต้องออก report
            return null;
        }

        // --------------------------------------------------
        // 3) ดึงทุก step ที่อยู่ก่อนสุดท้าย แล้วเรียงจากมากไปน้อย
        // --------------------------------------------------
        $steps = $mfgConn->table('workorderworkcenter as wowc')
            ->join('workcenter as wc', 'wc.id', '=', 'wowc.workcenter_id')
            ->where('wowc.workorder_id', $wo->id)
            ->where('wowc.workseq', '<', $maxSeq)
            ->orderByDesc('wowc.workseq')
            ->select('wowc.*', 'wc.description as workcenter_name')
            ->get();

        // --------------------------------------------------
        // 4) วนหา step แรกที่ "ไม่ใช่" บ่อ/ต้ม/ล้าง
        // --------------------------------------------------
        $targetRow = $steps->first(function ($row) {
            $desc = $row->workcenter_name ?? '';

            $hasBo   = mb_strpos($desc, 'บ่อ')  !== false;
            $hasTom  = mb_strpos($desc, 'ต้ม')  !== false;
            $hasLang = mb_strpos($desc, 'ล้าง') !== false;

            // ถ้าไม่ใช่บ่อ/ต้ม/ล้าง → ใช้ตัวนี้เป็น target
            return !($hasBo || $hasTom || $hasLang);
        });

        // ถ้าไม่เจอเลย ให้ fallback ไปที่ seq 1
        if ($targetRow) {
            $targetSeq = (int) $targetRow->workseq;
            $wowc      = $targetRow;
        } else {
            $targetSeq = 1;
            $wowc      = $mfgConn->table('workorderworkcenter as wowc')
                ->join('workcenter as wc', 'wc.id', '=', 'wowc.workcenter_id')
                ->where('wowc.workorder_id', $wo->id)
                ->where('wowc.workseq', $targetSeq)
                ->select('wowc.*', 'wc.description as workcenter_name')
                ->first();
        }

        if (!$wowc) {
            return null;
        }

        $manual = IsrManualDecision::where('wo_id', $wo->id)
            ->where('step_seq', $targetSeq)
            ->latest('decided_at')
            ->first();



        // --------------------------------------------------
        // 4.x) ดึง master การทดสอบจาก workcentertestval (ไว้ map v1,v2,...)
        // --------------------------------------------------
        $specColumns = $this->buildWorkorderSpecColumns($mfgConn, $wowc);
        $sizePointMap = $this->mapSizePointsFromSpecColumns($specColumns);
        //dd($specColumns, $sizePointMap);
        //dd($wo->id, $targetSeq);
        // --------------------------------------------------
        // 5) WORKORDER TEST (ข้อมูล spec + operator)
        // --------------------------------------------------
        $workTests = $mfgConn->table('workorder as wo')
            ->join('workorderworkcenter as wowc', 'wowc.workorder_id', '=', 'wo.id')
            ->join('workordertest as wot', function ($join) {
                $join->on('wot.workorder_id',  '=', 'wowc.workorder_id')
                    ->on('wot.workcenter_id', '=', 'wowc.workcenter_id')
                    ->on('wot.workseq',       '=', 'wowc.workseq');
            })
            ->leftJoin('workcenter as wc', 'wc.id', '=', 'wowc.workcenter_id')
            ->leftJoin('workorderreceive as wor', 'wot.workorderreceive_id', '=', 'wor.id')
            ->leftJoin('workorderusage as wou', 'wot.workorderreceive_id', '=', 'wou.id')
            ->leftJoin('workmachine as wm', 'wm.id', '=', 'wot.workmachine_id')
            ->leftJoin('employee as em', 'em.id', '=', 'wot.inspector_id')
            ->where('wo.id', $wo->id)
            ->where('wot.workseq', $targetSeq)
            // ->where('wor.receivetype', 1)
            ->selectRaw('DISTINCT ON (wo.id, wot.id)
            wo.workordernumber,
            wo.qty,
            wo.customer,
            wo.brand,
            wowc.msize,
            wowc.msizetolp,
            wowc.msizetolm,
            wot.docnumber,
            wot.inspector_id,
            wot.inspectedtime,
            wor.msize as wip_msize,
            wou.msize as wou_msize,
            wo.fsize  as wo_fsize,
            COALESCE(wor.coilno, wou.coilno) as coilno,
            -- spec หลักเดิม
            wot.v6  as std_roundness,
            wot.v7  as std_length,
            wot.v9  as std_straight,
            wo.flen as std_mlen,
            -- ====== ค่า v1–v10 ======
            wot.v1  as v1,
            wot.v2  as v2,
            wot.v3  as v3,
            wot.v4  as v4,
            wot.v5  as v5,
            wot.v6  as v6,
            wot.v7  as v7,
            wot.v8  as v8,
            wot.v9  as v9,
            wot.v10 as v10,

            -- ====== ค่า b1–b5 ======
            wot.b1  as b1,
            wot.b2  as b2,
            wot.b3  as b3,
            wot.b4  as b4,
            wot.b5  as b5,

            -- ====== ค่า qv1–qv10 ======
            wot.qv1  as qv1,
            wot.qv2  as qv2,
            wot.qv3  as qv3,
            wot.qv4  as qv4,
            wot.qv5  as qv5,
            wot.qv6  as qv6,
            wot.qv7  as qv7,
            wot.qv8  as qv8,
            wot.qv9  as qv9,
            wot.qv10 as qv10,

            -- ====== ค่า qb1–qb5 ======
            wot.qb1  as qb1,
            wot.qb2  as qb2,
            wot.qb3  as qb3,
            wot.qb4  as qb4,
            wot.qb5  as qb5,

            -- ====== ค่า t1–t10 ======
            wot.t1  as t1,
            wot.t2  as t2,
            wot.t3  as t3,
            wot.t4  as t4,
            wot.t5  as t5,
            wot.t6  as t6,
            wot.t7  as t7,
            wot.t8  as t8,
            wot.t9  as t9,
            wot.t10 as t10,

            -- ====== ค่า qt1–qt10 ======
            wot.qt1  as qt1,
            wot.qt2  as qt2,
            wot.qt3  as qt3,
            wot.qt4  as qt4,
            wot.qt5  as qt5,
            wot.qt6  as qt6,
            wot.qt7  as qt7,
            wot.qt8  as qt8,
            wot.qt9  as qt9,
            wot.qt10 as qt10,

            em.name as inspector_name
           
        ')
            ->orderBy('wo.id')
            ->orderByDesc('wot.id')
            ->get();


        // --------------------------------------------------
        // 6) QA WIP (สุ่มตรวจ)
        // --------------------------------------------------
        $qawipItems = $qaConn->table('qawip as qw')
            ->join('qawipitems as qwi', 'qw.id', '=', 'qwi.qawip_id')
            ->where('qw.workorder_id', $wo->id)
            ->where('qwi.wowc_id', $wowc->id)
            ->select('qw.*', 'qwi.*')
            ->orderBy('qwi.id')
            ->get();

        $qaHardnessSample = $qaConn->table('qawip as qw')
            ->join('qawipitems as qwi', 'qw.id', '=', 'qwi.qawip_id')
            ->where('qw.workorder_id', $wo->id)
            ->where(function ($q) {
                $q->whereNotNull('qw.point1')
                    ->orWhereNotNull('qw.point2')
                    ->orWhereNotNull('qw.point3')
                    ->orWhereNotNull('qw.point4')
                    ->orWhereNotNull('qw.point5');
            })
            ->orderByDesc('qw.id')
            ->selectRaw('qw.*')
            ->first();




        //dd($qawipItems, $qaHardnessSample);
        // --------------------------------------------------
        // 7) รวมข้อมูลต่อ coil
        //    - ต้องโชว์ทุกครั้งที่มีการ inspection (หลายรอบใน coil เดียวกัน)
        // --------------------------------------------------
        $qaMechRows = $this->buildQaWipMechanical($qawipItems);
        // 7.1 group WORKTEST ตาม coil (เก็บทุกแถว ไม่ทับ)
        $wtByCoil = [];
        foreach ($workTests as $wt) {
            $coilRaw = trim((string)($wt->coilno ?? ''));

            if ($coilRaw === '') {
                continue;
            }
            $coilNorm = str_pad($coilRaw, 3, '0', STR_PAD_LEFT);
            $wtByCoil[$coilNorm][] = $wt;
        }
        //dd($qawipItems);
        // 7.2 group QA WIP ตาม coil (เผื่อมีหลาย record)
        $qaByCoil = [];
        foreach ($qawipItems as $qi) {
            $coilRaw = trim((string) ($qi->coil ?? $qi->wipitemnumber ?? ''));
            //dump($qi->coil, $qi->wipitemnumber);
            if ($coilRaw === '') {
                continue;
            }
            $coilNorm = str_pad($coilRaw, 3, '0', STR_PAD_LEFT);
            $qaByCoil[$coilNorm][] = $qi;   // ✅ เก็บเป็น list
        }

        // เลือก QA sample ตัวเดียว (ล่าสุด) ใช้กับทุก coil
        $qaSample = null;
        foreach ($qawipItems as $qi) {
            // เงื่อนไข: ต้องมีข้อมูลจริงอย่างใดอย่างหนึ่ง (กัน record ว่าง)
            $hasMech =
                ($qi->ts !== null && $qi->ts !== '') ||
                ($qi->ys !== null && $qi->ys !== '') ||
                ($qi->el !== null && $qi->el !== '') ||
                ($qi->pp !== null && $qi->pp !== '');

            if (!$hasMech) {
                continue;
            }

            // ให้ตัวล่าสุดทับไปเรื่อย ๆ
            $qaSample = $qi;
        }

        $sizeWotKey   = null;
        $lengthWotKey = null;

        foreach ($specColumns as $sc) {
            $lbl = trim((string)($sc['label'] ?? ''));
            $key = trim((string)($sc['key'] ?? ''));

            if ($key === '' || $lbl === '') continue;

            if ($sizeWotKey === null && mb_stripos($lbl, 'ขนาด') !== false) {
                $sizeWotKey = $key;
            }

            if ($lengthWotKey === null && mb_stripos($lbl, 'ความยาว') !== false) {
                $lengthWotKey = $key;
            }

            if ($sizeWotKey !== null && $lengthWotKey !== null) {
                break;
            }
        }


        // 7.3 สร้างแถวแสดงผล: 1 worktest = 1 row (จึงเห็น inspection ซ้ำ)
        $coilKeys = array_unique(array_merge(array_keys($wtByCoil), array_keys($qaByCoil)));
        sort($coilKeys);

        $remark     = (string)($wowc->description ?? '');
        $stepDetail = (string)($wo->notes ?? '');
        $testsFlags = $this->resolveTestsToShow($wowc, $remark, $stepDetail);
        $columnConfig = $this->getColumnConfig($testsFlags);
        if (!empty($sizePointMap)) {
            $columnConfig['size']['size_points_map'] = $sizePointMap;
        }
        $wotKeyMap    = $this->mapWotKeysByMaster($columnConfig, $specColumns);
        $specCache = $this->buildSpecCache($remark, $stepDetail);

        $qaRows = [];
        $normalizeDefectValue = function ($val, array $cfg) {
            if (($cfg['zero_is_ok'] ?? false) === true) {
                if ($val === 0 || $val === '0') {
                    return 'OK'; // ปกติ
                }
            }
            return $val;
        };



        foreach ($coilKeys as $coilNorm) {
            $wtList = $wtByCoil[$coilNorm] ?? [];
            $qaList = $qaByCoil[$coilNorm] ?? [];
            $qaItem = !empty($qaList) ? end($qaList) : null; // ล่าสุด
            // ไม่มี worktest → สร้าง 1 แถวว่าง (แล้วค่อย fill จาก qa_sample ได้)
            if (count($wtList) === 0) {
                $row = new \stdClass();
                $row->coil = $coilNorm;
                $row->round = 1;
                $row->inspectedtime = null;
                $row->inspector_name = null;

                $wtOrNull = null;

                $sizeMap = $columnConfig['size']['size_points_map'] ?? null;
                if (is_array($sizeMap) && count($sizeMap)) {
                    $row->size_points = [];
                    $row->size_avg    = null;
                }

                foreach ($columnConfig as $colKey => $cfg) {

                    $val = $this->resolveValueBySources(
                        $wtOrNull,
                        $colKey,
                        $cfg,
                        $wotKeyMap,
                        $qaItem,
                        $qaSample
                    );

                    $row->{$colKey} = $val;
                    $row->{$colKey . '_display'} = $normalizeDefectValue($val, $cfg);

                    if ($colKey === 'hardness') {
                        $src = $qaItem ?: $qaHardnessSample;
                        $row->hardness_points = $this->getHardnessPointsFromQa($src);
                        $row->hardness_avg    = $this->calcHardnessAvgFromQa($src);

                        if ($row->hardness_avg !== null) {
                            $row->hardness = $row->hardness_avg;
                            $row->hardness_display = $row->hardness_avg;
                        }
                    }
                }

                $qaRows[] = $row;
                continue;
            }

            // มี worktest หลายรอบ → หลายแถว
            $round = 0;
            $sizeMap = $columnConfig['size']['size_points_map'] ?? null;

            foreach ($wtList as $wt) {
                $round++;

                $row = new \stdClass();
                $row->coil = $coilNorm;
                $row->round = $round;
                $row->inspectedtime = $wt->inspectedtime ?? null;
                $row->inspector_name = $wt->inspector_name ?? null;

                foreach ($columnConfig as $colKey => $cfg) {
                    $val = $this->resolveValueBySources($wt, $colKey, $cfg, $wotKeyMap, $qaItem, $qaSample);

                    $row->{$colKey} = $val;
                    $row->{$colKey . '_display'} = $normalizeDefectValue($val, $cfg);

                    if ($colKey === 'hardness') {
                        $src = $qaItem ?: $qaHardnessSample;  // ✅ qaItem มีไหม (บางที join เอามา) ถ้าไม่มีก็ใช้ global
                        $row->hardness_points = $this->getHardnessPointsFromQa($src);
                        $row->hardness_avg    = $this->calcHardnessAvgFromQa($src);

                        if ($row->hardness_avg !== null) {
                            $row->hardness = $row->hardness_avg;
                            $row->hardness_display = $row->hardness_avg;
                        }
                    }

                    if ($colKey === 'size' && is_array($sizeMap) && count($sizeMap)) {
                        $pts = $this->getPointsFromWt($wt, $sizeMap);   // ✅ ใช้ $wt จริง
                        $row->size_points = $pts;
                        $row->size_avg    = $this->avg($pts);

                        if ($row->size_avg !== null) {
                            $row->size = $row->size_avg;
                            $row->size_display = $row->size_avg;
                        }
                    }
                }

                $qaRows[] = $row;
            }
        }


        //dump($qaRows);
        // --------------------------------------------------
        // 8) ตัดสินว่าจะโชว์ test อะไร + โหลด spec จาก remark
        // --------------------------------------------------

        $specText   = trim($remark . "\n" . $stepDetail);

        $remarkExtractor = app(RemarkSpecExtractor::class);
        $parsedSpecs     = $remarkExtractor->extractAll($specText);

        $specCache  = $this->buildSpecCache($remark, $stepDetail);
        $colSpec    = [];
        $test = $workTests->first();
        $extractors = $this->getSpecExtractors();

        $displayColumns = [];
        foreach ($columnConfig as $colKey => $cfg) {

            $standard = $usl = $lsl = null;

            if (isset($extractors[$colKey])) {
                $colSpec[$colKey] ??= $extractors[$colKey]([
                    'wowc' => $wowc,
                    'wo' => $wo,
                    'test' => $test,
                    'remark' => $remark,
                    'step_detail' => $stepDetail,
                    'spec_text' => $specText,
                    'spec_cache' => $specCache,
                    'parsed_specs' => $parsedSpecs,
                ]);

                $out = $colSpec[$colKey];
                $standard = $out['standard'] ?? null;
                $usl      = $out['usl'] ?? null;
                $lsl      = $out['lsl'] ?? null;
            }

            $zeroIsOk = (bool)($cfg['zero_is_ok'] ?? false);

            $norm = $this->normalizeSpecBounds($standard, $usl, $lsl);
            $standard = $norm['standard'];
            $usl      = $norm['usl'];
            $lsl      = $norm['lsl'];

            // ✅ ถ้าเป็น defect-like และไม่มี spec ใด ๆ → ตั้ง standard เป็น "ปกติ"
            if (
                $zeroIsOk &&
                ($standard === null || $standard === '') &&
                ($usl === null || $usl === '') &&
                ($lsl === null || $lsl === '')
            ) {
                $standard = 'ปกติ';
            }

            $displayColumns[] = [
                'key'         => $colKey,
                'label'       => $cfg['label'] ?? $colKey,
                'standard'    => $standard,
                'usl'         => $usl,
                'lsl'         => $lsl,
                'qawip_field' => $cfg['qawip_field'] ?? null,
                'decimals'    => $cfg['decimals'] ?? null,
                'zero_is_ok'  => $zeroIsOk,
            ];
        }

        //dd($displayColumns, $workTests);
        // --------------------------------------------------
        // 9) Header
        // --------------------------------------------------
        $header = [
            'mfg_no'        => $wo->workordernumber,
            'brand_name'    => $wo->brand    ?? null,
            'customer_name' => $wo->customer ?? null,
            'qty_kg'        => $wo->qty      ?? null,
            'wo_id'         => $wo->id,
            'step_seq'      => $targetSeq,
            'workcenter'    => $wowc->workcenter_name ?? null,
            'step_label'    => ($wowc->workcenter_name ?? 'Process step')
                . ' (Seq ' . $targetSeq . ')',
            'remark'      => $remark,      // จาก wowc->description
            'step_detail' => $stepDetail,  // จาก wo->notes
        ];

        // --------------------------------------------------
        // 10) Summary (คำนวณ avg/max/min/stdev + ตัดสิน OK/NG ตาม spec)
        // --------------------------------------------------
        $summaryCols = [];
        $labelsByKey = [];

        // map spec by key สำหรับตัดสิน
        $specByKey = [];
        foreach ($displayColumns as $c) {
            $k = $c['key'] ?? null;
            if (!$k) continue;

            $zeroIsOk = (bool)($columnConfig[$k]['zero_is_ok'] ?? false);

            $std = $c['standard'] ?? null;
            $usl = $c['usl'] ?? null;
            $lsl = $c['lsl'] ?? null;

            // ✅ defect-like: ถ้า standard เป็น "ปกติ" ให้ถือเป็น 0
            if ($zeroIsOk && is_string($std)) {
                $t = strtoupper(trim($std));
                if (in_array($t, ['OK', 'ปกติ', 'NORMAL', 'PASS'], true)) {
                    $std = 0;
                }
            }

            $specByKey[$k] = [
                'standard' => is_numeric($std) ? (float)$std : $std,
                'usl'      => is_numeric($usl) ? (float)$usl : null,
                'lsl'      => is_numeric($lsl) ? (float)$lsl : null,
            ];
        }


        foreach ($displayColumns as $col) {
            $key   = $col['key'] ?? null;
            $label = $col['label'] ?? '';

            if (!$key) continue;

            $labelsByKey[$key] = $label;

            $cfg = $columnConfig[$key] ?? null;
            $zeroIsOk = (bool)($cfg['zero_is_ok'] ?? false);
            $decimals = isset($cfg['decimals']) ? (int)$cfg['decimals'] : null;

            $vals = [];
            foreach ($qaRows as $r) {
                $v = $r->{$key} ?? null;
                if ($v === null || $v === '') continue;

                // numeric
                if (is_numeric($v)) {
                    $vals[] = (float)$v;
                    continue;
                }

                // defect-like: "ปกติ/OK" ถือเป็น 0
                if ($zeroIsOk) {
                    $t = strtoupper(trim((string)$v));
                    if (in_array($t, ['OK', 'ปกติ', 'NORMAL', 'PASS'], true)) {
                        $vals[] = 0.0;
                    }
                }
            }

            $stat = $this->calcSummary($vals);

            $spec = $specByKey[$key] ?? ['standard' => null, 'usl' => null, 'lsl' => null];

            $dec = $this->decideBySpecValue(
                $stat['avg'] ?? null,
                $spec['standard'] ?? null,
                $spec['lsl'] ?? null,
                $spec['usl'] ?? null,
                $zeroIsOk
            );

            $summaryCols[$key] = array_merge($stat, [
                'decide' => $dec,
                'spec'   => $spec,
            ]);
        }

        // ---- NG reasons (for tooltip) ----
        $ngReasons = [];

        foreach ($summaryCols as $key => $s) {
            $dec = strtoupper(trim((string)($s['decide'] ?? '')));
            if ($dec === '' || $dec === '-' || $dec === 'OK') {
                continue;
            }

            $label = $labelsByKey[$key] ?? $key;

            $avg = $s['avg'] ?? null;

            $spec = $s['spec'] ?? ['standard' => null, 'lsl' => null, 'usl' => null];
            $std = $spec['standard'] ?? null;
            $lsl = $spec['lsl'] ?? null;
            $usl = $spec['usl'] ?? null;

            $ngReasons[] = [
                'key'   => $key,
                'label' => $label,
                'decide' => $dec,
                'avg'   => $avg,
                'std'   => $std,
                'lsl'   => $lsl,
                'usl'   => $usl,
            ];
        }


        // --------------------------------------------------
        // 11) รวมผลการตัดสินภาพรวม
        // --------------------------------------------------
        //รวมผลการตัดสินภาพรวม
        // --------------------------------------------------
        $allOk     = true;
        $hasDecide = false;

        foreach ($summaryCols as $colSummary) {
            $dec = strtoupper(trim((string) ($colSummary['decide'] ?? '')));

            if ($dec === '') {
                continue;
            }

            $hasDecide = true;

            if ($dec !== 'OK') {
                $allOk = false;
                break;
            }
        }

        $summaryOverall = [
            'pass'   => $hasDecide && $allOk ? 1 : 0,
            'ng'     => $hasDecide && !$allOk ? 1 : 0,
            'remark' => null,
        ];

        $test           = $workTests->first();
        $workTestValues = $this->buildWorkTestValueArrays($test);

        if ($manual) {
            $summaryOverall = [
                'pass'   => $manual->decision === 'PASS' ? 1 : 0,
                'ng'     => $manual->decision === 'NG'   ? 1 : 0,
                'remark' => $manual->remark,
            ];
        }

        $historyRows = IsrManualDecision::query()
            ->where('wo_id', $wo->id)
            ->where('step_seq', $targetSeq)
            ->orderByDesc('id')
            ->get();

        // latest record
        $md = $historyRows->first();

        // preload users (กัน N+1)
        $userIds = $historyRows->pluck('decided_by')->filter()->unique()->values();
        $userMap = $userIds->isEmpty()
            ? collect()
            : User::query()
            ->select('id', 'name', 'email')
            ->whereIn('id', $userIds->toArray())
            ->get()
            ->keyBy('id');

        // history สำหรับ modal (ทำเป็น array)
        $manualHistory = $historyRows->map(function ($r) use ($userMap) {
            $u = $userMap->get($r->decided_by);

            return [
                'decision' => $r->decision,
                'remark'   => $r->remark,
                'by'       => $r->decided_by,
                'by_name'  => $u?->name ?: ($u?->email ?: ('UID: ' . $r->decided_by)),
                'at'       => $r->decided_at,
            ];
        })->values()->all();

        // latest manual สำหรับโชว์ใต้ปุ่ม
        $manual = null;
        if ($md) {
            $u = $userMap->get($md->decided_by);

            $manual = [
                'decision' => $md->decision,
                'remark'   => $md->remark,
                'by'       => $md->decided_by,
                'by_name'  => $u?->name ?: ($u?->email ?: ('UID: ' . $md->decided_by)),
                'at'       => $md->decided_at,
            ];
        }

        return [
            'header'          => $header,
            'wowc'            => $wowc,
            'work_tests'      => $workTests,
            'qawip_rows'      => $qaRows,
            'qa_mech_rows'    => $qaMechRows,
            'spec_columns'    => $specColumns,
            'display_cols'    => $displayColumns,
            'summary_cols'    => $summaryCols,
            'summary_labels'  => $labelsByKey,
            'summary_overall' => $summaryOverall,
            'tests_to_show'   => $testsFlags,
            'remark'          => $remark,
            'step_detail'     => $stepDetail,
            'worktest_values' => $workTestValues,
            'manual_decision' => $manual,
            'manual_history'  => $manualHistory,
            'ng_reasons' => $ngReasons,
        ];
    }

    protected function extractNumberAfterKeyword(string $text, string $keyword): ?float
    {
        // ล้าง comma เช่น 2,500
        $text = str_replace(',', '', $text);

        // หาเลขตัวแรกหลัง keyword (เช่น "ขนาด 3.50" หรือ "ขนาด (mm) 3.50")
        $pattern = '/' . $keyword . '(?:\s*\(.*?\))?[^0-9]*([0-9]+(?:\.[0-9]+)?)/u';

        if (preg_match($pattern, $text, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * ดึง config การตรวจจาก workcentertestval
     * แล้ว map เป็น spec_columns (1,2,3,... ด้านบน)
     *
     * NOTE: ชื่อคอลัมน์เช่น valseq, v1text, v2text ให้เช็คกับ schema จริงอีกที
     */
    protected function buildWorkorderSpecColumns($mfgConn, $wowc): array
    {
        // ถ้าไม่มี workcenter_id ใน $wowc ให้เช็คชื่อฟิลด์ใน DB แล้วแก้ตรงนี้
        $wcId = $wowc->workcenter_id ?? null;
        if (!$wcId) {
            return [];
        }

        $row = $mfgConn->table('workcentertestval')
            ->where('workcenter_id', $wcId)
            ->first();

        if (!$row) {
            return [];
        }

        // สมมติฟิลด์ valseq เก็บรูปแบบ "v1:v2:v3:t1:t2:t3"
        $seq = trim((string) ($row->valseq ?? ''));
        if ($seq === '') {
            return [];
        }

        $keys = array_filter(explode(':', $seq));
        $cols = [];

        foreach ($keys as $k) {
            $k = trim($k);
            if ($k === '') {
                continue;
            }

            // สมมติใช้ฟิลด์ v1text, v2text, t1text, ...
            $labelField = $k . 'text';
            $label      = $row->{$labelField} ?? strtoupper($k);

            $cols[] = [
                'key'       => $k,       // v1, v2, t1, ...
                'label'     => $label,   // ชื่อรายการตรวจจาก master
                'wot_field' => $k,       // field ใน workordertest ที่ควรอ่านค่า (ไว้ใช้ต่อในอนาคต)
            ];
        }

        return $cols;
    }

    protected function buildSlotCandidates(string $mapped, ?string $fallbackGroup = null): array
    {
        $mapped = trim($mapped);
        if ($mapped === '') return [];

        if (!preg_match('/^([a-z]+)(\d+)$/i', $mapped, $m)) return [$mapped];

        $prefix = strtolower($m[1]);
        $idx    = (int)$m[2];

        // เลือก pool ให้ถูกกลุ่ม
        if (in_array($prefix, ['b', 'qb'], true)) {
            $pool = ['b', 'qb', 't', 'qt']; // ✅ defect-like slot
        } elseif (in_array($prefix, ['v', 'qv'], true)) {
            $pool = ['v', 'qv', 't', 'qt']; // ✅ numeric slot
        } elseif (in_array($prefix, ['t', 'qt'], true)) {
            // t/qt ต้องดู fallback_group ของคอลัมน์
            $pool = ($fallbackGroup === 'bqb')
                ? ['b', 'qb', 't', 'qt']
                : ['v', 'qv', 't', 'qt'];
        } else {
            $pool = [$prefix];
        }

        // mapped มาก่อน
        $cands = [$prefix . $idx];
        foreach ($pool as $p) {
            $k = $p . $idx;
            if (!in_array($k, $cands, true)) $cands[] = $k;
        }
        return $cands;
    }


    /**
     * config column ตาม test ที่ต้องแสดง (ไม่อิงแผนกแล้ว)
     *
     * @param array $testsFlags จาก resolveTestsToShow()
     */
    protected function getColumnConfig(array $testsFlags): array
    {
        $all = $this->getAllColumnDefs();

        $cols = [];
        foreach ($testsFlags as $key => $show) {
            if ($show && isset($all[$key])) {
                $cols[$key] = array_merge(['key' => $key], $all[$key]);
            }
        }
        return $cols; // return แบบ associative จะใช้ง่ายใน blade
    }

    protected function resolveWotValueForColumn($wt, string $colKey, array $wotKeyMap, array $cfg = []): mixed
    {
        if (!$wt) return null;

        // 1) master map (workcentertestval) มาก่อนเสมอ
        $mapped = $wotKeyMap[$colKey] ?? null;

        if ($colKey === 'size' && !$mapped) {
            return null; // ให้ไปใช้ wor_msize / wou_msize / wowc_msize / wo_fsize แทน
        }

        if ($mapped) {
            foreach ($this->buildSlotCandidates($mapped) as $k) {
                $v = $wt->{$k} ?? null;
                if ($v !== null && $v !== '') {
                    return is_numeric($v) ? (float)$v : $v;
                }
            }
        }

        // 2) per-column priority candidates (กำหนดเองได้ใน getAllColumnDefs)
        foreach (($cfg['wot_candidates'] ?? []) as $k) {
            $v = $wt->{$k} ?? null;
            if ($v !== null && $v !== '') {
                return is_numeric($v) ? (float)$v : $v;
            }
        }

        // 3) fallback scan ตาม type
        // 3.1 numeric แบบ v/qv/t/qt
        if (($cfg['fallback_group'] ?? null) === 'vqt') {
            $cands = [];

            if ($colKey === 'size') {
                // size ให้ v1-5 มาก่อนเสมอ
                $cands = array_merge(
                    array_map(fn($i) => "v{$i}", range(1, 5)),
                    array_map(fn($i) => "v{$i}", range(6, 10)),
                    array_map(fn($i) => "qv{$i}", range(1, 10)),
                    array_map(fn($i) => "t{$i}", range(1, 10)),
                    array_map(fn($i) => "qt{$i}", range(1, 10)),
                );
            } else {
                for ($i = 1; $i <= 10; $i++) $cands[] = "v{$i}";
                for ($i = 1; $i <= 10; $i++) $cands[] = "qv{$i}";
                for ($i = 1; $i <= 10; $i++) $cands[] = "t{$i}";
                for ($i = 1; $i <= 10; $i++) $cands[] = "qt{$i}";
            }

            foreach ($cands as $k) {
                $v = $wt->{$k} ?? null;
                if ($v !== null && $v !== '' && is_numeric($v)) return (float)$v;
            }
            return null;
        }

        // 3.2 b/qb (defect/pass-fail) : อย่า scan ยาวแบบสุ่มตั้งแต่ b1 เพราะเสี่ยงหยิบผิดความหมาย
        if (($cfg['fallback_group'] ?? null) === 'bqb') {

            // 1) ลอง b/qb ก่อน (สั้นๆ)
            for ($i = 1; $i <= 5; $i++) {
                foreach (["b{$i}", "qb{$i}"] as $k) {
                    $v = $wt->{$k} ?? null;
                    if ($v !== null && $v !== '') return $v; // ไม่บังคับ numeric
                }
            }

            // 2) ค่อยไป t/qt (เผื่อบางไลน์เก็บ defect ใน t/qt)
            for ($i = 1; $i <= 10; $i++) {
                foreach (["t{$i}", "qt{$i}"] as $k) {
                    $v = $wt->{$k} ?? null;
                    if ($v !== null && $v !== '') return $v;
                }
            }

            return null;
        }


        return null;
    }

    protected function getAllColumnDefs(): array
    {
        return [
            'size' => [
                'label' => 'ขนาด',
                'keywords' => ['ขนาด', 'ขนาด (mm)', 'Diameter', 'Dia'],
                'qawip_field' => 'actsize',
                'value_sources' => ['wot_by_master', 'wor_msize', 'wou_msize', 'wowc_msize', 'wo_fsize'],
                'fallback_group' => 'vqt',
                'decimals' => 3,
            ],

            'length' => [
                'label' => 'ความยาว',
                'keywords' => ['ความยาว', 'Length', 'mlen'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'vqt',
                'decimals' => 0,
            ],

            'roundness' => [
                'label' => 'ความกลม',
                'keywords' => ['ความกลม', 'Roundness', 'Oval'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'vqt',
                'decimals' => 3,
            ],

            'speed' => [
                'label' => 'ความเร็ว',
                'keywords' => ['ความเร็ว', 'Speed', 'm/min', 'mpm', 'RPM'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'vqt',
                'decimals' => 0,
            ],

            'hardness' => [
                'label' => 'Hardness',
                'keywords' => ['Hardness', 'HV', 'HRC', 'ความแข็ง', 'แข็ง'],
                'qawip_field' => null,
                'value_sources' => ['qa_sample'],
                'decimals' => 0,
            ],

            'scratch' => [
                'label' => 'รอย',
                'keywords' => ['รอย', 'Scratch', 'Mark'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'decimals' => null,
                'zero_is_ok' => true,
            ],

            'roller' => [
                'label' => 'ลูกกลิ้ง',
                'keywords' => ['ลูกกลิ้ง', 'Roller'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'decimals' => null,
                'zero_is_ok' => true,
            ],

            'dryer' => [
                'label' => 'ไดร์',
                'keywords' => ['ไดร์', 'Dry', 'Dryer'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'decimals' => null,
                'zero_is_ok' => true,
            ],

            // Surface P1-P5 (แนะนำทำเป็น 5 คอลัมน์แยก)
            'surface_p1' => [
                'label' => 'Surface P1',
                'keywords' => ['P1', 'Surface P1', 'ผิว P1'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'zero_is_ok' => true,
            ],
            'surface_p2' => [
                'label' => 'Surface P2',
                'keywords' => ['P2', 'Surface P2', 'ผิว P2'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'zero_is_ok' => true,
            ],
            'surface_p3' => [
                'label' => 'Surface P3',
                'keywords' => ['P3', 'Surface P3', 'ผิว P3'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'zero_is_ok' => true,
            ],
            'surface_p4' => [
                'label' => 'Surface P4',
                'keywords' => ['P4', 'Surface P4', 'ผิว P4'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'zero_is_ok' => true,
            ],
            'surface_p5' => [
                'label' => 'Surface P5',
                'keywords' => ['P5', 'Surface P5', 'ผิว P5'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'bqb',
                'zero_is_ok' => true,
            ],

            // W/H (ถ้าเป็นกว้าง/สูง หรือ Width/Height)
            'w' => [
                'label' => 'W',
                'keywords' => ['W', 'Width', 'กว้าง'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'vqt',
                'decimals' => 3,
            ],
            'h' => [
                'label' => 'H',
                'keywords' => ['H', 'Height', 'สูง'],
                'value_sources' => ['wot_by_master'],
                'fallback_group' => 'vqt',
                'decimals' => 3,
            ],


            'tensile' => [
                'label' => 'Tensile',
                'keywords' => ['Tensile', 'TS'],
                'qawip_field' => 'ts',
                'value_sources' => ['qa_sample'],
                'decimals' => 0,
            ],
            'elongation' => [
                'label' => 'Elongation',
                'keywords' => ['Elongation', 'EL', 'ยืดตัว'],
                'qawip_field' => 'el',
                'value_sources' => ['qa_sample'],
                'decimals' => 0,
            ],

            'roughness' => [
                'label' => 'Ra',
                'keywords' => ['Ra', 'Roughness', 'ความเรียบผิว'],
                'value_sources' => ['wot_by_master'],   // ถ้ามีเก็บใน workordertest
                'fallback_group' => 'vqt',
                'decimals' => 3,
            ],

        ];
    }


    protected function mapWotKeysByMaster(array $columnConfig, array $specColumns): array
    {
        $map = [];

        foreach ($specColumns as $sc) {
            $lbl = trim((string)($sc['label'] ?? ''));
            $k   = trim((string)($sc['key'] ?? ''));
            if ($lbl === '' || $k === '') continue;

            foreach ($columnConfig as $colKey => $cfg) {
                foreach (($cfg['keywords'] ?? []) as $kw) {
                    if ($this->keywordHit($lbl, (string)$kw)) {
                        $map[$colKey] ??= $k; // เจอแล้วไม่ต้องทับ
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    protected function keywordHit(string $label, string $kw): bool
    {
        $label = trim($label);
        $kw    = trim($kw);
        if ($kw === '') return false;

        // keyword สั้นมาก เสี่ยงชนคำอื่น (Dia, TS, EL) -> ต้องเป็นทั้งคำ
        if (mb_strlen($kw) <= 3) {
            $pattern = '/\b' . preg_quote($kw, '/') . '\b/iu';
            return preg_match($pattern, $label) === 1;
        }

        // keyword ปกติ ใช้ contains ได้
        return mb_stripos($label, $kw) !== false;
    }

    protected function isPlausibleWotValue(string $colKey, $val, ?object $wt): bool
    {
        if ($val === null || $val === '') return false;

        // เฉพาะ size: ต้องเป็นตัวเลข และต้องใกล้ reference size
        if ($colKey === 'size') {
            if (!is_numeric($val)) return false;
            $v = (float)$val;

            // กันค่าจิ๋วแบบ roundness หลุดมา (0.002)
            if ($v <= 0) return false;

            // หา reference size จากแหล่งที่เชื่อถือได้กว่า
            $ref = null;
            if ($wt) {
                foreach (['wip_msize', 'wou_msize', 'msize', 'wo_fsize'] as $f) {
                    $x = $wt->{$f} ?? null;
                    if ($x !== null && $x !== '' && is_numeric($x)) {
                        $ref = (float)$x;
                        break;
                    }
                }
            }

            // ถ้ามี ref -> ต้องใกล้พอสมควร (ปรับ threshold ได้)
            if ($ref !== null) {
                // ต่างเกิน 0.2mm (หรือ 5% ของ ref) ถือว่าหลุด
                $absDiff = abs($v - $ref);
                $pctDiff = $absDiff / max($ref, 0.0001);
                if ($absDiff > 0.2 && $pctDiff > 0.05) return false;
            }

            return true;
        }

        return true;
    }



    /**
     * แปลง qawipitems เป็นโครงสร้างผลทดสอบ mechanical (Act. Size / PP / TS / YS / EL / R1–R5)
     */
    protected function buildQaWipMechanical($qawipItems): array
    {
        $rows = [];

        foreach ($qawipItems as $row) {
            $coil = trim((string)($row->coil ?? $row->wipitemnumber ?? ''));
            if ($coil === '') {
                continue;
            }

            $rows[] = [
                'coil'     => str_pad($coil, 3, '0', STR_PAD_LEFT),
                'act_size' => $row->actsize ?? null,
                'pp'       => $row->pp      ?? null,
                'ts'       => $row->ts      ?? null,
                'ys'       => $row->ys      ?? null,
                'el'       => $row->el      ?? null,
                'r1'       => $row->r1      ?? null,
                'r2'       => $row->r2      ?? null,
                'r3'       => $row->r3      ?? null,
                'r4'       => $row->r4      ?? null,
                'r5'       => $row->r5      ?? null,
            ];
        }

        return $rows;
    }

    /**
     * ดึง spec ต่าง ๆ จาก description (ยังไม่ได้ใช้เยอะ แต่เก็บไว้เผื่อ)
     */
    protected function extractSpecFromDescription(string $desc): array
    {
        $desc = trim(preg_replace('/\s+/u', ' ', $desc));

        $spec = [
            'v1'  => null,
            'v2'  => null,
            'v3'  => null,
            'v4'  => null,
            'v5'  => null,
            'v6'  => null,
            'v7'  => null,
            'v8'  => null,
            'v9'  => null,
            'v10' => null,
            'mlen' => null,
            'size' => [
                'nominal' => null,
                'plus'    => null,
                'minus'   => null,
            ],
        ];

        // 1) ขนาด 6.75 +0.015/-0.015
        if (preg_match('/ขนาด[^0-9]*([\d\.]+)\s*\+([\d\.]+)\/-([\d\.]+)/u', $desc, $m)) {
            $spec['size']['nominal'] = (float) $m[1];
            $spec['size']['plus']    = (float) $m[2];
            $spec['size']['minus']   = (float) $m[3];

            for ($i = 1; $i <= 5; $i++) {
                $spec["v{$i}"] = $spec['size'];
            }
        }

        // 2) ความกลม v6
        if (preg_match('/ความกลม[^0-9]*([\d\.]+)/u', $desc, $m)) {
            $spec['v6'] = (float) $m[1];
        }
        if (preg_match('/v6\s*([\d\.]+)/u', $desc, $m)) {
            $spec['v6'] = (float) $m[1];
        }

        // 3) ความยาว v7 / mlen
        if (preg_match('/ความยาว[^0-9]*([\d\.]+)/u', $desc, $m)) {
            $spec['v7'] = (float) $m[1];
        }
        if (preg_match('/v7\s*([\d\.]+)/u', $desc, $m)) {
            $spec['v7'] = (float) $m[1];
        }
        if (preg_match('/mlen\s*([\d\.]+)/u', $desc, $m)) {
            $spec['mlen'] = (float) $m[1];
            $spec['v7']   = (float) $m[1];
        }

        // 4) พ่นสี v8
        if (preg_match('/v8\s*([\d\.]+)/u', $desc, $m)) {
            $spec['v8'] = (float) $m[1];
        }

        // 5) ความตรง v9
        if (preg_match('/v9\s*([\d\.]+)/u', $desc, $m)) {
            $spec['v9'] = (float) $m[1];
        }
        if (preg_match('/ความตรง[^0-9]*([\d\.]+)/u', $desc, $m)) {
            $spec['v9'] = (float) $m[1];
        }

        // 6) chamfer v10
        if (preg_match('/v10\s*([\d\.]+)/u', $desc, $m)) {
            $spec['v10'] = (float) $m[1];
        }
        return $spec;
    }

    /**
     * ดึงช่วง Tensile จาก description เช่น "Tensile : 700-750 N/mm2"
     */
    protected function extractTensileRange(string $desc): ?string
    {
        if (preg_match('/Tensile\s*:\s*([\d\.]+\s*-\s*[\d\.]+)/u', $desc, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * ดึงบรรทัดสเปกที่มี keyword ที่กำหนด เช่น "Tensile", "Elongation"
     */
    protected function extractSpecLine(string $remark, string $keyword): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $remark);
        foreach ($lines as $line) {
            if (mb_stripos($line, $keyword) !== false) {
                return trim($line);
            }
        }
        return null;
    }

    protected function extractRangeAfterKeyword(string $text, array $keywords): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/u', (string)$text);

        foreach ($lines as $line) {
            $lineTrim = trim($line);
            if ($lineTrim === '') continue;

            foreach ($keywords as $kw) {
                // เจอ keyword ในบรรทัดเดียวกัน
                if (mb_stripos($lineTrim, $kw) !== false) {

                    // เอาเฉพาะส่วน "หลัง keyword" เพื่อตัดตัวเลขอื่นก่อนหน้าออก
                    $pos = mb_stripos($lineTrim, $kw);
                    $after = trim(mb_substr($lineTrim, $pos + mb_strlen($kw)));

                    // ตัด :, =, - ที่ติดมา
                    $after = ltrim($after, " \t:：=-");

                    // ดึงช่วงตัวเลขตัวแรก เช่น 1300-1500
                    if (preg_match('/([\d\.]+)\s*-\s*([\d\.]+)/u', $after, $m)) {
                        return trim($m[1] . '-' . $m[2]);
                    }

                    // fallback: ถ้าเป็น > หรือ < หรือเลขเดี่ยว
                    if (preg_match('/([<>≤≥]?\s*[\d\.]+)/u', $after, $m2)) {
                        return trim($m2[1]);
                    }
                }
            }
        }

        return null;
    }


    /**
     * Normalize spec bounds: some tables store tolerance (+/-) instead of absolute limits.
     * If usl/lsl are small compared to standard (e.g., size tol 0.000 / -0.010),
     * convert to absolute limits: LSL = standard + lsl, USL = standard + usl.
     *
     * @return array{standard:mixed, usl:?float, lsl:?float}
     */
    protected function normalizeSpecBounds($standard, $usl, $lsl): array
    {
        // keep original standard (can be string like "240-280")
        $stdNum = (is_numeric($standard) ? (float)$standard : null);

        $uslNum = (is_numeric($usl) ? (float)$usl : null);
        $lslNum = (is_numeric($lsl) ? (float)$lsl : null);

        if ($stdNum !== null) {
            // If both bounds look like tolerance values (small), convert to absolute.
            $isTol = false;
            if ($uslNum !== null && $lslNum !== null) {
                $isTol = (abs($uslNum) <= 50 && abs($lslNum) <= 50 && ($uslNum <= 50) && ($lslNum >= -50));
                // For size, tolerance is typically < 1; for length, absolute limits are large.
                if (abs($uslNum) <= 10 && abs($lslNum) <= 10) {
                    $isTol = true;
                } elseif ($stdNum <= 20 && abs($uslNum) <= 2 && abs($lslNum) <= 2) {
                    $isTol = true;
                }
            }

            if ($isTol) {
                $uslNum = ($uslNum !== null) ? $stdNum + $uslNum : null;
                $lslNum = ($lslNum !== null) ? $stdNum + $lslNum : null;
            }
        }

        return ['standard' => $standard, 'usl' => $uslNum, 'lsl' => $lslNum];
    }

    protected function getSpecExtractors(): array
    {
        return [

            /* ===================== SIZE ===================== */
            'size' => function ($ctx) {
                $wowc = $ctx['wowc'];
                if ($wowc->msize !== null) {
                    return [
                        'standard' => $wowc->msize,
                        'usl'      => $wowc->msizetolp,
                        'lsl'      => $wowc->msizetolm,
                    ];
                }

                // 2) ใช้ service เฉพาะกรณีเป็น +a/-b (nominal)
                $p = $ctx['parsed_specs']['size'] ?? null;
                if ($p && $p['confidence'] >= 0.85 && $p['std'] !== null) {
                    return [
                        'standard' => $p['std'],
                        'usl'      => $p['usl'],
                        'lsl'      => $p['lsl'],
                    ];
                }

                // 3) fallback เดิม
                $seg = $this->extractSpecSegment($ctx['remark'] ?? '', 'ขนาด');
                $parsed = $this->parseDimWithTolerance($seg);
                return [
                    'standard' => $parsed['standard'],
                    'usl'      => $parsed['usl'],
                    'lsl'      => $parsed['lsl'],
                ];

                return compact('standard', 'usl', 'lsl');
            },

            /* ===================== LENGTH ===================== */
            'length' => function ($ctx) {
                // 1) service ก่อน
                $p = $ctx['parsed_specs']['length'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                // 2) logic เดิม (คงไว้ เพราะ length format พิเศษมาก)
                $specText = $ctx['spec_text'] ?? '';
                $txt = $this->normalizeRemark($specText);
                $txt = str_replace([',', "\r", "\n"], '', $txt);
                $txt = str_replace(["–", "—"], "-", $txt);

                $standard = $usl = $lsl = null;

                if (preg_match('/ความยาว[^0-9]*([0-9.]+).*?\+([0-9.]+).*?-([0-9.]+)/u', $txt, $m)) {
                    $std = (float)$m[1];
                    $standard = $std;
                    $usl = $std + (float)$m[2];
                    $lsl = $std - (float)$m[3];
                } elseif (preg_match('/ความยาว[^0-9]*([0-9.]+)/u', $txt, $m)) {
                    $standard = (float)$m[1];
                }

                if ($standard === null && isset($ctx['test']->std_mlen)) {
                    $standard = is_numeric($ctx['test']->std_mlen)
                        ? (float)$ctx['test']->std_mlen
                        : $ctx['test']->std_mlen;
                }

                return compact('standard', 'usl', 'lsl');
            },

            /* ===================== ROUGHNESS ===================== */
            'roughness' => function ($ctx) {
                $txt = $this->normalizeRemark(($ctx['remark'] ?? '') . ' ' . ($ctx['step_detail'] ?? ''));

                if (preg_match('/ความกลม\s*[:：\-–—]?\s*([<>]?\s*\d+(?:\.\d+)?)/u', $txt, $m)) {
                    $parsed = $this->parseSpecValueRobust($m[1]);
                    return [
                        'standard' => $parsed['standard'],
                        'usl' => $parsed['usl'],
                        'lsl' => $parsed['lsl'],
                    ];
                }

                // fallback test
                if (isset($ctx['test']->std_roundness)) {
                    return [
                        'standard' => (float)$ctx['test']->std_roundness,
                        'usl' => null,
                        'lsl' => null,
                    ];
                }

                return ['standard' => null, 'usl' => null, 'lsl' => null];
            },

            /* ===================== TENSILE ===================== */
            'tensile' => function ($ctx) {
                $p = $ctx['parsed_specs']['tensile'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                $cached = $ctx['spec_cache']['tensile'] ?? null;
                if ($cached) {
                    return [
                        'standard' => $cached['standard'] ?? null,
                        'usl' => $cached['usl'] ?? null,
                        'lsl' => $cached['lsl'] ?? null,
                    ];
                }

                $seg = $this->extractSpecSegmentByKeyword(
                    ($ctx['remark'] ?? '') . ' ' . ($ctx['step_detail'] ?? ''),
                    ['Tensile', 'TS', 'เทนไซล์'],
                    'N/mm'
                );
                $parsed = $this->parseSpecValueRobust($seg);

                return ['standard' => $parsed['standard'], 'usl' => $parsed['usl'], 'lsl' => $parsed['lsl']];
            },

            /* ===================== SPEED ===================== */
            'speed' => function ($ctx) {
                $p = $ctx['parsed_specs']['speed'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                $seg = $this->extractSpecSegmentByKeyword(
                    $ctx['spec_text'] ?? '',
                    ['ความเร็ว', 'Speed', 'm/min', 'mpm', 'ม/นาที'],
                    'm'
                );
                $parsed = $this->parseSpecValueRobust($seg);

                return [
                    'standard' => is_numeric($parsed['standard']) ? (float)$parsed['standard'] : $parsed['standard'],
                    'usl' => $parsed['usl'],
                    'lsl' => $parsed['lsl'],
                ];
            },

            /* ===================== COIL DIA ===================== */
            'coil_dia' => function ($ctx) {
                $p = $ctx['parsed_specs']['coil_dia'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                $seg = $this->extractSpecSegmentByKeyword(
                    ($ctx['remark'] ?? '') . ' ' . ($ctx['step_detail'] ?? ''),
                    ['ขนาดวงลวด', 'วงลวด', 'coil', 'coil dia'],
                    'mm'
                );
                $parsed = $this->parseSpecValueRobust($seg);

                return ['standard' => $parsed['standard'], 'usl' => $parsed['usl'], 'lsl' => $parsed['lsl']];
            },

            /* ===================== ELONGATION ===================== */
            'elongation' => function ($ctx) {
                $p = $ctx['parsed_specs']['elongation'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                $cached = $ctx['spec_cache']['elongation'] ?? null;
                if ($cached) {
                    return [
                        'standard' => $cached['standard'] ?? null,
                        'usl' => $cached['usl'] ?? null,
                        'lsl' => $cached['lsl'] ?? null,
                    ];
                }

                $seg = $this->extractSpecSegmentByKeyword(
                    ($ctx['remark'] ?? '') . ' ' . ($ctx['step_detail'] ?? ''),
                    ['Elongation', 'EL', 'ยืดตัว'],
                    '%'
                );
                $parsed = $this->parseSpecValueRobust($seg);

                return ['standard' => $parsed['standard'], 'usl' => $parsed['usl'], 'lsl' => $parsed['lsl']];
            },

            /* ===================== HARDNESS ===================== */
            'hardness' => function ($ctx) {
                $p = $ctx['parsed_specs']['hardness'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                $seg = $this->extractSpecSegmentByKeyword(
                    ($ctx['remark'] ?? '') . ' ' . ($ctx['step_detail'] ?? ''),
                    ['Hardness', 'HV', 'HRC', 'ความแข็ง'],
                    'HV'
                );
                $parsed = $this->parseSpecValueRobust($seg);

                return ['standard' => $parsed['standard'], 'usl' => $parsed['usl'], 'lsl' => $parsed['lsl']];
            },

            /* ===================== ROUNDNESS ===================== */
            'roundness' => function ($ctx) {
                $p = $ctx['parsed_specs']['roundness'] ?? null;
                if ($p && ($p['confidence'] ?? 0) >= 0.70) {
                    return ['standard' => $p['std'], 'usl' => $p['usl'], 'lsl' => $p['lsl']];
                }

                $txt = $this->normalizeRemark(($ctx['remark'] ?? '') . ' ' . ($ctx['step_detail'] ?? ''));

                if (preg_match('/ความกลม\s*([<>]?\s*\d+(?:\.\d+)?)/u', $txt, $m)) {
                    $parsed = $this->parseSpecValueRobust($m[1]);
                    return ['standard' => $parsed['standard'], 'usl' => $parsed['usl'], 'lsl' => $parsed['lsl']];
                }

                if (isset($ctx['test']->std_roundness)) {
                    return ['standard' => (float)$ctx['test']->std_roundness, 'usl' => null, 'lsl' => null];
                }

                return ['standard' => null, 'usl' => null, 'lsl' => null];
            },
        ];
    }



    /**
     * แยกค่าสเปกออกเป็น standard / LSL / USL จากข้อความเช่น
     * "700-750 N/mm2", ">8%", "<0.10 mm"
     *
     * @return array{standard: ?string, lsl: ?float, usl: ?float}
     */
    protected function parseSpecValue(?string $text): array
    {
        $text = trim((string)$text);

        $result = ['standard' => $text === '' ? null : $text, 'lsl' => null, 'usl' => null];
        if ($text === '') return $result;
        // 800 +/-30  หรือ 800 ±30
        if (preg_match('/([\d\.]+)\s*(?:\+\/-|\±)\s*([\d\.]+)/u', $text, $m)) {
            $std = (float)$m[1];
            $tol = (float)$m[2];
            return [
                'standard' => $m[1] . '+/-' . $m[2],
                'lsl' => $std - $tol,
                'usl' => $std + $tol,
            ];
        }

        // 700-750
        if (preg_match('/([\d\.]+)\s*-\s*([\d\.]+)/u', $text, $m)) {
            $result['lsl'] = (float)$m[1];
            $result['usl'] = (float)$m[2];
            $result['standard'] = $m[1] . '-' . $m[2];
            return $result;
        }

        // >8 หรือ ≥8
        if (preg_match('/[>≥]\s*([\d\.]+)/u', $text, $m)) {
            $result['lsl'] = (float)$m[1];
            $result['standard'] = '>' . $m[1];
            return $result;
        }

        // <0.10 หรือ ≤0.10
        if (preg_match('/[<≤]\s*([\d\.]+)/u', $text, $m)) {
            $result['usl'] = (float)$m[1];
            $result['standard'] = '<' . $m[1];
            return $result;
        }

        // เลขเดี่ยว
        if (preg_match('/([\d\.]+)/u', $text, $m)) {
            $result['standard'] = $m[1];
            return $result;
        }

        return $result;
    }

    /**
     * ตัดสินใจว่าต้องโชว์รายการตรวจอะไรบ้าง
     */
    protected function resolveTestsToShow($wowc, ?string $remark = null, ?string $stepDetail = null): array
    {
        // default: ปิดหมดก่อน
        $flags = [
            'size'       => false, // ขนาด
            'length'     => false,
            'speed'      => false,
            'time'       => false,
            'tensile'    => false, // Tensile
            'elongation' => false, // Elongation
            'hardness'   => false, // Hardness
            'straight'   => false, // ความตรง
            'roundness'  => false, // ความกลม
            'chamfer'    => false, // Chamfer
            'appearance' => false, // สภาพภายนอก/ผิว
            'scratch'    => false, // รอย / scratch
            'roughness'  => false, // Ra
            'magnet'     => false, // ค่าแม่เหล็ก
            'ys'         => false, // Yield strength
            'pp'         => false, // PP
        ];

        $wcName     = trim((string)($wowc->workcenter_name ?? $wowc->description ?? ''));
        $remark     = $this->normalizeRemark((string)($remark ?? ''));
        $stepDetail = $this->normalizeRemark((string)($stepDetail ?? ''));
        $ctx        = mb_strtolower($remark . ' ' . $stepDetail);

        // ✅ CGM: จากหมายเหตุให้ใช้เป็น spec เฉย ๆ ไม่ต้องโชว์ความกลม
        if ($this->strContainsAny($wcName, ['CGM'])) {
            $flags['roundness'] = false;
        }

        // 1) Base logic ตาม workcenter ---------------------------------
        if ($this->strContainsAny($wcName, ['รีด', 'rolling'])) {
            $flags['size']    = true;
            $flags['tensile'] = true;
        }

        if ($this->strContainsAny($wcName, ['polishing', 'ขัด'])) {
            $flags['size']       = true;
            $flags['appearance'] = true;
        }

        if ($this->strContainsAny($wcName, ['combine'])) {
            $flags['size'] = true;
        }

        if ($this->strContainsAny($wcName, ['two rollers', 'straightening', 'ดัดตรง'])) {
            $flags['size']     = true;
            $flags['straight'] = true;
        }

        // 2) ปรับตามคำใน remark + stepDetail ----------------------------

        $lines = preg_split('/\r\n|\r|\n/u', $ctx);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // ✅ SIZE (ขนาด)
            if ($this->hasKeyword($line, [
                '/\b(ขนาด|diameter|dia|size)\b/u',
                '/ขนาด\s*\(mm\)/u',
            ])) {
                $flags['size'] = true;
            }

            // ✅ LENGTH (ความยาว) — สำคัญ: อยู่บรรทัดเดียวกับขนาดก็ต้องติด
            if ($this->strContainsAny($ctx, ['ความยาว', 'length'])) {
                $flags['length'] = true;
            }

            // ✅ SPEED / TIME (จากพวก workcentertestval ที่เป็น Speed/เวลา)
            if ($this->hasKeyword($line, [
                '/\b(speed|ความเร็ว)\b/u',
            ])) {
                $flags['speed'] = true;
            }
            if ($this->hasKeyword($line, [
                '/\b(เวลา|time)\b/u',
            ])) {
                $flags['time'] = true;
            }

            // ✅ Tensile (กัน ts ชนมั่ว ๆ ด้วย boundary)
            if ($this->hasKeyword($line, [
                '/\b(tensile)\b/u',
                '/\bts\b/u',
                '/เทนไซล์|ดึงขาด/u',
            ])) {
                $flags['tensile'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\b(elongation)\b/u',
                '/\bel\b/u',
                '/ยืดตัว/u',
            ])) {
                $flags['elongation'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\b(hardness|hv|hrc)\b/u',
                '/แข็ง/u',
            ])) {
                $flags['hardness'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\b(straightness|bow|camber)\b/u',
                '/ความตรง/u',
            ])) {
                $flags['straight'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\b(roundness|oval)\b/u',
                '/ความกลม/u',
            ])) {
                $flags['roundness'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\bchamfer\b/u',
                '/\bc1\b|\bc2\b/u',
                '/ซีหนึ่ง/u',
            ])) {
                $flags['chamfer'] = true;
            }

            if ($this->hasKeyword($line, [
                '/สภาพภายนอก|ผิวภายนอก|\bsurface\b/u',
            ])) {
                $flags['appearance'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\b(scratch|mark)\b/u',
                '/รอย/u',
            ])) {
                $flags['scratch'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\broughness\b/u',
                '/\bra\b/u',
                '/ความเรียบผิว/u',
            ])) {
                $flags['roughness'] = true;
            }

            if ($this->hasKeyword($line, [
                '/แม่เหล็ก|\bmagnet(ic)?\b/u',
            ])) {
                $flags['magnet'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\bys\b|\byield\b/u',
            ])) {
                $flags['ys'] = true;
            }

            if ($this->hasKeyword($line, [
                '/\bpp\b/u',
            ])) {
                $flags['pp'] = true;
            }
        }
        return $flags;
    }

    /**
     * helper: เช็คว่า string มีคำใดคำหนึ่งใน list หรือไม่ (case-insensitive)
     */
    protected function strContainsAny(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);

        foreach ($needles as $needle) {
            $needle = mb_strtolower($needle);
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * รวมค่า v1–v10, b1–b5, qv1–qv10, qb1–qb5, t1–t10, qt1–qt10
     * จาก row ของ workordertest ให้เป็น array เดียว
     *
     * return รูปแบบ:
     * [
     *   'v'  => ['v1' => .., 'v2' => .., ... 'v10' => ..],
     *   'b'  => ['b1' => .., ... 'b5' => ..],
     *   'qv' => ['qv1' => .., ... 'qv10' => ..],
     *   'qb' => ['qb1' => .., ... 'qb5' => ..],
     *   't'  => ['t1' => .., ... 't10' => ..],
     *   'qt' => ['qt1' => .., ... 'qt10' => ..],
     * ]
     */
    protected function buildWorkTestValueArrays($test): array
    {
        if (!$test) {
            return [];
        }

        $groups = [
            'v'  => 10,
            'b'  => 5,
            'qv' => 10,
            'qb' => 5,
            't'  => 10,
            'qt' => 10,
        ];

        $result = [];

        foreach ($groups as $prefix => $max) {
            $result[$prefix] = [];

            for ($i = 1; $i <= $max; $i++) {
                $prop = $prefix . $i;
                $result[$prefix][$prop] = $test->$prop ?? null;
            }
        }

        return $result;
    }

    protected function hasKeyword(string $text, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if ($p === '') continue;
            if (preg_match($p, $text)) return true;
        }
        return false;
    }

    protected function extractSpecSegment(string $remark, string $keyword): ?string
    {
        // เอาบรรทัดที่มี keyword ก่อน
        $line = $this->extractSpecLine($remark, $keyword);
        if (!$line) return null;

        // ตัดเอาหลัง keyword ถึงก่อน "/" หรือจบข้อความ
        // รองรับ: "ขนาด 3.50 ... / ความยาว 2,500 ..."
        $pattern = '/' . preg_quote($keyword, '/') . '\s*([^\/\n\r]+)/u';
        if (preg_match($pattern, $line, $m)) {
            return trim($m[1]);
        }

        // ถ้าไม่มี "/" ก็เอาหลัง keyword ทั้งหมด
        $pattern2 = '/' . preg_quote($keyword, '/') . '\s*(.+)$/u';
        if (preg_match($pattern2, $line, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    protected function parseDimWithTolerance(?string $text): array
    {
        if (!$text) {
            return ['standard' => null, 'usl' => null, 'lsl' => null];
        }

        $s = trim((string)$text);
        if ($s === '') return ['standard' => null, 'usl' => null, 'lsl' => null];

        // normalize comma + dash variants
        $s = str_replace(',', '', $s);
        $s = str_replace(["–", "—"], "-", $s); // en/em dash -> hyphen

        // 1) std +plus -minus  (e.g. 3000 mm +20.00 -0.00)
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*(?:มม|mm)?\s*\+\s*(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)/u', $s, $m)) {
            $std   = (float)$m[1];
            $plus  = (float)$m[2];
            $minus = (float)$m[3];

            return [
                'standard' => $std,
                'usl'      => $std + $plus,
                'lsl'      => $std - $minus,
            ];
        }

        // 2) std ± tol  (e.g. 3000 ± 20)
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*(?:มม|mm)?\s*(?:\+\/-|\±)\s*(-?\d+(?:\.\d+)?)/u', $s, $m)) {
            $std = (float)$m[1];
            $tol = (float)$m[2];
            return [
                'standard' => $std,
                'usl'      => $std + $tol,
                'lsl'      => $std - $tol,
            ];
        }

        // 3) range a-b  (เผื่อเจอแบบ 3000-3020)
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)/u', $s, $m)) {
            return [
                'standard' => "{$m[1]}-{$m[2]}",
                'usl'      => (float)$m[2],
                'lsl'      => (float)$m[1],
            ];
        }

        // 4) fallback: single number
        if (preg_match('/(-?\d+(?:\.\d+)?)/u', $s, $m)) {
            return ['standard' => (float)$m[1], 'usl' => null, 'lsl' => null];
        }

        return ['standard' => null, 'usl' => null, 'lsl' => null];
    }


    protected function normalizeRemark(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        // แก้คำแตก/เว้นวรรคแปลก ๆ
        $s = preg_replace('/El\s*ongation/iu', 'Elongation', $s);
        $s = preg_replace('/Ten\s*sile/iu', 'Tensile', $s);

        // ลดช่องว่าง
        $s = preg_replace('/[ \t]+/u', ' ', $s);

        return trim($s);
    }

    protected function extractSpecSegmentByKeyword(string $text, array $aliases, ?string $preferUnit = null): ?string
    {
        $text = $this->normalizeRemark($text);

        // ✅ กัน segment วิ่งข้ามบรรทัด: แทน \n ด้วย " | " (เป็นตัวคั่น)
        $hay = preg_replace("/\r\n|\n|\r/", " | ", $text);
        $hay = " " . $hay . " "; // padding ให้ ^|space boundary ทำงานง่ายขึ้น

        $candidates = [];

        foreach ($aliases as $kwRaw) {
            $kw = trim((string) $kwRaw);
            if ($kw === '') continue;

            $kwEsc = preg_quote($kw, '/');
            $isShort = mb_strlen($kw) <= 3; // เช่น TS, EL, HV

            // ✅ short keyword ต้องเป็น "ทั้งคำ" เพื่อกันจับมั่ว (เช่น EL ในคำอื่น)
            $kwPattern = $isShort ? '\b' . $kwEsc . '\b' : $kwEsc;

            // ✅ รับทั้ง "KW: xxx" และ "KW xxx"
            // - กันไม่ให้กินข้ามตัวคั่น '|' หรือ ; ) ] 
            // - จำกัดความยาวเพื่อกันลากไปกินของตัวอื่น
            $pattern = '/(?:^|[\(\[\s\|])' . $kwPattern . '\s*(?:[:：\-–—]\s*)?([^;\]\|]{1,160})/iu';


            if (preg_match_all($pattern, $hay, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[1] as $i => $cap) {
                    $seg = trim($cap[0]);
                    if ($seg === '') continue;

                    $pos = $mm[0][$i][1];
                    $score = 0;

                    // ✅ ต้องมีตัวเลข/เครื่องหมาย spec ถึงจะน่าเชื่อ
                    if (preg_match('/\d/u', $seg)) $score += 5;

                    // ✅ รูปแบบ spec ที่พบบ่อยให้คะแนนเพิ่ม
                    if (preg_match('/\d+(?:\.\d+)?\s*[-–]\s*\d+(?:\.\d+)?/u', $seg)) $score += 4;  // 700-1400
                    if (preg_match('/(<=|<|>=|>)\s*\d+(?:\.\d+)?/u', $seg)) $score += 4;          // <12, >=2
                    if (preg_match('/\d+(?:\.\d+)?\s*%/u', $seg)) $score += 4;                    // 2%

                    // ✅ preferUnit (เช่น 'HV', '%', 'N/MM2') ให้ bonus
                    if ($preferUnit && stripos($seg, $preferUnit) !== false) $score += 3;

                    // ✅ ถ้า keyword อยู่ต้น ๆ ให้คะแนนเพิ่มเล็กน้อย
                    $score += max(0, 10 - (int)($pos / 60));

                    // ✅ penalty: keyword สั้นมาก แต่ segment ไม่มีรูปแบบชัดเจน -> ลดความเสี่ยงจับมั่ว
                    if ($isShort && !preg_match('/(\d+(?:\.\d+)?\s*[-–]\s*\d+(?:\.\d+)?|%|<=|<|>=|>)/u', $seg)) {
                        $score -= 3;
                    }

                    $candidates[] = [
                        'seg'   => $seg,
                        'score' => $score,
                        'kw'    => $kw,
                    ];
                }
            }
        }

        if (!$candidates) return null;

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return $candidates[0]['seg'];
    }


    protected function parseSpecValueRobust(?string $text): array
    {
        $text = trim((string)$text);
        if ($text === '') return ['standard' => null, 'lsl' => null, 'usl' => null];

        $clean = str_replace(',', '', $text);
        $clean = str_replace(['(', ')'], '', $clean);

        // 1) range a-b
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*[-–]\s*(-?\d+(?:\.\d+)?)/u', $clean, $m)) {
            return ['standard' => "{$m[1]}-{$m[2]}", 'lsl' => (float)$m[1], 'usl' => (float)$m[2]];
        }

        // 2) a +/- b  หรือ a±b
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*(?:\+\/-|\±)\s*(-?\d+(?:\.\d+)?)/u', $clean, $m)) {
            $std = (float)$m[1];
            $tol = (float)$m[2];
            return ['standard' => "{$m[1]} ±{$m[2]}", 'lsl' => $std - $tol, 'usl' => $std + $tol];
        }

        // 3) explicit comparator (>=, >, <=, <)
        if (preg_match('/(>=|≥|>|<=|≤|<)\s*(-?\d+(?:\.\d+)?)/u', $clean, $m)) {
            $op = $m[1];
            $num = (float)$m[2];
            if ($op === '>' || $op === '>=' || $op === '≥') {
                return ['standard' => "{$op}{$m[2]}", 'lsl' => $num, 'usl' => null];
            }
            return ['standard' => "{$op}{$m[2]}", 'lsl' => null, 'usl' => $num];
        }

        // 4) ✅ special: single number with % (Elongation : 2%) => treat as MIN (LSL)
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*%/u', $clean, $m)) {
            $num = (float)$m[1];
            return ['standard' => "{$m[1]}%", 'lsl' => $num, 'usl' => null];
        }

        // 5) single number
        if (preg_match('/(-?\d+(?:\.\d+)?)/u', $clean, $m)) {
            return ['standard' => $m[1], 'lsl' => null, 'usl' => null];
        }

        return ['standard' => $text, 'lsl' => null, 'usl' => null];
    }


    protected function buildSpecCache(string $remark, string $stepDetail): array
    {
        $remark = $this->normalizeRemark($remark);
        $stepDetail = $this->normalizeRemark($stepDetail);

        $cache = [
            'tensile' => null,
            'elongation' => null,
        ];

        // helper: หา segment จาก remark ก่อน ถ้าไม่เจอค่อยไป stepDetail
        $pickSeg = function (array $aliases, ?string $preferUnit = null) use ($remark, $stepDetail) {
            $seg = $this->extractSpecSegmentByKeyword($remark, $aliases, $preferUnit);
            if ($seg === null) {
                $seg = $this->extractSpecSegmentByKeyword($stepDetail, $aliases, $preferUnit);
            }
            return $seg;
        };

        // Tensile
        $segTs = $pickSeg(['Tensile', 'TS', 'เทนไซล์', 'ดึงขาด'], 'N/mm');
        if ($segTs !== null) {
            $cache['tensile'] = $this->parseSpecValueRobust($segTs);
        }

        // Elongation
        $segEl = $pickSeg(['Elongation', 'EL', 'ยืดตัว'], '%');
        if ($segEl !== null) {
            $cache['elongation'] = $this->parseSpecValueRobust($segEl);
        }

        return $cache;
    }


    protected function resolveValueBySources(
        ?object $wt,
        string $colKey,
        array $cfg,
        array $wotKeyMap,
        $qaItem = null,
        $qaSample = null    // global sample (สุ่ม 1 ตัวใช้ทุก coil)
    ) {
        foreach (($cfg['value_sources'] ?? []) as $src) {
            $val = null;
            if ($colKey === 'size' && !empty($cfg['size_points_map'])) {
                $pts = $this->getPointsFromWt($wt, $cfg['size_points_map']);
                $avg = $this->avg($pts);
                if ($avg !== null) {
                    return $avg;
                }
            } elseif ($src === 'wot_by_master') {
                $val = $wt ? $this->resolveWotValueForColumn($wt, $colKey, $wotKeyMap, $cfg) : null;

                if (!$this->isPlausibleWotValue($colKey, $val, $wt)) {
                    $val = null;
                }
            } elseif ($src === 'wor_msize') {
                $val = $wt->wip_msize ?? null;
            } elseif ($src === 'wou_msize') {
                $val = $wt->wou_msize ?? null;
            } elseif ($src === 'wowc_msize') {
                $val = $wt->msize ?? null;
            } elseif ($src === 'wo_fsize') {
                $val = $wt->wo_fsize ?? null;
            } elseif ($src === 'qa_item') {
                $qField = $cfg['qawip_field'] ?? null;
                $val = ($qField && $qaItem) ? ($qaItem->{$qField} ?? null) : null;
            } elseif ($src === 'qa_sample') {
                if (!$qaSample) {
                    $val = null;
                } else {
                    // ✅ special: hardness ใช้ avg(point1..point5)
                    if ($colKey === 'hardness') {
                        $val = $this->calcHardnessAvgFromQa($qaSample);
                    } else {
                        $qField = $cfg['qawip_field'] ?? null;
                        $val = ($qField) ? ($qaSample->{$qField} ?? null) : null;
                    }
                }
            }

            if ($val !== null && $val !== '') {
                if (($cfg['zero_is_ok'] ?? false) === true) {
                    if ($val === 0 || $val === '0' || $val === 0.0 || $val === '0.000' || $val === '0.00') {
                        return 'ปกติ';
                    }
                }
                return $val;
            }
        }

        return null;
    }


    protected function getHardnessPointsFromQa($qi): array
    {
        if (!$qi) return [];

        $out = [];
        foreach (['point1', 'point2', 'point3', 'point4', 'point5'] as $f) {
            $v = $qi->{$f} ?? null;
            $out[$f] = (is_numeric($v) ? (float)$v : null);
        }
        return $out;
    }

    protected array $defectMaster = [
        -1 => 'ไม่ได้เลือก',
        0 => 'ปกติ',
        1 => 'ลวดเป็นคลื่น',
        2 => 'รอย capstan',
        3 => 'รอยไดร์',
        4 => 'รอยลูกกลิ้ง',
        5 => 'สเก็ต,รอยขูด',
        6 => 'สนิท',
        7 => 'ลวดยืด',
        8 => 'ลวดพันกัน',
        9 => 'วงลวด,วงดีดไม่ดี',
        10 => 'สีผิวปกติ',
    ];

    protected function calcHardnessAvgFromQa($qi): ?float
    {
        if (!$qi) return null;

        $vals = [];
        foreach (['point1', 'point2', 'point3', 'point4', 'point5'] as $f) {
            $v = $qi->{$f} ?? null;
            if (is_numeric($v)) $vals[] = (float)$v;
        }
        if (!$vals) return null;

        return array_sum($vals) / count($vals);
    }

    protected function decideBySpec($value, $standard, $lsl, $usl): string
    {
        return $this->decideBySpecValue($value, $standard, $lsl, $usl, false);
    }

    /**
     * คืนค่าตัวแรกที่ไม่ใช่ null/'' จากรายการที่ส่งมา
     */
    protected function pickFirstNonEmpty(...$vals)
    {
        foreach ($vals as $v) {
            if ($v !== null && $v !== '') return $v;
        }
        return null;
    }

    /**
     * คำนวณ summary: avg/max/min/stdev (sample stdev)
     * @param float[] $vals
     */
    protected function calcSummary(array $vals): array
    {
        $n = count($vals);
        if ($n === 0) {
            return ['avg' => null, 'max' => null, 'min' => null, 'stdev' => null];
        }

        $sum = array_sum($vals);
        $avg = $sum / $n;
        $max = max($vals);
        $min = min($vals);

        $stdev = 0.0;
        if ($n > 1) {
            $sq = 0.0;
            foreach ($vals as $v) $sq += pow($v - $avg, 2);
            $stdev = sqrt($sq / ($n - 1));
        }

        return ['avg' => $avg, 'max' => $max, 'min' => $min, 'stdev' => $stdev];
    }

    /**
     * ตัดสิน OK/NG จาก spec (รองรับ zeroIsOk และ auto tolerance mode)
     */
    protected function decideBySpecValue($value, $standard, $lsl, $usl, bool $zeroIsOk = false): string
    {
        /*if ($zeroIsOk && !is_numeric($standard) && !is_numeric($lsl) && !is_numeric($usl)) {
            // ค่าเฉลี่ยเป็น 0 หรือ null → ถือว่าปกติ
            if ($value === null || (is_numeric($value) && (float)$value == 0.0)) {
                return 'OK';
            }
        }*/

        if ($zeroIsOk) {
            // ถ้า value เป็นข้อความปกติ/OK
            $t = strtoupper(trim((string)$value));
            if (in_array($t, ['OK', 'ปกติ', 'NORMAL', 'PASS'], true)) {
                return 'OK';
            }

            // ถ้า value เป็นตัวเลขและเป็น 0 => ปกติ
            if (is_numeric($value) && (float)$value == 0.0) {
                return 'OK';
            }
        }

        if (!is_numeric($value)) return '-';

        $hasStd = is_numeric($standard);
        $hasLsl = is_numeric($lsl);
        $hasUsl = is_numeric($usl);

        if ($zeroIsOk && (float)$value == 0.0) {
            return 'OK';
        }

        // ไม่มี LSL/USL → เทียบ standard
        if (!$hasLsl && !$hasUsl) {
            if (!$hasStd) return '-';   // ✅ ไม่มี spec ใด ๆ อย่าตัดสิน
            return ((float)$value == (float)$standard) ? 'OK' : 'NG';
        }

        $v = (float)$value;
        $std = $hasStd ? (float)$standard : null;
        $L = $hasLsl ? (float)$lsl : null;
        $U = $hasUsl ? (float)$usl : null;

        // tolerance mode
        $deltaMode = $hasStd && (
            ($hasLsl && abs($L) < 1) || ($hasUsl && abs($U) < 1)
        );

        if ($deltaMode) {
            $diff = $v - $std;
            if ($hasLsl && $diff < $L) return 'NG';
            if ($hasUsl && $diff > $U) return 'NG';
            return 'OK';
        }

        if ($hasLsl && $v < $L) return 'NG';
        if ($hasUsl && $v > $U) return 'NG';
        return 'OK';
    }

    /**
     * หา mapping ของ P1-P5 จาก specColumns (รองรับ label แบบ "P1", "P.1", ".P.1", "P 1")
     * return เช่น ['P1'=>'v1','P2'=>'v2',...]
     */
    protected function mapSizePointsFromSpecColumns(array $specColumns): array
    {
        $map = [];

        foreach ($specColumns as $sc) {
            $label = (string)($sc['label'] ?? '');
            $key   = (string)($sc['key'] ?? '');

            if ($label === '' || $key === '') continue;

            // normalize: ตัดช่องว่าง/จุด/ขีด
            $norm = strtoupper($label);
            $norm = preg_replace('/[\s\.\-_:]/u', '', $norm);

            if (preg_match('/\bP([1-5])\b/u', $norm, $m)) {
                $p = 'P' . $m[1];
                $map[$p] ??= $key;
            }
        }

        // เรียง P1..P5
        $ordered = [];
        foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $p) {
            if (isset($map[$p])) $ordered[$p] = $map[$p];
        }

        return $ordered;
    }


    protected function getPointsFromWt(?object $wt, array $pointMap): array
    {
        if (!$wt || !$pointMap) return [];

        $pts = [];
        foreach ($pointMap as $pf => $vf) {
            $v = $wt->{$vf} ?? null; // เช่น v1
            if (is_numeric($v)) $pts[$pf] = (float)$v;
        }
        return $pts;
    }

    protected function avg(array $nums): ?float
    {
        if (!$nums) return null;
        return array_sum($nums) / count($nums);
    }
}
