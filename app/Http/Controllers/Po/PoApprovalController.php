<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Mail\PoClosedNotificationMail;
use App\Mail\PoNeedsApprovalMail;
use App\Models\Po\PoHeader;
use App\Models\WF\WfForm;
use App\Services\Po\PoErpService;
use App\Services\WorkflowEngine;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class PoApprovalController extends Controller
{
    public function __construct(private readonly PoErpService $erpService) {}

    public function submit(Request $request, $id)
    {
        $po = PoHeader::query()->findOrFail($id);
        abort_if($po->workflow_id, 422, 'PO already submitted');
        abort_if(!$po->attachments()->exists(), 422, 'Please attach document before submitting PO');

        $po = $this->erpService->syncHeaderFromErp($po->ordnumber, auth()->id(), $po->site);
        $departmentId = $this->erpService->findDepartmentId($po->f1);
        $submitterDepartmentId = (int) (auth()->user()?->department_id ?? 0);

        $wfId = WorkflowEngine::submit(
            appCode: 'po',
            refType: PoHeader::class,
            refId: $po->id,
            options: [
                'form_no' => $po->ordnumber,
                'request_by_user_id' => (int) auth()->id(),
                'context' => [
                    'department_id' => $departmentId,
                    'document_department_id' => $departmentId,
                    'submitter_department_id' => $submitterDepartmentId,
                ],
                'submit_comment' => $request->input('comment'),
                'initial_step_no' => 2,
            ],
        );

        $wf = WfForm::query()->find($wfId);
        $po->workflow_id = $wfId;
        $po->status_code = $this->resolveStatusFromWorkflow($wf);
        $po->updated_at = now();
        $po->updated_by = auth()->id();
        $po->save();

        $this->notifyPendingApprovers(collect([$po]));

        return redirect()->route('po.show', $po->id)->with('ok', 'ส่ง PO เข้า workflow และแจ้งผู้อนุมัติเรียบร้อยแล้ว');
    }

    public function submitDepartment(Request $request)
    {
        $data = $request->validate([
            'department_group' => 'required|string|max:100',
        ]);

        $rows = $this->erpService->listOpenPos(
            department: $data['department_group'],
            search: null,
        );

        $eligible = $rows->filter(function ($row) {
            return in_array((string) $row->status_code, ['NEW', 'DRAFT'], true)
                && blank($row->workflow_id)
                && $row->has_attachment;
        })->values();

        abort_if($eligible->isEmpty(), 422, 'No PO available for submission in this department group');

        $submitted = collect();

        foreach ($eligible as $row) {
            $header = $row->po_header_id
                ? PoHeader::query()->find($row->po_header_id)
                : null;

            if (!$header) {
                $header = $this->erpService->syncHeaderFromErp($row->ordnumber, auth()->id(), $row->site);
            }

            if ($header->workflow_id || !$header->attachments()->exists()) {
                continue;
            }

            $header = $this->erpService->syncHeaderFromErp($header->ordnumber, auth()->id(), $header->site);
            $departmentId = $this->erpService->findDepartmentId($header->f1);
            $submitterDepartmentId = (int) (auth()->user()?->department_id ?? 0);

            $wfId = WorkflowEngine::submit(
                appCode: 'po',
                refType: PoHeader::class,
                refId: $header->id,
                options: [
                    'form_no' => $header->ordnumber,
                    'request_by_user_id' => (int) auth()->id(),
                    'context' => [
                        'department_id' => $departmentId,
                        'document_department_id' => $departmentId,
                        'submitter_department_id' => $submitterDepartmentId,
                    ],
                    'submit_comment' => 'Submitted from PO Online department group',
                    'initial_step_no' => 2,
                ],
            );

            $wf = WfForm::query()->find($wfId);
            $header->workflow_id = $wfId;
            $header->status_code = $this->resolveStatusFromWorkflow($wf);
            $header->updated_at = now();
            $header->updated_by = auth()->id();
            $header->save();

            $submitted->push($header->fresh(['workflow']));
        }

        abort_if($submitted->isEmpty(), 422, 'No PO submitted');

        $this->notifyPendingApprovers($submitted);

        $group = $data['department_group'];
        $count = $submitted->count();

        return redirect()
            ->route('po.index', ['department' => $group])
            ->with('ok', "ส่งอีเมลขออนุมัติสำหรับกลุ่ม {$group} เรียบร้อยแล้ว ({$count} PO)");
    }

    public function remindDepartmentHead(Request $request)
    {
        $data = $request->validate([
            'department_group' => 'required|string|max:100',
        ]);

        $rows = $this->erpService->listOpenPos(
            department: $data['department_group'],
            search: null,
        );

        $eligible = $rows->filter(function ($row) {
            return strtoupper((string) $row->status_code) === 'DEPT_MANAGER_APPROVAL'
                && !blank($row->workflow_id);
        })->values();

        abort_if($eligible->isEmpty(), 422, 'No PO waiting for department head approval in this department group');

        $headers = collect();

        foreach ($eligible as $row) {
            $header = $row->po_header_id
                ? PoHeader::query()->find($row->po_header_id)
                : null;

            if (!$header) {
                $header = $this->erpService->syncHeaderFromErp($row->ordnumber, auth()->id(), $row->site);
            }

            if (blank($header?->workflow_id)) {
                continue;
            }

            $headers->push($header->fresh(['workflow']));
        }

        abort_if($headers->isEmpty(), 422, 'No PO waiting for department head approval');

        $this->notifyPendingApprovers($headers);

        $group = $data['department_group'];
        $count = $headers->count();

        return redirect()
            ->route('po.index', ['department' => $group])
            ->with('ok', "ส่งอีเมลแจ้งหัวหน้าแผนกเรียบร้อยแล้ว ({$count} PO)");
    }

    public function notifySelectedStepThree(Request $request)
    {
        $data = $request->validate([
            'po_ids' => 'required|array|min:1',
            'po_ids.*' => 'integer',
        ]);

        $headers = PoHeader::query()
            ->with('workflow')
            ->whereIn('id', $data['po_ids'])
            ->get()
            ->filter(function (PoHeader $po) {
                return !blank($po->workflow_id)
                    && strtoupper((string) $po->status_code) === 'DEPT_MANAGER_APPROVAL'
                    && (int) ($po->workflow?->current_step_no ?? 0) === 3;
            })
            ->values();

        abort_if($headers->isEmpty(), 422, 'Selected PO must be at step 3 only');

        $this->notifyPendingApprovers($headers);

        return redirect()->back()->with('ok', 'ส่งอีเมลแจ้งเตือนรายการ PO ที่เลือกให้ผู้อนุมัติ step 3 เรียบร้อยแล้ว');
    }

    public function approve(Request $request, $id)
    {
        $po = PoHeader::query()->with('workflow')->findOrFail($id);
        abort_if(!$po->workflow_id, 422, 'Workflow not found');

        $stepBeforeApprove = (int) ($po->workflow?->current_step_no ?? 0);

        WorkflowEngine::approve((int) $po->workflow_id, (int) auth()->id(), $request->input('comment'), 'po');

        $wf = WfForm::query()->find($po->workflow_id);
        $po->status_code = $this->resolveStatusFromWorkflow($wf);
        $po->updated_at = now();
        $po->updated_by = auth()->id();
        $po->save();

        if ($po->status_code === 'CLOSED') {
            $this->notifyPurchaseDepartmentOnClosed($po);
        } elseif ($stepBeforeApprove !== 2) {
            $this->notifyPendingApprovers(collect([$po]));
        }

        return redirect()->route('po.show', $po->id)->with('ok', 'อนุมัติ PO เรียบร้อยแล้ว');
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $po = PoHeader::query()->findOrFail($id);
        abort_if(!$po->workflow_id, 422, 'Workflow not found');

        WorkflowEngine::reject((int) $po->workflow_id, (int) auth()->id(), $request->input('comment'), 'po');

        $po->status_code = 'REJECTED';
        $po->updated_at = now();
        $po->updated_by = auth()->id();
        $po->save();

        return redirect()->route('po.show', $po->id)->with('ok', 'ตีกลับ PO เรียบร้อยแล้ว');
    }

    public function sendBack(Request $request, $id)
    {
        return $this->reject($request, $id);
    }

    public function cancel(Request $request, $id)
    {
        $po = PoHeader::query()->findOrFail($id);
        abort_if(!$po->workflow_id, 422, 'Workflow not found');

        WorkflowEngine::void((int) $po->workflow_id, (int) auth()->id(), $request->input('comment'), 'po');

        $po->status_code = 'CANCELLED';
        $po->updated_at = now();
        $po->updated_by = auth()->id();
        $po->save();

        return redirect()->route('po.show', $po->id)->with('ok', 'ยกเลิก PO เรียบร้อยแล้ว');
    }

    private function resolveStatusFromWorkflow(?WfForm $wf): string
    {
        if (!$wf) {
            return 'IN_APPROVAL';
        }

        if ((int) ($wf->current_step_no ?? 0) === 999 || (string) ($wf->form_status ?? '') === WfForm::ST_CLOSED) {
            return 'CLOSED';
        }

        return match ((int) ($wf->current_step_no ?? 0)) {
            1 => 'PURCHASE_SUBMITTED',
            2 => 'PURCHASE_APPROVAL',
            3 => 'DEPT_MANAGER_APPROVAL',
            default => 'IN_APPROVAL',
        };
    }

    private function notifyPendingApprovers(Collection $poHeaders): void
    {
        $poHeaders = $poHeaders
            ->filter(fn ($po) => !blank($po?->workflow_id))
            ->values();

        if ($poHeaders->isEmpty()) {
            return;
        }

        $byWorkflow = $poHeaders->keyBy('workflow_id');
        $workflowIds = $byWorkflow->keys()->all();

        $pendingRows = SqlServerDb::table('wf_form_authorizes as wa')
            ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
            ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
            ->whereIn('wa.wf_form_id', $workflowIds)
            ->where('wa.status', 'PENDING')
            ->whereColumn('wa.step_no', 'wf.current_step_no')
            ->whereNotNull('u.email')
            ->select([
                'wa.wf_form_id',
                'u.email',
                'u.name',
            ])
            ->get();

        $groupedByEmail = $pendingRows
            ->groupBy('email')
            ->map(function ($rows, $email) use ($byWorkflow) {
                $items = $rows
                    ->map(function ($row) use ($byWorkflow) {
                        $po = $byWorkflow->get($row->wf_form_id);
                        if (!$po) {
                            return null;
                        }

                        return [
                            'ordnumber' => $po->ordnumber,
                            'source_label' => PoErpService::sourceLabel($po->site),
                            'department' => $po->f1,
                            'vendor_name' => $po->vendor_name,
                            'status_code' => $po->status_code,
                            'approve_url' => route('po.show', $po->id),
                            'print_url' => route('po.print', $po->id),
                        ];
                    })
                    ->filter()
                    ->unique(fn ($item) => ($item['source_label'] ?? '') . '::' . ($item['ordnumber'] ?? ''))
                    ->values()
                    ->all();

                return [
                    'name' => $rows->first()->name ?? $email,
                    'items' => $items,
                ];
            })
            ->filter(fn ($data) => !empty($data['items']));

        foreach ($groupedByEmail as $email => $data) {
            Mail::to($email)->send(new PoNeedsApprovalMail(
                approverName: (string) $data['name'],
                poItems: $data['items'],
            ));
        }
    }

    private function notifyPurchaseDepartmentOnClosed(PoHeader $po): void
    {
        $recipients = $this->purchaseDepartmentRecipients($po);

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->send(new PoClosedNotificationMail(
                recipientName: (string) ($recipient->name ?: 'Purchase'),
                poItem: [
                    'ordnumber' => $po->ordnumber,
                    'source_label' => PoErpService::sourceLabel($po->site),
                    'department' => $po->f1,
                    'vendor_name' => $po->vendor_name,
                    'show_url' => route('po.show', $po->id),
                    'print_url' => route('po.print', $po->id),
                ],
            ));
        }
    }

    private function purchaseDepartmentRecipients(PoHeader $po): Collection
    {
        $departmentIds = collect();

        if (!blank($po->workflow_id)) {
            $departmentIds = SqlServerDb::table('wf_form_authorizes as wa')
                ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
                ->where('wa.wf_form_id', $po->workflow_id)
                ->where('wa.step_no', 2)
                ->whereNotNull('u.department_id')
                ->pluck('u.department_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();
        }

        $baseQuery = SqlServerDb::table('users as u')
            ->where('u.is_active', 1)
            ->whereNotNull('u.email');

        if ($departmentIds->isNotEmpty()) {
            return $baseQuery
                ->whereIn('u.department_id', $departmentIds->all())
                ->orderBy('u.name')
                ->get(['u.name', 'u.email'])
                ->unique('email')
                ->values();
        }

        return $baseQuery
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->where(function ($query) {
                $query->where('d.name', 'like', '%Purchas%')
                    ->orWhere('d.code', 'like', '%PURCH%')
                    ->orWhere('u.department', 'like', '%Purchas%')
                    ->orWhere('u.position', 'like', '%Purchas%');
            })
            ->orderBy('u.name')
            ->get(['u.name', 'u.email'])
            ->unique('email')
            ->values();
    }
}
