<?php

namespace App\Http\Controllers\FormExam;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FormExam\FormExam;
use App\Models\FormExam\ExamType;
use App\Models\FormExam\Question;
use App\Models\FormExam\Choice;
use App\Models\FormExam\ExamSession;
use App\Models\FormExam\ExamAnswer;
use Illuminate\Support\Facades\DB;

class ExamController extends Controller
{
    // หน้าให้เลือก Pre / Post
    public function selectType()
    {
        // ถ้าอยาก filter เฉพาะฟอร์มบางตัว เพิ่ม where exam_form_id = ... ได้
        $forms = FormExam::active()
            ->with(['examTypes' => function ($q) {
                $q->where('is_active', 1)
                    ->orderByRaw("CASE WHEN code = 'pre' THEN 1 WHEN code = 'post' THEN 2 END");
            }])
            ->get();

        return view('formexam.select', compact('forms'));
    }

    // หน้าเริ่มทำข้อสอบ
    public function start(ExamType $examType)
    {
        $examType = ExamType::findOrFail($examType);

        $questions = Question::with('choices')
            ->where('exam_type_id', $examType->id)
            ->where('is_active', 1)
            ->get();

        return view('formexam.start', compact('examType', 'questions'));
        //$examType->load('formExam');

        /*$questions = Question::with('choices')
            ->where('exam_form_id', $examType->exam_form_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return view('formexam.start', [
            'examType'  => $examType,
            'questions' => $questions,
        ]);*/
    }

    // รับคำตอบข้อสอบ
    public function submit(Request $request)
    {
        // 1) validate ว่ามีชื่อ และตอบทุกข้อ
        $request->validate([
            'exam_type_id' => 'required|exists:exam_types,id',
            'full_name'    => 'required|string|max:255',
            'answers'      => 'required|array', // key = question_id (exam_questions.id), value = choice_id
        ]);

        $examTypeId = $request->input('exam_type_id');
        $fullName   = $request->input('full_name');
        $answers    = $request->input('answers'); // [exam_question_id => exam_choice_id]

        // โหลด examType + form ไว้ใช้ต่อ
        $examType = ExamType::with('formExam')->findOrFail($examTypeId);

        // เอาจำนวนข้อจริงของฟอร์มนี้
        $questionIds     = array_keys($answers);
        $questionsCount  = Question::whereIn('id', $questionIds)->count();
        $totalQuestions = Question::where('exam_type_id', $examTypeId)
            ->where('is_active', 1)
            ->count();




        if (count($answers) < $totalQuestions) {
            return back()->withErrors(['answers' => 'กรุณาตอบคำถามทุกข้อ'])->withInput();
        }

        // กันกรณีมีคำถามในระบบมากกว่าที่ตอบมา → ต้องบังคับตอบทุกข้อ
        if ($questionsCount < $totalQuestions) {
            return back()
                ->withErrors(['answers' => 'กรุณาตอบคำถามทุกข้อ'])
                ->withInput();
        }

        DB::beginTransaction();

        try {
            // 2) สร้าง session
            $session = ExamSession::create([
                'exam_form_id' => $examType->exam_form_id,
                'exam_type_id' => $examTypeId,
                'full_name'    => $fullName,
                'total_score'  => 0,
                'max_score'    => $totalQuestions,
                'is_pass'      => null,
                'submitted_at' => now(),
            ]);

            $score = 0;

            $validQuestionIds = Question::where('exam_type_id', $examTypeId)
                ->where('is_active', 1)
                ->pluck('id')
                ->toArray();

            foreach ($answers as $questionId => $choiceId) {
                if (!in_array((int)$questionId, $validQuestionIds, true)) {
                    continue; // หรือ throw ก็ได้
                }

                $choice = Choice::where('exam_question_id', $questionId)
                    ->where('id', $choiceId)
                    ->first();

                $isCorrect = $choice && $choice->is_correct;

                if ($isCorrect) {
                    $score++;
                }

                ExamAnswer::create([
                    'exam_session_id'  => $session->id,
                    'exam_question_id' => $questionId,
                    'exam_choice_id'   => $choiceId,
                    'is_correct'       => $isCorrect,
                ]);
            }

            // 3) คำนวณผ่าน/ไม่ผ่าน (เฉพาะ Post)
            $isPass = null;

            if ($examType->code === 'post' && $examType->pass_score !== null) {
                // pass_score = จำนวนข้อถูกขั้นต่ำ
                $isPass = $score >= $examType->pass_score;
            }

            $session->update([
                'total_score' => $score,
                'is_pass'     => $isPass,
            ]);

            DB::commit();

            return redirect()->route('exam.result', $session->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->withErrors(['system' => 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง']);
        }
    }

    // แสดงผลลัพธ์
    public function result($sessionId)
    {
        $session = ExamSession::with([
            'examType',
            'formExam',
            'answers.question.choices',   // เอา choice ทั้งชุดของแต่ละข้อ
            'answers.choice',             // choice ที่ผู้ทำเลือก
        ])
            ->findOrFail($sessionId);

        $percentage = $session->max_score > 0
            ? round(($session->total_score / $session->max_score) * 100)
            : null;

        return view('formexam.result', compact('session', 'percentage'));
    }
}
