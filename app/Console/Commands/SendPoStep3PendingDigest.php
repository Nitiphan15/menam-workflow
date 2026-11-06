<?php

namespace App\Console\Commands;

use App\Mail\PoStep3PendingDigestMail;
use App\Services\Po\PoErpService;
use App\Support\SqlServerDb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPoStep3PendingDigest extends Command
{
    protected $signature = 'po:send-step3-pending-digest {--dry-run : แสดงจำนวนรายการโดยไม่ส่งอีเมล}';

    protected $description = 'ส่งอีเมลสรุป PO ที่ยังรอผู้อนุมัติ Step 3 แยกตามผู้อนุมัติ';

    public function handle(): int
    {
        $rows = SqlServerDb::table('wf_form_authorizes as wa')
            ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
            ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
            ->join('po_headers as po', 'po.workflow_id', '=', 'wf.id')
            ->whereRaw('LOWER(wf.app_code) = ?', ['po'])
            ->where('wf.current_step_no', 3)
            ->where('wa.step_no', 3)
            ->where('wa.status', 'PENDING')
            ->where('u.is_active', 1)
            ->whereNotNull('u.email')
            ->whereRaw("LTRIM(RTRIM(u.email)) <> ''")
            ->select([
                'wa.approver_user_id',
                'u.name as approver_name',
                'u.email as approver_email',
                'po.id as po_id',
                'po.site',
                'po.ordnumber',
                'po.transdate',
                'po.vendor_name',
                'po.requester_name',
                'po.f1 as department',
                'po.netamount',
                'po.curr',
            ])
            ->orderBy('u.name')
            ->orderBy('po.transdate')
            ->orderBy('po.ordnumber')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('ไม่มี PO Step 3 ที่รออนุมัติ');

            return self::SUCCESS;
        }

        $groups = $rows->groupBy('approver_user_id');
        $sent = 0;
        $failed = 0;

        foreach ($groups as $approverId => $group) {
            $first = $group->first();
            $email = trim((string) $first->approver_email);
            $items = $group
                ->unique('po_id')
                ->map(fn ($row) => [
                    'ordnumber' => (string) $row->ordnumber,
                    'source_label' => PoErpService::sourceLabel($row->site),
                    'transdate' => $row->transdate,
                    'vendor_name' => $row->vendor_name,
                    'requester_name' => $row->requester_name,
                    'department' => $row->department,
                    'netamount' => $row->netamount,
                    'curr' => $row->curr,
                    'approve_url' => route('po.show', $row->po_id),
                ])
                ->values()
                ->all();

            if ($this->option('dry-run')) {
                $this->line("[dry-run] approver_user_id={$approverId} -> {$email}: ".count($items).' รายการ');

                continue;
            }

            try {
                Mail::to($email)->send(new PoStep3PendingDigestMail(
                    approverName: (string) ($first->approver_name ?: $email),
                    poItems: $items,
                ));
                $sent++;
            } catch (\Throwable $exception) {
                $failed++;
                Log::error('PO Step 3 pending digest mail failed', [
                    'approver_user_id' => $approverId,
                    'to' => $email,
                    'item_count' => count($items),
                    'error' => $exception->getMessage(),
                ]);
                $this->error("ส่งไม่สำเร็จ approver_user_id={$approverId} -> {$email}: {$exception->getMessage()}");
            }
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] พบผู้อนุมัติ {$groups->count()} คน รวม {$rows->unique('po_id')->count()} PO");

            return self::SUCCESS;
        }

        $this->info("ส่งสำเร็จ {$sent} ฉบับ; ไม่สำเร็จ {$failed} ฉบับ");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
