<?php

namespace App\Http\Controllers;

use App\Models\FormPA\PaData;
use App\Models\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PaDataController extends Controller
{
    /**
     * Dashboard: รายชื่อพนักงานในแผนกเดียวกับผู้ใช้ + สถานะ PA ของ period ที่เลือก
     */
    public function index(Request $r)
    {
        $periodId   = (int) $r->query('period_id');
        $deptId     = (int) ($r->query('department_id') ?: ($r->user()->department_id ?? 0));
        $status     = $r->query('status'); // optional: DRAFT/IN_PROGRESS/CLOSED/CANCELLED

        // พนักงานในแผนก
        $employees = User::query()
            ->when($deptId, fn($q) => $q->where('department_id', $deptId))
            ->where('active', true)
            ->select('id', 'name', 'department_id', 'position_title')
            ->orderBy('name')
            ->get();

        // Records ที่มีอยู่ใน pa_data (ของ period นี้)
        $paRecords = PaData::query()
            ->ofPeriod($periodId)
            ->when($deptId, fn($q) => $q->where('department_id', $deptId))
            ->when($status, fn($q) => $q->where('status', $status))
            ->get()
            ->keyBy('employee_id');

        // map รวมกันสำหรับหน้า dashboard
        $rows = $employees->map(function ($emp) use ($paRecords, $periodId) {
            /** @var PaData|null $pa */
            $pa = $paRecords->get($emp->id);
            return (object) [
                'employee_id'    => $emp->id,
                'employee_name'  => $emp->name,
                'department_id'  => $emp->department_id,
                'position_title' => $emp->position_title ?? null,
                'has_pa'         => (bool) $pa,
                'status'         => $pa->status ?? null,
                'final_avg'      => $pa->final_avg ?? null,
                'final_grade'    => $pa->final_grade ?? null,
                'form_no'        => $pa->form_no ?? null,
                'period_id'      => $periodId,
            ];
        });

        return view('pa.dashboard', [
            'periodId' => $periodId,
            'departmentId' => $deptId,
            'status' => $status,
            'rows' => $rows,
        ]);
    }

    /**
     * เปิดหน้า “ประเมินพนักงาน” (bootstrap record ถ้ายังไม่มี)
     */
    public function evaluate(Request $r, int $periodId, int $employeeId)
    {
        $employee = User::select('id', 'name', 'department_id', 'position_title')->findOrFail($employeeId);

        $pa = PaData::firstOrCreate(
            ['period_id' => $periodId, 'employee_id' => $employeeId],
            [
                'department_id'  => $employee->department_id,
                'position_title' => $employee->position_title,
                'scale_max'      => 5,
                'status'         => PaData::STATUS_DRAFT,
                'form_no'        => $this->makeFormNo(), // สร้างครั้งแรกเท่านั้น
            ]
        );

        // TODO: ดึง master หัวข้อประเมิน/น้ำหนัก มาแสดงประกอบ (ถ้าคุณมีตาราง master แยก)
        return view('pa.evaluate', [
            'employee' => $employee,
            'pa'       => $pa,
            'periodId' => $periodId,
        ]);
    }

    /**
     * บันทึก/อัปเดตคะแนนสรุปของฟอร์ม (หัวหน้ากดบันทึก)
     * - อัปเดต final_avg, final_grade, status
     * - รองรับ unique (period_id, employee_id) ด้วย updateOrCreate
     */
    public function saveScore(Request $r)
    {
        $data = $r->validate([
            'period_id'      => ['required', 'integer', 'min:1'],
            'employee_id'    => ['required', 'integer', 'min:1'],
            'department_id'  => ['nullable', 'integer', 'min:1'],
            'position_title' => ['nullable', 'string', 'max:100'],
            'scale_max'      => ['nullable', 'integer', 'min:1', 'max:100'],
            'final_avg'      => ['nullable', 'numeric', 'min:0'],
            'status'         => ['nullable', Rule::in([
                PaData::STATUS_DRAFT,
                PaData::STATUS_IN_PROGRESS,
                PaData::STATUS_CLOSED,
                PaData::STATUS_CANCELLED,
            ])],
            'employee_comment' => ['nullable', 'string'],
        ]);

        // เติมค่าที่จำเป็น
        $scaleMax = (int)($data['scale_max'] ?? 5);
        $finalAvg = isset($data['final_avg']) ? (float)$data['final_avg'] : null;

        // คำนวณ grade ถ้าไม่มีส่งมา
        $finalGrade = $r->input('final_grade');
        if ($finalGrade === null && $finalAvg !== null) {
            $finalGrade = $this->mapGrade($finalAvg, $scaleMax);
        }

        // สถานะ: ถ้าไม่ได้ส่งมา ให้คงเดิม หรือ default เป็น DRAFT
        $status = $data['status'] ?? PaData::STATUS_DRAFT;

        // ดึง employee เพื่ออัปเดต department/position อัตโนมัติ (กันกรณีไม่ส่งมา)
        $emp = User::select('id', 'department_id', 'position_title')->findOrFail($data['employee_id']);

        $payload = [
            'department_id'  => $data['department_id'] ?? $emp->department_id,
            'position_title' => $data['position_title'] ?? $emp->position_title,
            'scale_max'      => $scaleMax,
            'final_avg'      => $finalAvg,
            'final_grade'    => $finalGrade,
            'status'         => $status,
            'employee_comment' => $data['employee_comment'] ?? null,
        ];

        // ทำในทรานแซกชันเพื่อกัน race กับ unique(period_id, employee_id)
        $pa = DB::transaction(function () use ($data, $payload) {
            $pa = PaData::lockForUpdate()->where([
                'period_id'   => $data['period_id'],
                'employee_id' => $data['employee_id'],
            ])->first();

            if (!$pa) {
                $payload['form_no'] = $this->makeFormNo();
                $pa = PaData::create(array_merge([
                    'period_id'   => $data['period_id'],
                    'employee_id' => $data['employee_id'],
                ], $payload));
            } else {
                $pa->fill($payload)->save();
            }

            return $pa;
        });

        return response()->json([
            'ok' => true,
            'pa_id' => $pa->id,
            'status' => $pa->status,
            'final_avg' => $pa->final_avg,
            'final_grade' => $pa->final_grade,
        ]);
    }

    /**
     * พนักงานกดรับทราบ/ความเห็น (acknowledge)
     */
    public function employeeAck(Request $r, int $id)
    {
        $data = $r->validate([
            'employee_comment' => ['nullable', 'string'],
        ]);

        $pa = PaData::findOrFail($id);
        $pa->employee_ack_at  = Carbon::now();
        $pa->employee_comment = $data['employee_comment'] ?? $pa->employee_comment;
        $pa->save();

        return back()->with('ok', 'บันทึกการรับทราบแล้ว');
    }

    /**
     * เปิด workflow (ถ้ามีระบบ WF)
     */
    public function startWorkflow(Request $r, int $id)
    {
        $pa = PaData::findOrFail($id);

        // ถ้ายังไม่มี form_no ให้สร้าง
        if (!$pa->form_no) {
            $pa->form_no = $this->makeFormNo();
        }

        // === ถ้ามี WorkflowEngine ให้เอาออกจากคอมเมนต์ ===
        // $wf = app(\App\Services\WorkflowEngine::class)->start([
        //     'app_code'     => 'PA',
        //     'form_id'      => $pa->id,
        //     'form_no'      => $pa->form_no,
        //     'title'        => 'PA: ' . ($pa->employee->name ?? ('Emp#'.$pa->employee_id)),
        //     'originator_id'=> auth()->id(),
        //     'department_id'=> $pa->department_id,
        //     'payload'      => ['period_id' => $pa->period_id],
        // ]);
        // $pa->wf_form_id = $wf->id ?? $pa->wf_form_id;

        $pa->status = PaData::STATUS_IN_PROGRESS;
        $pa->save();

        return back()->with('ok', 'เริ่ม Workflow แล้ว');
    }

    /**
     * ปิดฟอร์ม (หัวหน้าหรือ HR)
     */
    public function close(Request $r, int $id)
    {
        $pa = PaData::findOrFail($id);
        $pa->status = PaData::STATUS_CLOSED;
        $pa->save();

        return back()->with('ok', 'ปิดแบบประเมินแล้ว');
    }

    /**
     * ===== Helpers =====
     */
    private function makeFormNo(): string
    {
        // รูปแบบ: PA-YYMM-#### (กันชนด้วย lockForUpdate)
        return DB::transaction(function () {
            $prefix = 'PA-' . date('ym') . '-';
            $last = PaData::lockForUpdate()
                ->where('form_no', 'like', $prefix . '%')
                ->orderByDesc('form_no')
                ->value('form_no');

            $seq = 1;
            if ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\d{4})$/', $last, $m)) {
                $seq = (int)$m[1] + 1;
            }
            return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        });
    }

    private function mapGrade(float $avg, int $scaleMax = 5): ?string
    {
        if ($scaleMax <= 0) return null;
        $pct = ($avg / $scaleMax) * 100.0;

        // ปรับเกณฑ์ตามที่องค์กรใช้ได้เลย
        if ($pct >= 90) return 'A';
        if ($pct >= 80) return 'B+';
        if ($pct >= 70) return 'B';
        if ($pct >= 60) return 'C+';
        if ($pct >= 50) return 'C';
        return 'D';
    }
}
