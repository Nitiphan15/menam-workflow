<?php

namespace App\Services\Po;

use App\Support\SqlServerDb;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * PO Online approval report + dashboard (read-only).
 *
 * Reads po_headers + wf_forms + wf_action_histories + wf_form_authorizes and
 * measures how long each PO took to get approved. Nothing is written.
 *
 * Time rules:
 *  - A PO's approval cycle starts at the first SUBMIT after the latest REJECT/REOPEN
 *    (so a resubmitted PO is measured from the resubmission) and ends at COMPLETE.
 *  - Step 2 (Purchase Approval) ends at its last APPROVE; step 3 (Department Manager)
 *    runs from there to its APPROVE. A skipped step 3 has no duration.
 *  - Hours are calendar hours (the workflow SLA is calendar hours too).
 */
class PoApprovalReportService
{
    public const STEP_PURCHASE = 2;
    public const STEP_DEPARTMENT = 3;
    public const SLA_HOURS_PER_STEP = 24;
    public const PENDING_LIMIT = 20;

    public const STATUS_LABELS = [
        'approved' => 'อนุมัติครบ',
        'pending' => 'รออนุมัติ',
        'rejected' => 'ส่งกลับแก้ไข',
        'cancelled' => 'ยกเลิก',
        // Still REQUESTED in PO Online, but already received / invoiced / closed in ERP:
        // nothing left to approve and the PO page no longer opens, so not counted as pending.
        'erp_done' => 'ปิด/รับของใน ERP แล้ว',
        'other' => 'อื่น ๆ',
    ];

    private const CHUNK = 1000;

    public function __construct(
        private readonly PoErpService $erpService,
        private readonly PoErpCompletionLookup $erpCompletion,
    ) {}

    /**
     * @param  array{date_from:string,date_to:string,site?:?string,department?:?string,status?:?string}  $filters
     * @param  array{department_id:int,position:?string}|null  $scope  null = every department
     */
    public function build(array $filters, ?array $scope = null): array
    {
        $key = 'po_approval_report:' . md5(json_encode([$filters, $scope, self::databaseFingerprint()]));

        return Cache::remember($key, now()->addMinutes(10), fn () => $this->buildUncached($filters, $scope));
    }

    public function buildUncached(array $filters, ?array $scope = null): array
    {
        $now = Carbon::now();
        $headers = $this->headers($filters);

        if ($scope !== null) {
            $headers = $this->erpService->filterForDepartmentViewer(
                $headers,
                (int) ($scope['department_id'] ?? 0),
                $scope['position'] ?? null,
            );
        }

        $departmentOptions = $headers->pluck('department_group')->unique()->sort()->values()->all();

        if (!empty($filters['department'])) {
            $headers = $headers->where('department_group', $filters['department'])->values();
        }

        $this->markDoneInErp($headers);

        $wfIds = $headers->pluck('wf_id')->map(fn ($id) => (int) $id)->unique()->values();
        $histories = $this->histories($wfIds);
        $pendingApprovers = $this->pendingApprovers($headers->where('status_key', 'pending')->pluck('wf_id'));
        $userNames = $this->userNames($histories->flatten(1)->pluck('actor_user_id'));

        $rows = $headers->map(function (object $header) use ($histories, $pendingApprovers, $userNames, $now) {
            $timeline = self::summarizeHistory($histories->get((int) $header->wf_id, collect()), $now);

            return $this->row($header, $timeline, $pendingApprovers, $userNames, $now);
        });

        if (!empty($filters['status'])) {
            $rows = $rows->where('status_key', $filters['status']);
        }

        $rows = $rows->sortByDesc('submitted_sort')->values();

        return [
            'filters' => $filters,
            'generated_at' => $now->format('d/m/Y H:i'),
            'rows' => $rows->all(),
            'summary' => self::summarize($rows),
            'monthly' => self::monthly($rows),
            'departments' => self::byDepartment($rows),
            'pending' => $rows->where('status_key', 'pending')
                ->sortByDesc('waiting_hours')
                ->take(self::PENDING_LIMIT)
                ->values()
                ->all(),
            'department_options' => $departmentOptions,
        ];
    }

    /** Pending POs that ERP already finished become "erp_done" (one batched read-only ERP query per site). */
    private function markDoneInErp(Collection $headers): void
    {
        $pending = $headers->where('status_key', 'pending');
        if ($pending->isEmpty()) {
            return;
        }

        $done = $this->erpCompletion->doneInErp(
            $pending->map(fn (object $h) => ['ordnumber' => (string) $h->ordnumber, 'site' => $h->site])->values()->all()
        );

        foreach ($pending as $header) {
            $note = $done[PoErpCompletionLookup::key($header->site, (string) $header->ordnumber)] ?? null;
            if ($note !== null) {
                $header->status_key = 'erp_done';
                $header->erp_note = $note;
            }
        }
    }

