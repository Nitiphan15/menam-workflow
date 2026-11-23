<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\DtcRateLimitException;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsPinSuggestionService;
use App\Services\FormDP\LongdoPlaceService;
use App\Services\FormDP\GpsStopSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * GPS Phase 1: customer / ship-to locations.
 * View: DP, DPA. Write (save, deactivate, import POI): DPA only (route middleware can:DPA).
 */
class GpsCustomerLocationController extends Controller
{
    public function __construct(
        private GpsCustomerLocationService $locations,
        private DtcGpsService $dtc
    )
    {
    }

    public function index(): View
    {
        $ready = $this->locations->tablesReady();

        return view('formdp.gps-locations.index', [
            'tablesReady' => $ready,
            'customers' => $ready ? $this->locations->customerList() : [],
            'poiStats' => $this->locations->poiStats(),
            'canEdit' => Gate::allows('DPA'),
            'defaultRadius' => GpsCustomerLocationService::DEFAULT_RADIUS_M,
        ]);
    }

    public function show(string $company, int $customerId): JsonResponse
    {
        $company = $this->validCompany($company);

        return response()->json(['ok' => true, 'customer' => $this->locations->customerDetail($company, $customerId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'address' => ['nullable', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'between:20,2000'],
            'source' => ['required', Rule::in(['dtc_poi', 'manual'])],
            'dtc_poi_id' => ['nullable', 'required_if:source,dtc_poi', 'string', 'max:20'],
        ]);
        if ((float) $data['lat'] === 0.0 && (float) $data['lon'] === 0.0) {
            return response()->json(['ok' => false, 'message' => 'พิกัด 0,0 ไม่ถูกต้อง'], 422);
        }

        try {
            $location = $this->locations->saveLocation($data, auth()->id());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'บันทึกพิกัดไม่สำเร็จ'], 500);
        }

        return response()->json(['ok' => true, 'message' => 'บันทึกพิกัดแล้ว', 'location' => $location]);
    }

    public function deactivate(int $id): JsonResponse
    {
        $done = $this->locations->deactivateLocation($id, auth()->id());

        return response()->json(['ok' => $done, 'message' => $done ? 'ยกเลิกพิกัดแล้ว' : 'ไม่พบพิกัดนี้'], $done ? 200 : 404);
    }

    /** Candidates for "confirm many DTC POIs at once" (no DTC request). */
    public function bulkCandidates(Request $request): JsonResponse
    {
        $minScore = in_array((int) $request->query('min', 85), [85, 90, 95], true) ? (int) $request->query('min', 85) : 85;

        return response()->json(['ok' => true, 'min_score' => $minScore, 'candidates' => $this->locations->bulkCandidates($minScore)]);
    }

    public function bulkConfirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.company' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items.*.customer_id' => ['required', 'integer', 'min:1'],
            'items.*.poi_id' => ['required', 'string', 'max:20'],
        ]);

        try {
            $result = $this->locations->bulkConfirm($data['items'], auth()->id());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'บันทึกไม่สำเร็จ'], 500);
        }

        $message = 'บันทึกพิกัด ' . number_format($result['saved']) . ' ราย'
            . ($result['skipped'] ? ' · ข้าม ' . count($result['skipped']) . ' ราย' : '');

        return response()->json(['ok' => true, 'message' => $message, 'result' => $result]);
    }

    /** Where trucks really parked on recent trips to this customer (up to 3 getHistory, cached routes free). */
    public function stopSuggestions(string $company, int $customerId): JsonResponse
    {
        $company = $this->validCompany($company);

        try {
            $result = app(GpsStopSuggestionService::class)->suggest($company, $customerId);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'ค้นหาจุดจอดไม่สำเร็จ', 'usage' => $this->dtc->usageSummary()], 500);
        }

        return response()->json(['ok' => true, 'result' => $result, 'usage' => $this->dtc->usageSummary()]);
    }

    /** Suggested pins for customers without one (DTC POI name, cached Longdo search, repeated stops). No request. */
    public function pinSuggestions(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'rows' => app(GpsPinSuggestionService::class)->suggestions(),
            'longdo' => app(LongdoPlaceService::class)->usageSummary(),
        ]);
    }

    /** Longdo name search for up to 10 customers (1 request each unless cached), returns their new suggestions. */
    public function pinSuggestionsLongdo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:10'],
            'keys.*' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]{1,10}\|\d+$/'],
        ]);
        $longdo = app(LongdoPlaceService::class);
        if (!$longdo->enabled()) {
            return response()->json(['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า LONGDO_MAP_API_KEY'], 422);
        }
        $result = app(GpsPinSuggestionService::class)->searchLongdo($data['keys']);

        return response()->json(['ok' => true, 'rows' => $result['rows'], 'requests' => $result['requests'], 'longdo' => $longdo->usageSummary()]);
    }

    public function savePinSuggestions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.company' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items.*.customer_id' => ['required', 'integer', 'min:1'],
            'items.*.lat' => ['required', 'numeric', 'between:-90,90', 'not_in:0'],
            'items.*.lon' => ['required', 'numeric', 'between:-180,180', 'not_in:0'],
            'items.*.label' => ['nullable', 'string', 'max:200'],
            'items.*.source' => ['required', Rule::in(['dtc_poi', 'longdo', 'stops'])],
            'items.*.dtc_poi_id' => ['nullable', 'string', 'max:20'],
            'items.*.radius_m' => ['nullable', 'integer', 'between:20,2000'],
        ]);

        try {
            $result = $this->locations->saveSuggestedPins($data['items'], auth()->id());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'บันทึกไม่สำเร็จ'], 500);
        }

        $message = 'บันทึกพิกัด ' . number_format($result['saved']) . ' ราย' . ($result['skipped'] ? ' · ข้าม ' . count($result['skipped']) . ' ราย (มีพิกัดแล้ว)' : '');

        return response()->json(['ok' => true, 'message' => $message, 'result' => $result]);
    }

    /** 1 DTC request (getPOI), protected by the same per-minute / monthly guards. */
    public function importPois(): JsonResponse
    {
        try {
            $result = $this->locations->importPois($this->dtc);

            return response()->json([
                'ok' => true,
                'message' => 'นำเข้าจุดจาก DTC ' . number_format($result['imported']) . ' จุด',
                'result' => $result,
                'usage' => $this->dtc->usageSummary(),
            ]);
        } catch (Throwable $e) {
            if (!$e instanceof DtcRateLimitException) {
                report($e);
            }
            $error = $this->dtc->describeException($e);

            return response()->json([
                'ok' => false,
                'message' => $error['title'],
                'error_info' => $error,
                'usage' => $this->dtc->usageSummary(),
            ], $e instanceof DtcRateLimitException ? 429 : 502);
        }
    }

    public function refreshSchema(): JsonResponse
    {
        $this->locations->forgetSchemaCache();

        return response()->json(['ok' => true, 'ready' => $this->locations->tablesReady()]);
    }

    private function validCompany(string $company): string
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9_-]{1,10}$/', $company), 404);

        return $company;
    }
}
