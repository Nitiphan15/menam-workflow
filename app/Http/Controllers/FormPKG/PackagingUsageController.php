<?php

namespace App\Http\Controllers\FormPkg;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackagingUsageController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'date_type' => ['nullable', 'in:reqdate,opendate'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
        ]);

        $dateType = $request->query('date_type', 'reqdate');
        $dateFrom = $request->query('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->query('date_to', now()->endOfMonth()->toDateString());

        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            return back()
                ->withErrors(['date_to' => 'วันที่สิ้นสุดต้องมากกว่าหรือเท่ากับวันที่เริ่มต้น'])
                ->withInput();
        }

        $product = trim((string) $request->query('product', ''));
        $fpack   = trim((string) $request->query('fpack', ''));
        $status  = trim((string) $request->query('status', ''));
        $site    = trim((string) $request->query('site', ''));

        $siteOptions = collect([
            ['value' => '', 'label' => '-- ทั้งหมด --'],
            ['value' => 'WIRE', 'label' => 'WIRE'],
            ['value' => 'PLUS', 'label' => 'PLUS'],
        ]);

        $rows = $this->getMergedBaseRows($dateType, $dateFrom, $dateTo, $product, $fpack);
        [$productOptions, $fpackOptions] = $this->getMergedDropdownOptions($dateType, $dateFrom, $dateTo);

        $statusOptions = collect([
            ['value' => '',          'label' => '-- ทั้งหมด --'],
            ['value' => 'shortage',  'label' => 'ขาด'],
            ['value' => 'risk',      'label' => 'เสี่ยง'],
            ['value' => 'enough',    'label' => 'พอ'],
            ['value' => 'idle',      'label' => 'ไม่มีใช้'],
            ['value' => 'unknown',   'label' => 'ไม่ทราบ'],
        ]);

        $fpacks = $rows->pluck('fpack')
            ->filter()
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->values()
            ->all();

        $products = $rows->pluck('fcat')
            ->filter()
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->values()
            ->all();

        $masters = collect();
        if (!empty($fpacks) && !empty($products)) {
            $masters = DB::connection('sqlsrv_menam')
                ->table('packaging_master')
                ->select([
                    'product',
                    'standard_packaging',
                    'code_packaging',
                    'package_per_kg',
                ])
                ->where('active', 1)
                ->whereIn('standard_packaging', $fpacks)
                ->whereIn('product', $products)
                ->get()
                ->groupBy(fn($m) => trim((string) $m->product) . '||' . trim((string) $m->standard_packaging));
        }

        $codes = $masters
            ->flatten(1)
            ->pluck('code_packaging')
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '')
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->values()
            ->all();

        [$stockMap, $partMap] = $this->getPackagingPartMaps($codes);

        $enriched = $this->buildEnrichedRows($rows, $masters, $stockMap, $partMap);
        $groups   = $this->buildGroupSummary($enriched);

        if ($status !== '') {
            $groups = $groups->filter(fn($g) => (string) ($g->status ?? '') === $status);
        }

        if ($site !== '') {
            $groups = $groups->filter(
                fn($g) => strtoupper((string) ($g->source_site ?? '')) === strtoupper($site)
            );
        }

        $groups = $groups
            ->sort(function ($a, $b) {
                $cmp1 = ((int) ($a->risk_rank ?? 999)) <=> ((int) ($b->risk_rank ?? 999));
                if ($cmp1 !== 0) return $cmp1;

                $cmp2 = ((float) ($b->shortage_pcs ?? 0)) <=> ((float) ($a->shortage_pcs ?? 0));
                if ($cmp2 !== 0) return $cmp2;

                $covA = is_numeric($a->coverage_pct) ? (float) $a->coverage_pct : 999999;
                $covB = is_numeric($b->coverage_pct) ? (float) $b->coverage_pct : 999999;
                $cmp3 = $covA <=> $covB;
                if ($cmp3 !== 0) return $cmp3;

                $cmp4 = strcmp((string) ($a->code_packaging ?? 'ZZZZZZ'), (string) ($b->code_packaging ?? 'ZZZZZZ'));
                if ($cmp4 !== 0) return $cmp4;

                return strcmp((string) ($a->source_site ?? ''), (string) ($b->source_site ?? ''));
            })
            ->values();

        $enrichedForSummary = $enriched;
        if ($site !== '') {
            $enrichedForSummary = $enrichedForSummary->filter(
                fn($x) => strtoupper((string) ($x->source_site ?? '')) === strtoupper($site)
            );
        }

        $productTotals = $enrichedForSummary
            ->groupBy(fn($x) => (string) $x->product . '||' . (string) $x->source_site)
            ->map(function ($items, $key) {
                [$productName, $siteName] = array_pad(explode('||', $key, 2), 2, '');

                return (object) [
                    'product'           => $productName,
                    'source_site'       => $siteName,
                    'count_wo'          => $items->count(),
                    'sum_open_qty'      => (float) $items->sum('open_qty'),
                    'sum_produced_qty'  => (float) $items->sum('produced_qty'),
                    'sum_balance_qty'   => (float) $items->sum('balance_qty'),
                    'sum_packs_used'    => (float) $items->sum(fn($x) => $x->packs_used ?? 0),
                ];
            })
            ->sortBy(fn($x) => $x->product . '|' . $x->source_site)
            ->values();

        $coverageAvg = $groups
            ->filter(fn($x) => (float) ($x->demand_pcs ?? 0) > 0 && is_numeric($x->coverage_pct))
            ->whenNotEmpty(fn($c) => $c->avg('coverage_pct'));

        $riskCount = $groups
            ->filter(fn($x) => in_array(($x->status ?? ''), ['shortage', 'risk'], true))
            ->count();

        return view('formpkg.packaging_usage', [
            'dateType'       => $dateType,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,

            'product'        => $product,
            'fpack'          => $fpack,
            'status'         => $status,

            'productOptions' => $productOptions,
            'fpackOptions'   => $fpackOptions,
            'statusOptions'  => $statusOptions,

            'groups'         => $groups,
            'productTotals'  => $productTotals,

            'coverageAvg'    => $coverageAvg,
            'riskCount'      => $riskCount,

            'site'           => $site,
            'siteOptions'    => $siteOptions,
        ]);
    }

    private function getPartMapFromErpConnection(string $connectionName, array $partsIds): Collection
    {
        if (empty($partsIds)) {
            return collect();
        }

        return DB::connection($connectionName)
            ->table('parts as p')
            ->selectRaw("
                p.id,
                p.partnumber,
                p.description,
                p.unit,
                ROUND(COALESCE(p.onhand, 0)::numeric, 2) AS fg_qty
            ")
            ->whereIn('p.id', $partsIds)
            ->get()
            ->keyBy('id');
    }

    private function getBaseRowsFromMfgConnection(
        string $mfgConnectionName,
        string $partConnectionName,
        string $dateType,
        string $dateFrom,
        string $dateTo,
        string $product = '',
        string $fpack = ''
    ): Collection {
        $mfgConn = DB::connection($mfgConnectionName);
        $site    = $mfgConnectionName === 'pgsqlmfgw' ? 'WIRE' : 'PLUS';

        $dateColumn = $dateType === 'opendate' ? 'w.dateopen' : 'w.reqdate';

        // 1) ดึง base จากฝั่ง MFG ก่อน
        $rows = $mfgConn->table('workorder as w')
            ->leftJoin('customer as c', 'w.customer_id', '=', 'c.id')
            ->selectRaw("
            w.id AS workorder_id,
            w.parts_id,
            w.dateopen,
            w.reqdate,
            w.workordernumber,
            w.fpack,
            w.fcat,
            w.flen,
            ROUND(COALESCE(w.qty, 0)::numeric, 2) AS open_qty,
            c.name AS customer_name,
            '{$mfgConnectionName}' AS source_conn,
            '{$site}' AS source_site
        ")
            ->whereNotNull('w.fpack')
            ->where('w.fpack', '<>', '');

        if ($dateFrom !== '') {
            $rows->whereRaw("{$dateColumn}::date >= ?", [$dateFrom]);
        }

        if ($dateTo !== '') {
            $rows->whereRaw("{$dateColumn}::date <= ?", [$dateTo]);
        }

        if ($product !== '') {
            $rows->where('w.fcat', $product);
        }

        if ($fpack !== '') {
            $rows->where('w.fpack', $fpack);
        }

        $rows = $rows
            ->orderByRaw($dateColumn . ' ASC')
            ->orderBy('w.workordernumber')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $partsIds = $rows->pluck('parts_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $workorderIds = $rows->pluck('workorder_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 2) part master จากฝั่ง ERP เดิม
        $partMap = $this->getPartMapFromErpConnection($partConnectionName, $partsIds);

        // 3) ของดีจากฝั่ง ERP
        $goodQtyMap = $this->getGoodQtyMapFromErpConnection($partConnectionName, $workorderIds);

        // 4) map กลับ
        return $rows->map(function ($row) use ($partMap, $goodQtyMap) {
            $part = $partMap->get($row->parts_id);

            $openQty     = (float) ($row->open_qty ?? 0);
            $producedQty = (float) ($goodQtyMap[$row->workorder_id] ?? 0);
            $balanceQty  = max($openQty - $producedQty, 0);

            $row->partnumber   = $part->partnumber ?? null;
            $row->description  = $part->description ?? null;
            $row->unit         = $part->unit ?? null;
            $row->fg_qty       = isset($part->fg_qty) ? (float) $part->fg_qty : 0.0;

            $row->produced_qty = round($producedQty, 2);   // ผลิตไปแล้ว = ของดี
            $row->balance_qty  = round($balanceQty, 2);    // ค้างผลิต = เปิดผลิต - ของดี

            return $row;
        });
    }

    private function getMergedBaseRows(
        string $dateType,
        string $dateFrom,
        string $dateTo,
        string $product = '',
        string $fpack = ''
    ): Collection {
        $sortField = $dateType === 'opendate' ? 'dateopen' : 'reqdate';

        $rowsW = $this->getBaseRowsFromMfgConnection(
            'pgsqlmfgw',
            'pgsqlw',
            $dateType,
            $dateFrom,
            $dateTo,
            $product,
            $fpack
        );

        $rowsP = $this->getBaseRowsFromMfgConnection(
            'pgsqlmfgp',
            'pgsqlp',
            $dateType,
            $dateFrom,
            $dateTo,
            $product,
            $fpack
        );

        return $rowsW
            ->merge($rowsP)
            ->sortBy([
                [$sortField, 'asc'],
                ['workordernumber', 'asc'],
                ['source_site', 'asc'],
            ])
            ->values();
    }

    private function getMergedDropdownOptions(string $dateType, string $dateFrom, string $dateTo): array
    {
        $dateColumn = $dateType === 'opendate' ? 'dateopen' : 'reqdate';

        $queryProductsW = DB::connection('pgsqlmfgw')->table('workorder');
        $queryProductsP = DB::connection('pgsqlmfgp')->table('workorder');
        $queryFpackW    = DB::connection('pgsqlmfgw')->table('workorder');
        $queryFpackP    = DB::connection('pgsqlmfgp')->table('workorder');

        if ($dateFrom !== '') {
            $queryProductsW->whereDate($dateColumn, '>=', $dateFrom);
            $queryProductsP->whereDate($dateColumn, '>=', $dateFrom);
            $queryFpackW->whereDate($dateColumn, '>=', $dateFrom);
            $queryFpackP->whereDate($dateColumn, '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $queryProductsW->whereDate($dateColumn, '<=', $dateTo);
            $queryProductsP->whereDate($dateColumn, '<=', $dateTo);
            $queryFpackW->whereDate($dateColumn, '<=', $dateTo);
            $queryFpackP->whereDate($dateColumn, '<=', $dateTo);
        }

        $productW = $queryProductsW
            ->whereNotNull('fcat')
            ->where('fcat', '<>', '')
            ->distinct()
            ->pluck('fcat');

        $productP = $queryProductsP
            ->whereNotNull('fcat')
            ->where('fcat', '<>', '')
            ->distinct()
            ->pluck('fcat');

        $fpackW = $queryFpackW
            ->whereNotNull('fpack')
            ->where('fpack', '<>', '')
            ->distinct()
            ->pluck('fpack');

        $fpackP = $queryFpackP
            ->whereNotNull('fpack')
            ->where('fpack', '<>', '')
            ->distinct()
            ->pluck('fpack');

        $productOptions = $productW
            ->merge($productP)
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '')
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->sort()
            ->values();

        $fpackOptions = $fpackW
            ->merge($fpackP)
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '')
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->sort()
            ->values();

        return [$productOptions, $fpackOptions];
    }

    private function getPackagingPartMaps(array $codes): array
    {
        if (empty($codes)) {
            return [collect(), collect()];
        }

        $stockW = DB::connection('pgsqlw')
            ->table('parts')
            ->selectRaw('partnumber, COALESCE(onhand,0) AS onhand')
            ->whereIn('partnumber', $codes)
            ->pluck('onhand', 'partnumber');

        $stockP = DB::connection('pgsqlp')
            ->table('parts')
            ->selectRaw('partnumber, COALESCE(onhand,0) AS onhand')
            ->whereIn('partnumber', $codes)
            ->pluck('onhand', 'partnumber');

        $infoW = DB::connection('pgsqlw')
            ->table('parts')
            ->selectRaw('partnumber, description, f2')
            ->whereIn('partnumber', $codes)
            ->get()
            ->keyBy('partnumber');

        $infoP = DB::connection('pgsqlp')
            ->table('parts')
            ->selectRaw('partnumber, description, f2')
            ->whereIn('partnumber', $codes)
            ->get()
            ->keyBy('partnumber');

        $stockMap = collect($codes)->mapWithKeys(function ($code) use ($stockW, $stockP) {
            return [
                $code => (float) ($stockW[$code] ?? 0) + (float) ($stockP[$code] ?? 0),
            ];
        });

        $partMap = collect($codes)->mapWithKeys(function ($code) use ($infoW, $infoP) {
            $row = $infoW[$code] ?? $infoP[$code] ?? null;

            return [
                $code => [
                    'description' => $row->description ?? null,
                    'length_mm'   => $row->f2 ?? null,
                ],
            ];
        });

        return [$stockMap, $partMap];
    }

    private function buildEnrichedRows(
        Collection $rows,
        Collection $masters,
        Collection $stockMap,
        Collection $partMap
    ): Collection {
        $enriched = collect();

        foreach ($rows as $r) {
            $productVal = trim((string) ($r->fcat ?? ''));
            $fpackVal   = trim((string) ($r->fpack ?? ''));
            $rowSite    = strtoupper(trim((string) ($r->source_site ?? '')));
            $partsId    = $r->parts_id ?? null;
            $flenVal    = (float) ($r->flen ?? 0);

            $openQty     = (float) ($r->open_qty ?? 0);
            $producedQty = (float) ($r->produced_qty ?? 0);
            $balanceQty  = (float) ($r->balance_qty ?? 0);

            $qty = $balanceQty;

            $key   = $productVal . '||' . $fpackVal;
            $mList = collect($masters->get($key, []));

            // ===== special override =====
            $specialCode = $this->resolveSpecialPackagingCode($fpackVal, $flenVal);

            if ($specialCode !== null) {
                $masterForSpecial = $mList->first(function ($m) use ($specialCode) {
                    return trim((string) ($m->code_packaging ?? '')) === $specialCode;
                });

                $kgPerPack = $masterForSpecial && $masterForSpecial->package_per_kg !== null
                    ? (float) $masterForSpecial->package_per_kg
                    : null;

                $stockOnHand = (float) ($stockMap[$specialCode] ?? 0);

                $partInfo = $partMap->get($specialCode);
                $packName = $partInfo['description'] ?? null;
                $lengthMm = $partInfo['length_mm'] ?? null;

                $demandPcs = ($kgPerPack && $kgPerPack > 0)
                    ? (int) floor($qty / $kgPerPack)
                    : 0;

                $balancePcs = $stockOnHand - $demandPcs;
                $shortagePcs = max(0, $demandPcs - $stockOnHand);

                $coveragePct = null;
                if ($demandPcs > 0) {
                    $coveragePct = ($stockOnHand / $demandPcs) * 100;
                } elseif ($stockOnHand > 0) {
                    $coveragePct = 999999;
                } else {
                    $coveragePct = 0;
                }

                $statusVal = 'unknown';
                if ($demandPcs <= 0 && $stockOnHand <= 0) {
                    $statusVal = 'idle';
                } elseif ($demandPcs <= 0 && $stockOnHand > 0) {
                    $statusVal = 'enough';
                } elseif ($stockOnHand < $demandPcs) {
                    $statusVal = 'shortage';
                } elseif ($coveragePct < 120) {
                    $statusVal = 'risk';
                } else {
                    $statusVal = 'enough';
                }

                $riskRank = match ($statusVal) {
                    'shortage' => 1,
                    'risk'     => 2,
                    'enough'   => 3,
                    'idle'     => 4,
                    default    => 5,
                };

                $packsUsed = ($kgPerPack && $kgPerPack > 0)
                    ? ($qty / $kgPerPack)
                    : null;

                $enriched->push((object) [
                    'source_site'     => $rowSite,
                    'source_conn'     => $r->source_conn ?? null,

                    'parts_id'        => $partsId,

                    'open_qty'        => $openQty,
                    'produced_qty'    => $producedQty,
                    'balance_qty'     => $balanceQty,

                    'product'         => $productVal,
                    'fpack'           => $fpackVal,
                    'flen'            => $flenVal,
                    'code_packaging'  => $specialCode,
                    'pack_name'       => $packName,
                    'length_mm'       => $lengthMm,

                    'workordernumber' => (string) ($r->workordernumber ?? ''),
                    'dateopen'        => $r->dateopen ?? null,
                    'reqdate'         => $r->reqdate ?? null,
                    'qty'             => $qty,

                    'kg_per_pack'     => $kgPerPack,
                    'packs_used'      => $packsUsed,
                    'demand_pcs'      => $demandPcs,
                    'stock_on_hand'   => $stockOnHand,
                    'balance_pcs'     => $balancePcs,
                    'shortage_pcs'    => $shortagePcs,
                    'coverage_pct'    => $coveragePct,
                    'status'          => $statusVal,
                    'risk_rank'       => $riskRank,
                    'mapped'          => true,
                ]);

                continue;
            }

            if ($mList->isEmpty()) {
                $enriched->push((object) [
                    'source_site'     => $rowSite,
                    'source_conn'     => $r->source_conn ?? null,

                    'parts_id'        => $partsId,

                    'open_qty'        => $openQty,
                    'produced_qty'    => $producedQty,
                    'balance_qty'     => $balanceQty,

                    'product'         => $productVal,
                    'fpack'           => $fpackVal,
                    'flen'            => $flenVal,
                    'code_packaging'  => null,
                    'pack_name'       => null,
                    'length_mm'       => null,

                    'workordernumber' => (string) ($r->workordernumber ?? ''),
                    'dateopen'        => $r->dateopen ?? null,
                    'reqdate'         => $r->reqdate ?? null,
                    'qty'             => $qty,

                    'kg_per_pack'     => null,
                    'packs_used'      => null,
                    'demand_pcs'      => 0,
                    'stock_on_hand'   => null,
                    'balance_pcs'     => null,
                    'shortage_pcs'    => null,
                    'coverage_pct'    => null,
                    'status'          => 'unknown',
                    'risk_rank'       => 5,
                    'mapped'          => false,
                ]);
                continue;
            }

            foreach ($mList as $m) {
                $code = $m->code_packaging ? trim((string) $m->code_packaging) : null;

                $kgPerPack   = $m->package_per_kg !== null ? (float) $m->package_per_kg : null;
                $stockOnHand = $code ? (float) ($stockMap[$code] ?? 0) : null;

                $partInfo = $code ? $partMap->get($code) : null;
                $packName = $partInfo['description'] ?? null;
                $lengthMm = $partInfo['length_mm'] ?? null;

                $demandPcs = ($kgPerPack && $kgPerPack > 0)
                    ? (int) floor($qty / $kgPerPack)
                    : 0;

                $balancePcs = $stockOnHand !== null
                    ? ($stockOnHand - $demandPcs)
                    : null;

                $shortagePcs = $stockOnHand !== null
                    ? max(0, $demandPcs - (float) $stockOnHand)
                    : null;

                $coveragePct = null;
                if ($demandPcs > 0 && $stockOnHand !== null) {
                    $coveragePct = ((float) $stockOnHand / $demandPcs) * 100;
                } elseif ($demandPcs === 0 && $stockOnHand !== null) {
                    $coveragePct = $stockOnHand > 0 ? 999999 : 0;
                }

                $statusVal = 'unknown';
                if ($stockOnHand === null) {
                    $statusVal = 'unknown';
                } elseif ($demandPcs <= 0 && (float) $stockOnHand <= 0) {
                    $statusVal = 'idle';
                } elseif ($demandPcs <= 0 && (float) $stockOnHand > 0) {
                    $statusVal = 'enough';
                } elseif ((float) $stockOnHand < $demandPcs) {
                    $statusVal = 'shortage';
                } elseif ($coveragePct !== null && $coveragePct < 120) {
                    $statusVal = 'risk';
                } else {
                    $statusVal = 'enough';
                }

                $riskRank = match ($statusVal) {
                    'shortage' => 1,
                    'risk'     => 2,
                    'enough'   => 3,
                    'idle'     => 4,
                    default    => 5,
                };

                $packsUsed = ($kgPerPack && $kgPerPack > 0)
                    ? ($qty / $kgPerPack)
                    : null;

                $enriched->push((object) [
                    'source_site'     => $rowSite,
                    'source_conn'     => $r->source_conn ?? null,

                    'parts_id'        => $partsId,

                    'open_qty'        => $openQty,
                    'produced_qty'    => $producedQty,
                    'balance_qty'     => $balanceQty,

                    'product'         => $productVal,
                    'fpack'           => $fpackVal,
                    'flen'            => $flenVal,
                    'code_packaging'  => $code,
                    'pack_name'       => $packName,
                    'length_mm'       => $lengthMm,

                    'workordernumber' => (string) ($r->workordernumber ?? ''),
                    'dateopen'        => $r->dateopen ?? null,
                    'reqdate'         => $r->reqdate ?? null,
                    'qty'             => $qty,

                    'kg_per_pack'     => $kgPerPack,
                    'packs_used'      => $packsUsed,
                    'demand_pcs'      => $demandPcs,
                    'stock_on_hand'   => $stockOnHand,
                    'balance_pcs'     => $balancePcs,
                    'shortage_pcs'    => $shortagePcs,
                    'coverage_pct'    => $coveragePct,
                    'status'          => $statusVal,
                    'risk_rank'       => $riskRank,
                    'mapped'          => true,
                ]);
            }
        }

        return $enriched;
    }

    private function buildGroupSummary(Collection $enriched): Collection
    {
        return $enriched
            ->groupBy(fn($x) => (string) ($x->code_packaging ?? '') . '||' . (string) ($x->source_site ?? ''))
            ->map(function ($items, $groupKey) {
                [$codeKey, $sourceSite] = array_pad(explode('||', $groupKey, 2), 2, null);

                $code       = trim((string) $codeKey) !== '' ? trim((string) $codeKey) : null;
                $sourceSite = trim((string) $sourceSite) !== '' ? trim((string) $sourceSite) : null;

                $byFpack = $items
                    ->groupBy(fn($x) => (string) ($x->fpack ?? ''))
                    ->map(function ($rows, $fpack) {
                        $sumKg = (float) $rows->sum('qty');

                        $demand = (int) $rows->sum(function ($r) {
                            $kg  = (float) ($r->qty ?? 0);
                            $kpp = (float) ($r->kg_per_pack ?? 0);
                            return $kpp > 0 ? (int) floor($kg / $kpp) : 0;
                        });

                        $kpp = collect($rows)
                            ->pluck('kg_per_pack')
                            ->filter(fn($v) => $v !== null && (float) $v > 0)
                            ->countBy()
                            ->sortDesc()
                            ->keys()
                            ->first();

                        return (object) [
                            'fpack'       => (string) $fpack,
                            'kg_per_pack' => $kpp !== null ? (float) $kpp : null,
                            'sum_qty'     => $sumKg,
                            'demand_pcs'  => $demand,
                            'count_wo'    => $rows->count(),
                            'items'       => $rows->values(),
                        ];
                    })
                    ->sortBy(fn($x) => (string) $x->fpack)
                    ->values();

                $sumKgAll = (float) $items->sum('qty');

                $demandAll = (int) $items->sum(function ($r) {
                    $kg  = (float) ($r->qty ?? 0);
                    $kpp = (float) ($r->kg_per_pack ?? 0);
                    return $kpp > 0 ? (int) floor($kg / $kpp) : 0;
                });

                $stockOnHand = optional($items->first())->stock_on_hand;
                $balanceAll  = $stockOnHand !== null ? ((float) $stockOnHand - $demandAll) : null;
                $shortagePcs = $stockOnHand !== null ? max(0, $demandAll - (float) $stockOnHand) : null;

                $coveragePct = null;
                if ($demandAll > 0 && $stockOnHand !== null) {
                    $coveragePct = ((float) $stockOnHand / $demandAll) * 100;
                } elseif ($demandAll === 0 && $stockOnHand !== null) {
                    $coveragePct = $stockOnHand > 0 ? 999999 : 0;
                }

                $statusVal = 'unknown';
                if ($stockOnHand === null) {
                    $statusVal = 'unknown';
                } elseif ($demandAll <= 0 && (float) $stockOnHand <= 0) {
                    $statusVal = 'idle';
                } elseif ($demandAll <= 0 && (float) $stockOnHand > 0) {
                    $statusVal = 'enough';
                } elseif ((float) $stockOnHand < $demandAll) {
                    $statusVal = 'shortage';
                } elseif ($coveragePct !== null && $coveragePct < 120) {
                    $statusVal = 'risk';
                } else {
                    $statusVal = 'enough';
                }

                $riskRank = match ($statusVal) {
                    'shortage' => 1,
                    'risk'     => 2,
                    'enough'   => 3,
                    'idle'     => 4,
                    default    => 5,
                };

                return (object) [
                    'code_packaging'    => $code,
                    'source_site'       => $sourceSite,
                    'pack_name'         => optional($items->first())->pack_name,
                    'length_mm'         => optional($items->first())->length_mm,
                    'stock_on_hand'     => $stockOnHand,
                    'balance_pcs'       => $balanceAll,
                    'shortage_pcs'      => $shortagePcs,
                    'coverage_pct'      => $coveragePct,
                    'status'            => $statusVal,
                    'risk_rank'         => $riskRank,
                    'product'           => optional($items->first())->product,
                    'sum_qty'           => $sumKgAll,
                    'demand_pcs'        => $demandAll,
                    'fpack_breakdowns'  => $byFpack,
                    'items'             => $items->values(),
                    'count_wo'          => $items->count(),
                    'mapped'            => $code !== null,
                    'sum_open_qty'      => (float) $items->sum('open_qty'),
                    'sum_produced_qty'  => (float) $items->sum('produced_qty'),
                    'sum_balance_qty'   => (float) $items->sum('balance_qty'),
                ];
            });
    }

    public function export(Request $request)
    {
        $request->validate([
            'date_type' => ['nullable', 'in:reqdate,opendate'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
        ]);

        $dateType = $request->query('date_type', 'reqdate');
        $dateFrom = $request->query('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->query('date_to', now()->endOfMonth()->toDateString());

        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            return back()
                ->withErrors(['date_to' => 'วันที่สิ้นสุดต้องมากกว่าหรือเท่ากับวันที่เริ่มต้น'])
                ->withInput();
        }

        $product = trim((string) $request->query('product', ''));
        $fpack   = trim((string) $request->query('fpack', ''));
        $status  = trim((string) $request->query('status', ''));
        $site    = trim((string) $request->query('site', ''));

        $productText  = $product ?: 'ทั้งหมด';
        $fpackText    = $fpack ?: 'ทั้งหมด';
        $siteText     = $site ?: 'ทั้งหมด';
        $statusText   = $status ?: 'ทั้งหมด';
        $dateTypeText = $dateType === 'opendate' ? 'วันที่เปิดเอกสาร' : 'วันกำหนดส่ง';

        $rows = $this->getMergedBaseRows($dateType, $dateFrom, $dateTo, $product, $fpack);

        $fpacks = $rows->pluck('fpack')->filter()->map(fn($x) => trim((string) $x))->unique()->values()->all();
        $products = $rows->pluck('fcat')->filter()->map(fn($x) => trim((string) $x))->unique()->values()->all();

        $masters = collect();
        if (!empty($fpacks) && !empty($products)) {
            $masters = DB::connection('sqlsrv_menam')
                ->table('packaging_master')
                ->select([
                    'product',
                    'standard_packaging',
                    'code_packaging',
                    'package_per_kg',
                ])
                ->where('active', 1)
                ->whereIn('standard_packaging', $fpacks)
                ->whereIn('product', $products)
                ->get()
                ->groupBy(fn($m) => trim((string) $m->product) . '||' . trim((string) $m->standard_packaging));
        }

        $codes = $masters
            ->flatten(1)
            ->pluck('code_packaging')
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '')
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->values()
            ->all();

        [$stockMap, $partMap] = $this->getPackagingPartMaps($codes);

        $enriched = $this->buildEnrichedRows($rows, $masters, $stockMap, $partMap);
        $groups   = $this->buildGroupSummary($enriched);

        if ($status !== '') {
            $groups = $groups->filter(fn($g) => (string) ($g->status ?? '') === $status);
        }

        if ($site !== '') {
            $groups = $groups->filter(
                fn($g) => strtoupper((string) ($g->source_site ?? '')) === strtoupper($site)
            );
        }

        $groups = $groups
            ->sort(function ($a, $b) {
                $cmp1 = ((int) ($a->risk_rank ?? 999)) <=> ((int) ($b->risk_rank ?? 999));
                if ($cmp1 !== 0) return $cmp1;

                $cmp2 = ((float) ($b->shortage_pcs ?? 0)) <=> ((float) ($a->shortage_pcs ?? 0));
                if ($cmp2 !== 0) return $cmp2;

                $covA = is_numeric($a->coverage_pct) ? (float) $a->coverage_pct : 999999;
                $covB = is_numeric($b->coverage_pct) ? (float) $b->coverage_pct : 999999;
                $cmp3 = $covA <=> $covB;
                if ($cmp3 !== 0) return $cmp3;

                $cmp4 = strcmp((string) ($a->code_packaging ?? 'ZZZZZZ'), (string) ($b->code_packaging ?? 'ZZZZZZ'));
                if ($cmp4 !== 0) return $cmp4;

                return strcmp((string) ($a->source_site ?? ''), (string) ($b->source_site ?? ''));
            })
            ->values();

        $enrichedForDetail = $enriched;

        if ($status !== '') {
            $enrichedForDetail = $enrichedForDetail->filter(
                fn($r) => (string) ($r->status ?? '') === $status
            );
        }

        if ($site !== '') {
            $enrichedForDetail = $enrichedForDetail->filter(
                fn($r) => strtoupper((string) ($r->source_site ?? '')) === strtoupper($site)
            );
        }

        $enrichedForDetail = $enrichedForDetail
            ->sort(function ($a, $b) {
                $cmp1 = ((int) ($a->risk_rank ?? 999)) <=> ((int) ($b->risk_rank ?? 999));
                if ($cmp1 !== 0) return $cmp1;

                $cmp2 = ((float) ($b->shortage_pcs ?? 0)) <=> ((float) ($a->shortage_pcs ?? 0));
                if ($cmp2 !== 0) return $cmp2;

                $cmp3 = strcmp((string) ($a->code_packaging ?? 'ZZZZZZ'), (string) ($b->code_packaging ?? 'ZZZZZZ'));
                if ($cmp3 !== 0) return $cmp3;

                $cmp4 = strcmp((string) ($a->source_site ?? ''), (string) ($b->source_site ?? ''));
                if ($cmp4 !== 0) return $cmp4;

                return strcmp((string) ($a->workordernumber ?? ''), (string) ($b->workordernumber ?? ''));
            })
            ->values();

        $exportRows = collect();

        foreach ($groups as $g) {
            $kppList = collect($g->fpack_breakdowns ?? [])
                ->map(function ($fp) {
                    $kpp = is_numeric($fp->kg_per_pack ?? null)
                        ? number_format((float) $fp->kg_per_pack, 4, '.', '')
                        : '-';

                    return ($fp->fpack ?? '-') . " ({$fp->demand_pcs}) / KG-Pack: {$kpp}";
                })
                ->implode(' ; ');

            $coverageText = is_numeric($g->coverage_pct ?? null)
                ? ((float) $g->coverage_pct >= 999999 ? '∞' : number_format((float) $g->coverage_pct, 2) . '%')
                : '-';

            $exportRows->push([
                'site'          => $g->source_site ?? '-',
                'code'          => $g->code_packaging ?? '-',
                'pack_name'     => $g->pack_name ?? '-',
                'product'       => $g->product ?? '-',
                'order_kg'      => (float) ($g->sum_qty ?? 0),
                'demand_pcs'    => (int) ($g->demand_pcs ?? 0),
                'stock_pcs'     => $g->stock_on_hand !== null ? (float) $g->stock_on_hand : null,
                'balance_pcs'   => $g->balance_pcs !== null ? (float) $g->balance_pcs : null,
                'shortage_pcs'  => $g->shortage_pcs !== null ? (float) $g->shortage_pcs : null,
                'coverage_pct'  => $coverageText,
                'status'        => $g->status ?? 'unknown',
                'stdpack_list'  => $kppList,
            ]);
        }

        $detailRows = collect();

        foreach ($enrichedForDetail as $r) {
            $coverageText = is_numeric($r->coverage_pct ?? null)
                ? ((float) $r->coverage_pct >= 999999 ? '∞' : number_format((float) $r->coverage_pct, 2) . '%')
                : '-';

            $detailRows->push([
                'site'           => $r->source_site ?? '-',
                'code'           => $r->code_packaging ?? '-',
                'pack_name'      => $r->pack_name ?? '-',
                'product'        => $r->product ?? '-',
                'stdpack'        => $r->fpack ?? '-',
                'flen'           => isset($r->flen) ? (float) $r->flen : null,
                'wo'             => $r->workordernumber ?? '-',
                'dateopen'       => $r->dateopen ?? '',
                'reqdate'        => $r->reqdate ?? '',
                'open_qty'       => (float) ($r->open_qty ?? 0),
                'produced_qty'   => (float) ($r->produced_qty ?? 0),
                'balance_qty'    => (float) ($r->balance_qty ?? 0),
                'kg_per_pack'    => $r->kg_per_pack !== null ? (float) $r->kg_per_pack : null,
                'demand_pcs'     => (int) ($r->demand_pcs ?? 0),
                'stock_pcs'      => $r->stock_on_hand !== null ? (float) $r->stock_on_hand : null,
                'balance_pcs'    => $r->balance_pcs !== null ? (float) $r->balance_pcs : null,
                'shortage_pcs'   => $r->shortage_pcs !== null ? (float) $r->shortage_pcs : null,
                'coverage_pct'   => $coverageText,
                'status'         => $r->status ?? 'unknown',
            ]);
        }

        $fileBase = "packaging_usage_{$dateType}_" . str_replace('-', '', $dateFrom) . '_' . str_replace('-', '', $dateTo);
        $isPhpSpreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);

        if ($isPhpSpreadsheet) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Summary');

            $sheet->setCellValue('A1', 'รายงานสรุปการใช้บรรจุภัณฑ์');
            $sheet->setCellValue('A2', "เงื่อนไขวันที่: {$dateTypeText} | ช่วงวันที่ {$dateFrom} ถึง {$dateTo}");
            $sheet->setCellValue('A3', "Filter: Product={$productText} | StdPack={$fpackText} | Site={$siteText} | Status={$statusText}");

            $sheet->mergeCells('A1:M1');
            $sheet->mergeCells('A2:M2');
            $sheet->mergeCells('A3:M3');

            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A2:A3')->getFont()->setSize(10);

            $headerRow = 5;
            $headers = [
                'No',
                'Site',
                'CODE บรรจุภัณฑ์',
                'ชื่อบรรจุภัณฑ์',
                'Product',
                'Order (KG)',
                'Demand (PCS)',
                'Stock (PCS)',
                'Balance (PCS)',
                'Shortage (PCS)',
                'Coverage %',
                'Status',
                'Standard Pack / KG-Pack',
            ];

            foreach ($headers as $i => $h) {
                $sheet->setCellValueByColumnAndRow($i + 1, $headerRow, $h);
            }

            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getAlignment()->setHorizontal('center');
            $sheet->getStyle("A{$headerRow}:M{$headerRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFF3E5AB');

            $r = $headerRow + 1;
            $no = 1;

            foreach ($exportRows as $row) {
                $sheet->setCellValueByColumnAndRow(1,  $r, $no++);
                $sheet->setCellValueByColumnAndRow(2,  $r, $row['site']);
                $sheet->setCellValueByColumnAndRow(3,  $r, $row['code']);
                $sheet->setCellValueByColumnAndRow(4,  $r, $row['pack_name']);
                $sheet->setCellValueByColumnAndRow(5,  $r, $row['product']);
                $sheet->setCellValueByColumnAndRow(6,  $r, $row['order_kg']);
                $sheet->setCellValueByColumnAndRow(7,  $r, $row['demand_pcs']);
                $sheet->setCellValueByColumnAndRow(8,  $r, $row['stock_pcs']);
                $sheet->setCellValueByColumnAndRow(9,  $r, $row['balance_pcs']);
                $sheet->setCellValueByColumnAndRow(10, $r, $row['shortage_pcs']);
                $sheet->setCellValueByColumnAndRow(11, $r, $row['coverage_pct']);
                $sheet->setCellValueByColumnAndRow(12, $r, $row['status']);
                $sheet->setCellValueByColumnAndRow(13, $r, $row['stdpack_list']);

                if (($row['status'] ?? '') === 'shortage') {
                    $sheet->getStyle("A{$r}:M{$r}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFE5E5');
                } elseif (($row['status'] ?? '') === 'risk') {
                    $sheet->getStyle("A{$r}:M{$r}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFF4E5');
                }

                $r++;
            }

            $lastRow = $r - 1;

            if ($lastRow >= $headerRow + 1) {
                $sheet->getStyle("F" . ($headerRow + 1) . ":F{$lastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0.00');

                $sheet->getStyle("G" . ($headerRow + 1) . ":J{$lastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0');
            }

            $sheet->getStyle("A{$headerRow}:M{$lastRow}")
                ->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            $sheet->freezePane("A" . ($headerRow + 1));

            foreach (range('A', 'M') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('WO Detail');

            $detailSheet->setCellValue('A1', 'รายงานรายละเอียดการใช้บรรจุภัณฑ์ (WO Detail)');
            $detailSheet->setCellValue('A2', "เงื่อนไขวันที่: {$dateTypeText} | ช่วงวันที่ {$dateFrom} ถึง {$dateTo}");
            $detailSheet->setCellValue('A3', "Filter: Product={$productText} | StdPack={$fpackText} | Site={$siteText} | Status={$statusText}");

            $detailSheet->mergeCells('A1:S1');
            $detailSheet->mergeCells('A2:S2');
            $detailSheet->mergeCells('A3:S3');

            $detailSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $detailSheet->getStyle('A2:A3')->getFont()->setSize(10);

            $detailHeaderRow = 5;
            $detailHeaders = [
                'No',
                'Site',
                'CODE บรรจุภัณฑ์',
                'ชื่อบรรจุภัณฑ์',
                'Product',
                'Standard Pack',
                'ความยาว',
                'Work Order',
                'Date Open',
                'Req Date',
                'เปิดผลิต',
                'ผลิตไปแล้ว',
                'ค้างผลิต',
                'KG/Pack',
                'Demand (PCS)',
                'Stock (PCS)',
                'Balance (PCS)',
                'Shortage (PCS)',
                'Coverage %',
            ];

            foreach ($detailHeaders as $i => $h) {
                $detailSheet->setCellValueByColumnAndRow($i + 1, $detailHeaderRow, $h);
            }

            $detailSheet->getStyle("A{$detailHeaderRow}:S{$detailHeaderRow}")->getFont()->setBold(true);
            $detailSheet->getStyle("A{$detailHeaderRow}:S{$detailHeaderRow}")->getAlignment()->setHorizontal('center');
            $detailSheet->getStyle("A{$detailHeaderRow}:S{$detailHeaderRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFDDEBF7');

            $dr = $detailHeaderRow + 1;
            $dno = 1;

            foreach ($detailRows as $row) {
                $detailSheet->setCellValueByColumnAndRow(1,  $dr, $dno++);
                $detailSheet->setCellValueByColumnAndRow(2,  $dr, $row['site']);
                $detailSheet->setCellValueByColumnAndRow(3,  $dr, $row['code']);
                $detailSheet->setCellValueByColumnAndRow(4,  $dr, $row['pack_name']);
                $detailSheet->setCellValueByColumnAndRow(5,  $dr, $row['product']);
                $detailSheet->setCellValueByColumnAndRow(6,  $dr, $row['stdpack']);
                $detailSheet->setCellValueByColumnAndRow(7,  $dr, $row['flen']);
                $detailSheet->setCellValueByColumnAndRow(8,  $dr, $row['wo']);
                $detailSheet->setCellValueByColumnAndRow(9,  $dr, $row['dateopen']);
                $detailSheet->setCellValueByColumnAndRow(10, $dr, $row['reqdate']);
                $detailSheet->setCellValueByColumnAndRow(11, $dr, $row['open_qty']);
                $detailSheet->setCellValueByColumnAndRow(12, $dr, $row['produced_qty']);
                $detailSheet->setCellValueByColumnAndRow(13, $dr, $row['balance_qty']);
                $detailSheet->setCellValueByColumnAndRow(14, $dr, $row['kg_per_pack']);
                $detailSheet->setCellValueByColumnAndRow(15, $dr, $row['demand_pcs']);
                $detailSheet->setCellValueByColumnAndRow(16, $dr, $row['stock_pcs']);
                $detailSheet->setCellValueByColumnAndRow(17, $dr, $row['balance_pcs']);
                $detailSheet->setCellValueByColumnAndRow(18, $dr, $row['shortage_pcs']);
                $detailSheet->setCellValueByColumnAndRow(19, $dr, $row['coverage_pct']);

                $dr++;
            }

            $detailLastRow = $dr - 1;

            if ($detailLastRow >= $detailHeaderRow + 1) {
                $detailSheet->getStyle("G" . ($detailHeaderRow + 1) . ":N{$detailLastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0.00');

                $detailSheet->getStyle("O" . ($detailHeaderRow + 1) . ":R{$detailLastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0');
            }

            $detailSheet->getStyle("A{$detailHeaderRow}:S{$detailLastRow}")
                ->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            $detailSheet->freezePane("A" . ($detailHeaderRow + 1));

            foreach (range('A', 'S') as $col) {
                $detailSheet->getColumnDimension($col)->setAutoSize(true);
            }

            $spreadsheet->setActiveSheetIndex(0);

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
            $writer->save($tmp);

            return response()->download($tmp, "{$fileBase}.xlsx")->deleteFileAfterSend(true);
        }

        $headers = [
            'No',
            'Site',
            'CODE บรรจุภัณฑ์',
            'ชื่อบรรจุภัณฑ์',
            'Product',
            'Order (KG)',
            'Demand (PCS)',
            'Stock (PCS)',
            'Balance (PCS)',
            'Shortage (PCS)',
            'Coverage %',
            'Status',
            'Standard Pack / KG-Pack',
        ];

        $callback = function () use ($exportRows, $headers, $dateTypeText, $dateFrom, $dateTo, $productText, $fpackText, $siteText, $statusText) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($out, ['รายงานสรุปการใช้บรรจุภัณฑ์']);
            fputcsv($out, ["เงื่อนไขวันที่: {$dateTypeText} | ช่วงวันที่ {$dateFrom} ถึง {$dateTo}"]);
            fputcsv($out, ["Filter: Product={$productText} | StdPack={$fpackText} | Site={$siteText} | Status={$statusText}"]);
            fputcsv($out, []);
            fputcsv($out, $headers);

            $no = 1;
            foreach ($exportRows as $row) {
                fputcsv($out, [
                    $no++,
                    $row['site'],
                    $row['code'],
                    $row['pack_name'],
                    $row['product'],
                    $row['order_kg'],
                    $row['demand_pcs'],
                    $row['stock_pcs'],
                    $row['balance_pcs'],
                    $row['shortage_pcs'],
                    $row['coverage_pct'],
                    $row['status'],
                    $row['stdpack_list'],
                ]);
            }

            fclose($out);
        };

        return response()->streamDownload($callback, "{$fileBase}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function resolveSpecialPackagingCode(string $fpack, $flen): ?string
    {
        $fpack = trim($fpack);
        $flen  = (int) round((float) $flen);

        if ($fpack === 'PS-005/BL-016') {
            return match ($flen) {
                300 => 'PB-003',
                350 => 'PB-004',
                250 => 'PB-005',
                default => null,
            };
        }

        return null;
    }

    private function getGoodQtyMapFromErpConnection(string $connectionName, array $workorderIds): Collection
    {
        if (empty($workorderIds)) {
            return collect();
        }

        return DB::connection($connectionName)
            ->table('workorderreceive as wor')
            ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
            ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
            ->whereIn('wor.workorder_id', $workorderIds)
            ->selectRaw("
            wor.workorder_id,
            ROUND(
                COALESCE(SUM(
                    CASE
                        WHEN COALESCE(pt2.id, 0) NOT IN (69, 70, 71, 95, 61, 62, 91, 0, 56)
                        THEN wor.qty
                        ELSE 0
                    END
                ), 0)::numeric,
            2) AS good_qty
        ")
            ->groupBy('wor.workorder_id')
            ->pluck('good_qty', 'workorder_id');
    }
}
