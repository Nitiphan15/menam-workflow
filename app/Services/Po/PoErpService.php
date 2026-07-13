<?php

namespace App\Services\Po;

use App\Models\Po\PoHeader;
use App\Support\SqlServerDb;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PoErpService
{
    public const SOURCE_WIRE = 'wire';
    public const SOURCE_PLUS = 'plus';

    private const ERP_CONNECTIONS = [
        self::SOURCE_WIRE => 'pgsqlw',
        self::SOURCE_PLUS => 'pgsqlp',
    ];

    private static ?bool $poHeadersHasSiteColumn = null;
    private static ?bool $usersHaveSignatureColumn = null;

    public function listOpenPos(
        ?string $department = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $status = null,
        ?string $source = null,
        ?array $workflowIds = null,
    ): Collection
    {
        $wantedSource = self::normalizeSource($source);
        $sources = $wantedSource ? [$wantedSource] : array_keys(self::ERP_CONNECTIONS);

        $rows = collect($sources)
            ->flatMap(function (string $erpSource) use ($dateFrom, $dateTo, $search) {
                return $this->baseListQuery($erpSource)
                    ->when($dateFrom, fn ($q) => $q->whereDate('oe.transdate', '>=', $dateFrom))
                    ->when($dateTo, fn ($q) => $q->whereDate('oe.transdate', '<=', $dateTo))
                    ->when($search, function ($q) use ($search) {
                        $kw = trim($search);
                        $q->where(function ($sub) use ($kw) {
                            $sub->where('oe.ordnumber', 'like', "%{$kw}%")
                                ->orWhere('vendor.name', 'like', "%{$kw}%")
                                ->orWhere('oe.f1', 'like', "%{$kw}%");
                        });
                    })
                    ->groupBy('oe.ordnumber', 'oe.f1')
                    ->orderByDesc(DB::raw('MAX(oe.transdate)'))
                    ->get([
                        DB::raw('MAX(oe.transdate) as transdate'),
                        DB::raw('MAX(oe.reqdate) as reqdate'),
                        'oe.ordnumber',
                        DB::raw('MAX(oe.amount) as qty'),
                        'oe.f1',
                    ])
                    ->map(function ($row) use ($erpSource) {
                        $row->site = $erpSource;
                        return $row;
                    });
            })
            ->sortByDesc(fn ($row) => (string) ($row->transdate ?? ''))
            ->values();

        $existing = PoHeader::query()
            ->withCount('attachments')
            ->whereIn('ordnumber', $rows->pluck('ordnumber')->filter()->values())
            ->get()
            ->keyBy(function (PoHeader $header) {
                return $this->poHeadersHaveSiteColumn()
                    ? self::poKey($header->site, $header->ordnumber)
                    : strtoupper(trim((string) $header->ordnumber));
            });

        return $rows->map(function ($row) use ($existing) {
            $sourceSystem = self::normalizeSource($row->site) ?? self::SOURCE_WIRE;
            $local = $this->poHeadersHaveSiteColumn()
                ? $existing->get(self::poKey($sourceSystem, $row->ordnumber))
                : $existing->get(strtoupper(trim((string) $row->ordnumber)));
            $departmentName = $row->f1 ?: 'Unassigned';
            $departmentGroup = self::departmentGroupName($departmentName);
            $statusCode = $this->normalizeStatusCode(
                $local?->status_code,
                $local?->workflow_id,
            );

            return (object) [
                'ordnumber' => $row->ordnumber,
                'invnumber' => null,
                'transdate' => $row->transdate,
                'reqdate' => $row->reqdate ?? null,
                'qty' => (float) ($row->qty ?? 0),
                'site' => $sourceSystem,
                'source_label' => self::sourceLabel($sourceSystem),
                'department' => $departmentName,
                'department_group' => $departmentGroup,
                'po_header_id' => $local?->id,
                'status_code' => $statusCode ?? 'NEW',
                'workflow_id' => $local?->workflow_id,
                'has_attachment' => (int) ($local?->attachments_count ?? 0) > 0,
            ];
        })->when($department, function (Collection $items) use ($department) {
            return $items->filter(
                fn ($row) => strcasecmp((string) $row->department_group, trim($department)) === 0
            )->values();
        })->when($status, function (Collection $items) use ($status) {
            $wanted = strtoupper(trim($status));
            return $items->filter(
                fn ($row) => strtoupper((string) $row->status_code) === $wanted
            )->values();
        })->when($wantedSource, function (Collection $items) use ($wantedSource) {
            return $items->filter(
                fn ($row) => self::normalizeSource($row->site) === $wantedSource
            )->values();
        })->when(is_array($workflowIds), function (Collection $items) use ($workflowIds) {
            $allowed = collect($workflowIds)
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            return $allowed->isEmpty()
                ? collect()
                : $items->filter(fn ($row) => $allowed->contains((int) ($row->workflow_id ?? 0)))->values();
        })->filter(function ($row) use ($status) {
            $rowStatus = strtoupper((string) $row->status_code);
            $wantedStatus = strtoupper(trim((string) $status));

            if ($wantedStatus !== '') {
                return $rowStatus !== 'APPROVED';
            }

            return !in_array($rowStatus, ['APPROVED', 'CLOSED'], true);
        });
    }

    public function getDetailRows(string $ordnumber, ?string $sourceSystem = null): Collection
    {
        return $this->baseDetailQuery($sourceSystem)
            ->table('oe')
            ->leftJoin('ap', 'ap.ordnumber', '=', 'oe.ordnumber')
            ->join('vendor', 'vendor.id', '=', 'oe.vendor_id')
            ->join('employee', 'oe.requester_id', '=', 'employee.id')
            ->join('orderitems', 'oe.id', '=', 'orderitems.trans_id')
            ->leftJoin('parts', 'orderitems.parts_id', '=', 'parts.id')
            ->leftJoin('partsunit as pu', function ($join) {
                $join->on('pu.parts_id', '=', 'orderitems.parts_id')
                    ->on('pu.unit', '=', 'orderitems.unit');
            })
            ->leftJoin('unitmeasure as um', 'um.unit', '=', 'pu.unit')
            ->where('oe.closed', false)
            ->where('oe.cancelled', false)
            ->whereDate('oe.transdate', '>=', '2026-04-01')
            ->whereRaw("oe.ordnumber ~ '^POR?[0-9]'")
            ->where('oe.shipped_or_received', false)
            ->where('oe.ordnumber', $ordnumber)
            ->orderBy('orderitems.id')
            ->get([
                'oe.transdate',
                'oe.reqdate',
                'oe.ordnumber',
                'oe.quotenumber',
                'ap.invnumber',
                'oe.amount',
                'oe.netamount',
                'oe.curr',
                'oe.terms',
                'oe.notes',
                'oe.f1',
                'oe.f3',
                'vendor.name as vendor_name',
                'vendor.addr1',
                'vendor.addr2',
                'vendor.contact',
                'vendor.phone',
                'vendor.fax',
                'employee.name as requester_name',
                'orderitems.qty',
                'orderitems.unit as item_unit_code',
                DB::raw("COALESCE(NULLIF(um.description, ''), orderitems.unit) as item_unit"),
                'orderitems.sellprice',
                DB::raw('(orderitems.qty * orderitems.sellprice) as extended_price'),
                'orderitems.description',
                'parts.purchase_unit',
                'parts.purchase_unit_qty',
            ]);
    }

    public function firstHeaderRow(string $ordnumber, ?string $sourceSystem = null): ?object
    {
        return $this->getDetailRows($ordnumber, $sourceSystem)->first();
    }

    public function purchaseIdForPo(string $ordnumber, ?string $sourceSystem = null): ?int
    {
        $id = $this->baseDetailQuery($sourceSystem)
            ->table('oe')
            ->where('oe.closed', false)
            ->where('oe.cancelled', false)
            ->whereDate('oe.transdate', '>=', '2026-04-01')
            ->whereRaw("oe.ordnumber ~ '^POR?[0-9]'")
            ->where('oe.shipped_or_received', false)
            ->where('oe.ordnumber', $ordnumber)
            ->orderByDesc('oe.id')
            ->value('oe.id');

        return $id ? (int) $id : null;
    }

    public function syncHeaderFromErp(string $ordnumber, ?int $userId = null, ?string $sourceSystem = null): PoHeader
    {
        $sourceSystem = self::normalizeSource($sourceSystem) ?? self::SOURCE_WIRE;
        $header = $this->firstHeaderRow($ordnumber, $sourceSystem);
        abort_if(!$header, 404, 'PO not found in ERP');

        $lookup = ['ordnumber' => $ordnumber];
        if ($this->poHeadersHaveSiteColumn()) {
            $lookup['site'] = $sourceSystem;
        }

        $record = PoHeader::query()->firstOrNew($lookup);
        $normalizedStatus = $this->normalizeStatusCode($record->status_code, $record->workflow_id) ?: 'DRAFT';
        $payload = [
            'site' => $sourceSystem,
            'invnumber' => $header->invnumber,
            'transdate' => $header->transdate,
            'reqdate' => $header->reqdate,
            'amount' => $header->amount,
            'netamount' => $header->netamount,
            'curr' => $header->curr,
            'terms' => $header->terms,
            'notes' => $header->notes,
            'f1' => $header->f1,
            'f3' => $header->f3,
            'vendor_name' => $header->vendor_name,
            'requester_name' => $header->requester_name,
            'status_code' => $normalizedStatus,
            'updated_at' => now(),
            'updated_by' => $userId,
        ];

        if (!$this->poHeadersHaveSiteColumn()) {
            unset($payload['site']);
        }

        $record->fill($payload);

        if (!$record->exists) {
            $record->created_at = now();
            $record->created_by = $userId;
        }

        $record->save();

        return $record->fresh(['attachments', 'workflow']);
    }

    public function findDepartmentId(?string $erpDepartment): ?int
    {
        return self::resolveDepartmentId($erpDepartment);
    }

    public function workflowSignatures(?int $workflowId): array
    {
        if (!$workflowId) {
            return [1 => null, 2 => null, 3 => null];
        }

        $selects = [
            'h.step_no',
            'h.actor_user_id',
            'h.created_at',
            'u.name as actor_name',
        ];
        if ($this->usersHaveSignatureColumn()) {
            $selects[] = 'u.signature_path';
        }

        $logs = SqlServerDb::table('wf_action_histories as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
            ->where('h.wf_form_id', $workflowId)
            ->where('h.action_type', 'APPROVE')
            ->orderBy('h.created_at')
            ->get($selects)
            ->map(fn ($row) => $this->decorateSignatureRow($row))
            ->keyBy('step_no');

        return [
            1 => $logs->get(1),
            2 => $logs->get(2),
            3 => $logs->get(3),
        ];
    }

    public function printWorkflowSignatures(?int $workflowId): array
    {
        if (!$workflowId) {
            return [
                'submitted_by' => null,
                'ordered_by' => collect(),
                'authorized_by' => null,
                'po_confirmed_by' => null,
            ];
        }

        $selects = [
            'h.step_no',
            'h.action_type',
            'h.actor_user_id',
            'h.created_at',
            'u.name as actor_name',
        ];
        if ($this->usersHaveSignatureColumn()) {
            $selects[] = 'u.signature_path';
        }

        $logs = SqlServerDb::table('wf_action_histories as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
            ->where('h.wf_form_id', $workflowId)
            ->whereIn('h.action_type', ['SUBMIT', 'APPROVE'])
            ->orderBy('h.created_at')
            ->get($selects);

        $logs = $logs->map(fn ($row) => $this->decorateSignatureRow($row));

        $approvals = $logs->where('action_type', 'APPROVE');

        return [
            'submitted_by' => $logs->firstWhere('action_type', 'SUBMIT'),
            'ordered_by' => $approvals->where('step_no', 2)->values(),
            'authorized_by' => $approvals->firstWhere('step_no', 3),
            'po_confirmed_by' => null,
        ];
    }

    public static function normalizeSource(?string $sourceSystem): ?string
    {
        $sourceSystem = strtolower(trim((string) $sourceSystem));

        return in_array($sourceSystem, [self::SOURCE_WIRE, self::SOURCE_PLUS], true)
            ? $sourceSystem
            : null;
    }

    public static function sourceLabel(?string $sourceSystem): string
    {
        return match (self::normalizeSource($sourceSystem)) {
            self::SOURCE_PLUS => 'Plus',
            default => 'Wire',
        };
    }

    private static function poKey(?string $sourceSystem, ?string $ordnumber): string
    {
        return (self::normalizeSource($sourceSystem) ?? self::SOURCE_WIRE) . '::' . strtoupper(trim((string) $ordnumber));
    }

    public static function departmentGroupName(?string $erpDepartment): string
    {
        $name = trim((string) $erpDepartment);
        if ($name === '') {
            return 'Unassigned';
        }

        $parts = preg_split('/\s*-\s*/', $name, 2);
        $group = trim((string) ($parts[0] ?? ''));

        return $group !== '' ? $group : $name;
    }

    public static function resolveDepartmentId(?string $erpDepartment): ?int
    {
        $name = trim((string) $erpDepartment);
        if ($name === '') {
            return null;
        }

        $group = self::departmentGroupName($name);
        $candidates = collect([$name, $group])->filter()->unique()->values();

        foreach ($candidates as $candidate) {
            $exact = SqlServerDb::table('departments')
                ->where(function ($q) use ($candidate) {
                    $q->where('name', $candidate)->orWhere('code', $candidate);
                })
                ->value('id');

            if ($exact) {
                return (int) $exact;
            }
        }

        foreach ($candidates as $candidate) {
            $fuzzy = SqlServerDb::table('departments')
                ->where(function ($q) use ($candidate) {
                    $q->where('name', 'like', $candidate . '%')
                        ->orWhere('code', 'like', $candidate . '%')
                        ->orWhere('name', 'like', '%' . $candidate . '%')
                        ->orWhere('code', 'like', '%' . $candidate . '%');
                })
                ->orderBy('code')
                ->value('id');

            if ($fuzzy) {
                return (int) $fuzzy;
            }
        }

        return null;
    }

    private function normalizeStatusCode(?string $statusCode, $workflowId): ?string
    {
        $statusCode = strtoupper(trim((string) $statusCode));

        if ($statusCode === '') {
            return null;
        }

        if (blank($workflowId) && in_array($statusCode, [
            'PURCHASE_SUBMITTED',
            'PURCHASE_APPROVAL',
            'DEPT_MANAGER_APPROVAL',
            'IN_APPROVAL',
        ], true)) {
            return 'DRAFT';
        }

        return $statusCode;
    }

    private function baseListQuery(string $sourceSystem)
    {
        // Scope: PO ที่ยังไม่รับของ (shipped_or_received = false) และยังไม่มี invoice
        // (ไม่มีแถวใน ap ที่ ordnumber ตรงกัน) ครอบทั้งเลข PO และ POR
        return $this->baseDetailQuery($sourceSystem)
            ->table('oe')
            ->join('vendor', 'vendor.id', '=', 'oe.vendor_id')
            ->join('employee', 'oe.requester_id', '=', 'employee.id')
            ->join('orderitems', 'oe.id', '=', 'orderitems.trans_id')
            ->where('oe.closed', false)
            ->where('oe.cancelled', false)
            ->whereDate('oe.transdate', '>=', '2026-04-01')
            ->whereRaw("oe.ordnumber ~ '^POR?[0-9]'")
            ->where('oe.shipped_or_received', false)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('ap')
                    ->whereColumn('ap.ordnumber', 'oe.ordnumber');
            });
    }

    private function baseDetailQuery(?string $sourceSystem = null)
    {
        $sourceSystem = self::normalizeSource($sourceSystem) ?? self::SOURCE_WIRE;
        $connection = self::ERP_CONNECTIONS[$sourceSystem] ?? self::ERP_CONNECTIONS[self::SOURCE_WIRE];

        return DB::connection($connection);
    }

    private function decorateSignatureRow(object $row): object
    {
        $path = $this->resolveSignaturePath($row);
        $row->signature_url = $path !== '' ? Storage::disk('public')->url($path) : null;
        $row->signature_file_url = $path !== '' ? $this->signatureFileUrl($path) : null;
        $row->signature_data_uri = $path !== '' ? $this->signatureDataUri($path) : null;

        return $row;
    }

    private function signatureDataUri(string $path): ?string
    {
        if (!Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($path);
        $contents = $this->transparentizedSignatureContents($absolutePath);
        if ($contents === false) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($contents);
    }

    private function signatureFileUrl(string $path): ?string
    {
        if (!Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($path);
        $normalized = str_replace('\\', '/', $absolutePath);

        return 'file:///' . ltrim($normalized, '/');
    }

    private function transparentizedSignatureContents(string $absolutePath): string|false
    {
        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return false;
        }

        $image = @imagecreatefromstring($raw);
        if (!$image) {
            return $raw;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $this->makeNearWhiteTransparent($image);

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png === false ? false : $png;
    }

    private function makeNearWhiteTransparent(\GdImage $image, int $threshold = 245): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if (
                    ($rgba['red'] ?? 0) >= $threshold &&
                    ($rgba['green'] ?? 0) >= $threshold &&
                    ($rgba['blue'] ?? 0) >= $threshold
                ) {
                    imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 255, 255, 255, 127));
                }
            }
        }
    }

    private function resolveSignaturePath(object $row): string
    {
        $path = trim((string) ($row->signature_path ?? ''));

        if ($path !== '') {
            $normalized = preg_replace('#^/?storage/#', '', str_replace('\\', '/', $path));
            if (is_string($normalized) && Storage::disk('public')->exists($normalized)) {
                return $normalized;
            }

            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        $actorUserId = (int) ($row->actor_user_id ?? 0);
        if ($actorUserId > 0) {
            $fallback = "signatures/users/user_{$actorUserId}.png";
            if (Storage::disk('public')->exists($fallback)) {
                return $fallback;
            }
        }

        return '';
    }

    private function poHeadersHaveSiteColumn(): bool
    {
        if (self::$poHeadersHasSiteColumn === null) {
            self::$poHeadersHasSiteColumn = Schema::connection('sqlsrv_menam')->hasColumn('po_headers', 'site');
        }

        return self::$poHeadersHasSiteColumn;
    }

    private function usersHaveSignatureColumn(): bool
    {
        if (self::$usersHaveSignatureColumn === null) {
            self::$usersHaveSignatureColumn = Schema::connection('sqlsrv_menam')->hasColumn('users', 'signature_path');
        }

        return self::$usersHaveSignatureColumn;
    }
}
