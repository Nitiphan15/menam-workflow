<?php

namespace App\Http\Controllers\FormExam;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

use App\Models\FormExam\FormExam;
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
            'mode' => 'required|in:append,replace',
        ]);

        DB::transaction(function () use ($request, $formExam) {
            // replace = ล้างข้อสอบเดิมของฟอร์มนี้ก่อน
            if ($request->mode === 'replace') {
                // ลบ choices ก่อน แล้วค่อยลบ questions (กัน FK)
                $formExam->questions()->each(function ($q) {
                    $q->choices()->delete();
                });
                $formExam->questions()->delete();
            }

            Excel::import(new ExamQuestionsImport($formExam->id), $request->file('file'));
        });

        return redirect()
            ->route('exam.master.edit', $formExam->id)
            ->with('success', 'Import ข้อสอบเรียบร้อยแล้ว');
    }
}
