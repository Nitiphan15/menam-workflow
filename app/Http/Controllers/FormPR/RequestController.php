<?php

namespace App\Http\Controllers\FormPR;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\Users\Department;
use App\Models\FormPR\PrData;
use App\Services\WorkflowEngine;
use App\Services\ApproverResolver;
use App\Support\SqlServerDb;

class RequestController extends Controller
{
    public function create()
    {
        $user = null;
        if (auth()->check()) {
            $user = auth()->user();
        }

        if ($user) {
            $items = Department::where('id', $user->department_id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }

        return view('formpr.create', compact('user', 'items'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'request_date'    => 'required|date',
            'docu_date'       => 'nullable|date',
            'docu_no'         => 'nullable|string|max:30',
            'department_id'   => 'required|integer|exists:sqlsrv_menam.departments,id',
            'type'            => 'required|array',
            'type.*'          => 'in:1,2,3,4,5,6',
            'type_other'      => 'nullable|string|max:255',
            'attached_file'   => 'nullable|boolean',
            'skip_sup'        => 'nullable|boolean',
            'skip_sup_reason' => 'nullable|string|max:1000',
            'reason'          => 'nullable|string|max:255',

            // รายการสินค้า
            'list'                 => 'nullable|array',
            'list.*.detail'        => 'required_with:list|string|max:500',
            'list.*.qty'           => 'required_with:list|numeric|min:0',
            'list.*.unit'          => 'nullable|string|max:50',
            'list.*.price'         => 'nullable|numeric|min:0',
            'list.*.objective'     => 'nullable|string|max:255',

            // files[]
            'files'           => 'nullable|array',
            'files.*'         => 'file|max:20480',
        ]);

        return DB::transaction(function () use ($r, $data) {

            $user = $r->user();

            $dept = SqlServerDb::table('departments')
                ->select('id', 'code', 'name')
                ->find($data['department_id']);

            // gen เลขเอกสาร: DEPT+YY+MM+RUN4
            $docuNo  = PrData::nextDocNo((int) $dept->id);
            $typeStr = implode(',', $data['type']);

            // --- บันทึกหัว PR ---
            $prId = DB::table('pr_data')->insertGetId([
                'form_id'       => null,
                'req_date'      => $data['request_date'],
                'docu_date'     => $data['docu_date'] ?? now()->toDateString(),
                'docu_no'       => $docuNo,
                'department_id' => $dept->id,
                'department'    => $dept->name,
                'site'          => $user->site ?? null,
                'type'          => $typeStr,
                'type_other'    => $data['type_other'] ?? null,
                'attached_file' => (int) $r->boolean('attached_file'),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // --- เปิด Workflow ---
            $wfId = WorkflowEngine::submit(
                appCode: 'pr',
                refType: PrData::class,
                refId: $prId,
                options: [
                    'form_no'            => $docuNo,
                    'request_by_user_id' => (int) $user->id,
                    'skip_flags'         => ['sup' => $r->boolean('skip_sup')],
                    'context'            => [
                        'department_id' => (int) $data['department_id'],
                    ],
                    'submit_comment'     => $data['skip_sup_reason'] ?? null,
                ]
            );

            // ชี้กลับ form_id
            DB::table('pr_data')->where('id', $prId)->update([
                'form_id'    => $wfId,
                'updated_at' => now(),
            ]);

            // --- Auto-approve step 1 ถ้าอนุมัติโดยต้นเรื่องเพียงคนเดียวจริง ---
            $authStep1 = SqlServerDb::table('wf_form_authorizes')
                ->where('wf_form_id', $wfId)
                ->where('step_no', 1)
                ->pluck('approver_user_id');

            if ($authStep1->count() === 1 && (int) $authStep1->first() === (int) $user->id) {
                WorkflowEngine::approve($wfId, (int) $user->id, $data['reason'] ?? null);
            }

            // --- รายการสินค้า ---
            if (!empty($data['list']) && is_array($data['list'])) {
                $rows = [];
                foreach ($data['list'] as $i => $it) {
                    if (!isset($it['detail']) || trim($it['detail']) === '') continue;
                    $rows[] = [
                        'form_id'   => $wfId,          // ใช้ wf_forms.id
                        'seq_no'    => $i + 1,
                        'detail'    => trim($it['detail']),
                        'qty'       => isset($it['qty']) ? (float) $it['qty'] : 0,
                        'unit'      => $it['unit'] ?? null,
                        'price'     => isset($it['price']) ? (float) $it['price'] : 0,
                        'objective' => $it['objective'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if ($rows) DB::table('pr_data_list')->insert($rows);
            }

            // --- แนบไฟล์ ---
            if ($r->hasFile('files')) {
                $today = now();
                $dir   = "pr/{$today->year}/{$today->format('m')}/{$today->format('d')}";

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
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
                if ($rows) DB::table('pr_data_files')->insert($rows);
            }

            return redirect()
                ->route('pr.create', $prId)
                ->with('ok', $r->boolean('skip_sup')
                    ? 'ส่ง PR แล้ว (ข้าม SUP ไป Manager)'
                    : 'ส่ง PR แล้ว (รอ Supervisor)');
        });
    }
}
