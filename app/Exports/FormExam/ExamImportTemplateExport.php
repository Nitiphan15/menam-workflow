<?php

namespace App\Exports\FormExam;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class ExamImportTemplateExport implements FromArray, WithHeadings, WithEvents
{
    public function headings(): array
    {
        return [
            'category',
            'question',
            'A',
            'B',
            'C',
            'D',
            'correct',
            'is_active',
            'exam_type', // pre/post/both
        ];
    }

    public function array(): array
    {
        return [
            // sample row (แก้/ลบได้)
            ['ตัวอย่างหมวด', 'ตัวอย่างคำถาม', 'ตัวเลือก A', 'ตัวเลือก B', 'ตัวเลือก C', 'ตัวเลือก D', 'A', 1, 'pre'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // ตั้งความกว้างคอลัมน์ให้อ่านง่าย
                $widths = [
                    'A' => 22, 'B' => 55,
                    'C' => 24, 'D' => 24, 'E' => 24, 'F' => 24,
                    'G' => 10, 'H' => 10, 'I' => 12,
                ];
                foreach ($widths as $col => $w) {
                    $sheet->getColumnDimension($col)->setWidth($w);
                }

                // Freeze header
                $sheet->freezePane('A2');

                // Data validations for rows 2..2000 (พอใช้งานทั่วไป)
                $start = 2;
                $end   = 2000;

                // correct (G): A/B/C/D
                $dvCorrect = new DataValidation();
                $dvCorrect->setType(DataValidation::TYPE_LIST);
                $dvCorrect->setErrorStyle(DataValidation::STYLE_STOP);
                $dvCorrect->setAllowBlank(false);
                $dvCorrect->setShowInputMessage(true);
                $dvCorrect->setShowErrorMessage(true);
                $dvCorrect->setErrorTitle('ค่าถูกต้อง');
                $dvCorrect->setError('กรุณาเลือก A/B/C/D');
                $dvCorrect->setPromptTitle('correct');
                $dvCorrect->setPrompt('เลือกคำตอบที่ถูก: A, B, C หรือ D');
                $dvCorrect->setFormula1('"A,B,C,D"');

                // is_active (H): 0/1
                $dvActive = new DataValidation();
                $dvActive->setType(DataValidation::TYPE_LIST);
                $dvActive->setErrorStyle(DataValidation::STYLE_STOP);
                $dvActive->setAllowBlank(false);
                $dvActive->setShowInputMessage(true);
                $dvActive->setShowErrorMessage(true);
                $dvActive->setErrorTitle('ค่าถูกต้อง');
                $dvActive->setError('กรุณาเลือก 0 หรือ 1');
                $dvActive->setPromptTitle('is_active');
                $dvActive->setPrompt('1 = เปิดใช้งาน, 0 = ปิด');
                $dvActive->setFormula1('"0,1"');

                // exam_type (I): pre/post/both
                $dvType = new DataValidation();
                $dvType->setType(DataValidation::TYPE_LIST);
                $dvType->setErrorStyle(DataValidation::STYLE_STOP);
                $dvType->setAllowBlank(true);
                $dvType->setShowInputMessage(true);
                $dvType->setShowErrorMessage(true);
                $dvType->setErrorTitle('ค่าถูกต้อง');
                $dvType->setError('กรุณาเลือก pre/post/both');
                $dvType->setPromptTitle('exam_type');
                $dvType->setPrompt('pre = Pre-Test, post = Post-Test, both = ใช้ทั้งคู่');
                $dvType->setFormula1('"pre,post,both"');

                for ($r = $start; $r <= $end; $r++) {
                    $sheet->getCell('G'.$r)->setDataValidation(clone $dvCorrect);
                    $sheet->getCell('H'.$r)->setDataValidation(clone $dvActive);
                    $sheet->getCell('I'.$r)->setDataValidation(clone $dvType);
                }
            },
        ];
    }
}
