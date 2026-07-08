<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $r)
    {
        $filters = [
            'q'           => trim((string) $r->query('q', '')),
            'action_type' => (string) $r->query('action_type', ''),
            'auth'        => (string) $r->query('auth', ''),   // '' = ทั้งหมด, '1' = member, '0' = guest
            'date_from'   => (string) $r->query('date_from', ''),
            'date_to'     => (string) $r->query('date_to', ''),
        ];

        $logs = UserActivityLog::query()
            ->when($filters['q'], fn ($w) => $w->where(function ($x) use ($filters) {
                $kw = "%{$filters['q']}%";
                $x->where('user_name', 'like', $kw)
                    ->orWhere('menu_name', 'like', $kw)
                    ->orWhere('route_name', 'like', $kw)
                    ->orWhere('url_path', 'like', $kw)
                    ->orWhere('ip_address', 'like', $kw);
            }))
            ->when($filters['action_type'] !== '', fn ($w) => $w->where('action_type', $filters['action_type']))
            ->when($filters['auth'] !== '', fn ($w) => $w->where('is_authenticated', (int) $filters['auth']))
            ->when($filters['date_from'] !== '', fn ($w) => $w->whereDate('accessed_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($w) => $w->whereDate('accessed_at', '<=', $filters['date_to']))
            ->orderByDesc('accessed_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('adminweb.activity_logs.index', compact('logs', 'filters'));
    }
}
