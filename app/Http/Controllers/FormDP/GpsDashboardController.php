<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsDailySummaryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/**
 * GPS planner dashboard: late arrivals (after DP window_at), late returns, trends.
 * Reads gps_trip_daily / gps_stop_visit (filled nightly by gps:summarize-day). View: DP, DPA.
 * "อัปเดต" (DPA) summarizes the selected day now, at most 3 DTC requests per click.
 */
class GpsDashboardController extends Controller
{
    private const WEEK_OPTIONS = [4, 8, 13, 26];

    public function __construct(
        private GpsDailySummaryService $summary,
        private DtcGpsService $dtc
    ) {
    }

    public function index(Request $request): View
    {
        $ready = $this->summary->tablesReady();
        $date = $this->resolveDate($request->query('date'));
        $weeks = in_array((int) $request->query('weeks', 8), self::WEEK_OPTIONS, true) ? (int) $request->query('weeks', 8) : 8;
        $to = now('Asia/Bangkok')->toDateString();
        $from = now('Asia/Bangkok')->subWeeks($weeks)->startOfWeek(Carbon::MONDAY)->toDateString();

        return view('formdp.gps-dashboard.index', [
            'tablesReady' => $ready,
            'date' => $date,
            'weeks' => $weeks,
            'weekOptions' => self::WEEK_OPTIONS,
            'board' => $ready ? $this->summary->dayBoard($date) : null,
            'trends' => $ready ? $this->summary->trends($from, $to) : null,
            'canRefresh' => Gate::allows('DPA'),
            'dtcUsage' => $this->dtc->usageSummary(),
            'lateReturnTime' => (string) config('dtc.late_return_time', '18:00'),
            'graceMinutes' => (int) config('dtc.late_arrival_grace_minutes', 0),
        ]);
    }

    /** DPA: summarize the selected day now (up to 3 DTC requests; cached routes are free). */
    public function refresh(Request $request): JsonResponse
    {
        $date = $this->resolveDate($request->input('date'));
        if ($date > now('Asia/Bangkok')->toDateString()) {
            return response()->json(['ok' => false, 'message' => 'สรุปวันในอนาคตไม่ได้'], 422);
        }

        $usageBefore = $this->dtc->usageSummary();
        try {
            $result = $this->summary->summarizeDay($date, true, 3);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'สรุปข้อมูลไม่สำเร็จ', 'usage' => $this->dtc->usageSummary()], 500);
        }

        $usageAfter = $this->dtc->usageSummary();
        $requestsUsed = max(0, (int) $usageAfter['month_total'] - (int) $usageBefore['month_total']);
        $requestLabel = $requestsUsed === 0 ? 'ใช้ DTC 0 request (ข้อมูลเส้นทางจาก cache)' : "ใช้ DTC {$requestsUsed} request";
        $message = "สรุป {$result['summarized']}/{$result['trucks']} คัน · {$requestLabel}";
        if ($result['pending'] > 0) {
            $message .= " · เหลือ {$result['pending']} คัน กดอัปเดตอีกครั้งในอีก 1 นาที (DTC จำกัด 3 ครั้ง/นาที)";
        }

        return response()->json([
            'ok' => true,
            'message' => $message,
            'result' => $result,
            'requests_used' => $requestsUsed,
            'usage' => $usageAfter,
        ]);
    }

    private function resolveDate($value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $value, 'Asia/Bangkok');
                if ($date !== false && $date->format('Y-m-d') === $value) {
                    return $value;
                }
            } catch (Throwable) {
                // fall through
            }
        }

        // Default: yesterday (the last full day the nightly job summarized).
        return now('Asia/Bangkok')->subDay()->toDateString();
    }
}
