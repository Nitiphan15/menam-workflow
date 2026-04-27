<?php

namespace App\Http\Controllers\FormWOCR;

use App\Http\Controllers\Controller;
use App\Models\FormWOCR\WocrData;
use App\Models\FormWOCR\WocrDataFile;
use App\Support\WorkflowDb;

class WocrViewController extends Controller
{
    public function view($id)
    {
        $wocrData = WocrData::query()
            ->where('form_id', $id)
            ->firstOrFail();   // ไม่เจอ => 404

        $wfForm = WorkflowDb::findForm('wocr', (int) $id);
        abort_unless($wfForm, 404);

        $history = WorkflowDb::historyWithActors('wocr', (int) $wfForm->id);

        $firstApprove = $history->first(
            fn($h) => (int)$h->step_no === 2 && strcasecmp($h->action_type, 'APPROVE') === 0
        );
        //dd($firstApprove);
        $items = [[
            'mfg_no'  => (string)($wocrData->mfg_no ?? ''),
            'grade'   => (string)($wocrData->grade ?? ''),
            'type'    => (string)($wocrData->form_type  ?? ''),
            'size'    => (string)($wocrData->size  ?? ''),
            'length'  => $wocrData->length ?? '',
            'qty'     => $wocrData->qty    ?? '',
            'remark'  => (string)($wocrData->mfg_request_detail ?? ''),
        ]];


        $attachments = WocrDataFile::where('form_id', $id)
            ->orderByDesc('id')
            ->get();

        $canApprove = WorkflowDb::canApprove('wocr', (int) $wfForm->id, (int) auth()->id());

        return view('formwocr.view', [
            'form'       => $wfForm,
            'items'      => $items,

            // ส่งอย่างอื่นเผื่อใช้ในหน้า
            'WocrData'   => $wocrData,
            'history'    => $history,
            'canApprove' => $canApprove,
            'firstApprove' => $firstApprove,
            'attachments' => $attachments,
        ]);
    }
}
