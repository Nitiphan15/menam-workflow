<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FormSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $appCode,           // 'PR' | 'PP' | 'WOCR' ...
        public int|string $wfFormId,      // wf_forms.id หรืออะไรก็ได้อ้างถึงเอกสาร
        public string $docNo,             // เลขเอกสาร (เช่น PR-2025-00123)
        public int|string $ownerUserId,   // ผู้ส่ง/เจ้าของเอกสาร
        public string $ownerEmail,        // อีเมลผู้ส่ง (แจ้งยืนยัน)
        public ?string $ownerName = null, // ชื่อผู้ส่ง (โชว์ในเมล)
        public ?string $reviewUrl = null, // URL ให้กดดูเอกสาร
        public int $attachmentsCount = 0, // จำนวนไฟล์แนบ (ถ้ามี)
        public array $extra = []          // field เสริมแต่ละ app (เช่น part_no, customer ฯลฯ)
    ) {}
}
