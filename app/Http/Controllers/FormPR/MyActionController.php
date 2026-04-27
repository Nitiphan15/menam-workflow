<?php

namespace App\Http\Controllers\FormPR;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;
use Illuminate\Support\Facades\Auth;

class MyActionController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $items = SqlServerDb::table('wf_form_authorizes as auth')
            ->join('wf_forms as wf', 'auth.wf_form_id', '=', 'wf.id')
            ->where('auth.approver_user_id', $userId)
            ->where('auth.status', 'PENDING')
            ->whereNotIn('wf.form_status', ['CLOESD', 'CANCELLED'])
            ->orderByDesc('wf.last_action_dt')
            ->get([
                'wf.id as form_id',
                'wf.app_code',
                'wf.form_no',
                'wf.form_status',
                'wf.ref_type',
                'wf.ref_id',
                'auth.step_no',
            ])
            ->map(function ($row) {
                // กำหนด action name แบบง่าย
                $row->action = 'review'; // หรือจะ map จาก step_no/form_status ก็ได้
                return $row;
            });

        return view('formpr.myTasks', compact('items'));
    }
}
