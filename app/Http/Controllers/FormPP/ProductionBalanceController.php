<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;
use App\Services\FormPP\ProductionBalanceHistoryService;
use App\Services\FormPP\ProductionBalanceService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class ProductionBalanceController extends Controller
{
    public function index(Request $request, ProductionBalanceService $service, ProductionBalanceHistoryService $history)
    {
        $filters = $request->validate([
            'site' => ['nullable', 'in:all,wire,plus'],
            'status' => ['nullable', 'in:DATA_ISSUE,EXCESS,SHORT,COMPLETE'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $filters['site'] = $filters['site'] ?? 'all';
        $cacheKey = 'pp:production-balance:'.sha1(json_encode($filters, JSON_UNESCAPED_UNICODE));
        $rows = Cache::remember($cacheKey, now()->addMinutes(10), fn () => $service->report($filters));
        $snapshotAt = null;
        if ($history->isReady()) {
            $comparison = $history->decorateWithPrevious($rows);
            $rows = $comparison['rows'];
            $snapshotAt = $comparison['snapshot_at'];
        }
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('formpp.production-balance', [
            'rows' => $paginator, 'filters' => $filters, 'snapshotAt' => $snapshotAt,
            'summary' => [
                'excess' => $rows->where('status', 'EXCESS')->count(),
                'excess_qty' => $rows->where('status', 'EXCESS')->sum('excess_qty'),
                'short' => $rows->where('status', 'SHORT')->count(),
                'short_qty' => $rows->where('status', 'SHORT')->sum('need_qty'),
                'complete' => $rows->where('status', 'COMPLETE')->count(),
                'data_issue' => $rows->where('status', 'DATA_ISSUE')->count(),
            ],
        ]);
    }
}
