<?php

namespace App\Http\Controllers\FormRisk;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionRiskController extends Controller
{
    private string $sqlsrvConn = 'sqlsrv_menam';

    private array $mfgConnections = [
        'Wire' => 'pgsqlmfgw',
        'Plus' => 'pgsqlmfgp',
    ];

    public function index(Request $request)
    {
        $filters = $this->resolveFilters($request);
        $rows = $this->getRiskRows($filters);

        $planners = $this->getBaseRiskRows($filters)
            ->pluck('planner_name')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $stations = $this->getBaseRiskRows($filters)
            ->pluck('notify_work_center_code')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $perPage = max(20, min(200, (int) $request->query('per_page', 100)));
        $page = max(1, (int) $request->query('page', 1));

        $paginated = $this->paginateCollection($rows, $perPage, $page, [
            'path' => route('risk.index'),
            'query' => $request->query(),
        ]);

        $summary = [
            'total' => $rows->count(),
            'warning' => $rows->where('risk_label', 'แจ้งเตือน')->count(),
            'critical' => $rows->where('risk_label', 'วิกฤต')->count(),
            'overdue' => $rows->where('risk_label', 'เลยกำหนด')->count(),
            'unmapped' => $rows->where('planner_map_status', 'Unmapped')->count(),
        ];

        return view('formrisk.index', [
            'rows' => $paginated,
            'allRowsCount' => $rows->count(),
            'filters' => $filters,
            'planners' => $planners,
            'stations' => $stations,
            'summary' => $summary,
            'perPage' => $perPage,
        ]);
    }

