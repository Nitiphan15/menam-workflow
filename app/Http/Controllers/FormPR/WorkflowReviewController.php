<?php

namespace App\Http\Controllers\FormPR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\WorkflowEngine;
use App\Support\SqlServerDb;

class WorkflowReviewController extends Controller
{
    public function show($id)
    {
        $form = SqlServerDb::table('wf_forms')->where('id', $id)->first();
        abort_unless($form, 404);

        $requester = SqlServerDb::table('users')->where('id', $form->request_by_user_id)->first();

        $data  = DB::table('pr_data')->where('id', $form->ref_id)->first();
        $lines = DB::table('pr_data_list')
            ->where('form_id', $form->id)
            ->orderBy('seq_no')
            ->get();

        $files = DB::table('pr_data_files')->where('form_id', $form->id)->get();

        $canApprove = SqlServerDb::table('wf_form_authorizes')
            ->where('wf_form_id', $form->id)
            ->where('approver_user_id', Auth::id())
            ->where('status', 'PENDING')
            ->exists();

        $history = SqlServerDb::table('wf_action_histories as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
            ->where('h.wf_form_id', $form->id)
            ->orderBy('h.created_at')
            ->get([
                'h.step_no',
                'h.action_type',
                'h.comment',
                'h.created_at',
                'u.name as actor_name'
            ]);

        return view('formpr.review', compact(
            'form',
            'requester',
            'data',
            'lines',
            'files',
            'canApprove',
            'history'
        ));
    }

    public function approve(Request $request, $id)
    {
        WorkflowEngine::approve((int)$id, Auth::id(), $request->input('comment'));
        return redirect()->route('pr.my_actions')->with('ok', 'อนุมัติเรียบร้อย');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['comment' => 'required|string|max:1000']);
        WorkflowEngine::reject((int)$id, Auth::id(), $request->input('comment'));
        return redirect()->route('pr.my_actions')->with('ok', 'ปฏิเสธเรียบร้อย');
    }
}
