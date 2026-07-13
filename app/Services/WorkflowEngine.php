<?php

namespace App\Services;

use App\Services\Po\PoErpService;
use App\Support\WorkflowDb;
use Illuminate\Support\Collection;

class WorkflowEngine
{
    public const ST_DRAFT = 'DRAFT';
    public const ST_REQUESTED = 'REQUESTED';
    public const ST_APPROVED = 'APPROVED';
    public const ST_REJECTED = 'REJECTED';
    public const ST_VOID = 'CANCELLED';
    public const ST_CLOSED = 'CLOESD';

    public static function submit(string $appCode, string $refType, int $refId, array $options = []): int
    {
        return WorkflowDb::transaction($appCode, function () use ($appCode, $refType, $refId, $options) {
            $requesterId = (int) ($options['request_by_user_id'] ?? auth()->id());
            $formNo = (string) ($options['form_no'] ?? '');
            $context = (array) ($options['context'] ?? []);
            $skipFlags = (array) ($options['skip_flags'] ?? []);
            $initialStepNo = (int) ($options['initial_step_no'] ?? 1);
            if ($initialStepNo <= 0) {
                $initialStepNo = 1;
            }

            self::getActiveWorkflow($appCode);
            $formNo = self::ensureUniqueFormNo($appCode, $formNo);

            $wfId = WorkflowDb::table($appCode, 'wf_forms')->insertGetId([
                'app_code' => $appCode,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'form_no' => $formNo,
                'form_status' => self::ST_REQUESTED,
                'current_step_no' => 1,
                'request_by_user_id' => $requesterId,
                'request_dt' => now(),
                'last_action_dt' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                'wf_form_id' => $wfId,
                'step_no' => 1,
                'actor_user_id' => $requesterId,
                'action_type' => 'SUBMIT',
                'comment' => $options['submit_comment'] ?? null,
                'created_at' => now(),
            ]);

            $ctx = array_merge($context, ['originator_id' => $requesterId]);
            self::moveToStep($wfId, $initialStepNo, $requesterId, $ctx, $skipFlags, $appCode);

            return $wfId;
        });
    }

    public static function approve(int $wfId, int $actorUserId, ?string $comment = null, ?string $appCode = null): void
    {
        $appCode = self::resolveAppCode($wfId, $appCode);

        WorkflowDb::transaction($appCode, function () use ($wfId, $actorUserId, $comment, $appCode) {
            $wf = WorkflowDb::table($appCode, 'wf_forms')->lockForUpdate()->find($wfId);
            abort_unless($wf && !in_array((string) $wf->form_status, [self::ST_VOID, self::ST_CLOSED], true), 404);

            $authorizeRow = WorkflowDb::table($appCode, 'wf_form_authorizes')
                ->where('wf_form_id', $wfId)
                ->where('step_no', $wf->current_step_no)
                ->where('approver_user_id', $actorUserId)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'PENDING');
                })
                ->orderBy('id')
                ->first();

            abort_if(!$authorizeRow, 403, 'You are not allowed to approve this step');

            $updated = WorkflowDb::table($appCode, 'wf_form_authorizes')
                ->where('id', (int) $authorizeRow->id)
                ->update([
                    'status' => 'APPROVED',
                    'decided_at' => now(),
                    'comment' => $comment,
                    'updated_at' => now(),
                ]);

            abort_if($updated === 0, 403, 'You are not allowed to approve this step');

            WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                'wf_form_id' => $wfId,
                'step_no' => $wf->current_step_no,
                'actor_user_id' => $actorUserId,
                'action_type' => 'APPROVE',
                'comment' => $comment,
                'created_at' => now(),
            ]);

            $counts = WorkflowDb::table($appCode, 'wf_form_authorizes')
                ->where('wf_form_id', $wfId)
                ->where('step_no', $wf->current_step_no)
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw("SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) AS approved")
                ->first();
            $step = self::getStepDefinition($wf->app_code, (int) $wf->current_step_no);

            $total = (int) ($counts->total ?? 0);
            $approved = (int) ($counts->approved ?? 0);
            $required = (int) ($step->min_approvals ?? 0);
            if ($required <= 0 || $required > $total) {
                $required = $total;
            }

            if ($approved < $required) {
                WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                    'last_action_dt' => now(),
                    'updated_at' => now(),
                ]);
                return;
            }

            $next = (int) ($step->next_on_approve ?? ($wf->current_step_no + 1));


            $hasNext = WorkflowDb::table($appCode, 'workflow_steps')
                ->where('workflow_id', $step->workflow_id)
                ->where('step_no', $next)
                ->exists();

            if (!$hasNext) {
                WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                    'form_status' => self::ST_CLOSED,
                    'current_step_no' => 999,
                    'last_action_dt' => now(),
                    'updated_at' => now(),
                ]);

                WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                    'wf_form_id' => $wfId,
                    'step_no' => 999,
                    'actor_user_id' => $actorUserId,
                    'action_type' => 'COMPLETE',
                    'comment' => 'Process completed',
                    'created_at' => now(),
                ]);
                return;
            }

            WorkflowDb::table($appCode, 'wf_form_authorizes')
                ->where('wf_form_id', $wfId)
                ->where('step_no', $next)
                ->delete();
            $context = self::buildContextFromRef($wf);
            self::moveToStep($wfId, $next, $actorUserId, $context, [], $appCode);
        });
    }

    public static function reject(int $wfId, int $actorUserId, string $reason, ?string $appCode = null): void
    {
        $appCode = self::resolveAppCode($wfId, $appCode);

        WorkflowDb::transaction($appCode, function () use ($wfId, $actorUserId, $reason, $appCode) {
            $wf = WorkflowDb::table($appCode, 'wf_forms')->lockForUpdate()->find($wfId);
            abort_unless($wf && !in_array((string) $wf->form_status, [self::ST_VOID, self::ST_CLOSED], true), 404);

            $updated = WorkflowDb::table($appCode, 'wf_form_authorizes')
                ->where('wf_form_id', $wfId)
                ->where('step_no', $wf->current_step_no)
                ->where('approver_user_id', $actorUserId)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'PENDING');
                })
                ->update([
                    'status' => 'REJECTED',
                    'decided_at' => now(),
                    'comment' => $reason,
                    'updated_at' => now(),
                ]);

            abort_if($updated === 0, 403, 'You are not allowed to reject this step');

            WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                'wf_form_id' => $wfId,
                'step_no' => $wf->current_step_no,
                'actor_user_id' => $actorUserId,
                'action_type' => 'REJECT',
                'comment' => $reason,
                'created_at' => now(),
            ]);

            $step = self::getStepDefinition($wf->app_code, (int) $wf->current_step_no);
            $nextOnReject = (int) ($step->next_on_reject ?? 0);

            if ($nextOnReject === 998) {
                WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                    'form_status' => self::ST_VOID,
                    'current_step_no' => 998,
                    'last_action_dt' => now(),
                    'updated_at' => now(),
                ]);

                WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                    'wf_form_id' => $wfId,
                    'step_no' => 998,
                    'actor_user_id' => $actorUserId,
                    'action_type' => 'CANCEL',
                    'comment' => 'Rejected to cancel',
                    'created_at' => now(),
                ]);
                return;
            }

            $targetStep = $nextOnReject > 0 ? $nextOnReject : 1;
            $hasTarget = WorkflowDb::table($appCode, 'workflow_steps')
                ->where('workflow_id', $step->workflow_id)
                ->where('step_no', $targetStep)
                ->exists();

            if (!$hasTarget) {
                WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                    'last_action_dt' => now(),
                    'updated_at' => now(),
                ]);
                return;
            }

            $hasAuthRows = WorkflowDb::table($appCode, 'wf_form_authorizes')
                ->where('wf_form_id', $wfId)
                ->where('step_no', $targetStep)
                ->exists();

            if ($hasAuthRows) {
                WorkflowDb::table($appCode, 'wf_form_authorizes')
                    ->where('wf_form_id', $wfId)
                    ->where('step_no', $targetStep)
                    ->update([
                        'status' => 'PENDING',
                        'decided_at' => null,
                        'comment' => null,
                        'updated_at' => now(),
                    ]);
            } else {
                $context = self::buildContextFromRef($wf);
                self::moveToStep($wfId, $targetStep, $actorUserId, $context, [], $appCode);
            }

            WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                'form_status' => self::ST_REJECTED,
                'current_step_no' => $targetStep,
                'last_action_dt' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public static function void(int $wfId, int $actorUserId, ?string $reason = null, ?string $appCode = null): void
    {
        $appCode = self::resolveAppCode($wfId, $appCode);

        WorkflowDb::transaction($appCode, function () use ($wfId, $actorUserId, $reason, $appCode) {
            $wf = WorkflowDb::table($appCode, 'wf_forms')->lockForUpdate()->find($wfId);
            abort_unless($wf, 404);
            abort_unless((int) $wf->request_by_user_id === $actorUserId, 403, 'Only originator can void');
            abort_if(in_array((string) $wf->form_status, [self::ST_CLOSED, self::ST_VOID], true), 422, 'Already finished');

            WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                'form_status' => self::ST_VOID,
                'current_step_no' => 998,
                'last_action_dt' => now(),
                'updated_at' => now(),
            ]);

            WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                'wf_form_id' => $wfId,
                'step_no' => 998,
                'actor_user_id' => $actorUserId,
                'action_type' => 'CANCEL',
                'comment' => $reason ?: 'Void by originator',
                'created_at' => now(),
            ]);
        });
    }

    private static function moveToStep(int $wfId, int $stepNo, int $actorUserId, array $context, array $skipFlags, ?string $appCode = null): void
    {
        $appCode = self::resolveAppCode($wfId, $appCode);
        $wf = WorkflowDb::table($appCode, 'wf_forms')->find($wfId);
        $master = self::getActiveWorkflow($wf->app_code);
        $step = WorkflowDb::table($appCode, 'workflow_steps')
            ->where('workflow_id', $master->id)
            ->where('step_no', $stepNo)
            ->first();

        abort_unless($step && (int) $step->is_active === 1, 422, "Step {$stepNo} not active");

        $rules = WorkflowDb::table($appCode, 'workflow_step_rules')
            ->where('workflow_step_id', $step->id)
            ->orderBy('priority')
            ->get();

        $approvers = collect();
        foreach ($rules as $rule) {
            $stepContext = array_merge($context, ['workflow_step_no' => $stepNo]);
            $list = ApproverResolver::resolve($rule, $wf, $stepContext, $skipFlags);
            $ruleApprovers = $list instanceof Collection ? $list : collect($list);
            $approvers = $approvers->merge($ruleApprovers->unique()->values());
        }
        $allowDuplicateApprovers = strtolower((string) ($wf->app_code ?? '')) === 'po';
        $approvers = $allowDuplicateApprovers
            ? $approvers->filter()->values()
            : $approvers->unique()->values();

        $isPoDepartmentFinalStep = strtolower((string) ($wf->app_code ?? '')) === 'po'
            && in_array((string) ($step->key ?? ''), ['dept_manager_approve', 'dept_manager_approval'], true);
        $skipIfNoApprover = (int) ($step->skip_if_no_approver ?? 0) === 1;
        $shouldSkip = (
            ($rules->isEmpty() || $approvers->isEmpty()) &&
            ($skipIfNoApprover || ($isPoDepartmentFinalStep && $approvers->isEmpty()))
        );

        if (!$shouldSkip && $rules->isEmpty()) {
            abort(422, "Workflow step {$stepNo} has no rule configuration");
        }

        if (!$shouldSkip && $approvers->isEmpty()) {
            abort(422, "No approver resolved for workflow step {$stepNo}");
        }

        if ($shouldSkip) {
            WorkflowDb::table($appCode, 'wf_action_histories')->insert([
                'wf_form_id' => $wfId,
                'step_no' => $stepNo,
                'actor_user_id' => $actorUserId,
                'action_type' => $stepNo === 1 ? 'SUBMIT' : 'SKIP',
                'comment' => 'No approver resolved',
                'created_at' => now(),
            ]);

            $next = (int) ($step->next_on_approve ?? ($stepNo + 1));
            $exists = WorkflowDb::table($appCode, 'workflow_steps')
                ->where('workflow_id', $master->id)
                ->where('step_no', $next)
                ->exists();

            if (!$exists) {
                WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                    'form_status' => self::ST_CLOSED,
                    'current_step_no' => 999,
                    'last_action_dt' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
                    'form_status' => self::ST_REQUESTED,
                    'current_step_no' => $next,
                    'last_action_dt' => now(),
                    'updated_at' => now(),
                ]);

                $ctx = self::buildContextFromRef($wf);
                self::moveToStep($wfId, $next, $actorUserId, $ctx, $skipFlags, $appCode);
            }
            return;
        }

        WorkflowDb::table($appCode, 'wf_form_authorizes')
            ->where('wf_form_id', $wfId)
            ->where('step_no', $stepNo)
            ->delete();

        $rows = $approvers->map(fn($uid) => [
            'wf_form_id' => $wfId,
            'step_no' => $stepNo,
            'approver_user_id' => $uid,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        WorkflowDb::table($appCode, 'wf_form_authorizes')->insert($rows);

        WorkflowDb::table($appCode, 'wf_forms')->where('id', $wfId)->update([
            'form_status' => self::ST_REQUESTED,
            'current_step_no' => $stepNo,
            'last_action_dt' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function getActiveWorkflow(string $appCode): object
    {
        $workflow = WorkflowDb::table($appCode, 'workflows')
            ->where('code', $appCode)
            ->where('is_active', 1)
            ->first();

        abort_unless($workflow, 422, "Workflow for app_code={$appCode} not found");

        return $workflow;
    }

    private static function ensureUniqueFormNo(string $appCode, string $formNo): string
    {
        $formNo = trim($formNo);
        if ($formNo === '') {
            return $formNo;
        }

        $candidate = $formNo;
        for ($i = 0; $i < 1000; $i++) {
            $exists = WorkflowDb::table($appCode, 'wf_forms')
                ->whereRaw('LOWER(app_code) = ?', [strtolower($appCode)])
                ->whereRaw('LOWER(form_no) = ?', [strtolower($candidate)])
                ->lockForUpdate()
                ->exists();

            if (!$exists) {
                return $candidate;
            }

            $candidate = self::nextRunningFormNo($candidate);
        }

        abort(422, "Unable to generate unique form number for {$appCode}");
    }

    private static function nextRunningFormNo(string $formNo): string
    {
        if (preg_match('/^(.*?)(\d+)$/', $formNo, $matches)) {
            $prefix = $matches[1];
            $number = $matches[2];

            return $prefix . str_pad((string) ((int) $number + 1), strlen($number), '0', STR_PAD_LEFT);
        }

        return $formNo . '-2';
    }

    private static function resolveAppCode(int $wfId, ?string $appCode = null): string
    {
        $appCode = strtolower(trim((string) $appCode));
        if ($appCode !== '') {
            return $appCode;
        }

        foreach ([null, 'pp', 'wocr'] as $candidateAppCode) {
            $wf = WorkflowDb::findForm($candidateAppCode, $wfId);
            if ($wf && !empty($wf->app_code)) {
                return strtolower((string) $wf->app_code);
            }
        }

        abort(404);
    }

    private static function getStepDefinition(string $appCode, int $stepNo): object
    {
        $workflow = self::getActiveWorkflow($appCode);
        $step = WorkflowDb::table($appCode, 'workflow_steps')
            ->where('workflow_id', $workflow->id)
            ->where('step_no', $stepNo)
            ->first();

        abort_unless($step, 422, 'Workflow step not found');

        return $step;
    }

    private static function buildContextFromRef(object $wf): array
    {
        $appCode = strtolower((string) ($wf->app_code ?? ''));

        if ($appCode === 'pr') {
            $pr = WorkflowDb::table($appCode, 'pr_data')->where('id', $wf->ref_id)->first();

            return [
                'department_id' => (int) ($pr->department_id ?? 0),
                'originator_id' => (int) ($wf->request_by_user_id ?? 0),
            ];
        }

        if ($appCode === 'po') {
            $po = WorkflowDb::table($appCode, 'po_headers')->where('id', $wf->ref_id)->first();
            $departmentId = $po ? PoErpService::resolveDepartmentId($po->f1 ?? null) : null;
            $submitterDepartmentId = WorkflowDb::table($appCode, 'users')
                ->where('id', (int) ($wf->request_by_user_id ?? 0))
                ->value('department_id');

            return [
                'department_id' => $departmentId ? (int) $departmentId : 0,
                'document_department_id' => $departmentId ? (int) $departmentId : 0,
                'submitter_department_id' => $submitterDepartmentId ? (int) $submitterDepartmentId : 0,
                'originator_id' => (int) ($wf->request_by_user_id ?? 0),
            ];
        }

        if ($appCode === 'pp') {
            $departmentId = WorkflowDb::table($appCode, 'users')
                ->where('id', (int) ($wf->request_by_user_id ?? 0))
                ->value('department_id');

            return [
                'department_id' => $departmentId ? (int) $departmentId : 0,
                'originator_id' => (int) ($wf->request_by_user_id ?? 0),
            ];
        }

        if ($appCode === 'wocr') {
            $departmentId = WorkflowDb::table($appCode, 'users')
                ->where('id', (int) ($wf->request_by_user_id ?? 0))
                ->value('department_id');

            return [
                'department_id' => $departmentId ? (int) $departmentId : 0,
                'originator_id' => (int) ($wf->request_by_user_id ?? 0),
            ];
        }

        if ($appCode === 'fc') {
            $submission = WorkflowDb::table($appCode, 'fc_rm_division_forecast_submissions')
                ->where('id', (int) ($wf->ref_id ?? 0))
                ->first();

            $departmentId = WorkflowDb::table($appCode, 'users')
                ->where('id', (int) ($wf->request_by_user_id ?? 0))
                ->value('department_id');

            return [
                'department_id' => (int) ($submission->department_id ?? ($departmentId ?: 0)),
                'originator_id' => (int) ($wf->request_by_user_id ?? 0),
                'sales_code' => (string) ($submission->sales_code ?? ''),
                'forecast_base_month' => (string) ($submission->forecast_base_month ?? ''),
            ];
        }

        return [];
    }
}
