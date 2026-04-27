<?php

namespace App\Http\Controllers\FormExam;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

use App\Models\FormExam\FormExam;
use App\Models\FormExam\ExamType;
use App\Models\FormExam\Question;
use App\Models\FormExam\Choice;
use App\Imports\FormExam\ExamQuestionsImport;
use App\Exports\FormExam\ExamImportTemplateExport;

class ExamImportController extends Controller
{
    public function create(FormExam $formExam)
    {
        return view('formexam.master.import', compact('formExam'));
    }

    public function downloadTemplate(FormExam $formExam)
    {
        $fileName = 'exam_import_template_form_' . $formExam->id . '.xlsx';
        return Excel::download(new ExamImportTemplateExport(), $fileName);
    }

    public function store(Request $request, FormExam $formExam)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'mode' => 'required|in:append,replace,replace_pre,replace_post,replace_all',
            'default_exam_type' => 'nullable|in:pre,post',
        ]);

        $mode = $request->input('mode');
        if ($mode === 'replace') {
            $mode = 'replace_all';
        }

        $defaultCode = (string) ($request->input('default_exam_type') ?? 'pre');

        DB::transaction(function () use ($request, $formExam, $defaultCode, $mode) {
            // 1) Replace mode = ล้างเฉพาะตามที่เลือก

            if ($mode !== 'append') {
                $typeIds = $this->resolveReplaceTypeIds($formExam, $mode);
                $this->deleteQuestionsByTypeIds($formExam->id, $typeIds);
            }

            // 2) Import
            Excel::import(
                new ExamQuestionsImport($formExam->id, $defaultCode),
                $request->file('file')
            );
        });

        return redirect()
            ->route('exam.master.edit', $formExam->id)
            ->with('success', 'Import ข้อสอบเรียบร้อยแล้ว');
    }

    /**
     * @return int[] type ids ที่ต้องล้างก่อน import
     */
    protected function resolveReplaceTypeIds(FormExam $formExam, string $mode): array
    {
        if ($mode === 'replace_all') {
            return $formExam->examTypes()->pluck('id')->all();
        }

        $code = $mode === 'replace_pre' ? 'pre' : 'post';

        $typeId = $formExam->examTypes()
            ->where('code', $code)
            ->value('id');

        if (!$typeId) {
            abort(422, "ไม่พบ Exam Type '{$code}' ในฟอร์มนี้");
        }

        return [(int) $typeId];
    }

    /**
     * ลบ choices ก่อน แล้วค่อยลบ questions (กัน FK)
     */
    protected function deleteQuestionsByTypeIds(int $formExamId, array $typeIds): void
    {
        if (empty($typeIds)) {
            return;
        }

        $qIds = Question::query()
            ->where('exam_form_id', $formExamId)
            ->whereIn('exam_type_id', $typeIds)
            ->pluck('id')
            ->all();

        if (empty($qIds)) {
            return;
        }

        Choice::query()->whereIn('exam_question_id', $qIds)->delete();
        Question::query()->whereIn('id', $qIds)->delete();
    }
}
