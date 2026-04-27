<?php

namespace App\Http\Controllers\FormExam;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\FormExam\FormExam;
use App\Models\FormExam\ExamType;
use App\Models\FormExam\Category;
use App\Models\FormExam\Question;
use App\Models\FormExam\Choice;
use App\Models\FormExam\ExamSession;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\FormExam\ExamSessionsExport;

class FormExamMasterController extends Controller
{
    public function index()
    {
        $forms = FormExam::withCount('questions')->get();
        return view('formexam.master.index', compact('forms'));
    }

    public function create()
    {
        return view('formexam.master.form_create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        DB::transaction(function () use ($data) {
            $form = FormExam::create($data);

            // สร้าง exam_types default pre/post ให้ฟอร์มนี้
            ExamType::create([
                'exam_form_id' => $form->id,   // แก้ตรงนี้
                'code'         => 'pre',
                'name'         => 'Pre Test',
                'pass_score'   => null,
            ]);

            ExamType::create([
                'exam_form_id' => $form->id,   // แก้ตรงนี้
                'code'         => 'post',
                'name'         => 'Post Test',
                'pass_score'   => 0,
            ]);
        });

        return redirect()
            ->route('exam.master.index')
            ->with('success', 'สร้างฟอร์มแบบทดสอบเรียบร้อยแล้ว');
    }

    public function edit(FormExam $formExam)
    {
        $formExam->load(['examTypes', 'categories']);
        return view('formexam.master.form', compact('formExam'));
    }

    public function update(Request $request, FormExam $formExam)
    {
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $formExam->update($data);

        return back()->with('success', 'บันทึกข้อมูลฟอร์มเรียบร้อยแล้ว');
    }

    public function updateExamType(Request $request, FormExam $formExam, ExamType $examType)
    {
        // กัน route ผิดฟอร์ม
        abort_unless($examType->exam_form_id == $formExam->id, 404);

        // ถ้าเป็น Pre → ไม่ให้ตั้งเกณฑ์ผ่าน บังคับเป็น null
        if ($examType->code === 'pre') {
            $examType->update([
                'pass_score' => null,
            ]);

            return back()->with('success', 'บันทึกข้อมูล Pre Test เรียบร้อยแล้ว');
        }

        // Post เท่านั้นที่ให้ตั้งเกณฑ์ได้
        $data = $request->validate([
            'pass_score' => 'nullable|integer|min:0',
        ]);

        $examType->update([
            'pass_score' => $data['pass_score'],
        ]);

        return back()->with('success', 'บันทึกเกณฑ์คะแนนผ่านเรียบร้อยแล้ว');
    }

    public function updateCategory(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category->update($data);

        return back()->with('success', 'แก้ไขหัวข้อเรียบร้อยแล้ว');
    }


    public function storeCategory(Request $request, FormExam $formExam)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Category::create([
            'exam_form_id' => $formExam->id,  // แก้ตรงนี้
            'name'         => $data['name'],
        ]);

        return back()->with('success', 'เพิ่มหัวข้อเรียบร้อยแล้ว');
    }

    public function destroyCategory(Category $category)
    {
        $category->delete();
        return back()->with('success', 'ลบหัวข้อเรียบร้อยแล้ว');
    }

    public function questions(Request $request, FormExam $formExam)
    {
        // 1) exam types สำหรับ tab
        $examTypes = $formExam->examTypes()->orderByRaw("FIELD(code,'pre','post')")->get();

        // 2) เลือก type ที่ active (รับจาก querystring ?exam_type_id=xx)
        $activeTypeId = (int) $request->query('exam_type_id', 0);

        // ถ้าไม่ส่งมา ให้ default เป็นตัวแรก (เช่น pre)
        if ($activeTypeId <= 0 && $examTypes->count()) {
            $activeTypeId = (int) $examTypes->first()->id;
        }

        // 3) ดึง questions เฉพาะ type ที่เลือก
        $q = $formExam->questions()->with('category')
            ->orderBy('exam_category_id')
            ->orderBy('id');

        // ถ้าในระบบคุณให้ questions ผูกกับ exam_type_id (pre/post) ก็กรองตรงนี้
        if ($activeTypeId > 0) {
            $q->where('exam_type_id', $activeTypeId);
        }

        $groupedQuestions = $q->get()->groupBy(function ($q) {
            return optional($q->category)->name ?? 'ไม่ระบุหมวด';
        });

        return view('formexam.master.questions', [
            'formExam'         => $formExam,
            'examTypes'        => $examTypes,
            'activeTypeId'     => $activeTypeId,
            'groupedQuestions' => $groupedQuestions,
        ]);
    }

    public function createQuestion(FormExam $formExam)
    {
        $categories = $formExam->categories()->orderBy('name')->get();

        return view('formexam.master.question_form', [
            'formExam'   => $formExam,
            'categories' => $categories,
            'question'   => null,
            'mode'       => 'create',
        ]);
    }

    public function storeQuestion(Request $request, FormExam $formExam)
    {
        $data = $this->validateQuestion($request);

        DB::transaction(function () use ($data, $formExam) {
            $question = Question::create([
                'exam_form_id'   => $formExam->id,
                'exam_type_id'   => $data['exam_type_id'],
                'exam_category_id' => $data['category_id'],
                'question_text'  => $data['question_text'],
                'is_active'      => 1,
            ]);

            foreach ($data['choices'] as $index => $choiceText) {
                if (trim($choiceText) === '') {
                    continue;
                }

                Choice::create([
                    'exam_question_id' => $question->id,
                    'choice_text'      => $choiceText,
                    'is_correct'       => ((int)$data['correct_index'] === (int)$index) ? 1 : 0,
                ]);
            }
        });

        return redirect()
            ->route('exam.master.questions.index', $formExam->id)
            ->with('success', 'เพิ่มข้อสอบเรียบร้อยแล้ว');
    }

    public function copyQuestion(FormExam $formExam, Question $question)
    {
        $targetType = ExamType::where('exam_form_id', $formExam->id)
            ->where('id', '!=', $question->exam_type_id)
            ->firstOrFail();

        DB::transaction(function () use ($question, $targetType, $formExam) {

            $newQuestion = $question->replicate();
            $newQuestion->exam_type_id = $targetType->id;
            $newQuestion->save();

            foreach ($question->choices as $choice) {
                $newChoice = $choice->replicate();
                // FK ของ choice คือ exam_question_id
                $newChoice->exam_question_id = $newQuestion->id;
                $newChoice->save();
            }
        });

        return back()->with('success', 'คัดลอกข้อสอบเรียบร้อย');
    }


    public function editQuestion(FormExam $formExam, Question $question)
    {
        if ($question->exam_form_id !== $formExam->id) {   // แก้ตรงนี้
            abort(404);
        }

        $question->load('choices');
        $categories = $formExam->categories()->orderBy('name')->get();

        return view('formexam.master.question_form', [
            'formExam'   => $formExam,
            'categories' => $categories,
            'question'   => $question,
            'mode'       => 'edit',
        ]);
    }

    public function updateQuestion(Request $request, FormExam $formExam, Question $question)
    {
        if ($question->exam_form_id !== $formExam->id) {   // แก้ตรงนี้
            abort(404);
        }

        $data = $this->validateQuestion($request);

        DB::transaction(function () use ($data, $question) {

            $question->update([
                'exam_category_id' => $data['category_id'],
                'question_text'    => $data['question_text'],
            ]);

            $question->choices()->delete();

            foreach ($data['choices'] as $index => $choiceText) {
                if (trim($choiceText) === '') {
                    continue;
                }

                Choice::create([
                    'exam_question_id' => $question->id,
                    'choice_text'      => $choiceText,
                    'is_correct'       => ((int)$data['correct_index'] === (int)$index) ? 1 : 0,
                ]);
            }
        });

        return redirect()
            ->route('exam.master.questions.index', $formExam->id)
            ->with('success', 'แก้ไขข้อสอบเรียบร้อยแล้ว');
    }

    public function sessions(Request $request, FormExam $formExam)
    {
        $examTypeCode = $request->input('exam_type');    // pre / post / null
        $dateFrom     = $request->input('date_from');    // YYYY-MM-DD
        $dateTo       = $request->input('date_to');      // YYYY-MM-DD

        $query = ExamSession::where('exam_form_id', $formExam->id)
            ->with('examType')
            ->orderByDesc('submitted_at');

        // filter ประเภท Pre / Post
        if ($examTypeCode && in_array($examTypeCode, ['pre', 'post'], true)) {
            $query->whereHas('examType', function ($q) use ($examTypeCode) {
                $q->where('code', $examTypeCode);
            });
        }

        // filter ช่วงวันที่ (อิง submitted_at)
        if ($dateFrom) {
            $query->whereDate('submitted_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('submitted_at', '<=', $dateTo);
        }

        $sessions = $query->get();

        return view('formexam.master.sessions', [
            'formExam' => $formExam,
            'sessions' => $sessions,
            'filters'  => [
                'exam_type' => $examTypeCode,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
            ],
        ]);
    }

    public function sessionsExport(Request $request, FormExam $formExam)
    {
        $examTypeCode = $request->input('exam_type');
        $dateFrom     = $request->input('date_from');
        $dateTo       = $request->input('date_to');

        $query = ExamSession::where('exam_form_id', $formExam->id)
            ->with('examType')
            ->orderByDesc('submitted_at');

        if ($examTypeCode && in_array($examTypeCode, ['pre', 'post'], true)) {
            $query->whereHas('examType', function ($q) use ($examTypeCode) {
                $q->where('code', $examTypeCode);
            });
        }

        if ($dateFrom) {
            $query->whereDate('submitted_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('submitted_at', '<=', $dateTo);
        }

        $sessions = $query->get();

        $fileName = 'exam_sessions_form_' . $formExam->id . '.xlsx';

        return Excel::download(new ExamSessionsExport($sessions, $formExam), $fileName);
    }


    protected function validateQuestion(Request $request): array
    {
        $data = $request->validate([
            'exam_type_id'  => 'required|exists:exam_types,id',
            'category_id'   => 'required|exists:exam_categories,id',
            'question_text' => 'required|string',
            'choices'       => 'required|array|min:2',
            'choices.*'     => 'nullable|string',
            'correct_index' => 'required|integer',
        ]);

        if (!array_key_exists($data['correct_index'], $data['choices'])) {
            abort(422, 'กรุณาเลือกเฉลยให้ถูกต้อง');
        }

        return $data;
    }

    public function deactivate(FormExam $formExam)
    {
        $formExam->update(['is_active' => 0]);
        return back()->with('success', 'ปิดการใช้งานฟอร์มแล้ว');
    }

    public function activate(FormExam $formExam)
    {
        $formExam->update(['is_active' => 1]);
        return back()->with('success', 'เปิดการใช้งานฟอร์มแล้ว');
    }

    public function deactivateQuestion(FormExam $formExam, Question $question)
    {
        if ($question->exam_form_id !== $formExam->id) {
            abort(404);
        }

        $question->update(['is_active' => 0]);
        return back()->with('success', 'ปิดการใช้งานคำถามแล้ว');
    }

    public function activateQuestion(FormExam $formExam, Question $question)
    {
        if ($question->exam_form_id !== $formExam->id) {
            abort(404);
        }

        $question->update(['is_active' => 1]);
        return back()->with('success', 'เปิดการใช้งานคำถามแล้ว');
    }
}
