<?php

namespace App\Services\FormMLA;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineLoadService
{
    private string $settingsConnection;

    private array $columnExistsCache = [];

    public function __construct()
    {
        $this->settingsConnection = (string) config('database.workflow_connection', 'sqlsrv_menam');
    }

    public function normalizeFilters(array $input): array
    {
        $start = Carbon::parse($input['date_from'] ?? now()->subDays(30)->toDateString())->toDateString();
        $end = Carbon::parse($input['date_to'] ?? now()->addDays(60)->toDateString())->toDateString();

        $dateType = $input['date_type'] ?? 'dateopen';
        $bucket = $input['bucket'] ?? 'week';
        $status = $input['status'] ?? 'open';
        $quickFilter = $input['quick_filter'] ?? 'all';

        return [
            'date_from' => $start,
            'date_to' => $end,
            'date_type' => in_array($dateType, ['dateopen', 'reqdate'], true) ? $dateType : 'dateopen',
            'site' => strtoupper(trim((string) ($input['site'] ?? ''))),
            'source' => $this->normalizeArray($input['source'] ?? [], ['WIRE', 'PLUS']),
            'workcenter_ids' => $this->normalizeIntArray($input['workcenter_ids'] ?? []),
            'machine_ids' => $this->normalizeIntArray($input['machine_ids'] ?? []),
            'bucket' => in_array($bucket, ['week', 'month'], true) ? $bucket : 'week',
            'status' => in_array($status, ['open', 'closed', 'all'], true) ? $status : 'open',
            'quick_filter' => in_array($quickFilter, ['all', 'load_80', 'load_100', 'due_risk'], true) ? $quickFilter : 'all',
            'start_at' => $this->normalizeStartAt($input['start_at'] ?? null),
        ];
    }

    public function getDashboardData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->backlogRows($filters);
        $machines = $this->summarizeMachines($rows, $filters);
        $workCenterGroups = $this->summarizeWorkCenters($machines);
        $buckets = $this->bucketSummary($rows, $filters);
        $capacityPlanRows = !empty($filters['machine_ids'])
            ? $machines->sortByDesc(fn($row) => (float) $row->balance_qty)->take(25)
            : $workCenterGroups->sortByDesc(fn($row) => (float) $row->balance_qty)->take(25);

