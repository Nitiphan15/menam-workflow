<?php

namespace App\Services\Po;

use App\Mail\PoNeedsApprovalMail;
use App\Models\Po\PoHeader;
use App\Services\ApproverResolver;
use App\Support\SqlServerDb;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class PoStep3RecoveryService
{
    private const RECOVERY_PREFIX = 'PO_STEP3_RECOVERY';

    private const NOTIFY_PREFIX = 'PO_STEP3_RECOVERY_NOTIFY';

    private const PO_MAIL_BCC = 'itprogramming@menamstainless.co.th';

    public function searchActiveUsers(string $term): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        return SqlServerDb::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.is_active', 1)
            ->whereNotNull('u.email')
            ->where(function ($query) use ($term) {
                $like = '%'.$term.'%';
                $query->where('u.username', 'like', $like)
                    ->orWhere('u.name', 'like', $like)
                    ->orWhere('u.email', 'like', $like);
            })
            ->orderBy('u.name')
            ->limit(30)
            ->get([
                'u.id',
                'u.username',
                'u.name',
                'u.email',
                'u.department_id',
                'd.code as department_code',
                'd.name as department_name',
            ]);
    }

    public function inspect(string $ordnumber, ?int $selectedUserId = null): array
    {
        $ordnumber = trim($ordnumber);
        $po = PoHeader::query()->with('workflow')->where('ordnumber', $ordnumber)->first();
        if (! $po || ! $po->workflow_id || ! $po->workflow) {
            throw new RuntimeException("ไม่พบ PO หรือ workflow ของ {$ordnumber}");
        }

        $workflow = $po->workflow;
        $skip = SqlServerDb::table('wf_action_histories')
            ->where('wf_form_id', (int) $workflow->id)
            ->where('step_no', 3)
            ->where('action_type', 'SKIP')
            ->whereRaw("UPPER(TRIM(COALESCE(comment, ''))) = ?", ['NO APPROVER RESOLVED'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $skip) {
            throw new RuntimeException('PO นี้ไม่มีประวัติ Step 3: SKIP — No approver resolved');
        }

        $pendingRows = SqlServerDb::table('wf_form_authorizes as wa')
            ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
            ->where('wa.wf_form_id', (int) $workflow->id)
            ->where('wa.step_no', 3)
            ->get(['wa.*', 'u.username', 'u.name', 'u.email', 'u.is_active']);

        $legacyRecoveryComment = "Administrative repair: restored skipped Step 3 for {$po->ordnumber}; no approver was resolved during the original transition";
        $recoveryHistory = SqlServerDb::table('wf_action_histories')
            ->where('wf_form_id', (int) $workflow->id)
            ->where('step_no', 3)
            ->where('action_type', 'ADM_FIX')
            ->where('id', '>', (int) $skip->id)
            ->where(function ($query) use ($legacyRecoveryComment) {
                $query->where('comment', 'like', self::RECOVERY_PREFIX.'%')
                    ->orWhere('comment', $legacyRecoveryComment);
            })
            ->orderByDesc('id')
            ->first();

        $isOriginalSkippedState = in_array(strtoupper((string) $po->status_code), ['CLOSED'], true)
            && in_array(strtoupper((string) $workflow->form_status), ['CLOSED', 'CLOESD'], true)
            && (int) $workflow->current_step_no === 999
            && $pendingRows->isEmpty()
            && ! $this->hasHistoryAfter((int) $workflow->id, $skip);

        $isRecoveredPendingState = strtoupper((string) $po->status_code) === 'DEPT_MANAGER_APPROVAL'
            && strtoupper((string) $workflow->form_status) === 'REQUESTED'
            && (int) $workflow->current_step_no === 3
            && $pendingRows->count() === 1
            && strtoupper((string) $pendingRows->first()->status) === 'PENDING'
            && (bool) $recoveryHistory;

        if (! $isOriginalSkippedState && ! $isRecoveredPendingState) {
            throw new RuntimeException('PO ไม่ผ่าน guard: ต้องเป็นรายการที่ปิดจาก Step 3 SKIP และยังไม่มี authorization หรือเป็นรายการที่เครื่องมือนี้กู้ไว้แล้ว');
        }

        $resolverUsers = $this->resolverUsers($po, $workflow);
        $selectedUser = $selectedUserId ? $this->activeUser($selectedUserId) : null;

        if ($isRecoveredPendingState) {
            $pendingUserId = (int) $pendingRows->first()->approver_user_id;
            if ($selectedUserId && $selectedUserId !== $pendingUserId) {
                throw new RuntimeException('รายการนี้ถูกกู้แล้ว ผู้รับต้องตรงกับ PENDING Step 3 เดิม');
            }
            $selectedUser = $this->activeUser($pendingUserId);
        }

        return [
            'po' => $po,
            'workflow' => $workflow,
            'skip' => $skip,
            'state' => $isOriginalSkippedState ? 'skipped' : 'recovered_pending',
            'resolver_users' => $resolverUsers,
            'resolver_user_ids' => $resolverUsers->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'selected_user' => $selectedUser,
            'is_resolver_match' => $selectedUser
                ? $resolverUsers->contains(fn ($user) => (int) $user->id === (int) $selectedUser->id)
                : null,
            'already_dispatched' => $selectedUser
                ? $this->notificationAlreadyRecorded((int) $workflow->id, (int) $selectedUser->id)
                : false,
        ];
    }

    public function recoverAndSend(string $ordnumber, int $selectedUserId, int $operatorUserId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('กรุณาระบุเหตุผลในการย้อน Step 3');
        }

        $context = $this->inspect($ordnumber, $selectedUserId);
        if ($context['already_dispatched']) {
            return $context + ['delivery' => 'already'];
        }

        $selected = $context['selected_user'];
        if (! $selected || trim((string) $selected->email) === '') {
            throw new RuntimeException('ผู้รับต้องเป็น Active User และมีอีเมล');
        }

        if ($context['state'] === 'skipped') {
            $context = $this->recover($context, $selectedUserId, $operatorUserId, $reason);
        }

        $po = $context['po'];
        Mail::to((string) $selected->email)
            ->bcc(self::PO_MAIL_BCC)
            ->send(new PoNeedsApprovalMail(
                approverName: (string) ($selected->name ?: $selected->username),
                poItems: [[
                    'ordnumber' => $po->ordnumber,
                    'source_label' => PoErpService::sourceLabel($po->site),
                    'department' => $po->f1,
                    'vendor_name' => $po->vendor_name,
                    'status_code' => 'DEPT_MANAGER_APPROVAL',
                    'step_no' => 3,
                    'step_label' => 'Department Manager Approval',
                    'flow_steps' => [
                        ['step_no' => 1, 'label' => 'Purchase Submit'],
                        ['step_no' => 2, 'label' => 'Purchase Approval'],
                        ['step_no' => 3, 'label' => 'Department Manager Approval'],
                    ],
                    'approve_url' => route('po.show', $po->id),
                    'print_url' => route('po.print', $po->id),
                ]],
            ));

        SqlServerDb::transaction(function () use ($context, $selectedUserId, $operatorUserId) {
            if ($this->notificationAlreadyRecorded((int) $context['workflow']->id, $selectedUserId)) {
                return;
            }

            SqlServerDb::table('wf_action_histories')->insert([
                'wf_form_id' => (int) $context['workflow']->id,
                'step_no' => 3,
                'actor_user_id' => $operatorUserId,
                'action_type' => 'NOTIFY',
                'comment' => self::NOTIFY_PREFIX." po={$context['po']->ordnumber}; selected_user_id={$selectedUserId};",
                'created_at' => now(),
            ]);
        });

        Log::notice('PO Step 3 recovery email sent', [
            'po_id' => (int) $po->id,
            'ordnumber' => (string) $po->ordnumber,
            'workflow_id' => (int) $context['workflow']->id,
            'operator_user_id' => $operatorUserId,
            'selected_user_id' => $selectedUserId,
            'resolver_user_ids' => $context['resolver_user_ids']->all(),
            'resolver_match' => (bool) $context['is_resolver_match'],
        ]);

        return $context + ['delivery' => 'sent'];
    }

    private function recover(array $context, int $selectedUserId, int $operatorUserId, string $reason): array
    {
        $resolverIds = $context['resolver_user_ids']->implode(',');
        $safeReason = mb_substr(preg_replace('/[\r\n|]+/u', ' ', $reason) ?? $reason, 0, 500);
        $comment = self::RECOVERY_PREFIX
            ." operator_user_id={$operatorUserId} selected_user_id={$selectedUserId}"
            .' resolver_user_ids='.($resolverIds !== '' ? $resolverIds : 'none')
            .' resolver_match='.($context['is_resolver_match'] ? '1' : '0')
            ." reason={$safeReason}";

        SqlServerDb::transaction(function () use ($context, $selectedUserId, $operatorUserId, $comment) {
            $workflowId = (int) $context['workflow']->id;
            $poId = (int) $context['po']->id;

            $workflow = SqlServerDb::table('wf_forms')->where('id', $workflowId)->lockForUpdate()->first();
            $po = SqlServerDb::table('po_headers')->where('id', $poId)->lockForUpdate()->first();
            if (! $workflow || ! $po
                || (int) $workflow->current_step_no !== 999
                || ! in_array(strtoupper((string) $workflow->form_status), ['CLOSED', 'CLOESD'], true)
                || strtoupper((string) $po->status_code) !== 'CLOSED') {
                throw new RuntimeException('สถานะ PO เปลี่ยนหลัง Preview กรุณาตรวจสอบใหม่');
            }

            $hasStep3Authorization = SqlServerDb::table('wf_form_authorizes')
                ->where('wf_form_id', $workflowId)
                ->where('step_no', 3)
                ->lockForUpdate()
                ->exists();
            if ($hasStep3Authorization) {
                throw new RuntimeException('พบ Step 3 authorization แล้ว ระบบยกเลิกการย้อนเพื่อป้องกันข้อมูลซ้ำ');
            }

            SqlServerDb::table('wf_forms')->where('id', $workflowId)->update([
                'form_status' => 'REQUESTED',
                'current_step_no' => 3,
                'last_action_dt' => now(),
                'updated_at' => now(),
            ]);
            SqlServerDb::table('po_headers')->where('id', $poId)->update([
                'status_code' => 'DEPT_MANAGER_APPROVAL',
                'updated_at' => now(),
                'updated_by' => $operatorUserId,
            ]);
            SqlServerDb::table('wf_form_authorizes')->insert([
                'wf_form_id' => $workflowId,
                'step_no' => 3,
                'approver_user_id' => $selectedUserId,
                'status' => 'PENDING',
                'decided_at' => null,
                'comment' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            SqlServerDb::table('wf_action_histories')->insert([
                'wf_form_id' => $workflowId,
                'step_no' => 3,
                'actor_user_id' => $operatorUserId,
                'action_type' => 'ADM_FIX',
                'comment' => mb_substr($comment, 0, 1000),
                'created_at' => now(),
            ]);
        });

        return $this->inspect((string) $context['po']->ordnumber, $selectedUserId);
    }

    private function resolverUsers(object $po, object $workflow): Collection
    {
        $departmentId = PoErpService::resolveDepartmentId($po->f1 ?? null);
        $activeWorkflow = SqlServerDb::table('workflows')->where('code', 'po')->where('is_active', 1)->first();
        $step = $activeWorkflow
            ? SqlServerDb::table('workflow_steps')->where('workflow_id', $activeWorkflow->id)->where('step_no', 3)->where('is_active', 1)->first()
            : null;
        if (! $step) {
            return collect();
        }

        $context = [
            'department_id' => (int) $departmentId,
            'document_department_id' => (int) $departmentId,
            'document_department_name' => (string) ($po->f1 ?? ''),
            'originator_id' => (int) ($workflow->request_by_user_id ?? 0),
            'workflow_step_no' => 3,
        ];

        $ids = SqlServerDb::table('workflow_step_rules')
            ->where('workflow_step_id', $step->id)
            ->orderBy('priority')
            ->get()
            ->flatMap(fn ($rule) => ApproverResolver::resolve($rule, $workflow, $context, []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return SqlServerDb::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->whereIn('u.id', $ids->all())
            ->get(['u.id', 'u.username', 'u.name', 'u.email', 'u.is_active', 'd.code as department_code', 'd.name as department_name'])
            ->sortBy(fn ($user) => $ids->search((int) $user->id))
            ->values();
    }

    private function activeUser(int $userId): object
    {
        $user = SqlServerDb::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.id', $userId)
            ->where('u.is_active', 1)
            ->whereNotNull('u.email')
            ->first(['u.id', 'u.username', 'u.name', 'u.email', 'u.department_id', 'd.code as department_code', 'd.name as department_name']);
        if (! $user || trim((string) $user->email) === '') {
            throw new RuntimeException('ไม่พบ Active User ที่เลือก หรือผู้ใช้นั้นไม่มีอีเมล');
        }

        return $user;
    }

    private function hasHistoryAfter(int $workflowId, object $skip): bool
    {
        return SqlServerDb::table('wf_action_histories')
            ->where('wf_form_id', $workflowId)
            ->where(function ($query) use ($skip) {
                $query->where('created_at', '>', $skip->created_at)
                    ->orWhere(function ($sameTime) use ($skip) {
                        $sameTime->where('created_at', $skip->created_at)->where('id', '>', $skip->id);
                    });
            })
            ->exists();
    }

    private function notificationAlreadyRecorded(int $workflowId, int $selectedUserId): bool
    {
        return SqlServerDb::table('wf_action_histories')
            ->where('wf_form_id', $workflowId)
            ->where('step_no', 3)
            ->where('action_type', 'NOTIFY')
            ->where('comment', 'like', self::NOTIFY_PREFIX."%selected_user_id={$selectedUserId};%")
            ->exists();
    }
}
