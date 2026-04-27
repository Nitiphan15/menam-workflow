<?php

namespace App\Services\FormPkg;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackagingUsageService
{
    public function getIndexData(array $filters): array
    {
        $dataset = $this->buildDataset($filters, true);
        $groups = $this->sortGroups($this->applyGroupFilters($dataset['groups'], $filters));
        $productTotals = $this->buildProductTotals($groups);

        $coverageAvg = $groups
            ->filter(fn($x) => (float) ($x->demand_pcs ?? 0) > 0 && is_numeric($x->coverage_pct))
            ->whenNotEmpty(fn($c) => $c->avg('coverage_pct'));

        $riskCount = $groups
            ->filter(fn($x) => in_array(($x->status ?? ''), ['shortage', 'risk'], true))
            ->count();

        $shortageCount = $groups
            ->filter(fn($x) => (float) ($x->shortage_pcs ?? 0) > 0)
            ->count();

        return [
            'dateType'       => $filters['date_type'],
            'dateFrom'       => $filters['date_from'],
            'dateTo'         => $filters['date_to'],
            'product'        => $filters['product'],
            'fpack'          => $filters['fpack'],
            'status'         => $filters['status'],
            'site'           => $filters['site'],
            'productOptions' => $dataset['productOptions'],
            'fpackOptions'   => $dataset['fpackOptions'],
            'statusOptions'  => $this->statusOptions(),
            'siteOptions'    => $this->siteOptions(),
            'groups'         => $groups,
            'productTotals'  => $productTotals,
            'coverageAvg'    => $coverageAvg,
            'riskCount'      => $riskCount,
            'shortageCount'  => $shortageCount,
        ];
    }