    /**
     * Turn one workflow's history rows (sorted oldest first) into cycle timestamps.
     *
     * @param  iterable<object{step_no:int,actor_user_id:int,action_type:string,created_at:string}>  $histories
     */
    public static function summarizeHistory(iterable $histories, ?CarbonInterface $now = null): array
    {
        $cycleStart = null;
        $completedAt = null;
        $approvals = [self::STEP_PURCHASE => [], self::STEP_DEPARTMENT => []];
        $rejectCount = 0;
        $departmentSkipped = false;

        foreach ($histories as $history) {
            $at = Carbon::parse($history->created_at);
            $step = (int) $history->step_no;

            switch (strtoupper((string) $history->action_type)) {
                case 'SUBMIT':
                    if ($cycleStart === null) {
                        $cycleStart = $at;
                        $completedAt = null;
                        $departmentSkipped = false;
                        $approvals = [self::STEP_PURCHASE => [], self::STEP_DEPARTMENT => []];
                    }
                    break;
                case 'REJECT':
                    $rejectCount++;
                    $cycleStart = null;
                    break;
                case 'REOPEN':
                    $cycleStart = null;
                    $completedAt = null;
                    break;
                case 'APPROVE':
                    if ($cycleStart !== null && isset($approvals[$step])) {
                        $approvals[$step][] = ['user_id' => (int) $history->actor_user_id, 'at' => $at];
                    }
                    break;
                case 'SKIP':
                    if ($step === self::STEP_DEPARTMENT) {
                        $departmentSkipped = true;
                    }
                    break;
                case 'COMPLETE':
                    if ($cycleStart !== null) {
                        $completedAt = $at;
                    }
                    break;
            }
        }

        $purchaseEnd = self::latest($approvals[self::STEP_PURCHASE]);
        $departmentEnd = self::latest($approvals[self::STEP_DEPARTMENT]);

        return [
            'submitted_at' => $cycleStart,
            'completed_at' => $completedAt,
            'purchase_end' => $purchaseEnd,
            'department_end' => $departmentEnd,
            'purchase_approvals' => $approvals[self::STEP_PURCHASE],
            'department_approvals' => $approvals[self::STEP_DEPARTMENT],
            'department_skipped' => $departmentSkipped && $departmentEnd === null,
            'reject_count' => $rejectCount,
            'total_hours' => self::hours($cycleStart, $completedAt),
            'purchase_hours' => self::hours($cycleStart, $purchaseEnd),
            'department_hours' => self::hours($purchaseEnd, $departmentEnd),
        ];
    }

    /** Summary cards. $rows are the report rows (arrays). */
    public static function summarize(Collection $rows): array
    {
        $completed = $rows->where('status_key', 'approved')->filter(fn ($row) => $row['total_hours'] !== null);
        $slaBase = $completed->filter(fn ($row) => $row['purchase_hours'] !== null);
        $withinSla = $slaBase->filter(fn ($row) => self::withinSla($row));

        return [
            'total' => $rows->count(),
            'approved' => $rows->where('status_key', 'approved')->count(),
            'pending' => $rows->where('status_key', 'pending')->count(),
            'rejected' => $rows->where('status_key', 'rejected')->count(),
            'cancelled' => $rows->where('status_key', 'cancelled')->count(),
            'erp_done' => $rows->where('status_key', 'erp_done')->count(),
            'avg_hours' => self::average($completed->pluck('total_hours')),
            'median_hours' => self::median($completed->pluck('total_hours')),
            'avg_purchase_hours' => self::average($rows->pluck('purchase_hours')),
            'avg_department_hours' => self::average($rows->pluck('department_hours')),
            'sla_rate' => $slaBase->isEmpty() ? null : round($withinSla->count() * 100 / $slaBase->count(), 1),
            'sla_base' => $slaBase->count(),
            'measured' => $completed->count(),
        ];
    }

    public static function monthly(Collection $rows): array
    {
        return $rows->filter(fn ($row) => $row['request_month'] !== null)
            ->groupBy('request_month')
            ->sortKeys()
            ->map(fn (Collection $group, string $month) => [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->format('m/Y'),
                'approved' => $group->where('status_key', 'approved')->count(),
                'pending' => $group->where('status_key', 'pending')->count(),
                'rejected' => $group->where('status_key', 'rejected')->count(),
                'cancelled' => $group->where('status_key', 'cancelled')->count(),
                'erp_done' => $group->where('status_key', 'erp_done')->count(),
                'avg_hours' => self::average($group->where('status_key', 'approved')->pluck('total_hours')),
            ])
            ->values()
            ->all();
    }