        return [
            'filters' => $filters,
            'siteOptions' => $this->siteOptions(),
            'sourceOptions' => $this->siteOptions(),
            'workcenterOptions' => $this->masterWorkCenterOptions(),
            'machineOptions' => $this->masterMachineOptions(),
            'kpis' => [
                'machine_count' => $machines->count(),
                'workcenter_count' => $workCenterGroups->count(),
                'backlog_orders' => $rows->count(),
                'backlog_qty' => (float) $rows->sum('balance_qty'),
                'load_hours' => (float) $rows->sum('required_hours'),
                'critical_count' => $machines->where('load_pct', '>=', 100)->count(),
            ],
            'machines' => $machines,
            'workCenterGroups' => $workCenterGroups,
            'buckets' => $buckets,
            'charts' => [
                'capacityPlanMode' => !empty($filters['machine_ids']) ? 'machine' : 'workcenter',
                'machineLoad' => $capacityPlanRows
                    ->map(fn($row) => [
                        'label' => $this->shortMachineLabel(!empty($filters['machine_ids']) ? $row->machine_label : $row->workcenter_label),
                        'full_label' => !empty($filters['machine_ids']) ? $row->machine_label : $row->workcenter_label,
                        'site' => $row->source_site,
                        'workcenter' => $row->workcenter_label,
                        'workcenter_id' => $row->workcenter_id,
                        'workmachine_id' => !empty($filters['machine_ids']) ? $row->workmachine_id : null,
                        'balance_qty' => round((float) $row->balance_qty, 2),
                        'capacity_qty' => round((float) ($row->capacity_qty ?? 0), 2),
                        'load_hours' => round((float) ($row->load_hours ?? 0), 2),
                        'capacity_hours' => round((float) ($row->capacity_hours ?? 0), 2),
                        'load_pct' => round((float) $row->load_pct, 2),
                        'next_available_at' => $row->next_available_at,
                    ])
                    ->values(),
                'availableCapacity' => $workCenterGroups
                    ->sortByDesc(fn($row) => (float) $row->capacity_hours)
                    ->take(12)
                    ->map(function ($row) {
                        $capacityHours = (float) ($row->capacity_hours ?? 0);
                        $usedHours = min((float) ($row->load_hours ?? 0), $capacityHours);
                        $remainingHours = max(0, $capacityHours - $usedHours);

                        return [
                            'label' => $this->shortMachineLabel($row->workcenter_label),
                            'full_label' => $row->workcenter_label,
                            'site' => $row->source_site,
                            'workcenter_id' => $row->workcenter_id,
                            'used_hours' => round($usedHours, 2),
                            'remaining_hours' => round($remainingHours, 2),
                            'capacity_hours' => round($capacityHours, 2),
                            'used_pct' => $capacityHours > 0 ? round(($usedHours / $capacityHours) * 100, 2) : 0,
                            'remaining_pct' => $capacityHours > 0 ? round(($remainingHours / $capacityHours) * 100, 2) : 0,
                        ];
                    })
                    ->values(),
                'bucketLoad' => $buckets->map(fn($row) => [
                    'label' => $row->label,
                    'load_hours' => round((float) $row->load_hours, 2),
                    'capacity_hours' => round((float) $row->capacity_hours, 2),
                ])->values(),
            ],
        ];
    }

    public function getInquiryData(array $filters, int $perPage = 50, int $page = 1): array
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->sortedInquiryRows($filters);

        $total = $rows->count();
        $page = max(1, $page);
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'filters' => $filters,
            'siteOptions' => $this->siteOptions(),
            'sourceOptions' => $this->siteOptions(),
            'workcenterOptions' => $this->masterWorkCenterOptions(),
            'machineOptions' => $this->masterMachineOptions(),
            'rows' => $items,
            'paginator' => $paginator,
            'totalRows' => $total,
            'perPage' => $perPage,
        ];
    }

    public function getSettingsData(): array
    {
        $workCenterOptions = $this->masterWorkCenterOptions();
        $machineOptions = $this->masterMachineOptions();

        try {
            $workCenters = $this->settingsDb()
                ->table('machine_load_work_centers')
                ->orderBy('source_site')
                ->orderBy('workcenter_id')
                ->orderBy('workmachine_id')
                ->get()
                ->map(function ($row) use ($workCenterOptions, $machineOptions) {
                    $row->workcenter_label = ($workCenterOptions->first(fn($option) => $option['site'] === $row->source_site && (string) $option['value'] === (string) $row->workcenter_id)['label'] ?? (string) $row->workcenter_id);
                    $row->machine_label = $row->workmachine_id
                        ? ($machineOptions->first(fn($option) => $option['site'] === $row->source_site && (string) $option['value'] === (string) $row->workmachine_id)['label'] ?? (string) $row->workmachine_id)
                        : '';

                    return $row;
                });

            return [
                'workCenters' => $workCenters,
                'holidays' => $this->settingsDb()
                    ->table('machine_load_holidays')
                    ->orderByDesc('holiday_date')
                    ->limit(300)
                    ->get(),
                'siteOptions' => $this->siteOptions(),
                'workCenterOptions' => $workCenterOptions,
                'machineOptions' => $machineOptions,
                'settingsError' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'workCenters' => collect(),
                'holidays' => collect(),
                'siteOptions' => $this->siteOptions(),
                'workCenterOptions' => $workCenterOptions,
                'machineOptions' => $machineOptions,
                'settingsError' => $e->getMessage(),
            ];
        }
    }

    public function saveWorkCenter(array $data, ?int $id = null): void
    {
        $sourceSite = strtoupper(trim((string) $data['source_site']));
        $workCenterId = (int) $data['workcenter_id'];
        $workMachineId = $data['workmachine_id'] !== null && $data['workmachine_id'] !== ''
            ? (int) $data['workmachine_id']
            : null;

        $duplicate = $this->settingsDb()
            ->table('machine_load_work_centers')
            ->where('source_site', $sourceSite)
            ->where('workcenter_id', $workCenterId)
            ->when(
                $workMachineId !== null,
                fn($q) => $q->where('workmachine_id', $workMachineId),
                fn($q) => $q->whereNull('workmachine_id')
            )
            ->when($id, fn($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($duplicate) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'workcenter_id' => 'มีการตั้งค่ารายการนี้อยู่แล้ว (Site / Work Center / Machine ซ้ำ).',
            ]);
        }

        $payload = [
            'source_site' => $sourceSite,
            'workcenter_id' => $workCenterId,
            'workmachine_id' => $workMachineId,
            'display_name' => trim((string) ($data['display_name'] ?? '')),
            'capacity_per_hour' => $this->nullableFloat($data['capacity_per_hour'] ?? null),
            'work_hours_per_day' => $this->nullableFloat($data['work_hours_per_day'] ?? null),
            'work_days_per_week' => (int) ($data['work_days_per_week'] ?? 6),
            'cycle_time_minutes' => $this->nullableFloat($data['cycle_time_minutes'] ?? null),
            'setup_time_minutes' => $this->nullableFloat($data['setup_time_minutes'] ?? null),
            'active' => !empty($data['active']) ? 1 : 0,
            'notes' => trim((string) ($data['notes'] ?? '')),
            'updated_at' => now(),
        ];

        try {
            $hasAuditUser = Schema::connection($this->settingsConnection)->hasColumn('machine_load_work_centers', 'updated_by');
            $hasAuditAt = Schema::connection($this->settingsConnection)->hasColumn('machine_load_work_centers', 'updated_by_at');
        } catch (\Throwable $e) {
            $hasAuditUser = false;
            $hasAuditAt = false;
        }

        if ($hasAuditUser) {
            $user = auth()->user();
            $payload['updated_by'] = (string) ($user->username ?? $user->name ?? auth()->id() ?? '');
        }
        if ($hasAuditAt) {
            $payload['updated_by_at'] = now();
        }

        if ($id) {
            $this->settingsDb()->table('machine_load_work_centers')->where('id', $id)->update($payload);
            return;
        }

        $payload['created_at'] = now();
        $this->settingsDb()->table('machine_load_work_centers')->insert($payload);
    }

    public function deleteWorkCenter(int $id): void
    {
        $this->settingsDb()->table('machine_load_work_centers')->where('id', $id)->delete();
    }

    public function saveHoliday(array $data): void
    {
        $sourceSite = strtoupper(trim((string) ($data['source_site'] ?? 'ALL')));

        $this->settingsDb()->table('machine_load_holidays')->insert([
            'source_site' => $sourceSite !== '' ? $sourceSite : 'ALL',
            'holiday_date' => $data['holiday_date'],
            'description' => trim((string) ($data['description'] ?? '')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function deleteHoliday(int $id): void
    {
        $this->settingsDb()->table('machine_load_holidays')->where('id', $id)->delete();
    }

    public function exportResponse(array $filters)
    {
        $filters = $this->normalizeFilters($filters);
        $rows = $this->sortedInquiryRows($filters);
        $fileBase = 'machine_load_' . str_replace('-', '', $filters['date_from']) . '_' . str_replace('-', '', $filters['date_to']);

        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Inquiry');
            $sheet->setCellValue('A1', 'Machine Load & Availability');
            $dateTypeText = $filters['date_type'] === 'reqdate' ? 'Req Date / Due Date' : 'Date Open';
            $sheet->setCellValue('A2', "{$dateTypeText}: {$filters['date_from']} to {$filters['date_to']}");
            $sheet->mergeCells('A1:R1');
            $sheet->mergeCells('A2:R2');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $headers = [
                'Site', 'Work Center', 'Machine', 'WO', 'Backlog Qty', 'Required Hours', 'Load %', 'Next Available',
                'Req Date', 'Date Open', 'Order Qty', 'Produced Qty', 'Capacity/Hour', 'Work Hour/Day',
                'Cycle Min/Unit', 'Setup Min', 'Customer', 'Source Connection',
            ];

            foreach ($headers as $index => $header) {
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue("{$column}4", $header);
            }
            $sheet->getStyle('A4:R4')->getFont()->setBold(true);

            $r = 5;
            foreach ($rows as $row) {
                $sheet->fromArray([
                    $row->source_site,
                    $row->workcenter_label,
                    $row->machine_label,
                    $row->workordernumber,
                    $row->balance_qty,
                    $row->required_hours,
                    $row->load_pct,
                    $row->next_available_at,
                    $row->reqdate,
                    $row->dateopen,
                    $row->order_qty,
                    $row->produced_qty,
                    $row->capacity_per_hour,
                    $row->work_hours_per_day,
                    $row->cycle_time_minutes,
                    $row->setup_time_minutes,
                    $row->customer_name,
                    $row->source_conn,
                ], null, "A{$r}");
                $r++;
            }

            foreach (range('A', 'R') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            $sheet->freezePane('A5');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
            $writer->save($tmp);

            return response()->download($tmp, "{$fileBase}.xlsx")->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, [
                'Site', 'Work Center', 'Machine', 'WO', 'Backlog Qty', 'Required Hours', 'Load %', 'Next Available',
                'Req Date', 'Date Open', 'Order Qty', 'Produced Qty', 'Capacity/Hour', 'Work Hour/Day',
                'Cycle Min/Unit', 'Setup Min', 'Customer', 'Source Connection',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->source_site, $row->workcenter_label, $row->machine_label, $row->workordernumber,
                    $row->balance_qty, $row->required_hours, $row->load_pct, $row->next_available_at,
                    $row->reqdate, $row->dateopen, $row->order_qty, $row->produced_qty,
                    $row->capacity_per_hour, $row->work_hours_per_day, $row->cycle_time_minutes,
                    $row->setup_time_minutes, $row->customer_name, $row->source_conn,
                ]);
            }
            fclose($out);
        }, "{$fileBase}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function backlogRows(array $filters): Collection
    {
        $all = collect();
        $sources = $filters['source'];
        if (empty($sources) && $filters['site'] !== '') {
            $sources = [$filters['site']];
        }

        if (empty($sources) || in_array('WIRE', $sources, true)) {
            $all = $all->merge($this->rowsFromManuCost('pgsqlmfgw', 'WIRE', $filters));
        }
        if (empty($sources) || in_array('PLUS', $sources, true)) {
            $all = $all->merge($this->rowsFromManuCost('pgsqlmfgp', 'PLUS', $filters));
        }

        $overrides = $this->workCenterOverrideMap();
        $holidays = $this->holidayMap();

        return $this->applyQuickFilter($all->map(function ($row) use ($overrides, $holidays, $filters) {
            $machineKey = $this->machineKey($row->source_site, $row->workcenter_id, $row->workmachine_id);
            $workCenterKey = $this->machineKey($row->source_site, $row->workcenter_id, null);
            $override = $overrides->get($machineKey) ?: $overrides->get($workCenterKey);

            if ($override && (int) $override->active === 0) {
                return null;
            }

            $capacity = $this->firstPositive($override->capacity_per_hour ?? null, $row->machine_capacity ?? null, $row->workcenter_capacity ?? null, 0);
            $workHours = $this->firstPositive($override->work_hours_per_day ?? null, $row->machine_workhour ?? null, $row->workcenter_workhour ?? null, 8);
            $cycleMinutes = $this->firstPositive($override->cycle_time_minutes ?? null, $capacity > 0 ? 60 / $capacity : null, 0);
            $setupMinutes = $this->firstPositive($override->setup_time_minutes ?? null, 0);
            $workDays = max(1, min(7, (int) ($override->work_days_per_week ?? 6)));
            $balanceQty = max(0, (float) ($row->balance_qty ?? 0));
            $requiredHours = $balanceQty > 0 ? (($balanceQty * $cycleMinutes) + $setupMinutes) / 60 : 0;
            $weeklyCapacity = $workHours * $workDays;

            $row->capacity_per_hour = round((float) $capacity, 4);
            $row->work_hours_per_day = round((float) $workHours, 2);
            $row->work_days_per_week = $workDays;
            $row->cycle_time_minutes = round((float) $cycleMinutes, 4);
            $row->setup_time_minutes = round((float) $setupMinutes, 2);
            $row->required_hours = round((float) $requiredHours, 2);
            $row->load_pct = $weeklyCapacity > 0 ? round(($requiredHours / $weeklyCapacity) * 100, 2) : 0;
            $row->machine_key = $machineKey;
            $row->workcenter_label = $this->masterLabel($row->workcenter_number ?? null, $row->workcenter_description ?? null, 'WC', $row->workcenter_id);
            $row->machine_label = $row->workmachine_id
                ? $this->masterLabel($row->machine_number ?? null, $row->machine_description ?? null, 'MC', $row->workmachine_id)
                : 'No machine receive';
            $row->filter_date = $row->{$filters['date_type']} ?? $row->dateopen;
            $row->next_available_at = $this->addWorkHours($filters['start_at'], $requiredHours, $workHours, $workDays, $this->holidaysForSite($holidays, $row->source_site))->format('Y-m-d H:i');

            return $row;
        })->filter(fn($row) => $row && (float) $row->balance_qty > 0)->values(), $filters);
    }

    private function sortedInquiryRows(array $filters): Collection
    {
        return $this->backlogRows($filters)->sortBy([
            ['source_site', 'asc'],
            ['workcenter_id', 'asc'],
            ['workmachine_id', 'asc'],
            ['dateopen', 'asc'],
            ['workorder_id', 'asc'],
        ])->values();
    }

    private function applyQuickFilter(Collection $rows, array $filters): Collection
    {
        return match ($filters['quick_filter'] ?? 'all') {
            'load_80' => $this->filterRowsByMachineLoad($rows, $filters, 80),
            'load_100' => $this->filterRowsByMachineLoad($rows, $filters, 100),
            'due_risk' => $rows->filter(function ($row) {
                if (empty($row->reqdate) || empty($row->next_available_at)) {
                    return false;
                }

                try {
                    return Carbon::parse($row->next_available_at)->gt(Carbon::parse($row->reqdate)->endOfDay());
                } catch (\Throwable $e) {
                    return false;
                }
            })->values(),
            default => $rows,
        };
    }

    private function filterRowsByMachineLoad(Collection $rows, array $filters, float $minimumLoadPct): Collection
    {
        $holidays = $this->holidayMap();
        $loadedMachineKeys = $rows
            ->groupBy('machine_key')
            ->filter(function ($items) use ($filters, $holidays, $minimumLoadPct) {
                $first = $items->first();
                $capacityHours = $this->capacityHoursBetween(
                    $filters['date_from'],
                    $filters['date_to'],
                    $first->work_hours_per_day,
                    $first->work_days_per_week,
                    $this->holidaysForSite($holidays, $first->source_site)
                );

                if ($capacityHours <= 0) {
                    return false;
                }

                return (((float) $items->sum('required_hours') / $capacityHours) * 100) >= $minimumLoadPct;
            })
            ->keys()
            ->flip();

        return $rows->filter(fn($row) => $loadedMachineKeys->has($row->machine_key))->values();
    }

    private function rowsFromManuCost(string $connection, string $site, array $filters): Collection
    {
        $workCenterDescription = $this->descriptionExpression($connection, 'workcenter', 'woc');
        $machineDescription = $this->descriptionExpression($connection, 'workmachine', 'wm');
        $dateColumn = $filters['date_type'] === 'reqdate' ? 'wo.reqdate' : 'wo.dateopen';
        $dateField = $filters['date_type'] === 'reqdate' ? 'reqdate' : 'dateopen';

        $statusSql = match ($filters['status']) {
            'closed' => 'AND wo.dateclose IS NOT NULL',
            'all' => '',
            default => 'AND wo.dateclose IS NULL',
        };

        $bindings = [$filters['date_from'], $filters['date_to']];
        $workCenterSql = '';
        if (!empty($filters['workcenter_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['workcenter_ids']), '?'));
            $workCenterSql = "AND wowc.workcenter_id IN ({$placeholders})";
            $bindings = array_merge($bindings, $filters['workcenter_ids']);
        }
        $machineSql = '';
        if (!empty($filters['machine_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['machine_ids']), '?'));
            $machineSql = "AND wor.workmachine_id IN ({$placeholders})";
            $bindings = array_merge($bindings, $filters['machine_ids']);
        }

        $sql = "
            WITH receive_rows AS (
                SELECT DISTINCT ON (wo.id, wor.id)
                    wo.id AS workorder_id,
                    wo.workordernumber,
                    wo.dateopen,
                    wo.reqdate,
                    wo.qty AS order_qty,
                    wowc.workcenter_id,
                    wowc.workseq,
                    wor.id AS receive_id,
                    wor.workmachine_id,
                    COALESCE(wor.qty, 0) AS produced_qty,
                    GREATEST(COALESCE(wo.qty, 0) - COALESCE(wor.qty, 0), 0) AS balance_qty,
                    woc.workcenternumber AS workcenter_number,
                    {$workCenterDescription} AS workcenter_description,
                    woc.capacity AS workcenter_capacity,
                    woc.workhour AS workcenter_workhour,
                    wm.machinenumber AS machine_number,
                    {$machineDescription} AS machine_description,
                    wm.capacity AS machine_capacity,
                    wm.workhour AS machine_workhour,
                    c.name AS customer_name
                FROM workorder wo
                JOIN workorderworkcenter wowc
                    ON wowc.workorder_id = wo.id
                JOIN workorderreceive wor
                    ON wor.workorder_id = wowc.workorder_id
                    AND wor.workcenter_id = wowc.workcenter_id
                    AND wor.workseq = wowc.workseq
                JOIN workcenter woc
                    ON woc.id = wowc.workcenter_id
                LEFT JOIN workmachine wm
                    ON wm.id = wor.workmachine_id
                LEFT JOIN customer c
                    ON c.id = wo.customer_id
                WHERE {$dateColumn}::date BETWEEN ? AND ?
                AND wor.workmachine_id IS NOT NULL
                {$statusSql}
                {$workCenterSql}
                {$machineSql}
                ORDER BY wo.id, wor.id, {$dateColumn} ASC, wowc.workseq ASC
            )
            SELECT
                workorder_id,
                workordernumber,
                dateopen,
                reqdate,
                order_qty,
                workcenter_id,
                workseq,
                workmachine_id,
                workcenter_number,
                workcenter_description,
                workcenter_capacity,
                workcenter_workhour,
                machine_number,
                machine_description,
                machine_capacity,
                machine_workhour,
                SUM(produced_qty) AS produced_qty,
                GREATEST(MAX(order_qty) - SUM(produced_qty), 0) AS balance_qty,
                customer_name
            FROM receive_rows
            GROUP BY
                workorder_id,
                workordernumber,
                dateopen,
                reqdate,
                order_qty,
                workcenter_id,
                workseq,
                workmachine_id,
                workcenter_number,
                workcenter_description,
                workcenter_capacity,
                workcenter_workhour,
                machine_number,
                machine_description,
                machine_capacity,
                machine_workhour,
                customer_name
            ORDER BY {$dateField} ASC, workorder_id ASC, workseq ASC
        ";

        return collect(DB::connection($connection)->select($sql, $bindings))
            ->map(function ($row) use ($connection, $site) {
                $row->source_conn = $connection;
                $row->source_site = $site;
                $row->order_qty = (float) ($row->order_qty ?? 0);
                $row->produced_qty = (float) ($row->produced_qty ?? 0);
                $row->balance_qty = (float) ($row->balance_qty ?? 0);
                $row->machine_capacity = (float) ($row->machine_capacity ?? 0);
                $row->machine_workhour = (float) ($row->machine_workhour ?? 0);
                $row->workcenter_capacity = (float) ($row->workcenter_capacity ?? 0);
                $row->workcenter_workhour = (float) ($row->workcenter_workhour ?? 0);
                return $row;
            });
    }

    private function summarizeMachines(Collection $rows, array $filters): Collection
    {
        $holidays = $this->holidayMap();

        return $rows->groupBy('machine_key')->map(function ($items) use ($filters, $holidays) {
            $first = $items->first();
            $loadHours = (float) $items->sum('required_hours');
            $siteHolidays = $this->holidaysForSite($holidays, $first->source_site);
            $capacityHours = $this->capacityHoursBetween($filters['date_from'], $filters['date_to'], $first->work_hours_per_day, $first->work_days_per_week, $siteHolidays);
            $capacityQty = $capacityHours * (float) ($first->capacity_per_hour ?? 0);
            $nextAvailable = $this->addWorkHours($filters['start_at'], $loadHours, $first->work_hours_per_day, $first->work_days_per_week, $siteHolidays);

            return (object) [
                'machine_key' => $first->machine_key,
                'source_site' => $first->source_site,
                'workcenter_id' => $first->workcenter_id,
                'workmachine_id' => $first->workmachine_id,
                'workcenter_label' => $first->workcenter_label,
                'machine_label' => $first->machine_label,
                'order_count' => $items->count(),
                'balance_qty' => round((float) $items->sum('balance_qty'), 2),
                'load_hours' => round($loadHours, 2),
                'capacity_hours' => round($capacityHours, 2),
                'capacity_qty' => round($capacityQty, 2),
                'capacity_per_hour' => round((float) ($first->capacity_per_hour ?? 0), 4),
                'load_pct' => $capacityHours > 0 ? round(($loadHours / $capacityHours) * 100, 2) : 0,
                'next_available_at' => $nextAvailable->format('Y-m-d H:i'),
                'items' => $items->sortBy('filter_date')->take(80)->values(),
                'items_total' => $items->count(),
            ];
        })->sortByDesc('load_pct')->values();
    }

    private function summarizeWorkCenters(Collection $machines): Collection
    {
        return $machines
            ->groupBy(fn($row) => $row->source_site . '|' . $row->workcenter_id)
            ->map(function ($items) {
                $first = $items->first();
                $loadHours = (float) $items->sum('load_hours');
                $capacityHours = (float) $items->sum('capacity_hours');
                $capacityQty = (float) $items->sum('capacity_qty');

                return (object) [
                    'source_site' => $first->source_site,
                    'workcenter_id' => $first->workcenter_id,
                    'workcenter_label' => $first->workcenter_label,
                    'machine_count' => $items->count(),
                    'order_count' => (int) $items->sum('order_count'),
                    'balance_qty' => round((float) $items->sum('balance_qty'), 2),
                    'load_hours' => round($loadHours, 2),
                    'capacity_hours' => round($capacityHours, 2),
                    'capacity_qty' => round($capacityQty, 2),
                    'load_pct' => $capacityHours > 0 ? round(($loadHours / $capacityHours) * 100, 2) : 0,
                    'next_available_at' => $items->max('next_available_at'),
                    'machines' => $items->sortByDesc('load_pct')->values(),
                ];
            })
            ->sortByDesc('load_pct')
            ->values();
    }

    private function bucketSummary(Collection $rows, array $filters): Collection
    {
        return $rows->groupBy(function ($row) use ($filters) {
            $date = Carbon::parse($row->filter_date ?? $row->dateopen);
            return $filters['bucket'] === 'month' ? $date->format('Y-m') : $date->startOfWeek()->format('Y-m-d');
        })->map(function ($items, $key) use ($filters) {
            $loadHours = (float) $items->sum('required_hours');
            $capacityHours = (float) $items->sum(fn($row) => $row->work_hours_per_day * ($filters['bucket'] === 'month' ? 24 : $row->work_days_per_week));

            return (object) [
                'label' => (string) $key,
                'order_count' => $items->count(),
                'balance_qty' => round((float) $items->sum('balance_qty'), 2),
                'load_hours' => round($loadHours, 2),
                'capacity_hours' => round($capacityHours, 2),
                'load_pct' => $capacityHours > 0 ? round(($loadHours / $capacityHours) * 100, 2) : 0,
            ];
        })->sortBy('label')->values();
    }

    private function settingsDb()
    {
        return DB::connection($this->settingsConnection);
    }

    private function workCenterOverrideMap(): Collection
    {
        try {
            return $this->settingsDb()
                ->table('machine_load_work_centers')
                ->get()
                ->keyBy(fn($row) => $this->machineKey($row->source_site, $row->workcenter_id, $row->workmachine_id));
        } catch (\Throwable $e) {
            return collect();
        }
    }

    private function holidayMap(): array
    {
        try {
            return $this->settingsDb()
                ->table('machine_load_holidays')
                ->get()
                ->groupBy('source_site')
                ->map(fn($rows) => $rows->pluck('holiday_date')->map(fn($d) => Carbon::parse($d)->toDateString())->all())
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function holidaysForSite(array $holidayMap, string $site): array
    {
        return array_values(array_unique(array_merge(
            $holidayMap['ALL'] ?? [],
            $holidayMap[strtoupper($site)] ?? []
        )));
    }

    private function machineKey(string $site, $workcenterId, $workmachineId): string
    {
        return strtoupper($site) . '|' . (string) $workcenterId . '|' . ($workmachineId !== null && $workmachineId !== '' ? (string) $workmachineId : 'WC');
    }

    private function label($displayName, string $prefix, $id): string
    {
        $displayName = trim((string) $displayName);
        return $displayName !== '' ? $displayName : "{$prefix}-{$id}";
    }

    private function shortMachineLabel(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '-';
        }

        $parts = explode(' - ', $label, 2);
        $first = trim($parts[0]);

        return $first !== '' ? $first : mb_substr($label, 0, 24);
    }

    private function masterLabel($number, $description, string $prefix, $id): string
    {
        $number = trim((string) $number);
        $description = trim((string) $description);

        if ($number !== '' && $description !== '') {
            return "{$number} - {$description}";
        }

        if ($number !== '') {
            return $number;
        }

        return $this->label(null, $prefix, $id);
    }

    private function siteOptions(): array
    {
        return [
            ['value' => 'PLUS', 'label' => 'Plus'],
            ['value' => 'WIRE', 'label' => 'Wire'],
        ];
    }

    private function workcenterOptions(Collection $rows): Collection
    {
        return $rows->map(fn($row) => [
            'value' => (string) $row->workcenter_id,
            'label' => $row->source_site . ' / ' . $row->workcenter_label,
        ])->unique('value')->sortBy('label')->values();
    }

    private function normalizeStartAt($value): string
    {
        if ($value) {
            return Carbon::parse($value)->format('Y-m-d H:i');
        }

        return now()->setTime(8, 0)->format('Y-m-d H:i');
    }

    private function addWorkHours(string $startAt, float $hours, float $workHoursPerDay, int $workDaysPerWeek, array $holidays): Carbon
    {
        $current = Carbon::parse($startAt);
        $remaining = max(0, $hours);
        $workHoursPerDay = max(0.01, $workHoursPerDay);
        $holidaySet = array_flip($holidays);
        $workDaysPerWeek = max(1, min(7, $workDaysPerWeek));

        while ($remaining > 0.0001) {
            if (isset($holidaySet[$current->toDateString()]) || $current->dayOfWeekIso > $workDaysPerWeek) {
                $current = $current->copy()->addDay()->setTime(8, 0);
                continue;
            }

            $dayStart = $current->copy()->setTime(8, 0);
            $dayEnd = $dayStart->copy()->addMinutes((int) round($workHoursPerDay * 60));
            if ($current->lessThan($dayStart)) {
                $current = $dayStart;
            }
            if ($current->greaterThanOrEqualTo($dayEnd)) {
                $current = $current->copy()->addDay()->setTime(8, 0);
                continue;
            }

            $available = $current->diffInMinutes($dayEnd) / 60;
            $take = min($remaining, $available);
            $current = $current->copy()->addMinutes((int) round($take * 60));
            $remaining -= $take;
        }

        return $current;
    }

    private function capacityHoursBetween(string $from, string $to, float $hoursPerDay, int $workDaysPerWeek, array $holidays): float
    {
        $holidaySet = array_flip($holidays);
        $workDaysPerWeek = max(1, min(7, $workDaysPerWeek));
        $hours = 0.0;
        foreach (CarbonPeriod::create($from, $to) as $date) {
            if (isset($holidaySet[$date->toDateString()])) {
                continue;
            }
            if ($date->dayOfWeekIso > $workDaysPerWeek) {
                continue;
            }
            $hours += $hoursPerDay;
        }
        return $hours;
    }

    private function isWorkDay(Carbon $date, int $workDaysPerWeek, array $holidays): bool
    {
        if (in_array($date->toDateString(), $holidays, true)) {
            return false;
        }

        $workDaysPerWeek = max(1, min(7, $workDaysPerWeek));
        // dayOfWeekIso: 1=จันทร์ ... 7=อาทิตย์ — ทำงานจาก จ. ไล่ไปจำนวน workDaysPerWeek วัน
        return $date->dayOfWeekIso <= $workDaysPerWeek;
    }

    private function firstPositive(...$values): float
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }
        return 0.0;
    }

    private function nullableFloat($value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function normalizeArray($value, array $allowed): array
    {
        $items = is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []);

        return collect($items)
            ->map(fn($item) => strtoupper(trim((string) $item)))
            ->filter(fn($item) => in_array($item, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeIntArray($value): array
    {
        $items = is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []);

        return collect($items)
            ->filter(fn($item) => is_numeric($item))
            ->map(fn($item) => (int) $item)
            ->filter(fn($item) => $item > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function masterWorkCenterOptions(): Collection
    {
        return collect([
            ['connection' => 'pgsqlmfgw', 'site' => 'WIRE'],
            ['connection' => 'pgsqlmfgp', 'site' => 'PLUS'],
        ])->flatMap(function ($source) {
            try {
                $description = $this->descriptionExpression($source['connection'], 'workcenter', 'workcenter');

                return collect(DB::connection($source['connection'])->select("
                    SELECT
                        id,
                        workcenternumber,
                        {$description} AS description
                    FROM workcenter
                    ORDER BY workcenternumber
                "))->map(fn($row) => [
                    'site' => $source['site'],
                    'value' => (string) $row->id,
                    'label' => $source['site'] . ' / ' . $this->masterLabel($row->workcenternumber ?? null, $row->description ?? null, 'WC', $row->id),
                ]);
            } catch (\Throwable $e) {
                return collect();
            }
        })->values();
    }

    private function masterMachineOptions(): Collection
    {
        return collect([
            ['connection' => 'pgsqlmfgw', 'site' => 'WIRE'],
            ['connection' => 'pgsqlmfgp', 'site' => 'PLUS'],
        ])->flatMap(function ($source) {
            try {
                $description = $this->descriptionExpression($source['connection'], 'workmachine', 'workmachine');

                return collect(DB::connection($source['connection'])->select("
                    SELECT
                        id,
                        machinenumber,
                        {$description} AS description
                    FROM workmachine
                    ORDER BY machinenumber
                "))->map(fn($row) => [
                    'site' => $source['site'],
                    'value' => (string) $row->id,
                    'label' => $source['site'] . ' / ' . $this->masterLabel($row->machinenumber ?? null, $row->description ?? null, 'MC', $row->id),
                ]);
            } catch (\Throwable $e) {
                return collect();
            }
        })->values();
    }

    private function descriptionExpression(string $connection, string $table, string $alias): string
    {
        if ($this->columnExists($connection, $table, 'description')) {
            return "{$alias}.description";
        }

        if ($this->columnExists($connection, $table, 'desc')) {
            return "{$alias}.\"desc\"";
        }

        return "NULL";
    }

    private function columnExists(string $connection, string $table, string $column): bool
    {
        $key = $connection . '|' . $table . '|' . $column;
        if (array_key_exists($key, $this->columnExistsCache)) {
            return $this->columnExistsCache[$key];
        }

        try {
            $exists = DB::connection($connection)
                ->table('information_schema.columns')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->exists();
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $this->columnExistsCache[$key] = $exists;
    }
}
