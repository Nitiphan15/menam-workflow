<?php

namespace App\Http\Controllers\FormWOCR;

use App\Http\Controllers\Controller;

use App\Models\FormWOCR\WocrData;
use App\Models\FormWOCR\WocrDataFile;
use App\Services\WorkflowEngine;
use App\Support\WorkflowDb;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MngPlannerController extends Controller
{
    public function showPlanner($id)
    {
        $step = 3;
        $wocrData = WocrData::query()
            ->where('form_id', $id)
            ->firstOrFail();   // ไม่เจอ => 404

        $wfForm = WorkflowDb::findForm('wocr', (int) $id);
        abort_unless($wfForm, 404);


        $items = [[
            'mfg_no'  => (string)($wocrData->mfg_no ?? ''),
            'grade'   => (string)($wocrData->grade ?? ''),
            'type'    => (string)($wocrData->form_type  ?? ''),
            'size'    => (string)($wocrData->size  ?? ''),
            'length'  => $wocrData->length ?? '',
            'qty'     => $wocrData->qty    ?? '',
            'remark'  => (string)($wocrData->mfg_request_detail ?? ''),
        ]];

        $history = WorkflowDb::historyWithActors('wocr', (int) $wfForm->id);

        $attachments = WocrDataFile::where('form_id', $id)
            ->orderByDesc('id')
            ->get();


        $canApprove = WorkflowDb::canApprove('wocr', (int) $wfForm->id, (int) auth()->id());

        if ($wfForm->current_step_no != $step) {
            $canApprove = false;
        }

        return view('formwocr.mngPlanner', [
            // ตัวแปรสำหรับ "โหมด Planner"
            'plannerMode' => true,
            'items'      => $items,
            'WocrData'    => $wocrData,
            'form'        => $wfForm,
            'history'    => $history,
            'canApprove'  => $canApprove,
            'attachments' => $attachments,
        ]);
    }

    public function planner_action(Request $request, $id)
    {
        $userId = $request->user()->id;

        // ดึงฟอร์ม + เช็คสิทธิ์เป็นผู้อนุมัติของ step ปัจจุบัน
        $form = WorkflowDb::findForm('wocr', (int) $id);
        abort_unless($form, 404);

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
            //dd($form->id, $userId, $request->input('reason'));
            WorkflowEngine::reject((int)$form->id, (int)$userId, (string) $request->input('reason'), 'wocr');

            $nextApprovers = WorkflowDb::pendingApprovers('wocr', (int) $id);

            // ถ้าคนถัดไปมีตัวเราเอง ให้ยิงไปหน้า planner/review
            $flagToNextSelf = $nextApprovers->contains(fn($x) => (int)$x->approver_user_id === (int)$userId);

            if ($flagToNextSelf) {
                return redirect()
                    ->route('wocr.revise', ['id' => $id])
                    ->with('ok', 'ปฏิเสธคำขอเรียบร้อย ');
            } else {
                return redirect()
                    ->route('wocr.view', ['id' => $id])
                    ->with('ok', 'ปฏิเสธคำขอเรียบร้อย ');
            }

            //return back()->with('success', 'ปฏิเสธคำขอเรียบร้อย');
        }

        if ($action === 'approve') {

            // comment เป็น optional
            $comment = (string) $request->input('reason', '');
            WorkflowEngine::approve((int)$form->id, (int)$userId, $comment, 'wocr');

            return redirect()
                ->route('wocr.view', ['id' => $id])
                ->with('ok', 'อนุมัติสำเร็จ');
            //return back()->with('success', 'อนุมัติสำเร็จ');
        }

        // action ไม่ถูกต้อง
        return back()->with('warning', 'ไม่รู้จักคำสั่งที่ส่งมา')->withInput();
    }
}
