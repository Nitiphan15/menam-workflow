<?php

namespace App\Http\Controllers\FormWOCR;

use App\Http\Controllers\Controller;
use App\Models\FormWOCR\WocrData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\WorkflowEngine;
use App\Services\ApproverResolver;
use App\Support\WorkflowDb;

use App\Models\Users\Department;

class OriginatorController extends Controller
{
    public function index(Request $r)
    {
        $user = null;
        $items = collect();

        if (auth()->check()) {
            $user = auth()->user();
        }

        if ($user) {
            $items = Department::where('id', $user->department_id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }
        return view('formwocr.originator', compact('user', 'items'));
    }

    public function index2(Request $r)
    {
        $user = null;
        $items = collect();

        if (auth()->check()) {
            $user = auth()->user();
        }

        if ($user) {
            $items = Department::where('id', $user->department_id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }
        return view('formwocr.originator2', compact('user', 'items'));
    }

    public function store(Request $r)
    {
        $requestDate = $r->input('request_date') ?? now()->toDateString();
        $docuDate    = $r->input('docu_date')    ?? now()->toDateString();

        // Validate สำหรับ WOCR
        $data = $r->validate([
            'request_date'  => 'nullable|date',
            'docu_date'     => 'nullable|date',

            // ประเภทคำขอ: 1=เปิดใหม่, 2=แก้ไข, 3=ยกเลิก
            'req_type'      => 'required|integer|in:1,2,3',
            'mfg_no'        => 'nullable|string|max:50',
            'mfg_no_id'     => 'nullable|string|max:50',
            'grade'         => 'nullable|string|max:100',
            'form_type'     => 'nullable|string|max:100',
            'size'          => 'nullable|string|max:100',
            'length'         => 'nullable|string|max:100',
            'qty'           => 'nullable|numeric|min:0',

            'mfg_request_detail'  => 'required|string|max:1000',
            'reason'       => 'nullable|string|max:2000',
            'comment'      => 'nullable|string|max:2000',

            // flags เพิ่มเติม
            'skip_sup'         => 'sometimes|boolean',
            'skip_sup_reason'  => 'nullable|string|max:1000',
        ]);


        // เติมค่า default วันที่
        $data['request_date'] = $requestDate;
        $data['docu_date']    = $docuDate;

        // กติกาเพิ่มเติมตามประเภทคำขอ
        $type = (int) $data['req_type'];

        // ถ้าเป็นแก้ไข/ยกเลิก ต้องมี MFG
        if (in_array($type, [2, 3], true) && blank($data['mfg_no']) && blank($data['mfg_no_id'])) {
            return back()->withErrors(['mfg_no' => 'กรุณาระบุ MFG No. สำหรับการแก้ไข/ยกเลิก'])->withInput();
        }

        // ถ้าเป็นเปิดใหม่/แก้ไข ต้องมี qty > 0
        if (in_array($type, [1, 2], true)) {
            $qty = $data['qty'] ?? null;
            if ($qty === null || $qty === '' || (is_numeric($qty) && (float)$qty <= 0)) {
                return back()->withErrors(['qty' => 'กรุณาระบุ QTY มากกว่า 0 สำหรับการเปิดใหม่/แก้ไข'])->withInput();
            }
        }


        return DB::transaction(function () use ($r, $data) {
            $user   = $r->user();
            $departmentId = (int) ($user->department_id ?? 0);

            // gen เลขเอกสาร: อิงฟังก์ชันของโมเดล WOCR
            $docuNo = WocrData::generateDocCode();

            // บันทึกคำขอ WOCR
            $wocrId = DB::table('wocr_data')->insertGetId([
                'form_id'    => null,

                'req_date'   => $data['request_date'],
                'req_type'   => (int) $data['req_type'],

                'docu_date'  => $data['docu_date'],
                'docu_no'    => $docuNo,

                'mfg_no'     => $data['mfg_no']    ?? null,
                'form_type'  => $data['form_type'] ?? null,
                'grade'      => $data['grade']     ?? null,
                'length'     => $data['length']    ?? null,
                'size'       => $data['size']      ?? null,
                'qty'        => $data['qty']       ?? null,
                'comment'    => $data['comment']   ?? null,
                'mfg_request_detail'    => $data['mfg_request_detail']   ?? null,
            ]);

            // ตัวเลือกส่งเข้า Workflow
            $options = [
                'form_no'            => $docuNo,
                'request_by_user_id' => (int) $user->id,
                'skip_flags'         => ['sup' => (bool) $r->boolean('skip_sup')],
                'context'            => [
                    'department_id'   => $departmentId,
                    'req_type'       => (int) $data['req_type'],
                    'mfg_no'         => $data['mfg_no'] ?? null,
                    'qty'            => $data['qty'] ?? null,
                ],
                'submit_comment'     => $data['skip_sup_reason'] ?? null,
            ];

            // เปิด workflow (appCode สมมุติเป็น 'wocr')
            $wfId = WorkflowEngine::submit(
                appCode: 'wocr',
                refType: WocrData::class,
                refId: $wocrId,
                options: $options
            );

            // หา approvers ตามกติกา step 1
            $wfForm   = WorkflowDb::table('wocr', 'wf_forms')->find($wfId);
            $docuNo = (string) ($wfForm->form_no ?? $docuNo);
            $workflow = WorkflowDb::table('wocr', 'workflows')->where('code', $wfForm->app_code)->first();

            $step  = WorkflowDb::table('wocr', 'workflow_steps')
                ->where('workflow_id', $workflow->id)
                ->where('step_no', 1)
                ->first();

            $rules = WorkflowDb::table('wocr', 'workflow_step_rules')
                ->where('workflow_step_id', $step->id)
                ->orderBy('priority')
                ->get();

            $approvers = collect();
            foreach ($rules as $rule) {
                $approvers = $approvers->merge(
                    ApproverResolver::resolve(
                        $rule,
                        $wfForm,
                        [
                            'department_id' => $departmentId,
                            'req_type' => (int) $data['req_type'],
                            'mfg_no'   => $data['mfg_no'] ?? null,
                            'qty'      => $data['qty'] ?? null,
                        ],
                        ['sup' => (bool) $r->boolean('skip_sup')]
                    )
                );
            }
            $approvers = $approvers->unique()->values();

            // ถ้ามีผู้อนุมัติเพียง 1 คนและเป็นคนเดียวกับผู้ขอ ให้ auto-approve
            if ($approvers->count() === 1 && (int)$approvers->first() === (int)$user->id) {
                WorkflowEngine::approve($wfId, $user->id, $data['reason'], 'wocr');
            }

            // อัปเดต wocr_data.form_id ให้ชี้ wf
            DB::table('wocr_data')->where('id', $wocrId)->update([
                'form_id'    => $wfId,
                'docu_no'    => $docuNo,
            ]);

            // --- แนบไฟล์ ---
            if ($r->hasFile('files')) {
                $today = now();
                $dir   = "wocr/{$today->year}/{$today->format('m')}/{$today->format('d')}";

                $rows = [];
                foreach ($r->file('files') as $file) {
                    if (!$file->isValid()) continue;
                    $stored = $file->store($dir);
                    $rows[] = [
                        'form_id'       => $wfId,
                        'file_name'     => basename($stored),
                        'original_name' => $file->getClientOriginalName(),
                        'file_path'     => $stored,
                        'file_type'     => $file->getClientMimeType(),
                        'file_size'     => $file->getSize(),
                    ];
                }
                if ($rows) DB::table('wocr_data_files')->insert($rows);
            }


            // หา next approvers (ไว้ส่งเมล/แจ้งเตือน ถ้าต้องการ)
            $nextApprovers = WorkflowDb::table('wocr', 'wf_form_authorizes as wa')
                ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
                ->where('wa.wf_form_id', $wfId)
                ->where('wa.status', 'PENDING')
                ->select('u.email', 'u.name', 'wa.approver_user_id')
                ->get();

            // ถ้าคนถัดไปมีตัวเราเอง ให้ยิงไปหน้า planner/review
            $flagToNextSelf = $nextApprovers->contains(fn($x) => (int)$x->approver_user_id === (int)$user->id);

            if ($flagToNextSelf) {
                return redirect()
                    ->route('wocr.planner', ['id' => $wfId]) // ปรับชื่อ route ตามจริง
                    ->with('ok', 'ส่งคำขอแล้ว (เข้าสู่ขั้นตอนถัดไป)');
            } else {
                return redirect()
                    ->route('wocr.view', ['id' => $wfId]) // ปรับชื่อ route ตามจริง
                    ->with('ok', 'ส่งคำขอแล้ว (รออนุมัติ)');
            }
        });
    }
}
