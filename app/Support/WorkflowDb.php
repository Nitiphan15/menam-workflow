<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WorkflowDb
{
    private const LEGACY_MYSQL_APPS = ['pp', 'wocr'];

    public static function connectionName(?string $appCode = null): string
    {
        $appCode = strtolower(trim((string) $appCode));

        if (in_array($appCode, self::LEGACY_MYSQL_APPS, true)) {
            return 'mysql';
        }

        return SqlServerDb::connectionName();
    }

    public static function connection(?string $appCode = null): ConnectionInterface
    {
        return DB::connection(self::connectionName($appCode));
    }

    public static function table(?string $appCode, string $table): Builder
    {
        return self::connection($appCode)->table($table);
    }

    public static function transaction(?string $appCode, Closure $callback, int $attempts = 1): mixed
    {
        return self::connection($appCode)->transaction($callback, $attempts);
    }

    public static function qualifyTable(?string $appCode, string $table): string
    {
        return self::connectionName($appCode) . '.' . $table;
    }

    public static function findForm(?string $appCode, int $wfId): ?object
    {
        $wf = self::table($appCode, 'wf_forms')->where('id', $wfId)->first();
        if (!$wf) {
            return null;
        }

        $requesterName = self::table($appCode, 'users')
            ->where('id', (int) ($wf->request_by_user_id ?? 0))
            ->value('name');
        $wf->requester = (object) ['name' => $requesterName];

        return $wf;
    }

    public static function canApprove(?string $appCode, int $wfId, int $userId): bool
    {
        $wf = self::findForm($appCode, $wfId);
        if (!$wf) {
            return false;
        }

        return self::table($appCode, 'wf_form_authorizes')
            ->where('wf_form_id', $wfId)
            ->where('step_no', (int) ($wf->current_step_no ?? 0))
            ->where('approver_user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'PENDING');
            })
            ->exists();
    }

    public static function historyWithActors(?string $appCode, int $wfId)
    {
        return self::table($appCode, 'wf_action_histories')
            ->leftJoin('users', 'users.id', '=', 'wf_action_histories.actor_user_id')
            ->where('wf_action_histories.wf_form_id', $wfId)
            ->orderBy('wf_action_histories.created_at', 'asc')
            ->get([
                'wf_action_histories.*',
                'users.name as actor_name',
            ]);
    }

    public static function pendingApprovers(?string $appCode, int $wfId)
    {
        return self::table($appCode, 'wf_form_authorizes as wa')
            ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
            ->where('wa.wf_form_id', $wfId)
            ->where('wa.status', 'PENDING')
            ->select('u.email', 'u.name', 'wa.approver_user_id')
            ->get();
    }
}
