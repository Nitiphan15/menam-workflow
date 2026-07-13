<?php

namespace App\Http\Controllers\FormDIE;

use App\Exports\FormDIE\DieTrackingExport;
use App\Http\Controllers\Controller;
use App\Repositories\FormDIE\SuggestRepository;
use App\Services\FormDIE\DieTrackingService;
use App\Services\FormDIE\WoDetailService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class DieTrackingController extends Controller
{
    public function __construct(private DieTrackingService $service) {}

    /**
     * แปลงพารามิเตอร์ connection/site ของผู้ใช้เป็น connection ที่อนุญาตเท่านั้น (W/P)
     * กันไม่ให้ผู้ใช้ใส่ชื่อ connection อื่น (เช่น ERP) แล้วชี้ query ไปฐานอื่น
     */
    private function resolveConn(Request $request): string
    {
        $raw = strtoupper(trim((string) ($request->query('connection') ?? $request->query('site') ?? '')));

        return in_array($raw, ['P', 'PGSQLPCMP'], true) ? 'pgsqlpcmp' : 'pgsqlpcmw';
    }

    /**
     * คืน list ของ connection: เลือก W+P พร้อมกัน (ALL) → ทั้งสองฐาน, ไม่งั้น → ฐานเดียวตาม resolveConn
     */
    private function resolveSites(Request $request): array
    {
        $raw = strtoupper(trim((string) ($request->query('connection') ?? $request->query('site') ?? '')));
        if (in_array($raw, ['ALL', 'BOTH', 'WP', 'W+P'], true)) {
            return ['pgsqlpcmw', 'pgsqlpcmp'];
        }

        return [$this->resolveConn($request)];
    }

    public function index(Request $request)
    {
        $mode = in_array($request->query('mode'), ['workorder', 'date', 'week'], true)
            ? $request->query('mode')
            : 'workorder';

        return view('formdie.dashboard', ['initialMode' => $mode]);
    }

    public function byWorkorder(Request $request)
    {
        $wo = mb_strtoupper(trim((string) $request->query('wo', '')));
        if ($wo === '') {
            return response()->json(['error' => 'workordernumber required'], 422);
        }
        $category = trim((string) $request->query('category', '')) ?: null;
        $description = trim((string) $request->query('description', '')) ?: null;

        $data = Cache::remember("die_track:v9:wo:" . md5(json_encode([$wo, $category, $description])), 300,
            fn() => $this->service->byWorkorder($wo, $category, $description));
        return response()->json($data);
    }

    public function byDate(Request $request)
    {
        $today = Carbon::today()->toDateString();
        // รองรับ legacy single 'date' หรือ range 'date_from' / 'date_to'
        $from = $request->query('date_from', $request->query('date', $today));
        $to   = $request->query('date_to',   $from);
        $sites = $this->resolveSites($request);
        $category = trim((string) $request->query('category', '')) ?: null;
        $description = trim((string) $request->query('description', '')) ?: null;

        try {
            $from = Carbon::parse($from)->toDateString();
            $to   = Carbon::parse($to)->toDateString();
            if ($from > $to) [$from, $to] = [$to, $from];
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid date'], 422);
        }

        $data = Cache::remember("die_track:v12:date:" . md5(json_encode([$from, $to, $sites, $category, $description])), 300,
            fn() => $this->service->byDate($from, $to, $sites, $category, $description));
        return response()->json($data);
    }

    public function byWeek(Request $request)
    {
        $year = (int) $request->query('year', Carbon::today()->year);
        $week = (int) $request->query('week', (int) Carbon::today()->isoWeek);
        $sites = $this->resolveSites($request);
        $category = trim((string) $request->query('category', '')) ?: null;
        $description = trim((string) $request->query('description', '')) ?: null;

        if ($year < 2000 || $year > 2100 || $week < 1 || $week > 53) {
            return response()->json(['error' => 'invalid year/week'], 422);
        }

        $data = Cache::remember("die_track:v12:week:" . md5(json_encode([$year, $week, $sites, $category, $description])), 300,
            fn() => $this->service->byWeek($year, $week, $sites, $category, $description));
        return response()->json($data);
    }

    public function compare(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $from = $request->query('date_from', $request->query('date', $today));
        $to   = $request->query('date_to', $from);
        $sites = $this->resolveSites($request);
        $category = trim((string) $request->query('category', '')) ?: null;

        try {
            $from = Carbon::parse($from)->toDateString();
            $to   = Carbon::parse($to)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid date'], 422);
        }

        $data = Cache::remember("die_track:v11:compare:" . md5(json_encode([$from, $to, $sites, $category])), 300,
            fn() => $this->service->compareDateRanges($from, $to, $sites, $category));
        return response()->json($data);
    }

    public function master(Request $request)
    {
        return view('formdie.master');
    }

    public function dieMaster(Request $request)
    {
        $sites = $this->resolveSites($request);
        $filters = [
            'q'           => $request->query('q'),
            'status'      => $request->query('status'),
            'category'    => $request->query('category'),
            'equiptype'   => $request->query('equiptype'),
            'vendor'      => $request->query('vendor'),
            'description' => $request->query('description'),
        ];
        $data = Cache::remember("die_track:master:v4:" . md5(json_encode([$sites, $filters])), 300,
            fn() => $this->service->dieMaster($sites, array_filter($filters)));
        return response()->json($data);
    }

    public function categories(Request $request)
    {
        $conn = $this->resolveConn($request);
        $etype = trim((string) $request->query('equiptype', 'ไดร์')) ?: 'ไดร์';
        $data = Cache::remember("die_track:categories:" . md5(json_encode([$conn, $etype])), 300,
            fn() => $this->service->categories($conn, $etype));
        return response()->json($data);
    }

    public function suppliers(Request $request)
    {
        $conn = $this->resolveConn($request);
        $etype = trim((string) $request->query('equiptype', 'ไดร์')) ?: 'ไดร์';
        $data = Cache::remember("die_track:suppliers:" . md5(json_encode([$conn, $etype])), 300,
            fn() => $this->service->suppliers($conn, $etype));
        return response()->json($data);
    }

    public function equipTypes(Request $request)
    {
        $conn = $this->resolveConn($request);
        $data = Cache::remember("die_track:equiptypes:{$conn}", 300,
            fn() => $this->service->equipTypes($conn));
        return response()->json($data);
    }

    public function statuses(Request $request)
    {
        $conn = $this->resolveConn($request);
        $etype = trim((string) $request->query('equiptype', 'ไดร์')) ?: 'ไดร์';
        $data = Cache::remember("die_track:statuses:" . md5(json_encode([$conn, $etype])), 300,
            fn() => $this->service->statuses($conn, $etype));
        return response()->json($data);
    }

    public function dieProfile(Request $request, string $equipnumber)
    {
        $conn = $this->resolveConn($request);
        $data = Cache::remember("die_track:profile:v3:{$equipnumber}:{$conn}", 300,
            fn() => $this->service->dieProfile($equipnumber, $conn));
        return response()->json($data);
    }

    public function currentLocation(Request $request)
    {
        $sites = $this->resolveSites($request);
        $filters = [
            'q'         => $request->query('q'),
            'days'      => (int) $request->query('days', 30),
            'category'  => $request->query('category'),
            'equiptype' => $request->query('equiptype'),
        ];
        $data = Cache::remember("die_track:loc:" . md5(json_encode([$sites, $filters])), 300,
            fn() => $this->service->currentLocations($sites, array_filter($filters)));
        return response()->json($data);
    }

    public function topConsumers(Request $request)
    {
        $conn  = $this->resolveConn($request);
        $end   = $request->query('end',   Carbon::today()->toDateString());
        $start = $request->query('start', Carbon::today()->subDays(6)->toDateString());
        $category = trim((string) $request->query('category', '')) ?: null;
        $data  = Cache::remember("die_track:v8:top:" . md5(json_encode([$start, $end, $conn, $category])), 300,
            fn() => $this->service->topConsumers($start, $end, $conn, $category));
        return response()->json($data);
    }

    public function topOutputDies(Request $request)
    {
        $conn  = $this->resolveConn($request);
        $end   = $request->query('end',   Carbon::today()->toDateString());
        // default ย้อนหลัง 1 ปี — "ยอด output สะสม" มีความหมายเมื่อดูช่วงยาว
        $start = $request->query('start', Carbon::today()->subDays(364)->toDateString());
        $category = trim((string) $request->query('category', '')) ?: null;
        $data  = Cache::remember("die_track:v1:output:" . md5(json_encode([$start, $end, $conn, $category])), 300,
            fn() => $this->service->topOutputDies($start, $end, $conn, $category));
        return response()->json($data);
    }

    public function woDetailPage(Request $request)
    {
        $wo = (string) $request->query('wo', '');
        return view('formdie.wo-detail', ['initialWo' => $wo]);
    }

    public function woDetailApi(Request $request, WoDetailService $svc)
    {
        $wo = mb_strtoupper(trim((string) $request->query('wo', '')));
        if ($wo === '') return response()->json(['error' => 'wo required'], 422);

        $data = Cache::remember("die_track:v15:wo_detail:{$wo}", 300, fn() => $svc->detail($wo));
        return response()->json($data);
    }

    public function recentClaims(Request $request, WoDetailService $svc)
    {
        $today = Carbon::today()->toDateString();
        // default = ย้อนหลัง 30 วัน ("รอบ 1 เดือน") แต่รองรับ date_from/date_to ถ้าส่งมา
        $from = $request->query('date_from', Carbon::today()->subDays(29)->toDateString());
        $to   = $request->query('date_to', $today);

        try {
            $from = Carbon::parse($from)->toDateString();
            $to   = Carbon::parse($to)->toDateString();
            if ($from > $to) [$from, $to] = [$to, $from];
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid date'], 422);
        }

        $raw = strtoupper(trim((string) ($request->query('site') ?? $request->query('connection') ?? 'W')));
        $sites = in_array($raw, ['ALL', 'BOTH', 'WP', 'W+P'], true)
            ? ['W', 'P']
            : (in_array($raw, ['P', 'PGSQLPCMP', 'PGSQLP'], true) ? ['P'] : ['W']);

        $data = Cache::remember('die_track:v4:recent_claims:' . md5(json_encode([$from, $to, $sites])), 300,
            fn() => $svc->recentClaims($sites, $from, $to));

        return response()->json($data);
    }

    public function currentMachines(Request $request)
    {
        $raw = strtoupper(trim((string) ($request->query('site') ?? $request->query('connection') ?? 'W')));
        $sites = in_array($raw, ['ALL', 'BOTH', 'WP', 'W+P'], true)
            ? ['W', 'P']
            : (in_array($raw, ['P', 'PGSQLPCMP', 'PGSQLP'], true) ? ['P'] : ['W']);

        // วันที่ขึ้นเครื่อง (last_move_date) — ค่าว่าง = ไม่กรอง (แสดงทั้งหมด)
        $from = trim((string) $request->query('date_from', ''));
        $to   = trim((string) $request->query('date_to', ''));
        try {
            if ($from !== '') $from = Carbon::parse($from)->toDateString();
            if ($to !== '')   $to   = Carbon::parse($to)->toDateString();
            if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid date'], 422);
        }

        $filters = array_filter([
            'category'  => trim((string) $request->query('category', '')) ?: null,
            'q'         => trim((string) $request->query('q', '')) ?: null,
            'date_from' => $from ?: null,
            'date_to'   => $to ?: null,
            // เอาเฉพาะแผนกรีด (DRAWING) ก่อน
            'drawing_only' => true,
        ]);

        $data = Cache::remember('die_track:v4:current_machines:' . md5(json_encode([$sites, $filters])), 300,
            fn() => $this->service->currentMachineDies($sites, $filters));
        $data['date_from'] = $from ?: null;
        $data['date_to']   = $to ?: null;

        return response()->json($data);
    }

    public function materialTrace(Request $request)
    {
        $site   = $request->query('site', 'W');
        $heatno = trim((string) $request->query('heatno', ''));
        $coilno = trim((string) $request->query('coilno', ''));
        $category = trim((string) $request->query('category', '')) ?: null;
        if ($heatno === '' && $coilno === '') {
            return response()->json(['error' => 'heatno or coilno required'], 422);
        }
        $key  = 'die_track:v7:material:' . md5(json_encode([$site, $heatno, $coilno, $category]));
        $data = Cache::remember($key, 300,
            fn() => $this->service->materialTrace($site, $heatno ?: null, $coilno ?: null, $category));
        return response()->json($data);
    }

    public function suggest(Request $request, SuggestRepository $repo, string $type)
    {
        $q    = mb_strtoupper(trim((string) $request->query('q', '')));
        $site = $request->query('site', 'W') === 'P' ? 'P' : 'W';
        if (strlen($q) < 1) return response()->json(['rows' => []]);

        $rows = match ($type) {
            'wo'   => $repo->suggestWorkorder($q, $site),
            'die'  => $repo->suggestDie($q, $site),
            'desc' => $repo->suggestDescription($q, $site),
            'heat' => $repo->suggestHeat($q, $site),
            'coil' => $repo->suggestCoil($q, $site),
            default => [],
        };
        return response()->json(['rows' => $rows]);
    }

    public function idleDies(Request $request)
    {
        $conn = $this->resolveConn($request);
        $days = (int) $request->query('days', 30);
        $category = trim((string) $request->query('category', '')) ?: null;
        $data = Cache::remember("die_track:v7:idle:" . md5(json_encode([$days, $conn, $category])), 300,
            fn() => $this->service->idleDies($days, $conn, $category));
        return response()->json($data);
    }

    public function export(Request $request)
    {
        $mode = $request->query('mode', 'workorder');
        $sites = $this->resolveSites($request);
        $category = trim((string) $request->query('category', '')) ?: null;
        $description = trim((string) $request->query('description', '')) ?: null;

        $data = match ($mode) {
            'date'     => $this->service->byDate(
                $request->query('date_from', $request->query('date', Carbon::today()->toDateString())),
                $request->query('date_to', $request->query('date', Carbon::today()->toDateString())),
                $sites,
                $category,
                $description
            ),
            'week'     => $this->service->byWeek((int) $request->query('year', Carbon::today()->year),
                                                  (int) $request->query('week', (int) Carbon::today()->isoWeek),
                                                  $sites,
                                                  $category,
                                                  $description),
            default    => $this->service->byWorkorder(trim((string) $request->query('wo', '')), $category, $description),
        };

        $rows = $this->service->attachWoKg($data['rows'] ?? [], $sites[0]);

        $filename = 'die-tracking-' . $mode . '-' . date('Ymd-His') . '.xlsx';
        return Excel::download(new DieTrackingExport($rows), $filename);
    }
}
