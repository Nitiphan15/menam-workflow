<?php

namespace App\Services\Audit;

use App\Models\UserActivityLog;
use App\Support\MenuResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * จุดเดียวที่เขียน user_activity_logs (ใช้ทั้ง middleware และ auth events)
 * ทุก method ต้องไม่ throw ออกไป เพราะห้ามทำให้ request ของผู้ใช้พังเพราะ log
 */
class ActivityLogger
{
    /** action_type ที่ใช้ทั้งระบบ (ค่าเก่า 'insert' ยังอ่านได้ในหน้า log) */
    public const ACTIONS = [
        'view', 'create', 'update', 'delete', 'approve', 'reject',
        'export', 'download', 'email', 'login', 'login_failed', 'login_blocked', 'logout',
    ];

    public static function enabled(): bool
    {
        // ไม่บันทึกบน local เพราะใช้ DB 'sqlsrv_menam' ร่วมกับ prod (กัน log ปนกัน)
        // override ได้ด้วย ACTIVITY_LOG_ENABLED ใน .env (true/false)
        $enabled = config('app.activity_log_enabled');

        return $enabled === null ? ! app()->environment('local') : (bool) $enabled;
    }

    /**
     * @param  mixed  $user  App\Models\Users\User|null
     */
    public static function record(
        Request $request,
        $user,
        string $actionType,
        ?int $statusCode = null,
        ?string $routeName = null,
        ?string $userNameOverride = null
    ): void {
        if (! self::enabled()) {
            return;
        }

        try {
            $routeName ??= $request->route()?->getName();

            $row = [
                'user_id'          => $user?->id,
                'user_name'        => mb_substr($userNameOverride ?? $user?->name ?? 'guest', 0, 150),
                'is_authenticated' => $user !== null && $actionType !== 'login_failed',
                'menu_name'        => $request->attributes->get('activity_menu_name')
                    ?: MenuResolver::labelFor($routeName),
                'route_name'       => $routeName,
                'url_path'         => mb_substr('/' . ltrim($request->path(), '/'), 0, 255), // ไม่เก็บ query string
                'method'           => strtoupper($request->getMethod()),
                'action_type'      => $actionType,
                'status_code'      => $statusCode,
                'ip_address'       => $request->ip(),
                'user_agent'       => mb_substr((string) $request->userAgent(), 0, 255),
                'accessed_at'      => now(),
            ];

            if (self::hasDepartmentColumns()) {
                $row['department_id'] = $user?->department_id;
                $row['department_name'] = self::departmentName($user);
            }

            UserActivityLog::create($row);
        } catch (\Throwable $e) {
            Log::warning('ActivityLogger failed: ' . $e->getMessage());
        }
    }

    /**
     * แปลง route name + HTTP method เป็น action_type ที่อ่านแล้วเข้าใจ
     */
    public static function classify(string $method, ?string $routeName): string
    {
        $method = strtoupper($method);
        $tokens = preg_split('/[.\-_]+/', strtolower((string) $routeName)) ?: [];
        $has = fn (array $words) => (bool) array_intersect($tokens, $words);

        if ($has(['approve', 'approved'])) {
            return 'approve';
        }
        if ($has(['reject', 'rejected'])) {
            return 'reject';
        }
        if ($has(['export', 'excel', 'pdf', 'csv', 'print'])) {
            return 'export';
        }
        if ($has(['download', 'attachment', 'attachments', 'file', 'files'])) {
            return $method === 'GET' || $method === 'HEAD' ? 'download' : self::byMethod($method, $tokens);
        }
        if ($method === 'GET' || $method === 'HEAD') {
            return 'view';
        }
        if ($has(['mail', 'email', 'sendmail', 'send', 'notify'])) {
            return 'email';
        }

        return self::byMethod($method, $tokens);
    }

    protected static function byMethod(string $method, array $tokens): string
    {
        if ($method === 'DELETE' || array_intersect($tokens, ['destroy', 'delete', 'remove'])) {
            return 'delete';
        }
        if ($method === 'PUT' || $method === 'PATCH'
            || array_intersect($tokens, ['update', 'edit', 'save', 'confirm', 'toggle', 'toggleactive', 'reset', 'password', 'assign', 'sync'])) {
            return 'update';
        }

        return 'create';
    }

    protected static function departmentName($user): ?string
    {
        if (! $user || ! $user->department_id || ! method_exists($user, 'department')) {
            return null;
        }

        $user->loadMissing('department');

        return $user->department?->name !== null ? mb_substr($user->department->name, 0, 150) : null;
    }

    /**
     * รองรับกรณี deploy โค้ดก่อนรัน SQL 01_alter_user_activity_logs_add_department.sql
     */
    protected static function hasDepartmentColumns(): bool
    {
        static $has = null;

        return $has ??= Cache::remember('activity_log.has_department_columns', 3600, function () {
            return Schema::connection((new UserActivityLog())->getConnectionName())
                ->hasColumn('user_activity_logs', 'department_id');
        });
    }
}
