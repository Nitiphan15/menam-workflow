<?php

namespace App\Http\Controllers\FormWOCR;

use App\Http\Controllers\Controller;

use App\Models\FormWOCR\WocrData;
use App\Models\FormWOCR\WocrDataFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\WorkflowEngine;
use App\Models\Users\Department;
use App\Support\WorkflowDb;
use Illuminate\Support\Facades\Storage;

class WOCRReviseController extends Controller
{
    public function showRevise(Request $r, $id)
    {
        $user = null;
        if (auth()->check()) {
            $user = auth()->user();
        }

        $wocrData = WocrData::query()
            ->where('form_id', $id)
            ->firstOrFail();   // ไม่เจอ => 404
        //dd($wocrData);
        $wfForm = WorkflowDb::findForm('wocr', (int) $id);
        abort_unless($wfForm, 404);

        $canApprove = WorkflowDb::canApprove('wocr', (int) $wfForm->id, (int) auth()->id());


        if ($user) {
            $items = Department::where('id', $user->department_id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }

        return view('formwocr.revise', [
            'user'          => $user,
            'items'         => $items,
            'form'          => $wfForm,
            'canApprove'    => $canApprove,
            'wocrData'      => $wocrData,
        ]);
    }

    public function originator_action(Request $request, $id)
    {
        $userId = $request->user()->id;

        $form = WorkflowDb::findForm('wocr', (int) $id);
        abort_unless($form, 404);

        // ตรวจสิทธิ์ step ปัจจุบัน
        $auth = WorkflowDb::table('wocr', 'wf_form_authorizes')
            ->where('wf_form_id', $form->id)
            ->where('step_no', $form->current_step_no)
            ->where('approver_user_id', $userId)
            ->first();

        if (!$auth) abort(403, 'คุณไม่มีสิทธิ์อนุมัติฟอร์มนี้');
        if (strtoupper($auth->status) !== 'PENDING') {
            return back()->with('warning', 'ฟอร์มนี้ถูกดำเนินการแล้ว')->withInput();
        }

        $data = $request->validate([
            'req_date'            => 'nullable|date',
            'docu_date'           => 'nullable|date',
            'docu_no'             => 'nullable|string|max:50',
            'mfg_no'              => 'nullable|string|max:50',
            'form_type'           => 'nullable|string|max:50',
            'grade'               => 'nullable|string|max:50',
            'req_type'            => 'required|in:1,2,3',
            'size'                => 'nullable|string|max:50',
            'length'              => 'nullable|string|max:50',
            'qty'                 => 'required|numeric|min:0',
            'mfg_request_detail'  => 'required|string|max:2000',
            'reason'              => 'nullable|string|max:2000',

        ]);

        //dd($request);

        $nullify = fn($v) => ($v === '' ? null : $v);

        $payload = [
            'mfg_no'             => $nullify($data['mfg_no'] ?? null),
            'form_type'          => $nullify($data['form_type'] ?? null),
            'grade'              => $nullify($data['grade'] ?? null),
            'req_type'           => (int) ($data['req_type'] ?? 0),
            'size'               => $nullify($data['size'] ?? null),
            'length'             => $nullify($data['length'] ?? null),
            'qty'                => (float) ($data['qty'] ?? 0),
            'mfg_request_detail' => $nullify($data['mfg_request_detail'] ?? null),
            'comment'             => $nullify($data['reason'] ?? null),
        ];



        // ดึงฟอร์ม + เช็คสิทธิ์เป็นผู้อนุมัติของ step ปัจจุบัน
        $form = WorkflowDb::findForm('wocr', (int) $id);
        abort_unless($form, 404);
        //dd($form, $id);
        $auth = WorkflowDb::table('wocr', 'wf_form_authorizes')
            ->where('wf_form_id', $form->id)
            ->where('step_no', $form->current_step_no)
            ->where('approver_user_id', $userId)
            ->first();

        if (!$auth) abort(403, 'คุณไม่มีสิทธิ์อนุมัติฟอร์มนี้');
        if (strtoupper($auth->status) !== 'PENDING') {
            return back()->with('warning', 'ฟอร์มนี้ถูกดำเนินการแล้ว')->withInput();
        }
        $action = strtolower((string) $request->input('action')); // 'approve' | 'reject'

        // dd($action);
        if ($action === 'reject') {
            // ต้องมีเหตุผลเวลา Reject
            $request->validate([
                'reason' => 'required|string|max:2000',
            ], [
                'reason.required' => 'กรุณาระบุเหตุผลการ Reject',
            ]);

            WorkflowEngine::reject((int)$form->id, (int)$userId, (string) $request->input('reason'), 'wocr');

            return redirect()
                ->route('wocr.view', ['id' => $id])
                ->with('ok', 'ปฏิเสธคำขอเรียบร้อย ');
            //return back()->with('success', 'ปฏิเสธคำขอเรียบร้อย');
        }

        if ($action === 'approve') {

            DB::table('wocr_data')
                ->where('form_id', $id)
                ->update($payload);

            // --- แนบไฟล์ ---
            if ($request->hasFile('files')) {

                $existing = DB::table('wocr_data_files')
                    ->where('form_id', $id)
                    ->get(['id', 'file_path']);

                foreach ($existing as $ex) {
                    if (!empty($ex->file_path)) {
                        // ปรับ disk ให้ตรงกับตอน store() ถ้าใช้ disk อื่น เช่น 'public' หรือ 's3'
                        Storage::delete($ex->file_path);
                        // ตัวอย่าง: Storage::disk('public')->delete($ex->file_path);
                    }
                }


                DB::table('wocr_data_files')->where('form_id', $id)->delete();


                $today = now();
                $dir   = "wocr/{$today->year}/{$today->format('m')}/{$today->format('d')}";

                $rows = [];
                foreach ($request->file('files') as $file) {
                    if (!$file->isValid()) continue;
                    $stored = $file->store($dir);
                    $rows[] = [
                        'form_id'       => $id,
                        'file_name'     => basename($stored),
                        'original_name' => $file->getClientOriginalName(),
                        'file_path'     => $stored,
                        'file_type'     => $file->getClientMimeType(),
                        'file_size'     => $file->getSize(),
                    ];
                }
                if ($rows) DB::table('wocr_data_files')->insert($rows);
            }


            // comment เป็น optional
            $comment = (string) $request->input('reason', '');
            //WorkflowEngine::approve((int)$form->id, (string) $request->input('reason'));
            WorkflowEngine::approve((int)$form->id, (int)$userId, $comment, 'wocr');

            $nextApprovers = WorkflowDb::pendingApprovers('wocr', (int) $id);

            // ถ้าคนถัดไปมีตัวเราเอง ให้ยิงไปหน้า planner/review
            $flagToNextSelf = $nextApprovers->contains(fn($x) => (int)$x->approver_user_id === (int)$userId);

            if ($flagToNextSelf) {
                return redirect()
                    ->route('wocr.mngPlanner', ['id' => $id])
                    ->with('ok', 'ส่งคำขอเรียบร้อย');
            } else {
                return redirect()
                    ->route('wocr.view', ['id' => $id])
                    ->with('ok', 'ส่งคำขอเรียบร้อย');
            }
        }

        // action ไม่ถูกต้อง
        return back()->with('warning', 'ไม่รู้จักคำสั่งที่ส่งมา')->withInput();
    }
}
