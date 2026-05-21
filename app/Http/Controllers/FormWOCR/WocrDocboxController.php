<?php

namespace App\Http\Controllers\FormWOCR;

use App\Http\Controllers\Controller;
use App\Models\FormWOCR\WocrData;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class WocrDocboxController extends Controller
{
    private string $wfTable         = 'wf_forms';
    private string $waTable         = 'wf_form_authorizes';
    private string $wfStatusCol     = 'form_status';
    private string $wfStepCol       = 'current_step_no';
    private string $wfOriginatorCol = 'request_by_user_id';

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

    public function export(Request $r)
    {
        $box = (string) $r->query('box', 'all');
        if (! in_array($box, ['pending', 'mine', 'all'], true)) {
            $box = 'all';
        }

        $rows = $this->docboxQuery($r, $box)
            ->orderByDesc('wocr.docu_no')
            ->get();

        $woCustomers = $this->woCustomerNamesByMfgNo($rows);

        $exportRows = $rows
            ->map(function ($row) use ($woCustomers) {
                return [
                    $row->id ?? '',
                    $row->form_id ?? '',
                    optional($row->req_date)->format('d/m/Y'),
                    $row->req_type ?? '',
                    optional($row->docu_date)->format('d/m/Y'),
                    $row->docu_no ?? '',
                    $row->mfg_no ?? '',
                    $woCustomers[$this->woCustomerKey($row->mfg_no ?? null)] ?? '',
                    $row->form_type ?? '',
                    $row->grade ?? '',
                    $row->size ?? '',
                    $row->length ?? '',
                    $row->qty ?? '',
                    $row->mfg_request_detail ?? '',
                    $row->comment ?? '',
                    $row->reason ?? '',
                    $row->wf_current_step_name ?? '',
                    $row->requester_name ?? '',
                    optional($row->updated_at)->format('d/m/Y H:i'),
                ];
            })
            ->all();

        $export = new class($exportRows) implements FromArray, WithHeadings {
            public function __construct(private array $rows)
            {
            }

            public function headings(): array
            {
                return [
                    'id',
                    'form_id',
                    'Req Date',
                    'Req Type',
                    'Docu Date',
                    'Docu No',
                    'MFG No',
                    'Customer',
                    'Form Type',
                    'Grade',
                    'Size',
                    'Length',
                    'Qty',
                    'MFG Request Detail',
                    'Comment',
                    'Reason',
                    'Status',
                    'Requester',
                    'Updated At',
                ];
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $fileName = 'wocr_' . $box . '_' . now('Asia/Bangkok')->format('Ymd_His') . '.xlsx';

        return Excel::download($export, $fileName);
    }

    private function list(Request $r, string $box)
    {
        $kw = trim((string) $r->query('q', ''));

        $list = $this->docboxQuery($r, $box)
            ->orderByDesc('wocr.docu_no')
            ->paginate(12)
            ->withQueryString();

        $list->getCollection()->each(function ($row) {
            $row->setRelation('requester', (object) ['name' => $row->requester_name ?? null]);
        });

        return view('formwocr.show', [
            'list'      => $list,
            'box'       => $box,
            'q'         => $kw,
            'pageTitle' => 'Production Planning Form',
            'showRoute' => null,
        ]);
    }

    private function docboxQuery(Request $r, string $box)
    {
        $userId = $r->user()?->id;
        $kw = trim((string) $r->query('q', ''));
        $status = trim((string) $r->query('status', ''));
        $site = trim((string) $r->query('site', ''));

        $waMulti = DB::table("{$this->waTable} as wa")
            ->select([
                'wa.wf_form_id',
                'wa.step_no',
                DB::raw('GROUP_CONCAT(DISTINCT wa.approver_user_id) as wf_approver_ids'),
                DB::raw('GROUP_CONCAT(wa.status) as wa_statuses'),
            ])
            ->groupBy('wa.wf_form_id', 'wa.step_no');

        $q = WocrData::query()
            ->from('wocr_data as wocr')
            ->join("{$this->wfTable} as wf", 'wf.id', '=', 'wocr.form_id')
            ->leftJoin('users as req_users', 'req_users.id', '=', "wf.{$this->wfOriginatorCol}")
            ->leftJoin('workflows as wfm', 'wfm.code', '=', 'wf.app_code')
            ->leftJoin('workflow_steps as wfs', function ($j) {
                $j->on('wfs.workflow_id', '=', 'wfm.id')
                    ->on('wfs.step_no', '=', "wf.{$this->wfStepCol}")
                    ->where('wfs.is_active', 1);
            })
            ->leftJoinSub($waMulti, 'wa', function ($j) {
                $j->on('wa.wf_form_id', '=', 'wf.id')
                    ->on('wa.step_no', '=', "wf.{$this->wfStepCol}");
            })
            ->select([
                'wocr.*',
                'wf.form_no',
                "wf.{$this->wfStatusCol} as wf_status",
                "wf.{$this->wfOriginatorCol} as wf_originator_id",
                'req_users.name as requester_name',
                "wf.{$this->wfStepCol} as wf_current_step",
                'wfs.id as wf_current_step_id',
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
            ->distinct('wocr.docu_no');

        if ($box === 'pending') {
            if (! $userId) {
                return $q->whereRaw('1 = 0');
            }

            $q->whereRaw('FIND_IN_SET(?, wa.wf_approver_ids)', [$userId])
                ->where(function ($w) {
                    $w->whereNull('wa.wa_statuses')
                        ->orWhere('wa.wa_statuses', 'like', '%PENDING%');
                });
        } elseif ($box === 'mine') {
            if (! $userId) {
                return $q->whereRaw('1 = 0');
            }

            $q->where("wf.{$this->wfOriginatorCol}", $userId);
        }

        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->where('wocr.part_no', 'like', "%{$kw}%")
                    ->orWhere('wocr.customer', 'like', "%{$kw}%")
                    ->orWhere('wocr.docu_no', 'like', "%{$kw}%");
            });
        }

        if ($site !== '') {
            $q->where('wocr.data_site', $site);
        }

        if ($status !== '') {
            $q->where(function ($w) use ($status) {
                $w->where('wf.current_step_no', 'like', "%{$status}%");
            });
        }

        return $q;
    }

    private function woCustomerNamesByMfgNo(Collection $rows): array
    {
        $mfgNos = $rows
            ->toBase()
            ->pluck('mfg_no')
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($mfgNos->isEmpty()) {
            return [];
        }

        $customers = [];
        foreach (['pgsqlw', 'pgsqlp'] as $connection) {
            try {
                DB::connection($connection)
                    ->table('workorder as w')
                    ->leftJoin('customer as c', 'w.customer_id', '=', 'c.id')
                    ->whereIn('w.workordernumber', $mfgNos->all())
                    ->whereNotNull('c.name')
                    ->select('w.workordernumber', 'c.name as customer_name')
                    ->get()
                    ->groupBy('workordernumber')
                    ->each(function ($items, $mfgNo) use (&$customers) {
                        $key = $this->woCustomerKey($mfgNo);
                        $names = $items
                            ->pluck('customer_name')
                            ->map(fn($value) => trim((string) $value))
                            ->filter()
                            ->unique()
                            ->values();

                        if ($names->isEmpty()) {
                            return;
                        }

                        $existing = collect(explode(' / ', $customers[$key] ?? ''))
                            ->map(fn($value) => trim((string) $value))
                            ->filter();

                        $customers[$key] = $existing
                            ->merge($names)
                            ->unique()
                            ->values()
                            ->implode(' / ');
                    });
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $customers;
    }

    private function woCustomerKey(?string $mfgNo): string
    {
        return trim((string) $mfgNo);
    }
}
