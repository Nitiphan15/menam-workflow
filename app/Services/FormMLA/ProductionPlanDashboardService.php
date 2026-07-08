<?php

namespace App\Services\FormMLA;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductionPlanDashboardService
{
    private array $columnExistsCache = [];
    private array $tableExistsCache = [];

    public function getDashboardData(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $planOptions = $this->latestPlanOptions($filters['source']);

        if ($filters['transnumber'] === '' && $planOptions->isNotEmpty()) {
            $filters['transnumber'] = (string) $planOptions->first()['transnumber'];
            $filters['source'] = [(string) $planOptions->first()['site']];
        }

        if ($filters['date_from'] === '' || $filters['date_to'] === '') {
            $seed = $this->seedDateRange($filters, $planOptions);
            $filters['date_from'] = $filters['date_from'] !== '' ? $filters['date_from'] : $seed['date_from'];
            $filters['date_to'] = $filters['date_to'] !== '' ? $filters['date_to'] : $seed['date_to'];
        }

        $rows = $this->planRows($filters);
        $rows = $this->applySearch($rows, $filters);

        $dailyLoad = $this->dailyLoad($rows);
        $machineLoad = $this->machineLoad($rows);
        $workCenterLoad = $this->workCenterLoad($rows);
        $longOperations = $rows
            ->filter(fn($row) => (float) $row->duration_minutes >= 480)
            ->sortByDesc('duration_minutes')
            ->take(30)
            ->values();

        return [
            'filters' => $filters,
            'siteOptions' => $this->siteOptions(),
            'planOptions' => $planOptions,
            'workcenterOptions' => $this->optionsFromRows($rows, 'workcenter_id', 'workcenter_label'),
            'machineOptions' => $this->optionsFromRows($rows, 'planmachine_id', 'machine_label'),
            'kpis' => [
                'plan_count' => $rows->pluck('plan_key')->unique()->count(),
                'operation_count' => $rows->count(),
                'workorder_count' => $rows->pluck('workorder_id')->filter()->unique()->count(),
                'workcenter_count' => $rows->pluck('workcenter_key')->filter()->unique()->count(),
                'machine_count' => $rows->pluck('machine_key')->filter()->unique()->count(),
                'planned_hours' => round((float) $rows->sum('duration_hours'), 2),
                'planned_qty' => round((float) $rows->sum('qty'), 2),
                'overlap_count' => $this->overlapCount($rows),
                'no_machine_count' => $rows->where('planmachine_id', null)->count(),
            ],
            'dailyLoad' => $dailyLoad,
            'machineLoad' => $machineLoad,
            'workCenterLoad' => $workCenterLoad,
            'longOperations' => $longOperations,
            'scheduleRows' => $this->scheduleRows($rows),
            'detailRows' => $rows
                ->sortBy([['plandate', 'asc'], ['machine_label', 'asc'], ['workorder_id', 'asc']])
                ->take(300)
                ->values(),
            'charts' => [
                'daily' => $dailyLoad->map(fn($row) => [
                    'label' => $row->date,
                    'hours' => $row->hours,
                    'jobs' => $row->jobs,
                ])->values(),
                'machine' => $machineLoad->take(20)->map(fn($row) => [
                    'label' => $this->shortLabel($row->machine_label),
                    'full_label' => $row->machine_label,
                    'hours' => $row->hours,
                    'jobs' => $row->jobs,
                ])->values(),
                'workcenter' => $workCenterLoad->take(15)->map(fn($row) => [
                    'label' => $this->shortLabel($row->workcenter_label),
                    'full_label' => $row->workcenter_label,
                    'hours' => $row->hours,
                    'jobs' => $row->jobs,
                ])->values(),
            ],
            'loadError' => null,
        ];
    }

