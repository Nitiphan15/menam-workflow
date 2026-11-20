<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\DtcRateLimitException;
use App\Services\FormDP\GpsCustomerLocationService;
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
