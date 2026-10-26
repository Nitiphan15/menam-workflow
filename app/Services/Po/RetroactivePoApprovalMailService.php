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
    private const APPROVER_USERNAME = 'jittinan_k';
    private const AUDIT_PREFIX = 'Retroactive Step 3 approval email dispatched to jittinan_k';

    public function inspect(string $ordnumber): array
    {
        $ordnumber = trim($ordnumber);
        $po = PoHeader::query()->with('workflow')->where('ordnumber', $ordnumber)->first();

        if (!$po) {
            throw new RuntimeException("ไม่พบ PO {$ordnumber}");
        }

        $workflow = $po->workflow;
        if (!$workflow
            || !str_starts_with(strtoupper(trim((string) $po->f1)), 'FACTORY')
            || strtoupper((string) $po->status_code) !== 'DEPT_MANAGER_APPROVAL'
            || strtoupper((string) $workflow->form_status) !== 'REQUESTED'
            || (int) $workflow->current_step_no !== 3) {
            throw new RuntimeException('PO ต้องเป็น Factory และกำลังรออนุมัติที่ Step 3');
        }

        $approver = SqlServerDb::table('wf_form_authorizes as auth_row')
            ->join('users as user_row', 'user_row.id', '=', 'auth_row.approver_user_id')
            ->where('auth_row.wf_form_id', (int) $workflow->id)
            ->where('auth_row.step_no', 3)
            ->where('auth_row.status', 'PENDING')
            ->where('user_row.username', self::APPROVER_USERNAME)
            ->where('user_row.is_active', 1)
            ->whereNotNull('user_row.email')
            ->first(['user_row.id', 'user_row.name', 'user_row.email']);

        if (!$approver || trim((string) $approver->email) === '') {
            throw new RuntimeException('ไม่พบสิทธิ์อนุมัติ PENDING หรืออีเมลของ Jittinan');
        }

        $auditComment = self::AUDIT_PREFIX . ' for ' . $ordnumber;

        return [
            'po' => $po,
            'workflow' => $workflow,
            'approver' => $approver,
            'audit_comment' => $auditComment,
            'already_dispatched' => SqlServerDb::table('wf_action_histories')
                ->where('wf_form_id', (int) $workflow->id)
                ->where('step_no', 3)
                ->where('action_type', 'NOTIFY')
                ->where('comment', $auditComment)
                ->exists(),
        ];
    }

    public function send(string $ordnumber): array
    {
        $context = $this->inspect($ordnumber);
        if ($context['already_dispatched']) {
            return $context + ['delivery' => 'already'];
        }

        $po = $context['po'];
        $workflow = $context['workflow'];
        $approver = $context['approver'];
        Mail::to((string) $approver->email)
            ->bcc(self::PO_MAIL_BCC)
            ->send(new PoNeedsApprovalMail(
                approverName: (string) ($approver->name ?: self::APPROVER_USERNAME),
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

        SqlServerDb::table('wf_action_histories')->insert([
            'wf_form_id' => (int) $workflow->id,
            'step_no' => 3,
            'actor_user_id' => (int) $approver->id,
            'action_type' => 'NOTIFY',
            'comment' => $context['audit_comment'],
            'created_at' => now(),
        ]);

        return $context + ['delivery' => 'sent'];
    }
}
