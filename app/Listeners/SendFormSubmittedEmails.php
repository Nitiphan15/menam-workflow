<?php

namespace App\Listeners;

use App\Events\FormSubmitted;
use App\Mail\FormSubmittedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendFormSubmittedEmails implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(FormSubmitted $e): void
    {
        // 3.1 เมลยืนยันกลับไปยังผู้ส่ง
        Mail::to($e->ownerEmail)->send(
            new FormSubmittedMail(
                $e->appCode,
                $e->docNo,
                $e->ownerName,
                $e->reviewUrl,
                $e->attachmentsCount,
                $e->extra
            )
        );

        // 3.2 (ทางเลือก) ส่งแจ้ง "ผู้อนุมัติถัดไป"
        // ถ้าคุณมี ApproverResolver/WfForm เมธอด nextApprovers():
        //$emails = app(\App\Services\ApproverResolver::class)->nextApproverEmails($e->wfFormId);
        //if (!empty($emails)) {
        //    Mail::to($emails)->send(
        //        new \App\Mail\FormNeedsApprovalMail($e->appCode, $e->docNo, $e->reviewUrl, $e->extra)
        //    );
        //}
    }
}
