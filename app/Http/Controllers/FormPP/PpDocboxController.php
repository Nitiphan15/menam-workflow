<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;
use App\Models\FormPP\PpData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PpDocboxController extends Controller
{
    private string $wfTable         = 'wf_forms';
    private string $waTable         = 'wf_form_authorizes';
    private string $wfStatusCol     = 'form_status';
    private string $wfStepCol       = 'current_step_no';
    private string $wfOriginatorCol = 'request_by_user_id';

    private array $pendingStates = ['pending', 'P', '']; // เว้นว่างไว้ครอบคลุมกรณี NULL/ค่าว่าง

    public function pending(Request $r)
    {
        return $this->list($r, 'pending');
    }
    public function mine(Request $r)
    {
        return $this->list($r, 'mine');
    }
    public function all(Request $r)
    {
        return $this->list($r, 'all');
    }

    private function list(Request $r, string $box)
    {
        $userId = $r->user()->id;
        $kw     = trim((string)$r->query('q', ''));
        $status     = trim((string)$r->query('status', ''));
        //dump($status);
        $waMulti = DB::table("{$this->waTable} as wa")
            ->select([
                'wa.wf_form_id',
                'wa.step_no',
                DB::raw('GROUP_CONCAT(DISTINCT wa.approver_user_id) as wf_approver_ids'),
                DB::raw('GROUP_CONCAT(wa.status) as wa_statuses')
            ])
            ->groupBy('wa.wf_form_id', 'wa.step_no');

        // pp + wf_forms
        $q = PpData::query()
            ->from('pp_data as pp')
            ->join("{$this->wfTable} as wf", 'wf.id', '=', 'pp.form_id')
            ->leftJoin('users as req_users', 'req_users.id', '=', "wf.{$this->wfOriginatorCol}")
            ->leftJoin('workflows as wfm', 'wfm.code', '=', 'wf.app_code')
            ->leftJoin('workflow_steps as wfs', function ($j) {
                $j->on('wfs.workflow_id', '=', 'wfm.id')
                    ->on('wfs.step_no',     '=', "wf.{$this->wfStepCol}")
                    ->where('wfs.is_active', 1); // กันกรณี step ถูกปิด
            })
            // leftJoin ไว้เสมอ เผื่ออยากแสดงสถานะ authorizes ในตาราง
            ->leftJoinSub($waMulti, 'wa', function ($j) {
                $j->on('wa.wf_form_id', '=', 'wf.id')
                    ->on('wa.step_no', '=', "wf.{$this->wfStepCol}");
            })
            ->select([
                'pp.*',
                'wf.form_no',
                "wf.{$this->wfStatusCol} as wf_status",
                "wf.{$this->wfOriginatorCol} as wf_originator_id",
                'req_users.name as requester_name',
                "wf.{$this->wfStepCol} as wf_current_step",
                'wfs.id  as wf_current_step_id',
                'wfs.key as wf_current_step_key',
                DB::raw("
                    CASE
                        WHEN wf.{$this->wfStepCol} = 999 THEN 'Closed'
                        WHEN wf.{$this->wfStepCol} = 998 THEN 'Voided'
                        ELSE wfs.name
                    END AS wf_current_step_name
                "),
                DB::raw('wa.wf_approver_ids'),
                DB::raw('wa.wa_statuses'),
            ])
            ->distinct('pp.docu_no');

        //
        // กล่องเอกสาร
        if ($box === 'pending') {
            $q->whereRaw("FIND_IN_SET(?, wa.wf_approver_ids)", [$userId])
                ->where(function ($w) {
                    $w->whereNull('wa.wa_statuses')
                        ->orWhere('wa.wa_statuses', 'like', '%PENDING%');
                });
        } elseif ($box === 'mine') {
            $q->where("wf.{$this->wfOriginatorCol}", $userId);
        } // 'all' ไม่กรองเพิ่ม

        // คีย์เวิร์ด
        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->where('pp.part_no', 'like', "%{$kw}%")
                    ->orWhere('pp.customer', 'like', "%{$kw}%")
                    ->orWhere('wf.form_status', 'like', "%{$kw}%")
                    ->orWhere('pp.docu_no', 'like', "%{$kw}%");
            });
        }

        $list = $q
            ->orderByDesc('pp.docu_no')
            ->paginate(12)
            ->withQueryString();
        $list->getCollection()->each(function ($row) {
            $row->setRelation('requester', (object) ['name' => $row->requester_name ?? null]);
        });
        //$canApprove = in_array(auth()->id(), explode(',', $row->wf_approver_ids ?? ''));

        //dd($list);
        //dd($q->toSql());
        return view('formpp.show', [
            'list'      => $list,
            'box'       => $box,
            'q'         => $kw,
            'pageTitle' => 'Production Planning Form',
            'showRoute' => null, // ถ้ามีหน้า show ให้ใส่ชื่อ route ที่นี่
        ]);
    }
}