    public function dashboard(Request $request)
    {
        $filters = $this->resolveFilters($request);

        $cacheKey = 'production_risk_dashboard_' . md5(json_encode([
            'since' => $filters['since'],
            'dueWithin' => $filters['dueWithin'],
            'site' => $filters['site'],
            'status' => $filters['status'],
        ], JSON_UNESCAPED_UNICODE));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($filters) {
            $rows = $this->getRiskRows($filters);

            $summary = [
                'total' => $rows->count(),
                'warning' => $rows->where('risk_label', 'แจ้งเตือน')->count(),
                'critical' => $rows->where('risk_label', 'วิกฤต')->count(),
                'overdue' => $rows->where('risk_label', 'เลยกำหนด')->count(),
                'not_started' => $rows->where('is_not_started', 1)->count(),
                'unmapped' => $rows->where('planner_map_status', 'Unmapped')->count(),
            ];

            $topRiskStations = $rows
                ->groupBy(fn($r) => $r->notify_work_center_code ?: 'UNKNOWN')
                ->map(function ($group, $station) {
                    $total = $group->count();
                    $overdue = $group->where('risk_label', 'เลยกำหนด')->count();
                    $critical = $group->where('risk_label', 'วิกฤต')->count();
                    $warning = $group->where('risk_label', 'แจ้งเตือน')->count();

                    return [
                        'station' => $station,
                        'count' => $total,
                        'overdue' => $overdue,
                        'critical' => $critical,
                        'warning' => $warning,
                        'overdue_pct' => $total > 0 ? round(($overdue / $total) * 100, 1) : 0,
                    ];
                })
                ->sortByDesc('count')
                ->values()
                ->take(10)
                ->values();

            $topOverdueStations = $rows
                ->where('risk_label', 'เลยกำหนด')
                ->groupBy(fn($r) => $r->notify_work_center_code ?: 'UNKNOWN')
                ->map(fn($group, $station) => [
                    'station' => $station,
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->take(10)
                ->values();

            $topPlanners = $rows
                ->groupBy(fn($r) => $r->planner_name ?: 'Unmapped')
                ->map(function ($group, $planner) {
                    $total = $group->count();
                    $overdue = $group->where('risk_label', 'เลยกำหนด')->count();

                    return [
                        'planner' => $planner,
                        'count' => $total,
                        'overdue' => $overdue,
                        'overdue_pct' => $total > 0 ? round(($overdue / $total) * 100, 1) : 0,
                    ];
                })
                ->sortByDesc('count')
                ->values()
                ->take(10)
                ->values();

            $siteSummary = $rows
                ->groupBy('site')
                ->map(function ($group, $site) {
                    $total = $group->count();
                    $overdue = $group->where('risk_label', 'เลยกำหนด')->count();

                    return [
                        'site' => $site,
                        'total' => $total,
                        'warning' => $group->where('risk_label', 'แจ้งเตือน')->count(),
                        'critical' => $group->where('risk_label', 'วิกฤต')->count(),
                        'overdue' => $overdue,
                        'overdue_pct' => $total > 0 ? round(($overdue / $total) * 100, 1) : 0,
                    ];
                })
                ->values();

            $unmappedStations = $rows
                ->where('planner_map_status', 'Unmapped')
                ->groupBy(fn($r) => $r->notify_work_center_code ?: 'UNKNOWN')
                ->map(fn($group, $station) => [
                    'station' => $station,
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->take(10)
                ->values();

            $notStartedStations = $rows
                ->where('is_not_started', 1)
                ->groupBy(fn($r) => $r->notify_work_center_code ?: 'UNKNOWN')
                ->map(fn($group, $station) => [
                    'station' => $station,
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->take(10)
                ->values();

            $trend7 = $this->buildTrend($rows, 7);
            $trend30 = $this->buildTrend($rows, 30);

            return [
                'summary' => $summary,
                'topRiskStations' => $this->addRank($topRiskStations),
                'topOverdueStations' => $this->addRank($topOverdueStations),
                'topPlanners' => $this->addRank($topPlanners),
                'siteSummary' => $siteSummary,
                'unmappedStations' => $this->addRank($unmappedStations),
                'notStartedStations' => $this->addRank($notStartedStations),
                'trend7' => $trend7,
                'trend30' => $trend30,
                'latestRows' => $rows->take(20)->values(),
            ];
        });

        return view('formrisk.dashboard', [
            'filters' => $filters,
            'summary' => $data['summary'],
            'topRiskStations' => $data['topRiskStations'],
            'topOverdueStations' => $data['topOverdueStations'],
            'topPlanners' => $data['topPlanners'],
            'siteSummary' => $data['siteSummary'],
            'unmappedStations' => $data['unmappedStations'],
            'notStartedStations' => $data['notStartedStations'],
            'trend7' => $data['trend7'],
            'trend30' => $data['trend30'],
            'latestRows' => $data['latestRows'],
        ]);
    }

    private function resolveFilters(Request $request): array
    {
        $today = Carbon::now('Asia/Bangkok')->startOfDay();

        return [
            'since' => $request->query('since', $today->copy()->subDays(60)->toDateString()),
            'dueWithin' => max(0, (int) $request->query('dueWithin', 14)),
            'site' => trim((string) $request->query('site', '')),
            'planner' => trim((string) $request->query('planner', '')),
            'station' => trim((string) $request->query('station', '')),
            'status' => trim((string) $request->query('status', '')),
            'keyword' => trim((string) $request->query('keyword', '')),
        ];
    }

    private function getRiskRows(array $filters): Collection
    {
        $rows = $this->getBaseRiskRows($filters);

        if ($filters['planner'] !== '') {
            $rows = $rows->filter(fn($r) => trim((string) ($r->planner_name ?? '')) === $filters['planner']);
        }

        if ($filters['station'] !== '') {
            $rows = $rows->filter(fn($r) => trim((string) ($r->notify_work_center_code ?? '')) === $filters['station']);
        }

        if ($filters['status'] !== '') {
            $rows = $rows->where('risk_label', $filters['status']);
        }

        if ($filters['keyword'] !== '') {
            $kw = mb_strtolower($filters['keyword']);
            $rows = $rows->filter(function ($r) use ($kw) {
                $hay = mb_strtolower(implode(' ', [
                    (string) ($r->workordernumber ?? ''),
                    (string) ($r->partnumber ?? ''),
                    (string) ($r->description ?? ''),
                    (string) ($r->name ?? ''),
                ]));
                return str_contains($hay, $kw);
            });
        }

        return $rows->values();
    }

    private function getBaseRiskRows(array $filters): Collection
    {
        $baseKey = 'production_risk_base_' . md5(json_encode([
            'since' => $filters['since'],
            'dueWithin' => $filters['dueWithin'],
            'site' => $filters['site'],
        ], JSON_UNESCAPED_UNICODE));

        return Cache::remember($baseKey, now()->addMinutes(10), function () use ($filters) {
            $leadtimeMap = $this->cachedLeadtimeMap();
            $plannerMap = $this->cachedPlannerMap();

            $today = Carbon::now('Asia/Bangkok')->startOfDay();
            $maxDueDate = $filters['dueWithin'] > 0
                ? $today->copy()->addDays($filters['dueWithin'])->toDateString()
                : null;

            $connections = $this->mfgConnections;
            if ($filters['site'] !== '' && isset($this->mfgConnections[$filters['site']])) {
                $connections = [$filters['site'] => $this->mfgConnections[$filters['site']]];
            }

            $rows = collect();

            foreach ($connections as $site => $conn) {
                $rows = $rows->merge(
                    $this->fetchBaseRowsFromConnection($conn, $site, $filters['since'], $maxDueDate)
                );
            }

            $rows = $rows->map(function ($r) use ($leadtimeMap, $plannerMap, $today) {
                $partnumber = strtoupper(trim((string) ($r->partnumber ?? '')));
                $leadtime = max(1, (int) ($leadtimeMap[$partnumber] ?? 7));

                $duedate = !empty($r->duedate)
                    ? Carbon::parse((string) $r->duedate)->startOfDay()
                    : null;

                $warningDays = $leadtime + 2;
                $daysToDue = $duedate ? $today->diffInDays($duedate, false) : null;

                if (!is_numeric($daysToDue)) {
                    $riskLabel = 'ไม่ระบุ';
                    $riskLevel = 99;
                    $shouldAlert = 0;
                } elseif ((int) $daysToDue < 0) {
                    $riskLabel = 'เลยกำหนด';
                    $riskLevel = 3;
                    $shouldAlert = 1;
                } elseif ((int) $daysToDue <= $leadtime) {
                    $riskLabel = 'วิกฤต';
                    $riskLevel = 2;
                    $shouldAlert = 1;
                } elseif ((int) $daysToDue <= $warningDays) {
                    $riskLabel = 'แจ้งเตือน';
                    $riskLevel = 1;
                    $shouldAlert = 1;
                } else {
                    $riskLabel = 'ปกติ';
                    $riskLevel = 0;
                    $shouldAlert = 0;
                }

                $currentCode = $this->normalizeStationCode($r->current_work_center_code ?? null);
                $nextCode = $this->normalizeStationCode($r->next_work_center_code ?? null);
                $firstCode = $this->normalizeStationCode($r->first_work_center_code ?? null);
                $nextFromFirstCode = $this->normalizeStationCode($r->next_from_first_work_center_code ?? null);

                $isNotStarted = empty($r->last_workseq) ? 1 : 0;

                if ($currentCode === '') {
                    $currentCode = $firstCode;
                }

                if ($isNotStarted && $nextCode === '') {
                    $nextCode = $nextFromFirstCode;
                }

                $notifyCode = $currentCode;
                if ($notifyCode === '' || $notifyCode === 'RM') {
                    $notifyCode = $nextCode;
                }
                if ($notifyCode === '') {
                    $notifyCode = $firstCode;
                }

                $map = $plannerMap->get($notifyCode, null);

                $r->leadtime = $leadtime;
                $r->warning_days = $warningDays;
                $r->days_to_due = $daysToDue;
                $r->should_alert = $shouldAlert;
                $r->risk_label = $riskLabel;
                $r->risk_level = $riskLevel;
                $r->is_not_started = $isNotStarted;
                $r->current_work_center_code = $currentCode;
                $r->next_work_center_code = $nextCode;
                $r->notify_work_center_code = $notifyCode;
                $r->step_progress_display = $isNotStarted
                    ? '0/' . (string) ($r->max_workseq ?? 0) . '*'
                    : (string) ($r->step_progress ?? '');

                $r->planner_name = $map['planner_name'] ?? null;
                $r->planner_email = $map['planner_email'] ?? null;
                $r->planner_map_status = !empty($r->planner_email) ? 'Mapped' : 'Unmapped';

                return $r;
            });

            return $rows
                ->where('should_alert', 1)
                ->sortBy([
                    ['risk_level', 'desc'],
                    ['days_to_due', 'asc'],
                    ['duedate', 'asc'],
                ])
                ->values();
        });
    }

    private function fetchBaseRowsFromConnection(string $conn, string $site, string $since, ?string $maxDueDate): Collection
    {
        $sql = "
        WITH candidate_workorders AS (
            SELECT
                wo.id,
                wo.workordernumber,
                wo.dateopen::date AS dateopen,
                wo.reqdate::date AS duedate,
                wo.qty,
                p.partnumber,
                p.description,
                c.name
            FROM workorder wo
            LEFT JOIN customer c ON c.id = wo.customer_id
            LEFT JOIN parts p ON p.id = wo.parts_id
            WHERE wo.dateclose IS NULL
              AND wo.suspended = FALSE
              AND wo.workordernumber !~* '^(\+)?(EX|S)'
              AND wo.workordernumber !~* '\(C\)$'
              AND wo.dateopen IS NOT NULL
              AND wo.dateopen::date >= :since::date
              AND wo.reqdate IS NOT NULL
              " . ($maxDueDate ? "AND wo.reqdate::date <= :max_due_date::date" : "") . "
        ),
        wc_max AS (
            SELECT
                wowc.workorder_id,
                MAX(wowc.workseq) AS max_workseq
            FROM workorderworkcenter wowc
            INNER JOIN candidate_workorders cw ON cw.id = wowc.workorder_id
            GROUP BY wowc.workorder_id
        ),
        wr_last AS (
            SELECT
                t.workorder_id,
                t.workseq AS last_workseq,
                t.workcenter_id
            FROM (
                SELECT
                    wor.workorder_id,
                    wor.workseq,
                    wor.workcenter_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY wor.workorder_id
                        ORDER BY wor.workseq DESC, wor.id DESC
                    ) AS rn
                FROM workorderreceive wor
                INNER JOIN candidate_workorders cw ON cw.id = wor.workorder_id
            ) t
            WHERE t.rn = 1
        ),
        wc_current AS (
            SELECT
                wowc.workorder_id,
                wowc.workseq,
                wowc.workcenter_id
            FROM workorderworkcenter wowc
            INNER JOIN wr_last wl
                ON wl.workorder_id = wowc.workorder_id
               AND wl.last_workseq = wowc.workseq
        ),
        wc_next AS (
            SELECT
                wowc.workorder_id,
                wowc.workseq,
                wowc.workcenter_id
            FROM workorderworkcenter wowc
            INNER JOIN wr_last wl
                ON wl.workorder_id = wowc.workorder_id
               AND wowc.workseq = wl.last_workseq + 1
        ),
        wc_first AS (
            SELECT
                t.workorder_id,
                t.workseq,
                t.workcenter_id
            FROM (
                SELECT
                    wowc.workorder_id,
                    wowc.workseq,
                    wowc.workcenter_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY wowc.workorder_id
                        ORDER BY wowc.workseq ASC, wowc.id ASC
                    ) AS rn
                FROM workorderworkcenter wowc
                INNER JOIN candidate_workorders cw ON cw.id = wowc.workorder_id
            ) t
            WHERE t.rn = 1
        ),
        wc_next_from_first AS (
            SELECT
                wowc.workorder_id,
                wowc.workseq,
                wowc.workcenter_id
            FROM workorderworkcenter wowc
            INNER JOIN wc_first wcf
                ON wcf.workorder_id = wowc.workorder_id
               AND wowc.workseq = wcf.workseq + 1
        )
        SELECT
            :site::text AS site,
            cw.workordernumber,
            cw.dateopen,
            cw.duedate,
            cw.qty,
            cw.partnumber,
            cw.description,
            cw.name,

            wc_first.workcenternumber AS first_work_center_code,
            wcf.workseq AS first_workseq,

            wl.last_workseq,
            wm.max_workseq,

            wc_cur.workcenternumber AS current_work_center_code,
            wc_nxt.workcenternumber AS next_work_center_code,
            wc_nxt_first.workcenternumber AS next_from_first_work_center_code,

            CONCAT(
                COALESCE(wl.last_workseq, 0),
                '/',
                COALESCE(wm.max_workseq, 0)
            ) AS step_progress
        FROM candidate_workorders cw
        LEFT JOIN wr_last wl ON wl.workorder_id = cw.id
        LEFT JOIN wc_max wm ON wm.workorder_id = cw.id

        LEFT JOIN wc_current wcc ON wcc.workorder_id = cw.id
        LEFT JOIN workcenter wc_cur ON wc_cur.id = wcc.workcenter_id

        LEFT JOIN wc_first wcf ON wcf.workorder_id = cw.id
        LEFT JOIN workcenter wc_first ON wc_first.id = wcf.workcenter_id

        LEFT JOIN wc_next wcn ON wcn.workorder_id = cw.id
        LEFT JOIN workcenter wc_nxt ON wc_nxt.id = wcn.workcenter_id

        LEFT JOIN wc_next_from_first wcnf ON wcnf.workorder_id = cw.id
        LEFT JOIN workcenter wc_nxt_first ON wc_nxt_first.id = wcnf.workcenter_id

        WHERE COALESCE(wl.last_workseq, 0) < COALESCE(wm.max_workseq, 0)
        ORDER BY cw.duedate ASC NULLS LAST
        ";

        $bindings = [
            'site' => $site,
            'since' => $since,
        ];

        if ($maxDueDate) {
            $bindings['max_due_date'] = $maxDueDate;
        }

        return collect(DB::connection($conn)->select($sql, $bindings));
    }

    private function cachedLeadtimeMap(): Collection
    {
        return Cache::remember('production_risk_leadtime_map', now()->addMinutes(30), function () {
            return DB::connection($this->sqlsrvConn)
                ->table('production_leadtime_master')
                ->where('is_active', 1)
                ->get(['partnumber', 'avg_production_leadtime'])
                ->mapWithKeys(fn($row) => [
                    strtoupper(trim((string) $row->partnumber)) => max(1, (int) $row->avg_production_leadtime),
                ]);
        });
    }

    private function cachedPlannerMap(): Collection
    {
        return Cache::remember('production_risk_planner_map', now()->addMinutes(30), function () {
            return DB::connection($this->sqlsrvConn)
                ->table('planner_work_centers as pwc')
                ->leftJoin('planners as p', 'p.id', '=', 'pwc.planner_id')
                ->where('pwc.is_active', 1)
                ->select([
                    'pwc.work_center_code',
                    'pwc.work_center_name',
                    'pwc.department_code',
                    'p.planner_name',
                    'p.email as planner_email',
                ])
                ->get()
                ->mapWithKeys(function ($row) {
                    $code = strtoupper(trim((string) ($row->work_center_code ?? '')));
                    return [$code => [[
                        'planner_name' => $row->planner_name,
                        'planner_email' => $row->planner_email,
                        'work_center_name' => $row->work_center_name,
                        'department_code' => $row->department_code,
                    ]]];
                })
                ->map(fn($items) => $items[0]);
        });
    }

    private function buildTrend(Collection $rows, int $days): array
    {
        $start = Carbon::now('Asia/Bangkok')->startOfDay()->subDays($days - 1);

        $bucket = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $bucket[$date] = [
                'date' => $date,
                'warning' => 0,
                'critical' => 0,
                'overdue' => 0,
            ];
        }

        foreach ($rows as $r) {
            if (empty($r->duedate)) {
                continue;
            }

            $due = Carbon::parse((string) $r->duedate)->toDateString();
            if (!isset($bucket[$due])) {
                continue;
            }

            if ($r->risk_label === 'แจ้งเตือน') {
                $bucket[$due]['warning']++;
            } elseif ($r->risk_label === 'วิกฤต') {
                $bucket[$due]['critical']++;
            } elseif ($r->risk_label === 'เลยกำหนด') {
                $bucket[$due]['overdue']++;
            }
        }

        return array_values($bucket);
    }

    private function addRank(Collection $rows): Collection
    {
        return $rows->values()->map(function ($row, $index) {
            $row['rank'] = $index + 1;
            return $row;
        });
    }

    private function normalizeStationCode($value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    private function paginateCollection(Collection $items, int $perPage, int $page, array $options = []): LengthAwarePaginator
    {
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($results, $total, $perPage, $page, $options);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $filters = $this->resolveFilters($request);
        $rows = $this->getRiskRows($filters);

        $fileName = 'production_risk_' . now('Asia/Bangkok')->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            // BOM สำหรับ Excel เปิดภาษาไทยได้ถูก
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'เลขที่ MFG',
                'โรงงาน',
                'วันที่เปิดงาน',
                'กำหนดส่ง',
                'คงเหลือ(วัน)',
                'สถานะ',
                'Part No.',
                'รายละเอียด',
                'ลูกค้า',
                'Lead Time',
                'จำนวนวันแจ้งเตือน',
                'สถานีปัจจุบัน',
                'สถานีถัดไป',
                'สถานีที่ใช้แจ้งเตือน',
                'Planner',
                'สถานะแมพ Planner',
                'ยังไม่เริ่มผลิต',
                'ความคืบหน้าขั้นตอน',
            ]);

            foreach ($rows as $r) {
                fputcsv($handle, [
                    $this->csvText($r->workordernumber),
                    $r->site,
                    $r->dateopen ? Carbon::parse($r->dateopen)->format('d/m/Y') : '',
                    $r->duedate ? Carbon::parse($r->duedate)->format('d/m/Y') : '',
                    $r->days_to_due,
                    $r->risk_label,
                    $this->csvText($r->partnumber),
                    $r->description,
                    $r->name,
                    $r->leadtime,
                    $r->warning_days,
                    $this->csvText($r->current_work_center_code),
                    $this->csvText($r->next_work_center_code),
                    $this->csvText($r->notify_work_center_code),
                    $r->planner_name ?: '-',
                    $r->planner_map_status,
                    $r->is_not_started ? 'ใช่' : 'ไม่ใช่',
                    $this->csvText($r->step_progress_display),
                ]);
            }

            fclose($handle);
        }, $fileName, $headers);
    }

    private function csvText($value): string
    {
        $value = (string) ($value ?? '');
        return "\t" . $value;
    }
}
