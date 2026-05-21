<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;
use App\Models\FormPP\PpData;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class PpDocboxController extends Controller
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
            ->orderByDesc('pp.docu_no')
            ->get();

        $woCustomers = $this->woCustomersByPartAndSite($rows);

        $exportRows = $rows
            ->map(function ($row) use ($woCustomers) {
                $customer = $this->woCustomerForRow($row, $woCustomers);

                return [
                    $row->id ?? '',
                    $row->form_id ?? '',
                    optional($row->req_date)->format('d/m/Y'),
                    optional($row->docu_date)->format('d/m/Y'),
                    $row->docu_no ?? '',
                    $row->part_no ?? '',
                    $row->part_name ?? '',
                    $customer ?: ($row->customer ?? ''),
                    $row->data_site ?? '',
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
                    'Docu Date',
                    'Docu No',
                    'Part No',
                    'Part Name',
                    'Customer',
                    'Site',
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

        $fileName = 'pp_' . $box . '_' . now('Asia/Bangkok')->format('Ymd_His') . '.xlsx';

        return Excel::download($export, $fileName);
    }

    private function list(Request $r, string $box)
    {
        $kw = trim((string) $r->query('q', ''));

        $list = $this->docboxQuery($r, $box)
            ->orderByDesc('pp.docu_no')
            ->paginate(12)
            ->withQueryString();

        $list->getCollection()->each(function ($row) {
            $row->setRelation('requester', (object) ['name' => $row->requester_name ?? null]);
        });

        return view('formpp.show', [
            'list'      => $list,
            'box'       => $box,
            'q'         => $kw,
            'pageTitle' => 'Production Planning Form',
            'showRoute' => null,
        ]);
    }

    private function docboxQuery(Request $r, string $box)
    {
        $userId = $r->user()->id;
        $kw = trim((string) $r->query('q', ''));

        $waMulti = DB::table("{$this->waTable} as wa")
            ->select([
                'wa.wf_form_id',
                'wa.step_no',
                DB::raw('GROUP_CONCAT(DISTINCT wa.approver_user_id) as wf_approver_ids'),
                DB::raw('GROUP_CONCAT(wa.status) as wa_statuses'),
            ])
            ->groupBy('wa.wf_form_id', 'wa.step_no');

        $q = PpData::query()
            ->from('pp_data as pp')
            ->join("{$this->wfTable} as wf", 'wf.id', '=', 'pp.form_id')
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
                'pp.*',
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
            ->distinct('pp.docu_no');

        if ($box === 'pending') {
            $q->whereRaw('FIND_IN_SET(?, wa.wf_approver_ids)', [$userId])
                ->where(function ($w) {
                    $w->whereNull('wa.wa_statuses')
                        ->orWhere('wa.wa_statuses', 'like', '%PENDING%');
                });
        } elseif ($box === 'mine') {
            $q->where("wf.{$this->wfOriginatorCol}", $userId);
        }

        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->where('pp.part_no', 'like', "%{$kw}%")
                    ->orWhere('pp.customer', 'like', "%{$kw}%")
                    ->orWhere('wf.form_status', 'like', "%{$kw}%")
                    ->orWhere('pp.docu_no', 'like', "%{$kw}%");
            });
        }

        return $q;
    }

    private function woCustomersByPartAndSite(Collection $rows): array
    {
        $partsBySite = $rows
            ->toBase()
            ->mapToGroups(function ($row) {
                $site = $this->normalizeSite($row->data_site ?? null);
                $partNo = trim((string) ($row->part_no ?? ''));

                if ($site === '') {
                    return ['Wire' => $partNo, 'Plus' => $partNo];
                }

                return [$site => $partNo];
            })
            ->map(fn($parts) => $parts->toBase()->filter()->unique()->values());

        $customers = [];
        foreach ([
            'Wire' => 'pgsqlmfgw',
            'Plus' => 'pgsqlmfgp',
        ] as $site => $connection) {
            $parts = $partsBySite->get($site, collect());
            if ($parts->isEmpty()) {
                continue;
            }

            try {
                DB::connection($connection)
                    ->table('workorder as w')
                    ->join('parts as p', 'p.id', '=', 'w.parts_id')
                    ->whereIn('p.partnumber', $parts->all())
                    ->whereNotNull('w.customer')
                    ->select('p.partnumber', 'w.customer')
                    ->orderByDesc('w.workordernumber')
                    ->get()
                    ->groupBy('partnumber')
                    ->each(function ($items, $partNo) use (&$customers, $site) {
                        $customers[$this->woCustomerKey($partNo, $site)] = $items
                            ->pluck('customer')
                            ->map(fn($value) => trim((string) $value))
                            ->filter()
                            ->unique()
                            ->values()
                            ->implode(', ');
                    });
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $customers;
    }

    private function woCustomerKey(?string $partNo, ?string $site): string
    {
        return trim((string) $partNo) . '|' . $this->normalizeSite($site);
    }

    private function woCustomerForRow($row, array $woCustomers): ?string
    {
        $partNo = trim((string) ($row->part_no ?? ''));
        $site = $this->normalizeSite($row->data_site ?? null);

        if ($site !== '') {
            return $woCustomers[$this->woCustomerKey($partNo, $site)] ?? null;
        }

        $names = collect([
            $woCustomers[$this->woCustomerKey($partNo, 'Wire')] ?? null,
            $woCustomers[$this->woCustomerKey($partNo, 'Plus')] ?? null,
        ])
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? null : $names->implode(' / ');
    }

    private function normalizeSite(?string $site): string
    {
        $site = strtoupper((string) $site);

        if (str_contains($site, 'PLUS')) {
            return 'Plus';
        }

        if (str_contains($site, 'WIRE')) {
            return 'Wire';
        }

        return '';
    }
}
