<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Models\WF\WfFormAuthorize;
use App\Services\Po\PoErpService;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PoController extends Controller
{
    public function __construct(private readonly PoErpService $erpService)
    {
    }

    public function index(Request $request)
    {
        if (Gate::denies('POPUR')) {
            return redirect()->route('po.myActions');
        }

        $filters = [
            'department' => $request->string('department')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'source' => $request->string('source')->toString() ?: null,
        ];

        $rows = $this->erpService->listOpenPos(
            department: $filters['department'],
            search: $filters['search'],
            dateFrom: $filters['date_from'],
            dateTo: $filters['date_to'],
            status: $filters['status'],
            source: $filters['source'],
        );

        $departmentRows = $this->erpService->listOpenPos(
            department: null,
            search: $filters['search'],
            dateFrom: $filters['date_from'],
            dateTo: $filters['date_to'],
            status: $filters['status'],
            source: $filters['source'],
        );

        $departments = $departmentRows->pluck('department_group')->filter()->unique()->sort()->values();
        $grouped = $rows->groupBy('department_group')->map(function ($items, $departmentGroup) {
            $eligibleStatuses = ['NEW', 'DRAFT'];
            $pendingItems = $items->filter(function ($row) use ($eligibleStatuses) {
                return in_array((string) $row->status_code, $eligibleStatuses, true)
                    && blank($row->workflow_id)
                    && $row->has_attachment;
            })->values();

            $attachedCount = $items->where('has_attachment', true)->count();
            $draftCount = $items->filter(fn ($row) => strtoupper((string) $row->status_code) === 'DRAFT')->count();
            $deptManagerPendingCount = $items->filter(
                fn ($row) => strtoupper((string) $row->status_code) === 'DEPT_MANAGER_APPROVAL'
            )->count();

            return (object) [
                'name' => $departmentGroup,
                'items' => $items->values(),
                'pending_submit_count' => $pendingItems->count(),
                'attached_count' => $attachedCount,
                'draft_count' => $draftCount,
                'dept_manager_pending_count' => $deptManagerPendingCount,
            ];
        });

        $summary = [
            'po_count' => $rows->count(),
            'department_count' => $grouped->count(),
            'attached_count' => $rows->where('has_attachment', true)->count(),
            'draft_count' => $rows->filter(fn ($row) => strtoupper((string) $row->status_code) === 'DRAFT')->count(),
        ];

        $statusOptions = $this->statusOptions();
        $sourceOptions = $this->sourceOptions();

        return view('po.index', compact('grouped', 'departments', 'summary', 'statusOptions', 'sourceOptions'));
    }

    public function create(Request $request)
    {
        $ordnumber = trim((string) $request->query('ordnumber'));
        $source = $request->string('source')->toString() ?: null;
        abort_if($ordnumber === '', 422, 'ordnumber is required');

        $header = $this->erpService->syncHeaderFromErp($ordnumber, auth()->id(), $source);

        return redirect()->route('po.show', $header->id);
    }

    public function myActions(Request $request)
    {
        $workflowIds = SqlServerDb::table('wf_form_authorizes as wa')
            ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
            ->where('wa.approver_user_id', auth()->id())
            ->where('wa.status', WfFormAuthorize::ST_PENDING)
            ->whereColumn('wa.step_no', 'wf.current_step_no')
            ->pluck('wa.wf_form_id')
            ->all();

        $rows = $this->erpService->listOpenPos(
            department: $request->string('department')->toString() ?: null,
            search: $request->string('search')->toString() ?: null,
            dateFrom: $request->string('date_from')->toString() ?: null,
            dateTo: $request->string('date_to')->toString() ?: null,
            status: $request->string('status')->toString() ?: null,
            source: $request->string('source')->toString() ?: null,
            workflowIds: $workflowIds,
        );

        return view('po.my_actions', [
            'rows' => $rows->values(),
            'statusOptions' => $this->statusOptions(),
            'sourceOptions' => $this->sourceOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'ordnumber' => 'required|string|max:50',
            'source' => 'nullable|in:wire,plus',
        ]);

        $header = $this->erpService->syncHeaderFromErp($request->ordnumber, auth()->id(), $request->input('source'));

        return redirect()->route('po.show', $header->id);
    }

    public function show($id)
    {
        $po = PoHeader::query()->with(['attachments.creator', 'workflow'])->findOrFail($id);
        $po = $this->erpService->syncHeaderFromErp($po->ordnumber, auth()->id(), $po->site);
        $po->load([
            'attachments.creator',
            'workflow',
        ]);
        $detailRows = $this->erpService->getDetailRows($po->ordnumber, $po->site);
        $headerRow = $detailRows->first();
        $signatures = $this->erpService->printWorkflowSignatures($po->workflow_id);
        $currentStepNo = (int) ($po->workflow?->current_step_no ?? 0);

        $canPurchaseOperate = Gate::allows('POPUR');
        $canSubmit = blank($po->workflow_id)
            && $po->status_code === 'DRAFT'
            && $po->attachments->isNotEmpty()
            && $canPurchaseOperate;
        $canEditAttachment = in_array($po->status_code, ['DRAFT', 'REJECTED'], true)
            && $canPurchaseOperate;
        $canApprove = $po->workflow_id
            ? SqlServerDb::table('wf_form_authorizes as wa')
                ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
                ->where('wa.wf_form_id', $po->workflow_id)
                ->where('wa.approver_user_id', auth()->id())
                ->where(function ($query) {
                    $query->whereNull('wa.status')
                        ->orWhere('wa.status', WfFormAuthorize::ST_PENDING);
                })
                ->whereColumn('wa.step_no', 'wf.current_step_no')
                ->exists()
            : false;
        $pendingApprovers = $po->workflow_id
            ? SqlServerDb::table('wf_form_authorizes as wa')
                ->join('wf_forms as wf', 'wf.id', '=', 'wa.wf_form_id')
                ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
                ->where('wa.wf_form_id', $po->workflow_id)
                ->where(function ($query) {
                    $query->whereNull('wa.status')
                        ->orWhere('wa.status', WfFormAuthorize::ST_PENDING);
                })
                ->whereColumn('wa.step_no', 'wf.current_step_no')
                ->orderBy('u.name')
                ->get([
                    'u.id',
                    'u.name',
                    'u.email',
                    'wa.step_no',
                    'wa.created_at',
                ])
                ->unique('id')
                ->values()
            : collect();

        $workflowHistories = $po->workflow_id
            ? SqlServerDb::table('wf_action_histories as h')
                ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
                ->where('h.wf_form_id', $po->workflow_id)
                ->orderBy('h.created_at')
                ->orderBy('h.id')
                ->get([
                    'h.step_no',
                    'h.action_type',
                    'h.comment',
                    'h.created_at',
                    DB::raw("CASE WHEN h.action_type = 'SKIP' THEN 'System' ELSE u.name END as actor_name"),
                    'u.email as actor_email',
                ])
            : collect();

        $workflowRemarks = $workflowHistories
            ->filter(fn ($row) => trim((string) ($row->comment ?? '')) !== '')
            ->sortBy(fn ($row) => (string) ($row->created_at ?? ''))
            ->values();

        $currentStepName = match ($currentStepNo) {
            1 => 'Purchase Submit',
            2 => 'Purchase Approval',
            3 => 'Department Manager Approval',
            999 => 'Closed',
            default => null,
        };

        return view('po.show', compact(
            'po',
            'detailRows',
            'headerRow',
            'signatures',
            'canSubmit',
            'canEditAttachment',
            'canApprove',
            'pendingApprovers',
            'workflowHistories',
            'workflowRemarks',
            'currentStepName',
        ));
    }

    public function edit($id)
    {
        return redirect()->route('po.show', $id);
    }

    public function update(Request $request, $id)
    {
        $po = PoHeader::query()->findOrFail($id);

        $request->validate([
            'notes' => 'nullable|string',
            'files' => 'nullable|array',
            'files.*' => 'file|max:20480',
            'file_remark' => 'nullable|array',
            'file_remark.*' => 'nullable|string|max:500',
        ]);

        $po->notes = $request->input('notes', $po->notes);
        $po->updated_at = now();
        $po->updated_by = auth()->id();
        $po->save();

        if ($request->hasFile('files')) {
            $today = now();
            $dir = "po/{$today->year}/{$today->format('m')}/{$today->format('d')}";
            $safePoNo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $po->ordnumber);
            $existingFileCount = $po->attachments()->count();

            foreach ($request->file('files') as $index => $file) {
                if (!$file || !$file->isValid()) {
                    continue;
                }

                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'file');
                $sequence = $existingFileCount + $index + 1;
                $fileName = sprintf('%s_%02d.%s', $safePoNo, $sequence, $extension);
                $candidate = "{$dir}/{$fileName}";

                while (Storage::disk('public')->exists($candidate)) {
                    $sequence++;
                    $fileName = sprintf('%s_%02d.%s', $safePoNo, $sequence, $extension);
                    $candidate = "{$dir}/{$fileName}";
                }

                $stored = $file->storeAs($dir, $fileName, 'public');

                PoAttached::query()->create([
                    'po_header_id' => $po->id,
                    'file_name' => $fileName,
                    'file_path' => $stored,
                    'file_ext' => $file->getClientOriginalExtension(),
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'remark' => $request->input("file_remark.{$index}"),
                    'created_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }
        }

        return redirect()->route('po.show', $po->id)->with('ok', 'บันทึกข้อมูล PO เรียบร้อยแล้ว');
    }
    private function statusOptions(): array
    {
        return [
            'NEW' => 'New',
            'DRAFT' => 'Draft',
            'PURCHASE_SUBMITTED' => 'Purchase Submitted',
            'PURCHASE_APPROVAL' => 'Waiting Purchase Approval',
            'DEPT_MANAGER_APPROVAL' => 'Waiting Department Approval',
            'CLOSED' => 'Closed',
            'REJECTED' => 'Rejected',
            'CANCELLED' => 'Cancelled',
        ];
    }

    private function sourceOptions(): array
    {
        return [
            'wire' => 'Wire',
            'plus' => 'Plus',
        ];
    }
}
