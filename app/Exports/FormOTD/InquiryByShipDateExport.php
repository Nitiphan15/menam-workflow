<?php

namespace App\Exports\FormOTD;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InquiryByShipDateExport implements WithMultipleSheets
{
    public function __construct(private array $filters = []) {}

    public function sheets(): array
    {
        $dates = $this->baseQuery()
            ->selectRaw("CONVERT(date, d.ship_posted_at) as d")
            ->whereNotNull('d.ship_posted_at')
            ->groupByRaw("CONVERT(date, d.ship_posted_at)")
            ->orderByRaw("CONVERT(date, d.ship_posted_at)")
            ->pluck('d')
            ->map(fn($x) => (string) $x)
            ->all();

        if (!$dates) {
            $dates = ['NO_SHIP_DATE'];
        }

        return array_map(
            fn($d) => new InquiryShipDateSheet($d, $this->filters),
            $dates
        );
    }

    private function baseQuery()
    {
        $q = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id');

        [$shipFrom, $shipTo] = $this->resolveShipDateRange();

        $q->whereBetween('d.ship_posted_at', [
            Carbon::parse($shipFrom)->startOfDay()->format('Y-m-d H:i:s'),
            Carbon::parse($shipTo)->endOfDay()->format('Y-m-d H:i:s'),
        ]);

        $mode = strtoupper(trim((string) ($this->filters['mode'] ?? '')));
        $status = strtoupper(trim((string) ($this->filters['status'] ?? 'NEW')));
        $revision = trim((string) ($this->filters['revision_number'] ?? ''));
        $revisionMax = trim((string) ($this->filters['revision_max_number'] ?? ''));
        $excludeVoidCancel = (string) ($this->filters['exclude_void_cancel'] ?? '') === '1';
        $so = trim((string) ($this->filters['so'] ?? ''));
        $customer = trim((string) ($this->filters['customer'] ?? ''));
        $shipto = trim((string) ($this->filters['shipto'] ?? ''));
        $divsales = trim((string) ($this->filters['divsales'] ?? ''));

        if ($mode === 'SO') {
            $q->where('d.delivery_type', 'SO');
        } elseif ($mode === 'ACID') {
            $q->where('d.delivery_type', 'ACID');
        } elseif ($mode === 'SPECIAL') {
            $q->where('d.delivery_type', 'SPECIAL');
        }

        if ($so !== '') {
            $q->where('d.so_number', 'like', "%{$so}%");
        }

        if ($customer !== '') {
            $q->where(function ($w) use ($customer) {
                $w->where('c.name', 'like', "%{$customer}%")
                    ->orWhere('c.customernumber', 'like', "%{$customer}%")
                    ->orWhereRaw('d.customer_name COLLATE DATABASE_DEFAULT LIKE ?', ["%{$customer}%"]);
            });
        }

        if ($shipto !== '') {
            $q->where('d.address', 'like', "%{$shipto}%");
        }

        if ($divsales !== '') {
            $this->applyDivisionSalesFilter($q, $divsales);
        }

        if ($status !== 'ALL' && $status !== '') {
            $q->where('d.status', $status);
        }

        if ($excludeVoidCancel) {
            $q->whereRaw("ISNULL(d.status,'') NOT IN ('VOID','CANCEL')");
        }

        if ($revisionMax !== '' && is_numeric($revisionMax)) {
            $q->where('d.revision_number', '<=', (int) $revisionMax);
        } elseif ($revision !== '' && is_numeric($revision)) {
            $q->where('d.revision_number', (int) $revision);
        }

        return $q;
    }