    public static function byDepartment(Collection $rows): array
    {
        return $rows->groupBy('department_group')
            ->map(fn (Collection $group, string $department) => [
                'department' => $department,
                'total' => $group->count(),
                'approved' => $group->where('status_key', 'approved')->count(),
                'pending' => $group->where('status_key', 'pending')->count(),
                'avg_hours' => self::average($group->where('status_key', 'approved')->pluck('total_hours')),
                'avg_purchase_hours' => self::average($group->pluck('purchase_hours')),
                'avg_department_hours' => self::average($group->pluck('department_hours')),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public static function statusKey(?string $formStatus): string
    {
        return match (strtoupper(trim((string) $formStatus))) {
            'CLOESD', 'CLOSED', 'APPROVED' => 'approved',
            'REQUESTED' => 'pending',
            'REJECTED' => 'rejected',
            'CANCELLED' => 'cancelled',
            default => 'other',
        };
    }

    public static function formatHours(?float $hours): string
    {
        if ($hours === null) {
            return '-';
        }
        if ($hours < 24) {
            return number_format($hours, 1) . ' ชม.';
        }

        $days = intdiv((int) round($hours), 24);
        $rest = (int) round($hours) - $days * 24;

        return $days . ' วัน' . ($rest > 0 ? " {$rest} ชม." : '');
    }

    private function headers(array $filters): Collection
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->endOfDay();

        $hasSite = $this->poHeadersHaveSiteColumn();

        $query = SqlServerDb::table('po_headers as p')
            ->join('wf_forms as wf', 'wf.id', '=', 'p.workflow_id')
            ->where('wf.app_code', 'po')
            ->whereBetween('wf.request_dt', [$from, $to])
            ->select([
                'p.id', 'p.ordnumber', 'p.transdate', 'p.f1', 'p.requester_name',
                'p.netamount', 'p.amount', 'p.curr',
                'wf.id as wf_id', 'wf.form_status', 'wf.current_step_no', 'wf.request_dt',
            ])
            ->selectRaw($hasSite ? 'p.site as site' : "'wire' as site");

        $site = PoErpService::normalizeSource($filters['site'] ?? null);
        if ($site !== null && $hasSite) {
            $query->where('p.site', $site);
        } elseif ($site === PoErpService::SOURCE_PLUS) {
            return collect();
        }

        return $query->orderByDesc('wf.request_dt')->get()->map(function (object $row) {
            $row->department = $row->f1;
            $row->department_group = PoErpService::departmentGroupName($row->f1);
            $row->status_key = self::statusKey($row->form_status);

            return $row;
        });
    }

    /**
     * The cache store is shared when the same app switches between DEV and PROD databases
     * (DB_PROFILE). PO ids differ per database, so cached rows must never cross databases.
     */
    public static function databaseFingerprint(): string
    {
        $connection = SqlServerDb::connectionName();

        return implode('|', [
            $connection,
            (string) config("database.connections.{$connection}.host"),
            (string) config("database.connections.{$connection}.database"),
        ]);
    }

    private function poHeadersHaveSiteColumn(): bool
    {
        return Cache::remember('po_approval_report:has_site_column:' . md5(self::databaseFingerprint()), now()->addHour(), fn () => Schema::connection(SqlServerDb::connectionName())
            ->hasColumn('po_headers', 'site'));
    }

    /** @return Collection<int, Collection> keyed by wf_form_id, rows sorted oldest first */
    private function histories(Collection $wfIds): Collection
    {
        $rows = collect();
        foreach ($wfIds->chunk(self::CHUNK) as $chunk) {
            $rows = $rows->concat(
                SqlServerDb::table('wf_action_histories')
                    ->whereIn('wf_form_id', $chunk->values()->all())
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['wf_form_id', 'step_no', 'actor_user_id', 'action_type', 'created_at'])
            );
        }

        return $rows->groupBy(fn ($row) => (int) $row->wf_form_id);
    }

    /** @return Collection<int, array<int,string>> wf id => names still waiting on the current step */
    private function pendingApprovers(Collection $wfIds): Collection
    {
        $rows = collect();
        foreach ($wfIds->map(fn ($id) => (int) $id)->unique()->chunk(self::CHUNK) as $chunk) {
            $rows = $rows->concat(
                SqlServerDb::table('wf_form_authorizes as wa')
                    ->join('wf_forms as wf', function ($join) {
                        $join->on('wf.id', '=', 'wa.wf_form_id')->on('wf.current_step_no', '=', 'wa.step_no');
                    })
                    ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
                    ->whereIn('wa.wf_form_id', $chunk->values()->all())
                    ->where(fn ($q) => $q->whereNull('wa.status')->orWhere('wa.status', 'PENDING'))
                    ->orderBy('u.name')
                    ->get(['wa.wf_form_id', 'u.name'])
            );
        }

        return $rows->groupBy(fn ($row) => (int) $row->wf_form_id)
            ->map(fn (Collection $group) => $group->pluck('name')->unique()->values()->all());
    }

    private function userNames(Collection $userIds): Collection
    {
        $ids = $userIds->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $names = collect();
        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $names = $names->union(
                SqlServerDb::table('users')->whereIn('id', $chunk->values()->all())->pluck('name', 'id')
            );
        }

        return $names;
    }

