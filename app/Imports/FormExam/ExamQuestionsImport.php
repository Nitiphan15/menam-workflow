<?php

namespace App\Imports\FormExam;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

use App\Models\FormExam\FormExam;
use App\Models\FormExam\ExamType;
use App\Models\FormExam\Category;
use App\Models\FormExam\Question;
use App\Models\FormExam\Choice;

class ExamQuestionsImport implements ToCollection, WithHeadingRow
{
    public function __construct(
        protected int $formExamId,
        protected string $defaultExamTypeCode = 'pre'
    ) {}

    public function headingRow(): int
    {
        return 1;
    }

    public function collection(Collection $rows)
    {
        $formExam = FormExam::findOrFail($this->formExamId);

        // Map exam types by code (pre/post)
        $typeByCode = $formExam->examTypes()
            ->select('id', 'code')
            ->get()
            ->keyBy(function ($t) {
                return strtolower((string) $t->code);
            });

        foreach ($rows as $i => $row) {
            $rowNo = $i + 2; // because heading row = 1

            $questionText = trim((string) ($row['question'] ?? ''));
            if ($questionText === '') {
                // skip blank rows
                continue;
            }

            $categoryName = trim((string) ($row['category'] ?? ''));
            if ($categoryName === '') {
                $categoryName = 'ไม่ระบุหมวด';
            }

            // A-D (รองรับคีย์ a,b,c,d หรือ A,B,C,D)
            $A = $this->val($row, 'a', 'A');
            $B = $this->val($row, 'b', 'B');
            $C = $this->val($row, 'c', 'C');
            $D = $this->val($row, 'd', 'D');

            $choices = array_values(array_filter([$A, $B, $C, $D], fn($v) => $v !== ''));
            if (count($choices) < 2) {
                throw new \RuntimeException("Row {$rowNo}: ต้องมีตัวเลือกอย่างน้อย 2 ข้อ (A-D)");
            }

            $correct = strtoupper(trim((string) ($row['correct'] ?? '')));
            if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                throw new \RuntimeException("Row {$rowNo}: correct ต้องเป็น A/B/C/D");
            }

            $isActive = (int) ($row['is_active'] ?? 1);
            $isActive = $isActive ? 1 : 0;

            // exam_type: pre/post/both (ถ้าไม่มีคอลัมน์/ว่าง ใช้ defaultExamTypeCode)
            $examTypeRaw = strtolower(trim((string) ($row['exam_type'] ?? '')));
            if ($examTypeRaw === '') {
                $examTypeRaw = strtolower($this->defaultExamTypeCode);
            }

            $targets = match ($examTypeRaw) {
                'pre'  => ['pre'],
                'post' => ['post'],
                'both' => ['pre', 'post'],
                default => throw new \RuntimeException("Row {$rowNo}: exam_type ต้องเป็น pre/post/both"),
            };

            // category: find or create per form
            $category = Category::firstOrCreate(
                ['exam_form_id' => $formExam->id, 'name' => $categoryName],
                ['is_active' => 1, 'created_by' => auth()->id(), 'updated_by' => auth()->id()]
            );

            foreach ($targets as $code) {
                $type = $typeByCode[$code] ?? null;
                if (!$type) {
                    throw new \RuntimeException("Row {$rowNo}: ไม่พบ Exam Type '{$code}' ในฟอร์มนี้");
                }

                $question = Question::create([
                    'exam_form_id'     => $formExam->id,
                    'exam_type_id'     => (int) $type->id,
                    'exam_category_id' => (int) $category->id,
                    'question_text'    => $questionText,
                    'is_active'        => $isActive,
                    'created_by'       => auth()->id(),
                    'updated_by'       => auth()->id(),
                ]);

                // สร้าง choices A-D (คงโครง 4 ตัวเลือกตาม template)
                $map = [
                    'A' => $A,
                    'B' => $B,
                    'C' => $C,
                    'D' => $D,
                ];

                foreach ($map as $label => $text) {
                    $text = trim((string) $text);
                    if ($text === '') {
                        // ถ้าว่างให้ข้าม (แต่ต้องมีอย่างน้อย 2 ซึ่งเช็คไปแล้ว)
                        continue;
                    }
                    Choice::create([
                        'exam_question_id' => $question->id,
                        'choice_text'      => $text,
                        'is_correct'       => $label === $correct,
                        'is_active'        => 1,
                        'created_by'       => auth()->id(),
                        'updated_by'       => auth()->id(),
                    ]);
                }
            }
        }
    }

    protected function val($row, string $k1, string $k2): string
    {
        $v = $row[$k1] ?? $row[$k2] ?? '';
        return trim((string) $v);
    }
}