    private function resolveShipDateRange(): array
    {
        $shipFrom = $this->normalizeDateInput((string) ($this->filters['ship_from'] ?? ''));
        $shipTo = $this->normalizeDateInput((string) ($this->filters['ship_to'] ?? ''));

        if ($shipFrom === null && $shipTo === null) {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        if ($shipFrom === null || $shipTo === null) {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        return [$shipFrom, $shipTo];
    }

    private function normalizeDateInput(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function applyDivisionSalesFilter($q, string $divsales): void
    {
        $groups = $this->expandDivisionSearchTokens($divsales);

        if (empty($groups)) {
            return;
        }

        // เคส domain ตาม delivery_type (sync กับ controller):
        //   PLN (วางแผน) = 'ACID', EXPORT (งานพิเศษ) = 'SPECIAL' — ไม่ match sales_name
        $matchesGroupKeywords = function (string $divKey) use ($groups): bool {
            $keywords = collect($this->divisionSearchMap()[$divKey] ?? [])
                ->map(fn($v) => mb_strtolower(trim((string) $v)))
                ->filter()
                ->all();

            return collect($groups)->contains(function ($group) use ($keywords) {
                foreach ($group as $keyword) {
                    if (in_array(mb_strtolower(trim((string) $keyword)), $keywords, true)) {
                        return true;
                    }
                }
                return false;
            });
        };

        if ($matchesGroupKeywords('PLN')) {
            $q->where('d.delivery_type', 'ACID');
            return;
        }

        if ($matchesGroupKeywords('EXPORT')) {
            $q->where('d.delivery_type', 'SPECIAL');
            return;
        }

        // เมื่อ filter เป็นชื่อ Division ปกติ — ตัด delivery_type domain อื่น (ACID/SPECIAL) ออก
        $q->where(function ($w) {
            $w->whereNotIn('d.delivery_type', ['ACID', 'SPECIAL'])
                ->orWhereNull('d.delivery_type');
        });

        $q->where(function ($outer) use ($groups) {
            foreach ($groups as $group) {
                $outer->where(function ($w) use ($group) {
                    foreach ($group as $keyword) {
                        $kw = '%' . $keyword . '%';

                        $w->orWhere('s.sales_name', 'like', $kw)
                            ->orWhere('s.sales_code', 'like', $kw)
                            ->orWhere('e.login', 'like', $kw)
                            ->orWhere('e.name', 'like', $kw);
                    }
                });
            }
        });
    }

    private function expandDivisionSearchTokens(string $keyword): array
    {
        $tokens = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        $map = $this->divisionSearchMap();
        $expanded = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            // ข้าม token ที่เป็นตัวเชื่อมล้วนๆ เช่น "-", "+", "/" ที่มาจาก label
            // เช่น "D3 - ภควดี + ธนัชชา" — ไม่งั้นจะถูก AND บังคับให้ sales_name
            // ต้องมี "-" และ "+" อยู่ในชื่อด้วย ทำให้ไม่เจอข้อมูล
            if (!preg_match('/[\p{L}\p{N}]/u', $token)) {
                continue;
            }

            $matched = false;

            foreach ($map as $groupKeywords) {
                $groupKeywordsLower = array_map(
                    fn($v) => mb_strtolower((string) $v),
                    $groupKeywords
                );

                if (in_array(mb_strtolower($token), $groupKeywordsLower, true)) {
                    $expanded[] = $groupKeywords;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $expanded[] = [$token];
            }
        }

        return $expanded;
    }

    // sync กับ DeliveryPlanInquiryController::divisionSearchMap()
    // ต้องตรงกันเพราะ export ต้อง filter ผลลัพธ์ให้เหมือนหน้า inquiry ทุกประการ
    private function divisionSearchMap(): array
    {
        return [
            'D1'  => ['D1', 'ดิลก', 'ขวัญเรือน'],
            'D2'  => ['D2', 'ปรียาพรรณ', 'นิตยา'],
            'D3'  => ['D3', 'ภควดี', 'ธนัชชา'],
            'D5'  => ['D5', 'ธัธลิญา', 'เฌอร์ลิญา'],
            'D6'  => ['D6', 'สุรศักดิ์', 'คณัญญ์นิชา'],
            'D7'  => ['D7', 'ศิรินภา', 'มนพัทธ์'],
            'D8'  => ['D8', 'สาธิต', 'สุธาสินี'],
            'D9'  => ['D9', 'วรเดชา', 'ลัดดาวัลย์'],
            'PLN' => ['PLN', 'วางแผน', 'กันยกร'],
            'EXPORT' => ['EXPORT', 'Export', 'งานพิเศษ', 'ส่งซ่อม', 'ส่งคืน'],
        ];
    }
}