    public function buildExportResponse(array $filters)
    {
        $dataset = $this->buildDataset($filters, false);
        $groups = $this->sortGroups($this->applyGroupFilters($dataset['groups'], $filters));
        $enrichedForDetail = $this->sortDetailRows($this->applyRowFilters($dataset['enriched'], $filters));

        $productText = $filters['product'] !== '' ? $filters['product'] : 'ALL';
        $fpackText = $filters['fpack'] !== '' ? $filters['fpack'] : 'ALL';
        $siteText = $filters['site'] !== '' ? $filters['site'] : 'ALL';
        $statusText = $filters['status'] !== '' ? $filters['status'] : 'ALL';
        $dateTypeText = $filters['date_type'] === 'opendate' ? 'Open Date' : 'Req Date';

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
                ? ((float) $g->coverage_pct >= 999999 ? 'INF' : number_format((float) $g->coverage_pct, 2) . '%')
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
                'wo_count'      => (int) ($g->count_wo ?? 0),
            ]);
        }

        $detailRows = collect();
        foreach ($enrichedForDetail as $r) {
            $coverageText = is_numeric($r->coverage_pct ?? null)
                ? ((float) $r->coverage_pct >= 999999 ? 'INF' : number_format((float) $r->coverage_pct, 2) . '%')
                : '-';

            $detailRows->push([
                'site'          => $r->source_site ?? '-',
                'code'          => $r->code_packaging ?? '-',
                'pack_name'     => $r->pack_name ?? '-',
                'product'       => $r->product ?? '-',
                'stdpack'       => $r->fpack ?? '-',
                'wo'            => $r->workordernumber ?? '-',
                'dateopen'      => $r->dateopen ?? '',
                'reqdate'       => $r->reqdate ?? '',
                'qty_raw'       => (float) ($r->qty_raw ?? 0),
                'part_onhand'   => (float) ($r->part_onhand ?? 0),
                'order_kg'      => (float) ($r->qty ?? 0),
                'kg_per_pack'   => $r->kg_per_pack !== null ? (float) $r->kg_per_pack : null,
                'demand_pcs'    => (int) ($r->demand_pcs ?? 0),
                'stock_pcs'     => $r->stock_on_hand !== null ? (float) $r->stock_on_hand : null,
                'balance_pcs'   => $r->balance_pcs !== null ? (float) $r->balance_pcs : null,
                'shortage_pcs'  => $r->shortage_pcs !== null ? (float) $r->shortage_pcs : null,
                'coverage_pct'  => $coverageText,
                'status'        => $r->status ?? 'unknown',
                'usage_rm_qty'  => (float) ($r->usage_rm_qty ?? 0),
                'received_qty'  => (float) ($r->received_qty ?? 0),
                'balance_qty'   => (float) ($r->balance_qty ?? 0),
            ]);
        }

        $fileBase = 'packaging_usage_' . $filters['date_type'] . '_' . str_replace('-', '', $filters['date_from']) . '_' . str_replace('-', '', $filters['date_to']);
        $isPhpSpreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);

        if ($isPhpSpreadsheet) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Summary');
            $sheet->setCellValue('A1', 'Packaging Usage Summary');
            $sheet->setCellValue('A2', "Date Type: {$dateTypeText} | Date Range: {$filters['date_from']} to {$filters['date_to']}");
            $sheet->setCellValue('A3', "Filter: Product={$productText} | StdPack={$fpackText} | Site={$siteText} | Status={$statusText}");
            $sheet->mergeCells('A1:N1');
            $sheet->mergeCells('A2:N2');
            $sheet->mergeCells('A3:N3');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A2:A3')->getFont()->setSize(10);

            $headerRow = 5;
            $headers = [
                'No',
                'Site',
                'Code',
                'Packaging',
                'Product',
                'Order (KG)',
                'Demand (PCS)',
                'Stock (PCS)',
                'Balance (PCS)',
                'Shortage (PCS)',
                'Coverage %',
                'Status',
                'Std Pack / KG-Pack',
                'WO Count',
            ];

            foreach ($headers as $i => $header) {
                $sheet->setCellValueByColumnAndRow($i + 1, $headerRow, $header);
            }

            $sheet->getStyle("A{$headerRow}:N{$headerRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$headerRow}:N{$headerRow}")->getAlignment()->setHorizontal('center');

            $rowNumber = $headerRow + 1;
            $no = 1;
            foreach ($exportRows as $row) {
                $sheet->fromArray([
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
                    $row['wo_count'],
                ], null, "A{$rowNumber}");

                if (($row['status'] ?? '') === 'shortage') {
                    $sheet->getStyle("A{$rowNumber}:N{$rowNumber}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFE5E5');
                } elseif (($row['status'] ?? '') === 'risk') {
                    $sheet->getStyle("A{$rowNumber}:N{$rowNumber}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFF4E5');
                }

                $rowNumber++;
            }

            $lastRow = max($rowNumber - 1, $headerRow);
            $sheet->getStyle("A{$headerRow}:N{$lastRow}")
                ->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->freezePane('A6');

            foreach (range('A', 'N') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $detailSheet = $spreadsheet->createSheet();
            $detailSheet->setTitle('WO Detail');
            $detailSheet->setCellValue('A1', 'Packaging Usage WO Detail');
            $detailSheet->setCellValue('A2', "Date Type: {$dateTypeText} | Date Range: {$filters['date_from']} to {$filters['date_to']}");
            $detailSheet->setCellValue('A3', "Filter: Product={$productText} | StdPack={$fpackText} | Site={$siteText} | Status={$statusText}");
            $detailSheet->mergeCells('A1:V1');
            $detailSheet->mergeCells('A2:V2');
            $detailSheet->mergeCells('A3:V3');
            $detailSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $detailSheet->getStyle('A2:A3')->getFont()->setSize(10);

            $detailHeaderRow = 5;
            $detailHeaders = [
                'No',
                'Site',
                'Code',
                'Packaging',
                'Product',
                'Standard Pack',
                'Work Order',
                'Date Open',
                'Req Date',
                'WO Qty Raw',
                'Part Onhand',
                'Order (KG)',
                'KG/Pack',
                'Demand (PCS)',
                'Stock (PCS)',
                'Balance (PCS)',
                'Shortage (PCS)',
                'Coverage %',
                'Status',
                'Usage RM Qty',
                'Received Qty',
                'Balance Qty',
            ];

            foreach ($detailHeaders as $i => $header) {
                $detailSheet->setCellValueByColumnAndRow($i + 1, $detailHeaderRow, $header);
            }

            $detailSheet->getStyle("A{$detailHeaderRow}:V{$detailHeaderRow}")->getFont()->setBold(true);
            $detailSheet->getStyle("A{$detailHeaderRow}:V{$detailHeaderRow}")->getAlignment()->setHorizontal('center');

            $detailRowNumber = $detailHeaderRow + 1;
            $detailNo = 1;
            foreach ($detailRows as $row) {
                $detailSheet->fromArray([
                    $detailNo++,
                    $row['site'],
                    $row['code'],
                    $row['pack_name'],
                    $row['product'],
                    $row['stdpack'],
                    $row['wo'],
                    $row['dateopen'],
                    $row['reqdate'],
                    $row['qty_raw'],
                    $row['part_onhand'],
                    $row['order_kg'],
                    $row['kg_per_pack'],
                    $row['demand_pcs'],
                    $row['stock_pcs'],
                    $row['balance_pcs'],
                    $row['shortage_pcs'],
                    $row['coverage_pct'],
                    $row['status'],
                    $row['usage_rm_qty'],
                    $row['received_qty'],
                    $row['balance_qty'],
                ], null, "A{$detailRowNumber}");

                if (($row['status'] ?? '') === 'shortage') {
                    $detailSheet->getStyle("A{$detailRowNumber}:V{$detailRowNumber}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFE5E5');
                } elseif (($row['status'] ?? '') === 'risk') {
                    $detailSheet->getStyle("A{$detailRowNumber}:V{$detailRowNumber}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFF4E5');
                }

                $detailRowNumber++;
            }

            $detailLastRow = max($detailRowNumber - 1, $detailHeaderRow);
            $detailSheet->getStyle("A{$detailHeaderRow}:V{$detailLastRow}")
                ->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $detailSheet->freezePane('A6');

            foreach (range('A', 'V') as $col) {
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
            'Code',
            'Packaging',
            'Product',
            'Order (KG)',
            'Demand (PCS)',
            'Stock (PCS)',
            'Balance (PCS)',
            'Shortage (PCS)',
            'Coverage %',
            'Status',
            'Std Pack / KG-Pack',
            'WO Count',
        ];

        $callback = function () use ($exportRows, $headers, $dateTypeText, $filters, $productText, $fpackText, $siteText, $statusText) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($out, ['Packaging Usage Summary']);
            fputcsv($out, ["Date Type: {$dateTypeText} | Date Range: {$filters['date_from']} to {$filters['date_to']}"]);
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
                    $row['wo_count'],
                ]);
            }

            fclose($out);
        };

        return response()->streamDownload($callback, "{$fileBase}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function buildDataset(array $filters, bool $includeOptions): array
    {
        $rows = $this->getMergedBaseRows(
            $filters['date_type'],
            $filters['date_from'],
            $filters['date_to'],
            $filters['product'],
            $filters['fpack']
        );

        [$wireOnhandMap, $plusOnhandMap] = $this->getPartOnhandMapsFromRows($rows);

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
        $enriched = $this->buildEnrichedRows($rows, $masters, $stockMap, $partMap, $wireOnhandMap, $plusOnhandMap);
        [$productOptions, $fpackOptions] = $includeOptions
            ? $this->getMergedDropdownOptions($filters['date_type'], $filters['date_from'], $filters['date_to'])
            : [collect(), collect()];

        return [
            'rows'           => $rows,
            'enriched'       => $enriched,
            'groups'         => $this->buildGroupSummary($enriched),
            'productOptions' => $productOptions,
            'fpackOptions'   => $fpackOptions,
        ];
    }

    private function siteOptions(): Collection
    {
        return collect([
            ['value' => '', 'label' => 'All Sites'],
            ['value' => 'WIRE', 'label' => 'WIRE'],
            ['value' => 'PLUS', 'label' => 'PLUS'],
        ]);
    }

    private function statusOptions(): Collection
    {
        return collect([
            ['value' => '', 'label' => 'All Statuses'],
            ['value' => 'shortage', 'label' => 'Shortage'],
            ['value' => 'risk', 'label' => 'Risk'],
            ['value' => 'enough', 'label' => 'Enough'],
            ['value' => 'idle', 'label' => 'Idle'],
            ['value' => 'unknown', 'label' => 'Unknown'],
        ]);
    }

    private function applyGroupFilters(Collection $groups, array $filters): Collection
    {
        if ($filters['status'] !== '') {
            $groups = $groups->filter(fn($g) => (string) ($g->status ?? '') === $filters['status']);
        }

        if ($filters['site'] !== '') {
            $groups = $groups->filter(
                fn($g) => strtoupper((string) ($g->source_site ?? '')) === strtoupper($filters['site'])
            );
        }

        return $groups->values();
    }

    private function applyRowFilters(Collection $rows, array $filters): Collection
    {
        if ($filters['status'] !== '') {
            $rows = $rows->filter(fn($r) => (string) ($r->status ?? '') === $filters['status']);
        }

        if ($filters['site'] !== '') {
            $rows = $rows->filter(
                fn($r) => strtoupper((string) ($r->source_site ?? '')) === strtoupper($filters['site'])
            );
        }

        return $rows->values();
    }

    private function sortGroups(Collection $groups): Collection
    {
        return $groups
            ->sort(function ($a, $b) {
                $cmp1 = ((int) ($a->risk_rank ?? 999)) <=> ((int) ($b->risk_rank ?? 999));
                if ($cmp1 !== 0) {
                    return $cmp1;
                }

                $cmp2 = ((float) ($b->shortage_pcs ?? 0)) <=> ((float) ($a->shortage_pcs ?? 0));
                if ($cmp2 !== 0) {
                    return $cmp2;
                }

                $covA = is_numeric($a->coverage_pct) ? (float) $a->coverage_pct : 999999;
                $covB = is_numeric($b->coverage_pct) ? (float) $b->coverage_pct : 999999;
                $cmp3 = $covA <=> $covB;
                if ($cmp3 !== 0) {
                    return $cmp3;
                }

                $cmp4 = strcmp((string) ($a->code_packaging ?? 'ZZZZZZ'), (string) ($b->code_packaging ?? 'ZZZZZZ'));
                if ($cmp4 !== 0) {
                    return $cmp4;
                }

                return strcmp((string) ($a->source_site ?? ''), (string) ($b->source_site ?? ''));
            })
            ->values();
    }

    private function sortDetailRows(Collection $rows): Collection
    {
        return $rows
            ->sort(function ($a, $b) {
                $cmp1 = ((int) ($a->risk_rank ?? 999)) <=> ((int) ($b->risk_rank ?? 999));
                if ($cmp1 !== 0) {
                    return $cmp1;
                }

                $cmp2 = ((float) ($b->shortage_pcs ?? 0)) <=> ((float) ($a->shortage_pcs ?? 0));
                if ($cmp2 !== 0) {
                    return $cmp2;
                }

                $cmp3 = strcmp((string) ($a->code_packaging ?? 'ZZZZZZ'), (string) ($b->code_packaging ?? 'ZZZZZZ'));
                if ($cmp3 !== 0) {
                    return $cmp3;
                }

                $cmp4 = strcmp((string) ($a->source_site ?? ''), (string) ($b->source_site ?? ''));
                if ($cmp4 !== 0) {
                    return $cmp4;
                }

                return strcmp((string) ($a->workordernumber ?? ''), (string) ($b->workordernumber ?? ''));
            })
            ->values();
    }

    private function buildProductTotals(Collection $groups): Collection
    {
        return $groups
            ->groupBy(fn($x) => (string) ($x->product ?? '') . '||' . (string) ($x->source_site ?? ''))
            ->map(function ($items, $key) {
                [$productName, $siteName] = array_pad(explode('||', $key, 2), 2, '');

                return (object) [
                    'product'         => $productName,
                    'source_site'     => $siteName,
                    'code_count'      => $items->count(),
                    'sum_qty'         => (float) $items->sum('sum_qty'),
                    'sum_demand_pcs'  => (float) $items->sum('demand_pcs'),
                    'sum_shortage_pcs' => (float) $items->sum(fn($x) => (float) ($x->shortage_pcs ?? 0)),
                    'sum_count_wo'    => (int) $items->sum('count_wo'),
                ];
            })
            ->sortBy(fn($x) => $x->product . '|' . $x->source_site)
            ->values();
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
        $conn = DB::connection($mfgConnectionName);
        $site = $mfgConnectionName === 'pgsqlmfgw' ? 'WIRE' : 'PLUS';

        $dateColumn = $dateType === 'opendate' ? 'w.dateopen' : 'w.reqdate';

        $usageRm = $conn->table('workorderusage as wu')
            ->selectRaw('wu.workorder_id, SUM(wu.qty) AS qty')
            ->where('wu.workcenter_id', 1780619)
            ->groupBy('wu.workorder_id');

        $worSummary = $conn->table('workorderreceive as wor')
            ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
            ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
            ->selectRaw("
                wor.workorder_id,
                ROUND(COALESCE(SUM(CASE WHEN pt2.id IN (61, 62) THEN wor.qty ELSE 0 END), 0)::numeric, 2) AS defect_qty,
                ROUND(COALESCE(SUM(CASE WHEN pt2.id IN (69, 70, 71, 95, 91) THEN wor.qty ELSE 0 END), 0)::numeric, 2) AS return_rm_qty,
                ROUND(
                    COALESCE(SUM(
                        CASE
                            WHEN pt2.id NOT IN (69, 70, 71, 95, 61, 62, 91, 0, 56)
                            THEN wor.qty
                            ELSE 0
                        END
                    ), 0)::numeric,
                    2
                ) AS good_qty
            ")
            ->groupBy('wor.workorder_id');

        $rows = $conn->table('workorder as w')
            ->leftJoin('customer as c', 'w.customer_id', '=', 'c.id')
            ->leftJoinSub($usageRm, 'ur', function ($join) {
                $join->on('ur.workorder_id', '=', 'w.id');
            })
            ->leftJoinSub($worSummary, 'ws', function ($join) {
                $join->on('ws.workorder_id', '=', 'w.id');
            })
            ->selectRaw("
                w.id AS workorder_id,
                w.parts_id,
                w.dateopen,
                w.reqdate,
                w.workordernumber,
                w.qty,
                w.received,
                w.fpack,
                w.fcat,
                ROUND(w.qty::numeric, 2) AS order_qty,
                ROUND(COALESCE(ur.qty, 0)::numeric, 2) AS usage_rm_qty,
                COALESCE(ws.defect_qty, 0) AS defect_qty,
                COALESCE(ws.return_rm_qty, 0) AS return_rm_qty,
                COALESCE(ws.good_qty, 0) AS good_qty,
                ROUND((COALESCE(ur.qty, 0) - COALESCE(w.received, 0))::numeric, 2) AS balance_qty,
                ROUND(COALESCE(w.received, 0)::numeric, 2) AS received_qty,
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

        $partsIds = $rows->pluck('parts_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $partMap = $this->getPartMapFromErpConnection($partConnectionName, $partsIds);

        return $rows->map(function ($row) use ($partMap) {
            $part = $partMap->get($row->parts_id);
            $row->partnumber = $part->partnumber ?? null;
            $row->description = $part->description ?? null;
            $row->unit = $part->unit ?? null;
            $row->fg_qty = isset($part->fg_qty) ? (float) $part->fg_qty : 0.0;

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
        $queryFpackW = DB::connection('pgsqlmfgw')->table('workorder');
        $queryFpackP = DB::connection('pgsqlmfgp')->table('workorder');

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

        $productOptions = $queryProductsW
            ->whereNotNull('fcat')
            ->where('fcat', '<>', '')
            ->distinct()
            ->pluck('fcat')
            ->merge(
                $queryProductsP->whereNotNull('fcat')
                    ->where('fcat', '<>', '')
                    ->distinct()
                    ->pluck('fcat')
            )
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '')
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->sort()
            ->values();

        $fpackOptions = $queryFpackW
            ->whereNotNull('fpack')
            ->where('fpack', '<>', '')
            ->distinct()
            ->pluck('fpack')
            ->merge(
                $queryFpackP->whereNotNull('fpack')
                    ->where('fpack', '<>', '')
                    ->distinct()
                    ->pluck('fpack')
            )
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '')
            ->map(fn($x) => trim((string) $x))
            ->unique()
            ->sort()
            ->values();

        return [$productOptions, $fpackOptions];
    }

    private function getPartOnhandMapsFromRows(Collection $rows): array
    {
        $wirePartIds = $rows
            ->filter(fn($r) => strtoupper((string) ($r->source_site ?? '')) === 'WIRE')
            ->pluck('parts_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $plusPartIds = $rows
            ->filter(fn($r) => strtoupper((string) ($r->source_site ?? '')) === 'PLUS')
            ->pluck('parts_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $wireMap = collect();
        $plusMap = collect();

        if (!empty($wirePartIds)) {
            $wireMap = DB::connection('pgsqlw')
                ->table('parts')
                ->whereIn('id', $wirePartIds)
                ->pluck('onhand', 'id');
        }

        if (!empty($plusPartIds)) {
            $plusMap = DB::connection('pgsqlp')
                ->table('parts')
                ->whereIn('id', $plusPartIds)
                ->pluck('onhand', 'id');
        }

        return [$wireMap, $plusMap];
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
                    'length_mm' => $row->f2 ?? null,
                ],
            ];
        });

        return [$stockMap, $partMap];
    }

    private function buildEnrichedRows(
        Collection $rows,
        Collection $masters,
        Collection $stockMap,
        Collection $partMap,
        Collection $wireOnhandMap,
        Collection $plusOnhandMap
    ): Collection {
        $enriched = collect();

        foreach ($rows as $r) {
            $productVal = trim((string) ($r->fcat ?? ''));
            $fpackVal = trim((string) ($r->fpack ?? ''));
            $rowSite = strtoupper(trim((string) ($r->source_site ?? '')));
            $partsId = $r->parts_id ?? null;
            $rawQty = (float) ($r->balance_qty ?? 0);

            $partOnhand = 0.0;
            if ($partsId) {
                if ($rowSite === 'WIRE') {
                    $partOnhand = (float) ($wireOnhandMap[$partsId] ?? 0);
                } elseif ($rowSite === 'PLUS') {
                    $partOnhand = (float) ($plusOnhandMap[$partsId] ?? 0);
                }
            }

            $qty = max($rawQty - $partOnhand, 0);

            $key = $productVal . '||' . $fpackVal;
            $mList = collect($masters->get($key, []));

            if ($mList->isEmpty()) {
                $enriched->push((object) [
                    'source_site'     => $rowSite,
                    'source_conn'     => $r->source_conn ?? null,
                    'parts_id'        => $partsId,
                    'qty_raw'         => $rawQty,
                    'part_onhand'     => $partOnhand,
                    'product'         => $productVal,
                    'fpack'           => $fpackVal,
                    'code_packaging'  => null,
                    'pack_name'       => null,
                    'length_mm'       => null,
                    'workordernumber' => (string) ($r->workordernumber ?? ''),
                    'dateopen'        => $r->dateopen ?? null,
                    'reqdate'         => $r->reqdate ?? null,
                    'qty'             => $qty,
                    'usage_rm_qty'    => (float) ($r->usage_rm_qty ?? 0),
                    'defect_qty'      => (float) ($r->defect_qty ?? 0),
                    'return_rm_qty'   => (float) ($r->return_rm_qty ?? 0),
                    'good_qty'        => (float) ($r->good_qty ?? 0),
                    'balance_qty'     => (float) ($r->balance_qty ?? 0),
                    'received_qty'    => (float) ($r->received_qty ?? 0),
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
                $kgPerPack = $m->package_per_kg !== null ? (float) $m->package_per_kg : null;
                $stockOnHand = $code ? (float) ($stockMap[$code] ?? 0) : null;
                $partInfo = $code ? $partMap->get($code) : null;
                $packName = $partInfo['description'] ?? null;
                $lengthMm = $partInfo['length_mm'] ?? null;
                $demandPcs = ($kgPerPack && $kgPerPack > 0) ? (int) floor($qty / $kgPerPack) : 0;
                $balancePcs = $stockOnHand !== null ? ($stockOnHand - $demandPcs) : null;
                $shortagePcs = $stockOnHand !== null ? max(0, $demandPcs - (float) $stockOnHand) : null;

                $coveragePct = null;
                if ($demandPcs > 0 && $stockOnHand !== null) {
                    $coveragePct = ((float) $stockOnHand / $demandPcs) * 100;
                } elseif ($demandPcs === 0 && $stockOnHand !== null) {
                    $coveragePct = $stockOnHand > 0 ? 999999 : 0;
                }

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
                    'risk' => 2,
                    'enough' => 3,
                    'idle' => 4,
                    default => 5,
                };

                $packsUsed = ($kgPerPack && $kgPerPack > 0) ? ($qty / $kgPerPack) : null;

                $enriched->push((object) [
                    'source_site'     => $rowSite,
                    'source_conn'     => $r->source_conn ?? null,
                    'parts_id'        => $partsId,
                    'qty_raw'         => $rawQty,
                    'part_onhand'     => $partOnhand,
                    'product'         => $productVal,
                    'fpack'           => $fpackVal,
                    'code_packaging'  => $code,
                    'pack_name'       => $packName,
                    'length_mm'       => $lengthMm,
                    'workordernumber' => (string) ($r->workordernumber ?? ''),
                    'dateopen'        => $r->dateopen ?? null,
                    'reqdate'         => $r->reqdate ?? null,
                    'qty'             => $qty,
                    'usage_rm_qty'    => (float) ($r->usage_rm_qty ?? 0),
                    'defect_qty'      => (float) ($r->defect_qty ?? 0),
                    'return_rm_qty'   => (float) ($r->return_rm_qty ?? 0),
                    'good_qty'        => (float) ($r->good_qty ?? 0),
                    'balance_qty'     => (float) ($r->balance_qty ?? 0),
                    'received_qty'    => (float) ($r->received_qty ?? 0),
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

                $code = trim((string) $codeKey) !== '' ? trim((string) $codeKey) : null;
                $sourceSite = trim((string) $sourceSite) !== '' ? trim((string) $sourceSite) : null;

                $byFpack = $items
                    ->groupBy(fn($x) => (string) ($x->fpack ?? ''))
                    ->map(function ($rows, $fpack) {
                        $sumKg = (float) $rows->sum('qty');
                        $demand = (int) $rows->sum(function ($r) {
                            $kg = (float) ($r->qty ?? 0);
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
                            'fpack' => (string) $fpack,
                            'kg_per_pack' => $kpp !== null ? (float) $kpp : null,
                            'sum_qty' => $sumKg,
                            'demand_pcs' => $demand,
                            'count_wo' => $rows->count(),
                        ];
                    })
                    ->sortBy(fn($x) => (string) $x->fpack)
                    ->values();

                $sumKgAll = (float) $items->sum('qty');
                $demandAll = (int) $items->sum(function ($r) {
                    $kg = (float) ($r->qty ?? 0);
                    $kpp = (float) ($r->kg_per_pack ?? 0);

                    return $kpp > 0 ? (int) floor($kg / $kpp) : 0;
                });

                $stockOnHand = optional($items->first())->stock_on_hand;
                $balanceAll = $stockOnHand !== null ? ((float) $stockOnHand - $demandAll) : null;
                $shortagePcs = $stockOnHand !== null ? max(0, $demandAll - (float) $stockOnHand) : null;

                $coveragePct = null;
                if ($demandAll > 0 && $stockOnHand !== null) {
                    $coveragePct = ((float) $stockOnHand / $demandAll) * 100;
                } elseif ($demandAll === 0 && $stockOnHand !== null) {
                    $coveragePct = $stockOnHand > 0 ? 999999 : 0;
                }

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
                    'risk' => 2,
                    'enough' => 3,
                    'idle' => 4,
                    default => 5,
                };

                return (object) [
                    'code_packaging'   => $code,
                    'source_site'      => $sourceSite,
                    'pack_name'        => optional($items->first())->pack_name,
                    'length_mm'        => optional($items->first())->length_mm,
                    'stock_on_hand'    => $stockOnHand,
                    'balance_pcs'      => $balanceAll,
                    'shortage_pcs'     => $shortagePcs,
                    'coverage_pct'     => $coveragePct,
                    'status'           => $statusVal,
                    'risk_rank'        => $riskRank,
                    'product'          => optional($items->first())->product,
                    'sum_qty'          => $sumKgAll,
                    'demand_pcs'       => $demandAll,
                    'fpack_breakdowns' => $byFpack,
                    'items'            => $items->values(),
                    'count_wo'         => $items->count(),
                    'mapped'           => $code !== null,
                ];
            })
            ->values();
    }
}
