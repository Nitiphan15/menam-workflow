<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;
use App\Models\FormPP\PpData;
use App\Support\WorkflowDb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ViewController extends Controller
{
    public function view($id)
    {
        $ppData = PpData::query()
            ->where('form_id', $id)
            ->firstOrFail();   // ไม่เจอ => 404
        $sku    = $ppData->part_no;
        //  dd($ppData);
        $wfForm = WorkflowDb::findForm('pp', (int) $id);
        abort_unless($wfForm, 404);

        $history = WorkflowDb::historyWithActors('pp', (int) $wfForm->id);

        $canApprove = WorkflowDb::canApprove('pp', (int) $wfForm->id, (int) auth()->id());

        return view('formpp.view', [
            // ของเดิมที่ index ต้องการ
            'sku'         => $sku,
            'ppData'      => $ppData,
            'form'        => $wfForm,
            'canApprove'  => $canApprove,
            'history'     => $history,
        ]);
    }
}
