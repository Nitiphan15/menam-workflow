<?php

namespace App\Exports\FormExam;

use App\Models\FormExam\FormExam;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ExamSessionsExport implements FromCollection, WithHeadings
{
    protected $sessions;
    protected $formExam;

    public function __construct(Collection $sessions, FormExam $formExam)
    {
        $this->sessions = $sessions;
        $this->formExam = $formExam;
    }

    public function collection()
    {
        return $this->sessions->map(function ($s) {
            $typeCode = $s->examType->code ?? '';
            $typeName = strtoupper($typeCode) . ' Test';

            return [
                'form_name'    => $this->formExam->name,
                'full_name'    => $s->full_name,
                'exam_type'    => $typeName,
                'score'        => $s->total_score . ' / ' . $s->max_score,
                'status'       => $this->renderStatusText($s),
                'submitted_at' => optional($s->submitted_at)->format('Y-m-d H:i'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ฟอร์ม',
            'ชื่อผู้ทำแบบทดสอบ',
            'ประเภท',
            'คะแนน',
            'สถานะ',
            'วันที่ทำ',
        ];
    }

    protected function renderStatusText($session): string
    {
        // Pre: ไม่มีเกณฑ์ผ่าน
        if (($session->examType->code ?? null) === 'pre') {
            return 'Pre Test (ไม่มีเกณฑ์ผ่าน)';
        }

        if ($session->is_pass === 1) {
            return 'ผ่าน';
        }

        if ($session->is_pass === 0) {
            return 'ไม่ผ่าน';
        }

        return '-';
    }
}