    private function row(object $header, array $timeline, Collection $pendingApprovers, Collection $userNames, CarbonInterface $now): array
    {
        $statusKey = $header->status_key;
        $currentStep = (int) $header->current_step_no;
        $waitingSince = null;
        if ($statusKey === 'pending') {
            $waitingSince = $currentStep === self::STEP_DEPARTMENT
                ? ($timeline['purchase_end'] ?? $timeline['submitted_at'])
                : $timeline['submitted_at'];
        }
        $requestDt = $header->request_dt ? Carbon::parse($header->request_dt) : null;
        $submittedAt = $timeline['submitted_at'] ?? $requestDt;

        $approvers = fn (array $approvals) => collect($approvals)
            ->map(fn ($a) => trim(($userNames[$a['user_id']] ?? ('#' . $a['user_id'])) . ' ' . $a['at']->format('d/m/Y H:i')))
            ->implode("\n");

        return [
            'po_id' => (int) $header->id,
            'ordnumber' => (string) $header->ordnumber,
            'site' => PoErpService::sourceLabel($header->site),
            'po_date' => $header->transdate ? Carbon::parse($header->transdate)->format('d/m/Y') : '',
            'department' => (string) $header->f1,
            'department_group' => (string) $header->department_group,
            'requester' => (string) ($header->requester_name ?? ''),
            'amount' => $header->netamount !== null ? (float) $header->netamount : (float) ($header->amount ?? 0),
            'currency' => (string) ($header->curr ?? ''),
            'status_key' => $statusKey,
            'status_label' => self::STATUS_LABELS[$statusKey] ?? $statusKey,
            'current_step_label' => match ($statusKey) {
                'pending' => $currentStep === self::STEP_DEPARTMENT ? 'หัวหน้าแผนก' : 'Purchase',
                'erp_done' => 'ค้างขั้น ' . ($currentStep === self::STEP_DEPARTMENT ? 'หัวหน้าแผนก' : 'Purchase') . ' ใน PO Online',
                default => '',
            },
            'erp_note' => (string) ($header->erp_note ?? ''),
            // The PO page reads only open, not-received POs from ERP, so it returns 404 for these.
            'linkable' => $statusKey !== 'erp_done',
            'request_month' => $requestDt?->format('Y-m'),
            'submitted_sort' => $submittedAt?->timestamp ?? 0,
            'submitted_at' => $submittedAt?->format('d/m/Y H:i') ?? '',
            'purchase_approvers' => $approvers($timeline['purchase_approvals']),
            'purchase_end' => $timeline['purchase_end']?->format('d/m/Y H:i') ?? '',
            'department_approvers' => $timeline['department_skipped'] ? 'ข้าม (ไม่มีผู้อนุมัติ)' : $approvers($timeline['department_approvals']),
            'department_end' => $timeline['department_end']?->format('d/m/Y H:i') ?? '',
            'completed_at' => $timeline['completed_at']?->format('d/m/Y H:i') ?? '',
            'purchase_hours' => $timeline['purchase_hours'],
            'department_hours' => $timeline['department_hours'],
            'total_hours' => $timeline['total_hours'],
            'reject_count' => $timeline['reject_count'],
            'waiting_hours' => self::hours($waitingSince, $now),
            'waiting_for' => implode(', ', $pendingApprovers->get((int) $header->wf_id, [])),
            'within_sla' => $statusKey === 'approved' && $timeline['purchase_hours'] !== null
                ? self::withinSla($timeline)
                : null,
        ];
    }

    private static function withinSla(array $row): bool
    {
        return $row['purchase_hours'] <= self::SLA_HOURS_PER_STEP
            && ($row['department_hours'] === null || $row['department_hours'] <= self::SLA_HOURS_PER_STEP);
    }

    private static function latest(array $approvals): ?Carbon
    {
        return collect($approvals)->pluck('at')->sortBy(fn (Carbon $at) => $at->timestamp)->last();
    }

    private static function hours(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if ($from === null || $to === null || $to->lessThan($from)) {
            return null;
        }

        return round(($to->getTimestamp() - $from->getTimestamp()) / 3600, 1);
    }

    private static function average(Collection $values): ?float
    {
        $values = $values->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : round($values->avg(), 1);
    }

    private static function median(Collection $values): ?float
    {
        $values = $values->filter(fn ($v) => $v !== null)->sort()->values();
        $count = $values->count();
        if ($count === 0) {
            return null;
        }
        $mid = intdiv($count, 2);

        return round($count % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
