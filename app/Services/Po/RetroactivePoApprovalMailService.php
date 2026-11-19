<?php

namespace App\Services\Po;

use App\Mail\PoNeedsApprovalMail;
use App\Models\Po\PoHeader;
use App\Support\SqlServerDb;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class RetroactivePoApprovalMailService
{
    private const PO_MAIL_BCC = 'itprogramming@menamstainless.co.th';
    private const AUDIT_PREFIX = 'Retroactive Step 3 approval email dispatched to ';

    public function inspect(string $ordnumber): array
    {
        $ordnumber = trim($ordnumber);
        $po = PoHeader::query()->with('workflow')->where('ordnumber', $ordnumber)->first();

        if (!$po) {
            throw new RuntimeException("ไม่พบ PO {$ordnumber}");
        }

        $workflow = $po->workflow;
        if (!$workflow
            || strtoupper((string) $po->status_code) !== 'DEPT_MANAGER_APPROVAL'
            || strtoupper((string) $workflow->form_status) !== 'REQUESTED'
            || (int) $workflow->current_step_no !== 3) {
            throw new RuntimeException('PO ต้องกำลังรออนุมัติที่ Step 3');
        }

        [$approvers, $unreachableApprovers] = SqlServerDb::table('wf_form_authorizes as auth_row')
            ->join('users as user_row', 'user_row.id', '=', 'auth_row.approver_user_id')
            ->where('auth_row.wf_form_id', (int) $workflow->id)
            ->where('auth_row.step_no', 3)
            ->where('auth_row.status', 'PENDING')
            ->orderBy('user_row.name')
            ->get(['user_row.id', 'user_row.username', 'user_row.name', 'user_row.email', 'user_row.is_active'])
            ->unique('id')
            ->partition(fn ($row) => (int) $row->is_active === 1 && trim((string) $row->email) !== '');

        if ($approvers->isEmpty()) {
            throw new RuntimeException('ไม่พบผู้อนุมัติ PENDING ที่ใช้งานอยู่และมีอีเมลใน Step 3');
        }

        $dispatchedComments = SqlServerDb::table('wf_action_histories')
            ->where('wf_form_id', (int) $workflow->id)
            ->where('step_no', 3)
            ->where('action_type', 'NOTIFY')
            ->pluck('comment');

        $approvers = $approvers->values()->each(function ($approver) use ($ordnumber, $dispatchedComments) {
            $approver->audit_comment = self::AUDIT_PREFIX . $approver->username . ' for ' . $ordnumber;
            $approver->already_dispatched = $dispatchedComments->contains($approver->audit_comment);
        });

        return [
            'po' => $po,
            'workflow' => $workflow,
            'approvers' => $approvers,
            'unreachable_approvers' => $unreachableApprovers->values(),
            'already_dispatched' => $approvers->every(fn ($approver) => $approver->already_dispatched),
        ];
    }

    public function send(string $ordnumber, bool $allowDuplicate = false): array
    {
        $context = $this->inspect($ordnumber);
        $recipients = $allowDuplicate
            ? $context['approvers']
            : $context['approvers']->reject(fn ($approver) => $approver->already_dispatched)->values();

        if ($recipients->isEmpty()) {
            return $context + ['delivery' => 'already', 'sent_to' => collect()];
        }

        $po = $context['po'];
        $workflow = $context['workflow'];

        foreach ($recipients as $approver) {
            // Audit first: a failed insert sends no mail, and a failed send rolls the audit back.
            SqlServerDb::transaction(function () use ($po, $workflow, $approver) {
                SqlServerDb::table('wf_action_histories')->insert([
                    'wf_form_id' => (int) $workflow->id,
                    'step_no' => 3,
                    'actor_user_id' => (int) $approver->id,
                    'action_type' => 'NOTIFY',
                    'comment' => $approver->audit_comment,
                    'created_at' => now(),
                ]);

                Mail::to((string) $approver->email)
                    ->bcc(self::PO_MAIL_BCC)
                    ->send(new PoNeedsApprovalMail(
                        approverName: (string) ($approver->name ?: $approver->username),
                        poItems: [[
                            'ordnumber' => $po->ordnumber,
                            'source_label' => PoErpService::sourceLabel($po->site),
                            'department' => $po->f1,
                            'vendor_name' => $po->vendor_name,
                            'status_code' => $po->status_code,
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
            });
        }

        return $context + ['delivery' => 'sent', 'sent_to' => $recipients];
    }
}
