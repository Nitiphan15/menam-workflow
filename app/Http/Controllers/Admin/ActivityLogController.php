<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivityLog;
use App\Models\UserAuditChange;
use App\Services\Audit\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;

class ActivityLogController extends Controller
{
    /** ถ้าไม่ระบุวันที่ ให้ดูย้อนหลังเท่านี้ (กันสแกนทั้งตาราง) */
    private const DEFAULT_DAYS = 7;

    /** ช่วงวันที่ค้นได้สูงสุดต่อครั้ง */
    private const MAX_RANGE_DAYS = 92;

    public function index(Request $r)
    {
        [$from, $to, $dateFrom, $dateTo] = $this->dateRange($r);

        $filters = [
            'q'           => mb_substr(trim((string) $r->query('q', '')), 0, 100),
            'action_type' => (string) $r->query('action_type', ''),
            'auth'        => (string) $r->query('auth', ''),   // '' = ทั้งหมด, '1' = member, '0' = guest
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ];

        $logs = UserActivityLog::query()
            // เทียบช่วงเวลาตรงๆ ให้ใช้ index ual_at_idx ได้ (whereDate ใช้ index ไม่ได้บน SQL Server)
            ->where('accessed_at', '>=', $from)
            ->where('accessed_at', '<', $to)
            ->when($filters['q'] !== '', fn ($w) => $w->where(function ($x) use ($filters) {
                $kw = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $filters['q']) . '%';
                $x->where('user_name', 'like', $kw)
                    ->orWhere('menu_name', 'like', $kw)
                    ->orWhere('route_name', 'like', $kw)
                    ->orWhere('url_path', 'like', $kw)
                    ->orWhere('ip_address', 'like', $kw);
            }))
            ->when($filters['action_type'] !== '', function ($w) use ($filters) {
                // ข้อมูลก่อนปรับปรุงใช้ 'insert' แทน 'create'
                $types = $filters['action_type'] === 'create' ? ['create', 'insert'] : [$filters['action_type']];
                $w->whereIn('action_type', $types);
            })
            ->when($filters['auth'] !== '', fn ($w) => $w->where('is_authenticated', (int) $filters['auth']))
            ->orderByDesc('accessed_at')
            ->orderByDesc('id')
            ->simplePaginate(50) // ไม่ COUNT ทั้งตาราง
            ->withQueryString();

        return view('adminweb.activity_logs.index', compact('logs', 'filters'));
    }

    /**
     * ประวัติการเปลี่ยนแปลงค่า (ก่อน/หลัง) ของข้อมูลสำคัญใน adminweb
     */
    public function changes(Request $r)
    {
        [$from, $to, $dateFrom, $dateTo] = $this->dateRange($r);

        $filters = [
            'q'           => mb_substr(trim((string) $r->query('q', '')), 0, 100),
            'entity_type' => (string) $r->query('entity_type', ''),
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ];

        // ยังไม่ได้รัน database/sql/activity-log/02_create_user_audit_changes.sql
        if (! AuditTrail::tableExists()) {
            $changes = new Paginator([], 50);
            $tableMissing = true;

            return view('adminweb.activity_logs.changes', compact('changes', 'filters', 'tableMissing'));
        }

        $changes = UserAuditChange::query()
            ->where('changed_at', '>=', $from)
            ->where('changed_at', '<', $to)
            ->when($filters['entity_type'] !== '', fn ($w) => $w->where('entity_type', $filters['entity_type']))
            ->when($filters['q'] !== '', fn ($w) => $w->where(function ($x) use ($filters) {
                $kw = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $filters['q']) . '%';
                $x->where('user_name', 'like', $kw)
                    ->orWhere('entity_label', 'like', $kw)
                    ->orWhere('entity_id', $filters['q']);
            }))
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString();

        return view('adminweb.activity_logs.changes', compact('changes', 'filters'));
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string, 3: string}
     */
    private function dateRange(Request $r): array
    {
        $parse = function (?string $value): ?Carbon {
            if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $to = $parse($r->query('date_to')) ?? today();
        $from = $parse($r->query('date_from')) ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from, $to->copy()->addDay(), $from->format('Y-m-d'), $to->format('Y-m-d')];
    }
}