    public function normalizeFilters(array $input): array
    {
        $sources = $this->normalizeArray($input['source'] ?? [], ['WIRE', 'PLUS']);
        if (empty($sources) && !empty($input['site'])) {
            $sources = $this->normalizeArray([$input['site']], ['WIRE', 'PLUS']);
        }

        return [
            'source' => $sources ?: ['WIRE'],
            'site' => strtoupper(trim((string) ($input['site'] ?? ''))),
            'transnumber' => trim((string) ($input['transnumber'] ?? '')),
            'date_from' => $this->dateString($input['date_from'] ?? ''),
            'date_to' => $this->dateString($input['date_to'] ?? ''),
            'workcenter_ids' => $this->normalizeIntArray($input['workcenter_ids'] ?? []),
            'machine_ids' => $this->normalizeIntArray($input['machine_ids'] ?? []),
            'q' => trim((string) ($input['q'] ?? '')),
        ];
    }

    private function planRows(array $filters): Collection
    {
        $all = collect();

        foreach ($this->sources($filters['source']) as $source) {
            $all = $all->merge($this->rowsFromConnection($source['connection'], $source['site'], $filters));
        }

        return $all->values();
    }

    private function rowsFromConnection(string $connection, string $site, array $filters): Collection
    {
        $planTable = $this->firstExistingTable($connection, ['mfgschedule', 'workplan', 'workplans']);
        $lineTable = $this->firstExistingTable($connection, ['mfgscheduleitems', 'workplanitem', 'workplanitems', 'workplandetail', 'workplandetails']);

        if ($planTable === null || $lineTable === null) {
            return collect();
        }

        $workCenterDescription = $this->descriptionExpression($connection, 'workcenter', 'wc');
        $machineDescription = $this->descriptionExpression($connection, 'planmachine', 'pm');

        $bindings = [];
        $where = [];

        if ($filters['transnumber'] !== '') {
            $where[] = 'wp.transnumber = ?';
            $bindings[] = $filters['transnumber'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'wpi.plandate::date >= ?';
            $bindings[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'wpi.plandate::date <= ?';
            $bindings[] = $filters['date_to'];
        }
        if (!empty($filters['workcenter_ids'])) {
            $where[] = 'wowc.workcenter_id IN (' . implode(',', array_fill(0, count($filters['workcenter_ids']), '?')) . ')';
            $bindings = array_merge($bindings, $filters['workcenter_ids']);
        }
        if (!empty($filters['machine_ids'])) {
            $where[] = 'wpi.planmachine_id IN (' . implode(',', array_fill(0, count($filters['machine_ids']), '?')) . ')';
            $bindings = array_merge($bindings, $filters['machine_ids']);
        }

        $whereSql = empty($where) ? '' : 'WHERE ' . implode("\nAND ", $where);

        $sql = "
            SELECT
                wp.id AS plan_id,
                wp.transnumber,
                wp.transdate,
                wp.startdate,
                wp.setuptime,
                wp.plandays,
                wpi.id AS plan_line_id,
                wpi.plandate,
                wpi.planseq,
                COALESCE(wpi.duration, 0) AS duration_minutes,
                COALESCE(wpi.qty, 0) AS qty,
                wpi.workmachine_id,
                wpi.planmachine_id,
                wowc.id AS wowc_id,
                wowc.workorder_id,
                wowc.workcenter_id,
                wowc.workseq,
                wowc.msize,
                wowc.msizein,
                wowc.description AS operation_description,
                wowc.notes AS operation_notes,
                wo.workordernumber,
                wc.workcenternumber,
                {$workCenterDescription} AS workcenter_description,
                pm.machinenumber,
                {$machineDescription} AS machine_description,
                pm.workmachine_id AS plan_workmachine_id
            FROM {$planTable} wp
            JOIN {$lineTable} wpi
                ON wpi.trans_id = wp.id
            JOIN workorderworkcenter wowc
                ON wowc.id = wpi.wowc_id
            LEFT JOIN workorder wo
                ON wo.id = wowc.workorder_id
            LEFT JOIN workcenter wc
                ON wc.id = wowc.workcenter_id
            LEFT JOIN planmachine pm
                ON pm.id = wpi.planmachine_id
            {$whereSql}
            ORDER BY wpi.plandate ASC, wpi.planmachine_id ASC, wowc.workorder_id ASC, wowc.workseq ASC
            LIMIT 5000
        ";

        try {
            return collect(DB::connection($connection)->select($sql, $bindings))
                ->map(function ($row) use ($connection, $site) {
                    $row->source_conn = $connection;
                    $row->source_site = $site;
                    $row->plan_key = $site . '|' . $row->plan_id;
                    $row->workcenter_key = $site . '|' . $row->workcenter_id;
                    $row->machine_key = $site . '|' . ($row->planmachine_id ?: 'NO_MACHINE');
                    $row->duration_minutes = (float) ($row->duration_minutes ?? 0);
                    $row->duration_hours = round($row->duration_minutes / 60, 2);
                    $row->qty = (float) ($row->qty ?? 0);
                    $row->start_at = $this->carbon($row->plandate);
                    $row->end_at = $row->start_at ? $row->start_at->copy()->addMinutes((int) round($row->duration_minutes)) : null;
                    $row->date = $row->start_at ? $row->start_at->toDateString() : '';
                    $row->start_text = $row->start_at ? $row->start_at->format('Y-m-d H:i') : '';
                    $row->end_text = $row->end_at ? $row->end_at->format('Y-m-d H:i') : '';
                    $row->workcenter_label = $this->masterLabel($row->workcenternumber ?? null, $row->workcenter_description ?? null, 'WC', $row->workcenter_id);
                    $row->machine_label = $row->planmachine_id
                        ? $this->masterLabel($row->machinenumber ?? null, $row->machine_description ?? null, 'MC', $row->planmachine_id)
                        : 'ยังไม่ระบุเครื่อง';
                    $row->workorder_label = trim((string) ($row->workordernumber ?? '')) !== ''
                        ? (string) $row->workordernumber
                        : 'WO-' . (string) $row->workorder_id;

                    return $row;
                });
        } catch (\Throwable $e) {
            return collect();
        }
    }

    private function latestPlanOptions(array $sources): Collection
    {
        return collect($this->sources($sources))->flatMap(function ($source) {
            $planTable = $this->firstExistingTable($source['connection'], ['mfgschedule', 'workplan', 'workplans']);
            if ($planTable === null) {
                return collect();
            }

            try {
                return collect(DB::connection($source['connection'])->select("
                    SELECT id, transnumber, transdate, startdate
                    FROM {$planTable}
                    WHERE transnumber IS NOT NULL
                    ORDER BY transdate DESC NULLS LAST, id DESC
                    LIMIT 40
                "))->map(fn($row) => [
                    'site' => $source['site'],
                    'value' => (string) $row->transnumber,
                    'transnumber' => (string) $row->transnumber,
                    'label' => $source['site'] . ' / ' . (string) $row->transnumber,
                    'transdate' => $this->dateString($row->transdate ?? ''),
                    'startdate' => $this->dateString($row->startdate ?? ''),
                ]);
            } catch (\Throwable $e) {
                return collect();
            }
        })->unique(fn($row) => $row['site'] . '|' . $row['transnumber'])->values();
    }

    private function seedDateRange(array $filters, Collection $planOptions): array
    {
        $planRange = $this->planDateRange($filters);
        if ($planRange !== null) {
            return $planRange;
        }

        $selected = $planOptions->first(fn($row) => ($row['transnumber'] ?? '') === $filters['transnumber']);
        $start = $selected && !empty($selected['startdate'])
            ? Carbon::parse($selected['startdate'])
            : now();

        return [
            'date_from' => $start->toDateString(),
            'date_to' => $start->copy()->addDays(30)->toDateString(),
        ];
    }

    private function planDateRange(array $filters): ?array
    {
        if ($filters['transnumber'] === '') {
            return null;
        }

        $dates = collect();

        foreach ($this->sources($filters['source']) as $source) {
            $planTable = $this->firstExistingTable($source['connection'], ['mfgschedule', 'workplan', 'workplans']);
            $lineTable = $this->firstExistingTable($source['connection'], ['mfgscheduleitems', 'workplanitem', 'workplanitems', 'workplandetail', 'workplandetails']);
            if ($planTable === null || $lineTable === null) {
                continue;
            }

            try {
                $row = DB::connection($source['connection'])->selectOne("
                    SELECT MIN(wpi.plandate)::date AS date_from, MAX(wpi.plandate)::date AS date_to
                    FROM {$planTable} wp
                    JOIN {$lineTable} wpi
                        ON wpi.trans_id = wp.id
                    WHERE wp.transnumber = ?
                ", [$filters['transnumber']]);

                if ($row && $row->date_from && $row->date_to) {
                    $dates->push([
                        'date_from' => $this->dateString($row->date_from),
                        'date_to' => $this->dateString($row->date_to),
                    ]);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if ($dates->isEmpty()) {
            return null;
        }

        return [
            'date_from' => (string) $dates->min('date_from'),
            'date_to' => (string) $dates->max('date_to'),
        ];
    }

    private function dailyLoad(Collection $rows): Collection
    {
        return $rows
            ->groupBy('date')
            ->map(fn($items, $date) => (object) [
                'date' => (string) $date,
                'jobs' => $items->count(),
                'hours' => round((float) $items->sum('duration_hours'), 2),
                'qty' => round((float) $items->sum('qty'), 2),
                'machines' => $items->pluck('machine_key')->unique()->count(),
                'workorders' => $items->pluck('workorder_id')->unique()->count(),
            ])
            ->sortBy('date')
            ->values();
    }

    private function machineLoad(Collection $rows): Collection
    {
        return $rows
            ->groupBy('machine_key')
            ->map(function ($items) {
                $first = $items->first();
                $peakDay = $items
                    ->groupBy('date')
                    ->map(fn($dayItems, $date) => (object) [
                        'date' => (string) $date,
                        'hours' => round((float) $dayItems->sum('duration_hours'), 2),
                        'jobs' => $dayItems->count(),
                    ])
                    ->sortByDesc('hours')
                    ->first();

                return (object) [
                    'source_site' => $first->source_site,
                    'planmachine_id' => $first->planmachine_id,
                    'machine_label' => $first->machine_label,
                    'jobs' => $items->count(),
                    'hours' => round((float) $items->sum('duration_hours'), 2),
                    'qty' => round((float) $items->sum('qty'), 2),
                    'workorders' => $items->pluck('workorder_id')->unique()->count(),
                    'first_start' => $items->min('start_text'),
                    'last_end' => $items->max('end_text'),
                    'peak_day' => $peakDay?->date,
                    'peak_hours' => $peakDay?->hours ?? 0,
                    'peak_jobs' => $peakDay?->jobs ?? 0,
                    'sample_jobs' => $items
                        ->sortBy('start_at')
                        ->take(4)
                        ->map(fn($row) => [
                            'start' => $row->start_text,
                            'workorder' => $row->workorder_label,
                            'workcenter' => $row->workcenter_label,
                            'hours' => $row->duration_hours,
                        ])
                        ->values(),
                ];
            })
            ->sortByDesc('hours')
            ->values();
    }

    private function workCenterLoad(Collection $rows): Collection
    {
        return $rows
            ->groupBy('workcenter_key')
            ->map(function ($items) {
                $first = $items->first();
                return (object) [
                    'source_site' => $first->source_site,
                    'workcenter_id' => $first->workcenter_id,
                    'workcenter_label' => $first->workcenter_label,
                    'jobs' => $items->count(),
                    'hours' => round((float) $items->sum('duration_hours'), 2),
                    'qty' => round((float) $items->sum('qty'), 2),
                    'machines' => $items->pluck('machine_key')->unique()->count(),
                    'workorders' => $items->pluck('workorder_id')->unique()->count(),
                ];
            })
            ->sortByDesc('hours')
            ->values();
    }

    private function scheduleRows(Collection $rows): Collection
    {
        $windowStart = $rows->min('start_at');
        $windowEnd = $rows->max('end_at');
        if (!$windowStart || !$windowEnd) {
            return collect();
        }

        $totalMinutes = max(1, $windowStart->diffInMinutes($windowEnd));

        return $rows
            ->groupBy('machine_key')
            ->map(function ($items) use ($windowStart, $totalMinutes) {
                $first = $items->first();
                return (object) [
                    'machine_label' => $first->machine_label,
                    'hours' => round((float) $items->sum('duration_hours'), 2),
                    'items' => $items
                        ->sortBy('start_at')
                        ->take(20)
                        ->map(function ($row) use ($windowStart, $totalMinutes) {
                            $offset = $row->start_at ? $windowStart->diffInMinutes($row->start_at, false) : 0;
                            return (object) [
                                'label' => $row->workorder_label,
                                'title' => $row->workorder_label . ' / ' . $row->workcenter_label . ' / ' . $row->start_text . ' - ' . $row->end_text,
                                'left' => max(0, min(100, ($offset / $totalMinutes) * 100)),
                                'width' => max(0.8, min(100, ($row->duration_minutes / $totalMinutes) * 100)),
                                'hours' => $row->duration_hours,
                            ];
                        })
                        ->values(),
                ];
            })
            ->sortByDesc('hours')
            ->take(14)
            ->values();
    }

    private function overlapCount(Collection $rows): int
    {
        return $rows
            ->groupBy('machine_key')
            ->sum(function ($items) {
                $count = 0;
                $lastEnd = null;
                foreach ($items->sortBy('start_at') as $row) {
                    if ($lastEnd && $row->start_at && $row->start_at->lt($lastEnd)) {
                        $count++;
                    }
                    if ($row->end_at && (!$lastEnd || $row->end_at->gt($lastEnd))) {
                        $lastEnd = $row->end_at;
                    }
                }
                return $count;
            });
    }

    private function applySearch(Collection $rows, array $filters): Collection
    {
        if ($filters['q'] === '') {
            return $rows;
        }

        $needle = mb_strtolower($filters['q']);

        return $rows->filter(function ($row) use ($needle) {
            $haystack = mb_strtolower(implode(' ', [
                $row->transnumber,
                $row->workorder_label,
                $row->workcenter_label,
                $row->machine_label,
                $row->operation_description,
                $row->operation_notes,
            ]));

            return str_contains($haystack, $needle);
        })->values();
    }

    private function optionsFromRows(Collection $rows, string $valueField, string $labelField): Collection
    {
        return $rows
            ->filter(fn($row) => !empty($row->{$valueField}))
            ->map(fn($row) => [
                'value' => (string) $row->{$valueField},
                'label' => $row->source_site . ' / ' . (string) $row->{$labelField},
            ])
            ->unique('value')
            ->sortBy('label')
            ->values();
    }

    private function sources(array $selected): array
    {
        $all = [
            ['connection' => 'pgsqlmfgw', 'site' => 'WIRE'],
            ['connection' => 'pgsqlmfgp', 'site' => 'PLUS'],
        ];

        if (empty($selected)) {
            return $all;
        }

        return array_values(array_filter($all, fn($source) => in_array($source['site'], $selected, true)));
    }

    private function siteOptions(): array
    {
        return [
            ['value' => 'WIRE', 'label' => 'Wire'],
            ['value' => 'PLUS', 'label' => 'Plus'],
        ];
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

        return "{$prefix}-{$id}";
    }

    private function shortLabel(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '-';
        }

        $parts = explode(' - ', $label, 2);
        return mb_substr(trim($parts[0]) !== '' ? trim($parts[0]) : $label, 0, 28);
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

    private function firstExistingTable(string $connection, array $tables): ?string
    {
        foreach ($tables as $table) {
            if ($this->tableExists($connection, $table)) {
                return $table;
            }
        }

        return null;
    }

    private function tableExists(string $connection, string $table): bool
    {
        $key = $connection . '|' . $table;
        if (array_key_exists($key, $this->tableExistsCache)) {
            return $this->tableExistsCache[$key];
        }

        try {
            $exists = DB::connection($connection)
                ->table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists();
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $this->tableExistsCache[$key] = $exists;
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
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->exists();
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $this->columnExistsCache[$key] = $exists;
    }

    private function dateString($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function carbon($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
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
}
