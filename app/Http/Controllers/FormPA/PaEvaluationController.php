<?php

namespace App\Http\Controllers\FormPA;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use App\Models\FormPA\PaSection;
use App\Models\FormPA\PaQuestion;
use App\Models\FormPA\PaData;
use App\Models\FormPA\PaScore;
use App\Models\FormPA\PaSectionNote;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaEvaluationController extends Controller
{
    /* ================= Helpers ================= */

    /** เอา period_id ปัจจุบันจาก ?period_id=... หรือจาก pa_periods.is_active=1 */
    protected function currentPeriodId(Request $request): int
    {
        if ($request->filled('period_id')) {
            return (int) $request->period_id;
        }
        $id = DB::table('pa_periods')->where('is_active', 1)->value('id');
        return (int) ($id ?: 0);
    }

    /** ประเมินได้เมื่ออยู่แผนกเดียวกัน (หรือเป็น superadmin) */
    protected function canEvaluate(User $actor, User $employee): bool
    {
        if (($actor->is_superadmin ?? 0) == 1) return true;
        if ((int)$actor->id === (int)$employee->id) return false;

        $sameDept   = (int)$actor->department_id === (int)$employee->department_id;
        $actorLevel = (int)$actor->level_no;  // <-- มาจาก accessor ข้างบน

        // ตัวเลือก: ถ้าอยากให้ level >=3 ประเมินข้ามแผนกได้ ให้ปลดคอมเมนต์ด้านล่าง
        // if ($actorLevel >= 3) return true;
        //dd($actorLevel);
        return $sameDept && $actorLevel >= 2;
    }

    protected function bucketOf(float $score): string
    {
        return match (true) {
            $score >= 4.5 => 'excellent',
            $score >= 4.0 => 'good',
            $score >= 3.0 => 'avg',
            $score >= 2.5 => 'improve',
            default       => 'fail',
        };
    }

    protected function gradeOf(float $score): string
    {
        return match (true) {
            $score >= 4.5 => 'A',
            $score >= 4.0 => 'B+',
            $score >= 3.5 => 'B',
            $score >= 3.0 => 'C+',
            $score >= 2.5 => 'C',
            default       => 'D',
        };
    }

    /* ================= Pages ================= */

    /** Dashboard */
    public function dashboard(Request $request)
    {
        $me       = $request->user();
        $periodId = $this->currentPeriodId($request);

        $formsQ = PaData::query()
            ->when($periodId > 0, fn($q) => $q->where('period_id', $periodId))
            ->when(($me->is_superadmin ?? 0) != 1, fn($q) => $q->where('department_id', $me->department_id))
            ->whereNotNull('final_avg');

        $forms = $formsQ->get(['employee_id', 'final_avg']);

        $total = $forms->count();
        $avg   = $total ? round($forms->avg('final_avg'), 2) : null;

        $dist = ['excellent' => 0, 'good' => 0, 'avg' => 0, 'improve' => 0, 'fail' => 0];
        foreach ($forms as $f) {
            $dist[$this->bucketOf((float)$f->final_avg)]++;
        }

        $latest = PaData::query()
            ->with(['employee:id,name'])
            ->whereIn('id', $formsQ->latest('id')->limit(5)->pluck('id'))
            ->get(['id', 'employee_id', 'final_avg']);

        return view('formpa.dashboard', compact('dist', 'total', 'avg', 'latest'));
    }

    /** รายชื่อพนักงานในแผนก */
    public function index(Request $request)
    {
        $me       = $request->user();
        $periodId = $this->currentPeriodId($request);
        $totalQ   = PaQuestion::where('is_active', 1)->count();

        $department = SqlServerDb::table('departments')->find($me->department_id);

        $users = User::query()
            ->where('is_active', 1)
            ->where('department_id', $me->department_id)
            ->when($request->filled('q'), function ($q) use ($request) {
                $kw = '%' . $request->q . '%';
                $q->where(fn($w) => $w->where('name', 'like', $kw)->orWhere('email', 'like', $kw));
            })
            ->orderBy('name')
            ->paginate(20);

        // ฟอร์มของรอบนี้
        $forms = PaData::query()
            ->when($periodId > 0, fn($q) => $q->where('period_id', $periodId))
            ->whereIn('employee_id', $users->pluck('id'))
            ->get(['id', 'employee_id', 'final_avg'])
            ->keyBy('employee_id');

        $status = [];
        foreach ($users as $u) {
            $f = $forms[$u->id] ?? null;

            if ($f?->status) {
                if ($f->status === 'completed') {
                    $status[$u->id] = ['label' => 'Complete', 'class' => 'success', 'total' => $f->final_avg];
                } elseif ($f->status === 'draft') {
                    // เช็คว่ามีการให้คะแนนบ้างหรือยัง
                    $hasScores = PaScore::where('pa_data_id', $f->id)->whereNotNull('score')->exists();
                    $status[$u->id] = $hasScores
                        ? ['label' => 'In progress', 'class' => 'warning', 'total' => null]
                        : ['label' => 'Draft', 'class' => 'secondary', 'total' => null];
                } else {
                    $status[$u->id] = ['label' => ucfirst($f->status), 'class' => 'secondary', 'total' => null];
                }
            } else {
                // fallback แบบเดิม
                if ($f && $f->final_avg !== null) {
                    $status[$u->id] = ['label' => 'Complete', 'class' => 'success', 'total' => $f->final_avg];
                } else {
                    $hasScores = $f
                        ? PaScore::where('pa_data_id', $f->id)->whereNotNull('score')->exists()
                        : false;
                    $status[$u->id] = $hasScores
                        ? ['label' => 'In progress', 'class' => 'warning', 'total' => null]
                        : ['label' => 'Draft', 'class' => 'secondary', 'total' => null];
                }
            }
        }

        return view('formpa.index', compact('department', 'users', 'status'));
    }

    /** ฟอร์มประเมิน (create/edit) */
    public function form(Request $request, User $employee)
    {
        $me = $request->user();
        if (!$this->canEvaluate($me, $employee)) {
            return response()->view('errors.403', [
                'reason' => 'อนุญาตเฉพาะหัวหน้างานในแผนกเดียวกันเท่านั้น และห้ามประเมินตัวเอง',
            ], 403);
        }
        $periodId = $this->currentPeriodId($request);

        // เปิด/สร้างใบประเมิน (ไม่แตะ status/updated_by)
        $form = PaData::firstOrCreate(
            [
                'period_id'     => $periodId ?: 0,
                'employee_id'   => $employee->id,
                'department_id' => $employee->department_id,
            ],
            [
                'scale_max' => 5,
            ]
        );

        $sections = PaSection::where('is_active', 1)
            ->orderBy('order_no')
            ->with(['questions' => fn($q) => $q->where('is_active', 1)->orderBy('order_no')])
            ->get();


        $scores = PaScore::where('pa_data_id', $form->id)
            ->get(['question_id', 'score', 'comment'])
            ->keyBy('question_id')
            ->toArray();

        $sectionNotes = PaSectionNote::where('pa_data_id', $form->id)
            ->get()
            ->keyBy('section_id');

        return view('formpa.eval.form', [
            'employee'      => $employee,
            'sections'      => $sections,
            'scores'        => $scores,
            'overallRemark' => $form->employee_comment,
            'sectionNotes'  => $sectionNotes,
        ]);
    }

    /** POST: บันทึกคะแนน */
    public function store(Request $request, User $employee)
    {
        $me = $request->user();

        abort_unless($this->canEvaluate($me, $employee), 403);

        $periodId = $this->currentPeriodId($request);

        $form = PaData::firstOrCreate(
            [
                'period_id'     => $periodId ?: 0,
                'employee_id'   => $employee->id,
                'department_id' => $employee->department_id,
            ],
            ['scale_max' => 5, 'status' => 'draft']
        );

        $data = $request->validate([
            'scores'            => ['array'],
            'scores.*'          => ['nullable', 'numeric', 'min:0', 'max:5'],
            'remarks'           => ['array'],
            'remarks.*'         => ['nullable', 'string', 'max:500'],
            'overall_remark'    => ['nullable', 'string', 'max:2000'],
            'section_remarks'   => ['array'],
            'section_remarks.*' => ['nullable', 'string', 'max:2000'],
            'save_as'           => ['nullable', 'in:draft,final'],
        ]);

        $qids  = collect($data['scores'] ?? [])->keys()->map(fn($k) => (int)$k)->all();
        $qMeta = PaQuestion::whereIn('id', $qids)
            ->get(['id', 'section_id', 'weight'])
            ->keyBy('id');

        //dd($form, $data);
        DB::transaction(function () use ($form, $data, $qMeta) {   // << เพิ่ม $qMeta ใน use
            $employeeId = (int) $form->employee_id;
            $reviewerId = (int) auth()->id();

            foreach ($data['scores'] ?? [] as $qid => $score) {
                $qid     = (int) $qid;
                $val     = is_numeric($score) ? round((float)$score, 1) : null;
                $comment = trim((string)($data['remarks'][$qid] ?? ''));

                // ถ้าไม่มีทั้งคะแนนและหมายเหตุ -> ลบแถว
                if ($val === null && $comment === '') {
                    PaScore::where('pa_data_id', $form->id)
                        ->where('question_id', $qid)
                        ->where('reviewer_id', $reviewerId)
                        ->delete();
                    continue;
                }

                $meta = $qMeta[$qid] ?? null;

                PaScore::updateOrCreate(
                    // รองรับหลายผู้ประเมิน: match ด้วย reviewer_id ด้วย
                    ['pa_data_id' => $form->id, 'question_id' => $qid, 'reviewer_id' => $reviewerId],
                    [
                        'period_id'   => (int) $form->period_id,
                        'employee_id' => $employeeId,
                        'section_id'  => $meta?->section_id,
                        'weight'      => $meta?->weight,
                        'score'       => $val,
                        'comment'     => $comment,
                    ]
                );
            }

            foreach (($data['section_remarks'] ?? []) as $secId => $note) {
                $secId = (int) $secId;
                $note  = trim((string) $note);

                if ($note === '') {
                    // ลบทิ้งถ้าล้างข้อความ
                    DB::table('pa_section_notes')
                        ->where('pa_data_id', $form->id)
                        ->where('section_id', $secId)
                        ->delete();
                    continue;
                }

                DB::table('pa_section_notes')->updateOrInsert(
                    ['pa_data_id' => $form->id, 'section_id' => $secId],
                    ['note' => $note, 'updated_at' => now()] + (
                        DB::table('pa_section_notes')
                        ->where('pa_data_id', $form->id)
                        ->where('section_id', $secId)
                        ->exists()
                        ? []
                        : ['created_at' => now()]
                    )
                );
            }

            $this->recalcSectionNotesAndTotals($form);

            $this->rebuildSectionTotals($form);

            // คำนวณเฉลี่ยถ่วงน้ำหนักแบบเดิม (final_avg) เพื่อใช้กับ dashboard เดิม
            $scores = PaScore::where('pa_data_id', $form->id)->get(['question_id', 'score', 'weight']);
            $sumWX = 0.0;
            $sumW = 0.0;
            foreach ($scores as $r) {
                if ($r->score === null) continue;
                $w = (float)($r->weight ?? 0);
                $s = (float)$r->score;
                $sumWX += $w * $s;
                $sumW  += $w;
            }
            $finalAvg = $sumW > 0 ? round($sumWX / $sumW, 3) : 0.0;

            $form->employee_comment = $data['overall_remark'] ?? null;
            $form->final_avg        = $finalAvg;
            $form->final_grade      = $this->gradeOf($finalAvg);

            // สถานะ: draft ถ้ากดแบบร่าง, มิฉะนั้น complete ต่อเมื่อให้คะแนนครบทุกข้อ
            if (($data['save_as'] ?? 'final') === 'draft') {
                $form->status = 'draft';
            } else {
                $totalQ    = PaQuestion::where('is_active', 1)->count();
                $answeredQ = PaScore::where('pa_data_id', $form->id)
                    ->whereNotNull('score')
                    ->distinct('question_id')
                    ->count('question_id');
                $form->status = ($totalQ > 0 && $answeredQ >= $totalQ) ? 'completed' : 'draft';
            }

            $form->save();

            // ถ้ามีข้อมูล HR ก็คิด final_points ด้วย (คงลอจิกเดิม)
            $this->recalcFinal($form);
        });

        return back()->with('ok', 'บันทึกเรียบร้อย');
    }

    /* ===== สร้างผลสรุปต่อ Section + รวม Part A ===== */
    protected function rebuildSectionTotals(PaData $form, int $scaleMax = 5): array
    {
        $sections = PaSection::with(['questions' => fn($q) => $q->where('is_active', 1)->orderBy('order_no')])
            ->where('is_active', 1)->orderBy('order_no')->get();

        // FIX: ใช้ data_id
        $scores = PaScore::where('pa_data_id', $form->id)->pluck('score', 'question_id');

        $partAPoints = 0.0;
        $rows = [];

        foreach ($sections as $sec) {
            $full = 0.0;
            $earn = 0.0;

            foreach ($sec->questions as $q) {
                $w = (float)$q->weight;
                $full += $w * $scaleMax;

                $s = (float)($scores[$q->id] ?? 0);
                $earn += $w * $s;
            }

            $percent   = $full > 0 ? ($earn / $full) * 100.0 : 0.0;
            $maxPoints = (float)($sec->max_points ?? 0);
            $point     = round($percent * $maxPoints / 100.0, 2);

            $rows[] = [
                'section_id'  => $sec->id,
                'full_mark'   => round($full, 3),
                'earned_mark' => round($earn, 3),
                'percent_val' => round($percent, 3),
                'point_val'   => $point,
            ];

            $partAPoints += $point;
        }

        foreach ($rows as $r) {
            DB::table('pa_section_totals')->updateOrInsert(
                ['pa_data_id' => $form->id, 'section_id' => $r['section_id']],
                [
                    'full_mark'   => $r['full_mark'],
                    'earned_mark' => $r['earned_mark'],
                    'percent_val' => $r['percent_val'],
                    'point_val'   => $r['point_val'],
                    'updated_at'  => now(),
                ] + (DB::table('pa_section_totals')->where(['pa_data_id' => $form->id, 'section_id' => $r['section_id']])->exists()
                    ? []
                    : ['created_at' => now()])
            );
        }

        $form->part_a_points_h1  = $partAPoints;
        $form->part_a_points_h2  = 0;
        $form->part_a_points_avg = $form->part_a_points_h1;
        $form->save();

        return ['rows' => $rows, 'part_a_points' => $partAPoints];
    }

    /* ===== แปลงตัวชี้วัด HR เป็นคะแนนรวม ===== */
    protected function scoreHr(array $metrics): float
    {
        $rules = DB::table('pa_hr_rules')->orderBy('metric_key')->orderBy('sort_no')->get();

        $sum = 0.0;
        foreach ($metrics as $key => $val) {
            $rule = $rules->first(function ($r) use ($key, $val) {
                return $r->metric_key === $key
                    && $val >= (float)$r->min_val
                    && $val <  (float)$r->max_val;
            });
            if ($rule) $sum += (float)$rule->points;
        }
        return round($sum, 2);
    }

    /** POST: บันทึกข้อมูล HR */
    public function storeHr(Request $request, PaData $form)
    {

        $me = $request->user();
        // dd($request);
        abort_unless($this->isHr($me), 403);

        $data = $request->validate([
            // Attendance (ค่าเดียวทั้งปี)
            'late_hours'      => 'nullable|numeric|min:0',
            'absent_days'     => 'nullable|numeric|min:0',
            'sick_days'       => 'nullable|numeric|min:0',
            'bizleave_days'   => 'nullable|numeric|min:0',
            'military_days'   => 'nullable|numeric|min:0',
            'maternity_days'  => 'nullable|numeric|min:0',
            'ordination_days' => 'nullable|numeric|min:0',
            'annual_days'     => 'nullable|numeric|min:0',
            'other_days'      => 'nullable|numeric|min:0',
            'unpaid_days'     => 'nullable|numeric|min:0',
            // Discipline
            'warnings'        => 'nullable|integer|min:0',
            'suspends'        => 'nullable|integer|min:0',
        ]);

        DB::transaction(function () use ($form, $data) {
            // Attendance: 8 ชั่วโมง = 1 วัน
            $formId = (int) $form->id;

            if ($formId <= 0 || !DB::table('pa_data')->where('id', $formId)->exists()) {
                abort(404, "ไม่พบใบประเมิน (pa_data_id={$formId})");
            }

            $daysFromLate = (float)($data['late_hours'] ?? 0) / 8.0;
            $attDaysTotal =
                $daysFromLate
                + (float)($data['absent_days'] ?? 0)
                + (float)($data['sick_days'] ?? 0)
                + (float)($data['bizleave_days'] ?? 0)
                + (float)($data['military_days'] ?? 0)
                + (float)($data['maternity_days'] ?? 0)
                + (float)($data['ordination_days'] ?? 0)
                + (float)($data['annual_days'] ?? 0)
                + (float)($data['other_days'] ?? 0)
                + (float)($data['unpaid_days'] ?? 0);

            $attPoints  = $this->scoreAttendanceDays($attDaysTotal); // /10

            // Discipline: เตือน*5 + พักงาน*10 (ขั้นต่ำ 0 สูงสุด 10)
            $discPoints = $this->scoreDiscipline(
                (int)($data['warnings'] ?? 0),
                (int)($data['suspends'] ?? 0)
            ); // /10

            $scoreTotal = round($attPoints + $discPoints, 2); // /20

            // upsert pa_hr_stats (เก็บข้อมูลดิบ + คะแนน)
            $payload = [
                'late_hours'      => $data['late_hours'] ?? 0,
                'absent_days'     => $data['absent_days'] ?? 0,
                'sick_days'       => $data['sick_days'] ?? 0,
                'bizleave_days'   => $data['bizleave_days'] ?? 0,
                'military_days'   => $data['military_days'] ?? 0,
                'maternity_days'  => $data['maternity_days'] ?? 0,
                'ordination_days' => $data['ordination_days'] ?? 0,
                'annual_days'     => $data['annual_days'] ?? 0,
                'other_days'      => $data['other_days'] ?? 0,
                'unpaid_days'     => $data['unpaid_days'] ?? 0,
                'warnings'        => $data['warnings'] ?? 0,
                'suspends'        => $data['suspends'] ?? 0,
                'att_days_total'  => $attDaysTotal,
                'att_points'      => $attPoints,
                'disc_points'     => $discPoints,
                'score_total'     => $scoreTotal,
                'updated_at'      => now(),
            ];

            $exists = DB::table('pa_hr_stats')->where('pa_data_id', $form->id)->exists();
            if ($exists) {
                DB::table('pa_hr_stats')->where('pa_data_id', $form->id)->update($payload);
            } else {
                DB::table('pa_hr_stats')->updateOrInsert(
                    ['pa_data_id' => (int) $form->id],   // เงื่อนไข (และจะถูกใส่ตอน insert อัตโนมัติ)
                    $payload                              // ค่าที่จะ update/insert (ไม่ต้องมี created_at)
                );
            }

            // อัปเดต pa_data และคำนวณคะแนนถ่วงน้ำหนัก
            $form->weight_part_a  = $form->weight_part_a ?: 80;
            $form->weight_hr      = $form->weight_hr     ?: 20;
            $form->hr_points_total = $scoreTotal;
            $form->save();

            $this->recalcFinal($form);
        });

        return back()->with('ok', 'บันทึกข้อมูล HR แล้ว');
    }


    /** คำนวณคะแนนรวมถ่วงน้ำหนัก (Part A + HR) */
    protected function recalcFinal(PaData $form): void
    {
        $partA = (float)($form->part_a_points_avg ?? 0);
        $hr    = (float)($form->hr_points_total   ?? 0);

        $wA = (float)($form->weight_part_a ?? 80); // default 80
        $wH = (float)($form->weight_hr     ?? 20); // default 20
        $wT = max($wA + $wH, 1);

        $final = round(($partA * $wA + $hr * $wH) / $wT, 2);
        $form->final_points = $final;

        // แปลงเป็นเกรด (ขึ้นกับสเกลองค์กร ปรับได้)
        $form->final_grade  = $this->gradeOf($final / (($wA + $wH) / 100));
        $form->save();
    }

    /** หน้า Summary */
    public function summary(Request $request, PaData $form)
    {
        $sections = DB::table('pa_section_totals')
            ->join('pa_sections', 'pa_sections.id', '=', 'pa_section_totals.section_id')
            ->where('pa_section_totals.pa_data_id', $form->id)
            ->orderBy('pa_sections.order_no')
            ->get([
                'pa_sections.name as section_name',
                'pa_sections.max_points',
                'pa_section_totals.full_mark',
                'pa_section_totals.earned_mark',
                'pa_section_totals.percent_val',
                'pa_section_totals.point_val',
            ]);

        $hr = DB::table('pa_hr_stats')->where('pa_data_id', $form->id)->first();

        return view('formpa.summary', [
            'form'     => $form,
            'sections' => $sections,
            'hr'       => $hr,
        ]);
    }


    protected function isHr(User $u): bool
    {
        if (!$u) return false;
        if (($u->is_superadmin ?? 0) == 1) return true;

        // อ่านรายชื่อแผนก HR จาก .env
        $hrIds = collect(explode(',', (string) env('HR_DEPARTMENT_IDS', '')))
            ->map(fn($v) => (int) trim($v))
            ->filter()
            ->values()
            ->all();

        if (empty($hrIds)) return false;

        // แผนกหลักของ user
        $userDeptIds = [];
        if ($u->department_id) $userDeptIds[] = (int) $u->department_id;

        // กรณีมีหลายแผนกผ่าน pivot (ถ้ามีตารางนี้)
        $extraDeptIds = SqlServerDb::table('department_role_users as dru')
            ->join('department_roles as dr', 'dr.id', '=', 'dru.department_role_id')
            ->where('dru.user_id', $u->id)
            ->pluck('dr.department_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $userDeptIds = array_values(array_unique(array_merge($userDeptIds, $extraDeptIds)));

        return count(array_intersect($userDeptIds, $hrIds)) > 0;
    }

    /** HR: รายชื่อพนักงานทุกแผนก + สถานะใบ HR ของรอบปัจจุบัน */
    public function hrIndex(Request $request)
    {
        $me = $request->user();
        //dd($this->isHr($me));
        abort_unless($this->isHr($me), 403);

        $periodId = $this->currentPeriodId($request);

        $departments = SqlServerDb::table('departments')->orderBy('name')->get(['id', 'name']);

        $q = SqlServerDb::table('users as u')
            ->where('u.is_active', 1)
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->leftJoin('pa_data as f', function ($j) use ($periodId) {
                $j->on('f.employee_id', '=', 'u.id')
                    ->where('f.period_id', $periodId);
            })
            ->leftJoin('pa_hr_stats as h', 'h.pa_data_id', '=', 'f.id')
            ->select([
                'u.id as user_id',
                'u.name',
                'u.email',
                'd.name as dept_name',
                'u.department_id',
                'f.id as form_id',
                'f.part_a_points_avg',
                'f.hr_points_total',
                'h.score_total',
                'h.updated_at'
            ])
            ->when($request->filled('q'), function ($qq) use ($request) {
                $kw = '%' . $request->q . '%';
                $qq->where(function ($w) use ($kw) {
                    $w->where('u.name', 'like', $kw)->orWhere('u.email', 'like', $kw);
                });
            })
            ->when($request->filled('dept'), fn($qq) => $qq->where('u.department_id', (int)$request->dept))
            ->when($request->status === 'noform',  fn($qq) => $qq->whereNull('f.id'))
            ->when($request->status === 'pending', fn($qq) => $qq->whereNotNull('f.id')->whereNull('h.score_total'))
            ->when($request->status === 'complete', fn($qq) => $qq->whereNotNull('h.score_total'))
            ->orderBy('d.name')->orderBy('u.name');

        $rows = $q->paginate(25)->withQueryString();

        return view('formpa.hr_index', [
            'rows'        => $rows,
            'departments' => $departments,
            'periodId'    => $periodId,
        ]);
    }

    /** HR: เปิด/สร้างใบ HR แล้วพาเข้าแบบฟอร์ม */
    public function hrOpen(Request $request, User $employee)
    {
        $me = $request->user();
        abort_unless($this->isHr($me), 403);

        $periodId = $this->currentPeriodId($request);

        $form = PaData::firstOrCreate(
            [
                'period_id'     => $periodId ?: 0,
                'employee_id'   => $employee->id,
                'department_id' => $employee->department_id,
            ],
            ['scale_max' => 5]
        );

        return redirect()->route('pa.hr.form', $form->id);
    }

    /** HR: แสดงฟอร์ม HR (view ที่ให้ไปก่อนหน้า) */
    public function hrForm(Request $request, PaData $form)
    {
        $me = $request->user();
        abort_unless($this->isHr($me), 403);

        $hr = DB::table('pa_hr_stats')->where('pa_data_id', $form->id)->first();

        // ส่ง $form และ $hr ให้ view hr.blade.php (ที่ให้คุณไปแล้ว)
        return view('formpa.eval.hr', compact('form', 'hr'));
    }


    // ==== HELPERS (HR) ===========================================================
    protected function scoreAttendanceDays(float $days): float
    {
        return match (true) {
            $days <= 3    => 10.0,
            $days <= 5    => 8.5,
            $days <= 10   => 7.5,
            $days <= 15   => 5.0,
            default       => 0.0,
        };
    }

    protected function scoreTrainingHours(float $hours): float
    {
        return match (true) {
            $hours <= 0   => 0.0,
            $hours <= 5   => 1.0,
            $hours <= 10  => 2.0,
            $hours <= 15  => 3.0,
            $hours <= 20  => 4.0,
            default       => 5.0,
        };
    }

    protected function scoreDiscipline(int $letters, int $suspends): float
    {
        // เตือน 1 ฉบับ = หัก 5, พักงาน 1 ครั้ง = หัก 10 (ขั้นต่ำ 0 สูงสุด 10)
        $deduct = 5 * $letters + 10 * $suspends;
        $score  = 10.0 - $deduct;
        return max(0.0, min(10.0, $score));
    }

    /**
     * คำนวณสรุปต่อหมวด แล้วอัปเดตทั้ง
     * - pa_section_notes: sum_wx, sum_w, avg, sec_max, sec_score, sec_pct (เก็บ note เดิมไว้)
     * - pa_section_totals: full_mark, earned_mark, percent_val, point_val
     */
    protected function recalcSectionNotesAndTotals(PaData $form): void
    {
        $scaleMax = (float) ($form->scale_max ?: 5);

        // master: หมวด + คำถาม
        $sections = PaSection::with(['questions' => fn($q) => $q->where('is_active', 1)->orderBy('order_no')])
            ->where('is_active', 1)->orderBy('order_no')->get();

        // คะแนนที่ให้ไว้ (map: question_id => score)
        $scores = PaScore::where('pa_data_id', $form->id)->pluck('score', 'question_id');

        $partAPoints = 0.0;

        foreach ($sections as $sec) {
            $sumW  = 0.0; // Σ w_i
            $sumWX = 0.0; // Σ (w_i * x_i)

            foreach ($sec->questions as $q) {
                $w = (float) $q->weight;
                $sumW += $w;

                $x = (float) ($scores[$q->id] ?? 0); // ยังไม่กรอก = 0
                $sumWX += $w * $x;
            }

            // คำนวณตัวเลขต่อหมวด
            $avg     = $sumW > 0 ? $sumWX / $sumW : 0.0;               // 1..5
            $secMax  = $sumW * $scaleMax;                              // คะแนนเต็มหมวดแบบถ่วงน้ำหนัก
            $secPct  = $secMax > 0 ? ($sumWX / $secMax) * 100.0 : 0.0; // %
            $secScore = $sumWX;                                         // คะแนนถ่วงน้ำหนักที่ทำได้

            // === อัปเดต pa_section_notes (ไม่แตะค่า note ถ้าไม่มีส่งมา) ===
            $exists = DB::table('pa_section_notes')
                ->where('pa_data_id', $form->id)->where('section_id', $sec->id)->exists();

            DB::table('pa_section_notes')->updateOrInsert(
                ['pa_data_id' => $form->id, 'section_id' => $sec->id],
                [
                    'sum_wx'    => round($sumWX, 3),
                    'sum_w'     => round($sumW,  3),
                    'avg'       => round($avg,   3),
                    'sec_max'   => round($secMax, 3),
                    'sec_score' => round($secScore, 3),
                    'sec_pct'   => round($secPct, 2),
                    'updated_at' => now(),
                ] + ($exists ? [] : ['created_at' => now()])
            );

            // === อัปเดต pa_section_totals (สำหรับรายงาน/สรุป Part A) ===
            $point = round($secPct * (float)($sec->max_points ?? 0) / 100.0, 2);
            DB::table('pa_section_totals')->updateOrInsert(
                ['pa_data_id' => $form->id, 'section_id' => $sec->id],
                [
                    'full_mark'   => round($secMax,  3),   // = sum_w * scaleMax
                    'earned_mark' => round($sumWX,   3),
                    'percent_val' => round($secPct,  2),
                    'point_val'   => $point,
                    'updated_at'  => now(),
                ] + (
                    DB::table('pa_section_totals')->where('pa_data_id', $form->id)->where('section_id', $sec->id)->exists()
                    ? [] : ['created_at' => now()]
                )
            );

            $partAPoints += $point;
        }

        // เก็บสรุป Part A ลง pa_data (เผื่อใช้ต่อ)
        $form->part_a_points_h1  = $partAPoints;
        $form->part_a_points_h2  = 0;
        $form->part_a_points_avg = $form->part_a_points_h1;
        $form->save();
    }
}
