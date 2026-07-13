<?php

namespace App\Console\Commands;

use App\Mail\WocrPendingDigestMail;
use App\Models\FormWOCR\WocrData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ส่งอีเมลสรุป "งาน WOCR ที่ยังไม่ได้ดำเนินการ" ให้ Planner (ผู้อนุมัติที่ยัง PENDING)
 * ใช้รันทุกเช้าผ่าน scheduler
 */
class SendWocrPendingDigest extends Command
{
    protected $signature = 'wocr:pending-digest {--dry-run : แสดงผลโดยไม่ส่งอีเมลจริง}';

    protected $description = 'ส่งอีเมลสรุปงาน WOCR ที่ยังรอดำเนินการให้ Planner ทุกเช้า';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ดึงงาน WOCR ที่ยังรอผู้อนุมัติของ step ปัจจุบัน (PENDING) — งานที่ approve/reject แล้วจะไม่ถูกดึง
        $rows = DB::connection('mysql')->table('wf_form_authorizes as wa')
            ->join('wf_forms as f', 'f.id', '=', 'wa.wf_form_id')
            ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
            ->leftJoin('wocr_data as d', 'd.form_id', '=', 'f.id')
            ->leftJoin('users as ru', 'ru.id', '=', 'f.request_by_user_id')
            ->whereRaw('LOWER(f.app_code) = ?', ['wocr'])
            ->where('u.is_active', 1)
            ->where('wa.status', 'PENDING')
            ->whereColumn('wa.step_no', 'f.current_step_no')
            ->select(
                'wa.approver_user_id',
                'u.email as approver_email',
                'u.name as approver_name',
                'f.id as wf_form_id',
                'd.docu_no',
                'd.urgency',
                'd.mfg_no',
                'd.req_date',
                'ru.name as requester_name'
            )
            ->orderByDesc('d.urgency')
            ->orderBy('d.req_date')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('ไม่มีงาน WOCR ที่รอดำเนินการ');
            return self::SUCCESS;
        }

        // group ตาม Planner (ผู้อนุมัติ)
        $byApprover = $rows->groupBy('approver_user_id');
        $sent = 0;

        foreach ($byApprover as $approverId => $group) {
            $first = $group->first();
            $email = $first->approver_email ?? null;

            if (blank($email)) {
                $this->warn("ข้าม approver_user_id={$approverId} (ไม่มีอีเมล)");
                continue;
            }

            $items = $group->map(function ($r) {
                return (object) [
                    'docu_no'        => $r->docu_no,
                    'urgency'        => (int) $r->urgency,
                    'urgency_label'  => WocrData::urgencyText((int) $r->urgency),
                    'mfg_no'         => $r->mfg_no,
                    'requester_name' => $r->requester_name,
                    'req_date'       => $r->req_date,
                    'review_url'     => route('wocr.planner', ['id' => $r->wf_form_id]),
                ];
            })->values();

            if ($dryRun) {
                $this->line("[dry-run] -> {$email} : {$items->count()} รายการ");
                continue;
            }

            try {
                Mail::to($email)->send(new WocrPendingDigestMail($first->approver_name, $items));
                $sent++;
            } catch (\Throwable $e) {
                Log::error('WOCR pending digest mail failed: ' . $e->getMessage(), [
                    'to' => $email,
                    'approver_user_id' => $approverId,
                ]);
                $this->error("ส่งไม่สำเร็จ -> {$email}: {$e->getMessage()}");
            }
        }

        $this->info($dryRun
            ? "[dry-run] พบ Planner {$byApprover->count()} คน, รวม {$rows->count()} งาน"
            : "ส่งอีเมลสรุปแล้ว {$sent} ฉบับ (Planner {$byApprover->count()} คน, รวม {$rows->count()} งาน)");

        return self::SUCCESS;
    }
}
