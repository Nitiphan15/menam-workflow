<?php

namespace App\Services\FormPkg;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackagingUsageService
{
    public function getAnalysisData(array $filters): array
    {
        $filters = $this->normalizeAnalysisFilters($filters);
        $baseFilters = $this->baseFiltersForRange($filters, $filters['date_from'], $filters['date_to']);
        $dataset = $this->buildDataset($baseFilters, true);
        $rows = $this->applyAnalysisRowFilters($dataset['enriched'], $filters);

        $currentPeriodStart = Carbon::parse($filters['date_from'])->startOfDay();
        $currentPeriodEnd = Carbon::parse($filters['date_to'])->endOfDay();
        $previousPeriodMonthStart = $currentPeriodStart->copy()->subMonthNoOverflow();
        $previousPeriodMonthEnd = $currentPeriodEnd->copy()->subMonthNoOverflow();
        $previousPeriodYearStart = $currentPeriodStart->copy()->subYear();
        $previousPeriodYearEnd = $currentPeriodEnd->copy()->subYear();

        $monthStart = Carbon::create((int) $filters['year'], (int) $filters['month'], 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $previousMonthStart = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $previousMonthStart->copy()->endOfMonth();
        $yearStart = Carbon::create((int) $filters['year'], 1, 1)->startOfDay();
        $yearEnd = Carbon::create((int) $filters['year'], 12, 31)->endOfDay();
        $previousYearStart = $yearStart->copy()->subYear()->startOfYear();
        $previousYearEnd = $previousYearStart->copy()->endOfYear();

        $comparisonFrom = $previousYearStart->copy();
        if ($previousPeriodYearStart->lt($comparisonFrom)) {
            $comparisonFrom = $previousPeriodYearStart->copy();
        }
        if ($previousPeriodMonthStart->lt($comparisonFrom)) {
            $comparisonFrom = $previousPeriodMonthStart->copy();
        }

        $comparisonTo = $yearEnd->copy();
        if ($currentPeriodEnd->gt($comparisonTo)) {
            $comparisonTo = $currentPeriodEnd->copy();
        }

        $comparisonRows = $this->comparisonRowsForAnalysis($filters, $comparisonFrom, $comparisonTo);

        $selectedMonthRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $monthStart, $monthEnd);
        $previousMonthRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $previousMonthStart, $previousMonthEnd);
        $selectedYearRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $yearStart, $yearEnd);
        $previousYearRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $previousYearStart, $previousYearEnd);
        $currentPeriodRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $currentPeriodStart, $currentPeriodEnd);
        $previousPeriodMonthRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $previousPeriodMonthStart, $previousPeriodMonthEnd);
        $previousPeriodYearRows = $this->rowsBetween($comparisonRows, $filters['date_type'], $previousPeriodYearStart, $previousPeriodYearEnd);

        $productSummary = $this->summaryByProduct($rows);
        $codeSummary = $this->summaryByCodePackaging($rows);
        $productComparison = $this->periodComparisonByProduct($currentPeriodRows, $previousPeriodMonthRows, $previousPeriodYearRows, $filters);
        $codeComparison = $this->periodComparisonByCodePackaging($currentPeriodRows, $previousPeriodMonthRows, $previousPeriodYearRows, $filters);

        $topProduct = $productSummary->sortByDesc('demand_pcs')->first();
        $topCode = $codeSummary->sortByDesc('demand_pcs')->first();

        $monthlyTrend = collect(range(1, 12))->map(function ($month) use ($selectedYearRows, $filters) {
            $monthRows = $selectedYearRows->filter(function ($row) use ($month, $filters) {
                $date = $this->rowDate($row, $filters['date_type']);
                return $date && (int) $date->format('n') === $month;
            });

            return [
                'label' => Carbon::create((int) $filters['year'], $month, 1)->format('M'),
                'value' => (int) $monthRows->sum('demand_pcs'),
            ];
        })->values();

        return [
            'filters' => $filters,
            'productOptions' => $dataset['productOptions'],
            'fpackOptions' => $dataset['fpackOptions'],
            'codeOptions' => $this->codeOptions($rows, $filters['code_packaging']),
            'siteOptions' => $this->siteOptions(),
            'quickLinks' => $this->analysisQuickLinks($filters),
            'periods' => [
                'current' => $currentPeriodStart->toDateString() . ' - ' . $currentPeriodEnd->toDateString(),
                'previous_month' => $previousPeriodMonthStart->toDateString() . ' - ' . $previousPeriodMonthEnd->toDateString(),
                'previous_year' => $previousPeriodYearStart->toDateString() . ' - ' . $previousPeriodYearEnd->toDateString(),
            ],
            'kpis' => [
                'selected_month_demand' => (int) $selectedMonthRows->sum('demand_pcs'),
                'previous_month_demand' => (int) $previousMonthRows->sum('demand_pcs'),
                'mom_change_pct' => $this->changePercent((float) $selectedMonthRows->sum('demand_pcs'), (float) $previousMonthRows->sum('demand_pcs')),
                'selected_year_demand' => (int) $selectedYearRows->sum('demand_pcs'),
                'previous_year_demand' => (int) $previousYearRows->sum('demand_pcs'),
                'yoy_change_pct' => $this->changePercent((float) $selectedYearRows->sum('demand_pcs'), (float) $previousYearRows->sum('demand_pcs')),
                'top_product' => $topProduct,
                'top_code' => $topCode,
            ],
            'charts' => [
                'monthlyTrend' => $monthlyTrend,
                'productUsage' => $productSummary->take(10)->map(fn($row) => [
                    'label' => $row->product ?: '-',
                    'value' => (int) $row->demand_pcs,
                ])->values(),
                'codeUsage' => $codeSummary->take(10)->map(fn($row) => [
                    'label' => $row->code_packaging ?: '-',
                    'value' => (int) $row->demand_pcs,
                ])->values(),
                'siteUsage' => $this->summaryBySite($rows)->map(fn($row) => [
                    'label' => $row->source_site ?: '-',
                    'value' => (int) $row->demand_pcs,
                ])->values(),
            ],
            'comparison' => [
                'products' => $productComparison,
                'codes' => $codeComparison,
            ],
            'riskInsights' => $this->analysisRiskInsights($productSummary, $codeSummary, $productComparison, $codeComparison, $filters),
            'monthlySummary' => $this->summaryByMonth($rows, $filters['date_type']),
            'productSummary' => $productSummary,
            'codeSummary' => $codeSummary,
            'matrix' => $this->productCodeMatrix($rows),
        ];
    }

    public function buildAnalysisExportResponse(array $filters)
    {
        $data = $this->getAnalysisData($filters);
        $filters = $data['filters'];
        $fileBase = 'packaging_analysis_' . str_replace('-', '', $filters['date_from']) . '_' . str_replace('-', '', $filters['date_to']);

        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $this->fillAnalysisSheet(
                $spreadsheet->getActiveSheet(),
                'Monthly Summary',
                ['Year-Month', 'Demand PCS', 'Stock PCS', 'Shortage PCS', 'Coverage %', 'WO Count'],
                $data['monthlySummary']->map(fn($row) => [
                    $row->year_month,
                    $row->demand_pcs,
                    $row->stock_pcs,
                    $row->shortage_pcs,
                    $this->coverageText($row->coverage_pct),
                    $row->wo_count,
                ])
            );

            $this->fillAnalysisSheet(
                $spreadsheet->createSheet(),
                'Product Summary',
                ['Product', 'Demand PCS', 'Stock PCS', 'Shortage PCS', 'Coverage %', 'Code Packaging Count', 'WO Count'],
                $data['productSummary']->map(fn($row) => [
                    $row->product,
                    $row->demand_pcs,
                    $row->stock_pcs,
                    $row->shortage_pcs,
                    $this->coverageText($row->coverage_pct),
                    $row->code_packaging_count,
                    $row->wo_count,
                ])
            );

            $this->fillAnalysisSheet(
                $spreadsheet->createSheet(),
                'Code Packaging Summary',
                ['Code Packaging', 'Packaging Name', 'Product', 'Site', 'Demand PCS', 'Stock PCS', 'Shortage PCS', 'Coverage %', 'WO Count'],
                $data['codeSummary']->map(fn($row) => [
                    $row->code_packaging,
                    $row->pack_name,
                    $row->product,
                    $row->source_site,
                    $row->demand_pcs,
                    $row->stock_pcs,
                    $row->shortage_pcs,
                    $this->coverageText($row->coverage_pct),
                    $row->wo_count,
                ])
            );

            $spreadsheet->setActiveSheetIndex(0);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
            $writer->save($tmp);

            return response()->download($tmp, "{$fileBase}.xlsx")->deleteFileAfterSend(true);
        }

        $callback = function () use ($data) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Monthly Summary']);
            fputcsv($out, ['Year-Month', 'Demand PCS', 'Stock PCS', 'Shortage PCS', 'Coverage %', 'WO Count']);
            foreach ($data['monthlySummary'] as $row) {
                fputcsv($out, [$row->year_month, $row->demand_pcs, $row->stock_pcs, $row->shortage_pcs, $this->coverageText($row->coverage_pct), $row->wo_count]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Product Summary']);
            fputcsv($out, ['Product', 'Demand PCS', 'Stock PCS', 'Shortage PCS', 'Coverage %', 'Code Packaging Count', 'WO Count']);
            foreach ($data['productSummary'] as $row) {
                fputcsv($out, [$row->product, $row->demand_pcs, $row->stock_pcs, $row->shortage_pcs, $this->coverageText($row->coverage_pct), $row->code_packaging_count, $row->wo_count]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Code Packaging Summary']);
            fputcsv($out, ['Code Packaging', 'Packaging Name', 'Product', 'Site', 'Demand PCS', 'Stock PCS', 'Shortage PCS', 'Coverage %', 'WO Count']);
            foreach ($data['codeSummary'] as $row) {
                fputcsv($out, [$row->code_packaging, $row->pack_name, $row->product, $row->source_site, $row->demand_pcs, $row->stock_pcs, $row->shortage_pcs, $this->coverageText($row->coverage_pct), $row->wo_count]);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, "{$fileBase}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

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

    private function normalizeAnalysisFilters(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $year = $year > 0 ? $year : now()->year;
        $month = $month >= 1 && $month <= 12 ? $month : now()->month;

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        if ($dateFrom === '' || $dateTo === '') {
            $dateFrom = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
            $dateTo = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        }

        return [
            'date_type' => in_array(($filters['date_type'] ?? 'reqdate'), ['reqdate', 'opendate'], true)
                ? $filters['date_type']
                : 'reqdate',
            'year' => $year,
            'month' => $month,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'product' => trim((string) ($filters['product'] ?? '')),
            'fpack' => trim((string) ($filters['fpack'] ?? ($filters['standard_pack'] ?? ''))),
            'code_packaging' => trim((string) ($filters['code_packaging'] ?? '')),
            'site' => strtoupper(trim((string) ($filters['site'] ?? ''))),
            'status' => '',
        ];
    }

    private function baseFiltersForRange(array $filters, string $dateFrom, string $dateTo): array
    {
        return [
            'date_type' => $filters['date_type'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'product' => $filters['product'],
            'fpack' => $filters['fpack'],
            'status' => '',
            'site' => '',
        ];
    }

    private function comparisonRowsForAnalysis(array $filters, Carbon $from, Carbon $to): Collection
    {
        $dataset = $this->buildDataset($this->baseFiltersForRange($filters, $from->toDateString(), $to->toDateString()), false);

        return $this->applyAnalysisRowFilters($dataset['enriched'], $filters);
    }

    private function applyAnalysisRowFilters(Collection $rows, array $filters): Collection
    {
        if (($filters['site'] ?? '') !== '') {
            $rows = $rows->filter(
                fn($row) => strtoupper((string) ($row->source_site ?? '')) === strtoupper($filters['site'])
            );
        }

        if (($filters['code_packaging'] ?? '') !== '') {
            $rows = $rows->filter(
                fn($row) => (string) ($row->code_packaging ?? '') === (string) $filters['code_packaging']
            );
        }

        return $rows->values();
    }

    private function rowsBetween(Collection $rows, string $dateType, Carbon $from, Carbon $to): Collection
    {
        return $rows->filter(function ($row) use ($dateType, $from, $to) {
            $date = $this->rowDate($row, $dateType);

            return $date && $date->betweenIncluded($from, $to);
        })->values();
    }

    private function rowDate($row, string $dateType): ?Carbon
    {
        $value = $dateType === 'opendate' ? ($row->dateopen ?? null) : ($row->reqdate ?? null);

        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function summaryByMonth(Collection $rows, string $dateType): Collection
    {
        return $rows
            ->groupBy(function ($row) use ($dateType) {
                $date = $this->rowDate($row, $dateType);
                return $date ? $date->format('Y-m') : '-';
            })
            ->map(fn($items, $month) => $this->aggregateAnalysisItems($items, ['year_month' => $month]))
            ->sortBy('year_month')
            ->values();
    }

    private function summaryByProduct(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn($row) => (string) ($row->product ?? ''))
            ->map(function ($items, $product) {
                $summary = $this->aggregateAnalysisItems($items, [
                    'product' => $product !== '' ? $product : '-',
                    'code_packaging_count' => $items->pluck('code_packaging')->filter()->unique()->count(),
                ]);

                return $summary;
            })
            ->sortByDesc('demand_pcs')
            ->values();
    }

    private function summaryByCodePackaging(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn($row) => (string) ($row->code_packaging ?? '') . '||' . (string) ($row->product ?? '') . '||' . (string) ($row->source_site ?? ''))
            ->map(function ($items, $key) {
                [$code, $product, $site] = array_pad(explode('||', $key, 3), 3, '');

                return $this->aggregateAnalysisItems($items, [
                    'code_packaging' => $code !== '' ? $code : '-',
                    'pack_name' => optional($items->first())->pack_name ?? '-',
                    'product' => $product !== '' ? $product : '-',
                    'source_site' => $site !== '' ? $site : '-',
                ]);
            })
            ->sortByDesc('demand_pcs')
            ->values();
    }

    private function summaryBySite(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn($row) => (string) ($row->source_site ?? ''))
            ->map(fn($items, $site) => $this->aggregateAnalysisItems($items, [
                'source_site' => $site !== '' ? $site : '-',
            ]))
            ->sortBy('source_site')
            ->values();
    }

    private function periodComparisonByProduct(Collection $currentRows, Collection $previousMonthRows, Collection $previousYearRows, array $filters): Collection
    {
        return $this->periodComparison(
            $currentRows,
            $previousMonthRows,
            $previousYearRows,
            fn($row) => (string) ($row->product ?? ''),
            function ($items, $key) use ($filters) {
                $product = $key !== '' ? $key : '-';

                return [
                    'label' => $product,
                    'product' => $product,
                    'code_packaging' => '',
                    'source_site' => '',
                    'link_filters' => array_merge($filters, ['product' => $product === '-' ? '' : $product]),
                ];
            }
        );
    }

    private function periodComparisonByCodePackaging(Collection $currentRows, Collection $previousMonthRows, Collection $previousYearRows, array $filters): Collection
    {
        return $this->periodComparison(
            $currentRows,
            $previousMonthRows,
            $previousYearRows,
            fn($row) => (string) ($row->code_packaging ?? '') . '||' . (string) ($row->product ?? '') . '||' . (string) ($row->source_site ?? ''),
            function ($items, $key) use ($filters) {
                [$code, $product, $site] = array_pad(explode('||', $key, 3), 3, '');
                $code = $code !== '' ? $code : '-';
                $product = $product !== '' ? $product : '-';
                $site = $site !== '' ? $site : '-';

                return [
                    'label' => $code,
                    'product' => $product,
                    'code_packaging' => $code,
                    'source_site' => $site,
                    'link_filters' => array_merge($filters, [
                        'product' => $product === '-' ? '' : $product,
                        'code_packaging' => $code === '-' ? '' : $code,
                        'site' => $site === '-' ? '' : $site,
                    ]),
                ];
            }
        );
    }

    private function periodComparison(Collection $currentRows, Collection $previousMonthRows, Collection $previousYearRows, callable $keyForRow, callable $metadataForKey): Collection
    {
        $currentGroups = $currentRows->groupBy($keyForRow);
        $previousMonthGroups = $previousMonthRows->groupBy($keyForRow);
        $previousYearGroups = $previousYearRows->groupBy($keyForRow);

        return $currentGroups
            ->keys()
            ->merge($previousMonthGroups->keys())
            ->merge($previousYearGroups->keys())
            ->unique()
            ->map(function ($key) use ($currentGroups, $previousMonthGroups, $previousYearGroups, $metadataForKey) {
                $current = $this->aggregateAnalysisItems($currentGroups->get($key, collect()), []);
                $previousMonth = $this->aggregateAnalysisItems($previousMonthGroups->get($key, collect()), []);
                $previousYear = $this->aggregateAnalysisItems($previousYearGroups->get($key, collect()), []);
                $meta = $metadataForKey($currentGroups->get($key, collect()), $key);

                return (object) array_merge($meta, [
                    'current_demand_pcs' => (int) $current->demand_pcs,
                    'previous_month_demand_pcs' => (int) $previousMonth->demand_pcs,
                    'previous_year_demand_pcs' => (int) $previousYear->demand_pcs,
                    'mom_change_pcs' => (int) $current->demand_pcs - (int) $previousMonth->demand_pcs,
                    'yoy_change_pcs' => (int) $current->demand_pcs - (int) $previousYear->demand_pcs,
                    'mom_change_pct' => $this->changePercent((float) $current->demand_pcs, (float) $previousMonth->demand_pcs),
                    'yoy_change_pct' => $this->changePercent((float) $current->demand_pcs, (float) $previousYear->demand_pcs),
                    'shortage_pcs' => (float) $current->shortage_pcs,
                    'coverage_pct' => $current->coverage_pct,
                    'wo_count' => (int) $current->wo_count,
                ]);
            })
            ->sortByDesc(fn($row) => max(abs((int) $row->mom_change_pcs), abs((int) $row->yoy_change_pcs), (int) $row->current_demand_pcs))
            ->values();
    }

    private function analysisRiskInsights(Collection $productSummary, Collection $codeSummary, Collection $productComparison, Collection $codeComparison, array $filters): array
    {
        $activeCodes = $codeSummary->filter(fn($row) => (float) ($row->demand_pcs ?? 0) > 0);
        $shortageCodes = $activeCodes->filter(fn($row) => (float) ($row->shortage_pcs ?? 0) > 0);
        $lowCoverageCodes = $activeCodes->filter(function ($row) {
            return is_numeric($row->coverage_pct ?? null) && (float) $row->coverage_pct < 120;
        });

        $totalDemand = (float) $activeCodes->sum('demand_pcs');
        $totalStock = (float) $activeCodes->sum('stock_pcs');
        $totalShortage = (float) $activeCodes->sum('shortage_pcs');
        $coveragePct = $totalDemand > 0 ? ($totalStock / $totalDemand) * 100 : null;

        $topShortageCodes = $shortageCodes
            ->sortByDesc(fn($row) => (float) ($row->shortage_pcs ?? 0))
            ->take(8)
            ->map(function ($row) use ($filters, $totalShortage) {
                return (object) [
                    'code_packaging' => $row->code_packaging,
                    'pack_name' => $row->pack_name,
                    'product' => $row->product,
                    'source_site' => $row->source_site,
                    'demand_pcs' => (float) $row->demand_pcs,
                    'stock_pcs' => (float) $row->stock_pcs,
                    'shortage_pcs' => (float) $row->shortage_pcs,
                    'coverage_pct' => $row->coverage_pct,
                    'impact_pct' => $totalShortage > 0 ? ((float) $row->shortage_pcs / $totalShortage) * 100 : 0,
                    'link_filters' => $this->analysisCodeLinkFilters($filters, $row),
                ];
            })
            ->values();

        $lowCoverageRows = $lowCoverageCodes
            ->sort(function ($a, $b) {
                $cmp = ((float) ($a->coverage_pct ?? 999999)) <=> ((float) ($b->coverage_pct ?? 999999));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ((float) ($b->shortage_pcs ?? 0)) <=> ((float) ($a->shortage_pcs ?? 0));
            })
            ->take(8)
            ->map(function ($row) use ($filters) {
                return (object) [
                    'code_packaging' => $row->code_packaging,
                    'product' => $row->product,
                    'source_site' => $row->source_site,
                    'demand_pcs' => (float) $row->demand_pcs,
                    'stock_pcs' => (float) $row->stock_pcs,
                    'shortage_pcs' => (float) $row->shortage_pcs,
                    'coverage_pct' => $row->coverage_pct,
                    'link_filters' => $this->analysisCodeLinkFilters($filters, $row),
                ];
            })
            ->values();

        $growthCodes = $codeComparison
            ->filter(fn($row) => (int) ($row->mom_change_pcs ?? 0) > 0 || (int) ($row->yoy_change_pcs ?? 0) > 0)
            ->sortByDesc(fn($row) => max((int) ($row->mom_change_pcs ?? 0), (int) ($row->yoy_change_pcs ?? 0)))
            ->take(6)
            ->values();

        $shortageProducts = $productSummary
            ->filter(fn($row) => (float) ($row->shortage_pcs ?? 0) > 0)
            ->sortByDesc(fn($row) => (float) ($row->shortage_pcs ?? 0))
            ->take(6)
            ->map(function ($row) use ($filters, $totalShortage) {
                return (object) [
                    'product' => $row->product,
                    'demand_pcs' => (float) $row->demand_pcs,
                    'shortage_pcs' => (float) $row->shortage_pcs,
                    'coverage_pct' => $row->coverage_pct,
                    'impact_pct' => $totalShortage > 0 ? ((float) $row->shortage_pcs / $totalShortage) * 100 : 0,
                    'link_filters' => array_merge($filters, [
                        'product' => $row->product === '-' ? '' : $row->product,
                    ]),
                ];
            })
            ->values();

        return [
            'total_demand_pcs' => $totalDemand,
            'total_stock_pcs' => $totalStock,
            'total_shortage_pcs' => $totalShortage,
            'coverage_pct' => $coveragePct,
            'shortage_code_count' => $shortageCodes->count(),
            'low_coverage_code_count' => $lowCoverageCodes->count(),
            'active_code_count' => $activeCodes->count(),
            'top_shortage_codes' => $topShortageCodes,
            'low_coverage_rows' => $lowCoverageRows,
            'growth_codes' => $growthCodes,
            'shortage_products' => $shortageProducts,
        ];
    }

    private function analysisCodeLinkFilters(array $filters, object $row): array
    {
        return array_merge($filters, [
            'product' => ($row->product ?? '-') === '-' ? '' : (string) $row->product,
            'code_packaging' => ($row->code_packaging ?? '-') === '-' ? '' : (string) $row->code_packaging,
            'site' => ($row->source_site ?? '-') === '-' ? '' : (string) $row->source_site,
        ]);
    }

    private function aggregateAnalysisItems(Collection $items, array $extra): object
    {
        $codeSiteGroups = $items->groupBy(fn($row) => (string) ($row->code_packaging ?? '') . '||' . (string) ($row->source_site ?? ''));
        $demand = (int) $items->sum('demand_pcs');
        $stock = 0.0;
        $shortage = 0.0;
        $woCount = $items->pluck('workordernumber')->filter()->unique()->count();

        foreach ($codeSiteGroups as $groupRows) {
            $groupDemand = (int) $groupRows->sum('demand_pcs');
            $groupStock = optional($groupRows->first())->stock_on_hand;
            $groupStock = $groupStock !== null ? (float) $groupStock : 0.0;
            $stock += $groupStock;
            $shortage += max(0, $groupDemand - $groupStock);
        }

        $coverage = $demand > 0 ? ($stock / $demand) * 100 : ($stock > 0 ? 999999 : 0);

        return (object) array_merge($extra, [
            'demand_pcs' => $demand,
            'stock_pcs' => $stock,
            'shortage_pcs' => $shortage,
            'coverage_pct' => $coverage,
            'wo_count' => $woCount,
        ]);
    }

    private function productCodeMatrix(Collection $rows): array
    {
        $products = $rows->pluck('product')->filter()->unique()->sort()->values();
        $codes = $rows->pluck('code_packaging')->filter()->unique()->sort()->values();
        $body = $products->map(function ($product) use ($rows, $codes) {
            $cells = [];
            $total = 0;
            foreach ($codes as $code) {
                $value = (int) $rows
                    ->filter(fn($row) => (string) ($row->product ?? '') === (string) $product && (string) ($row->code_packaging ?? '') === (string) $code)
                    ->sum('demand_pcs');
                $cells[$code] = $value;
                $total += $value;
            }

            return [
                'product' => $product,
                'cells' => $cells,
                'total' => $total,
            ];
        })->values();

        $columnTotals = [];
        foreach ($codes as $code) {
            $columnTotals[$code] = (int) $rows
                ->filter(fn($row) => (string) ($row->code_packaging ?? '') === (string) $code)
                ->sum('demand_pcs');
        }

        return [
            'codes' => $codes,
            'rows' => $body,
            'columnTotals' => $columnTotals,
            'grandTotal' => array_sum($columnTotals),
        ];
    }

    private function analysisQuickLinks(array $filters): array
    {
        $today = now();
        $thisMonth = $today->copy()->startOfMonth();
        $lastMonth = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $thisYear = $today->copy()->startOfYear();
        $base = collect($filters)->except(['date_from', 'date_to', 'year', 'month'])->all();

        return [
            'this_month' => array_merge($base, [
                'year' => (int) $thisMonth->format('Y'),
                'month' => (int) $thisMonth->format('n'),
                'date_from' => $thisMonth->toDateString(),
                'date_to' => $thisMonth->copy()->endOfMonth()->toDateString(),
            ]),
            'last_month' => array_merge($base, [
                'year' => (int) $lastMonth->format('Y'),
                'month' => (int) $lastMonth->format('n'),
                'date_from' => $lastMonth->toDateString(),
                'date_to' => $lastMonth->copy()->endOfMonth()->toDateString(),
            ]),
            'this_year' => array_merge($base, [
                'year' => (int) $thisYear->format('Y'),
                'month' => (int) $today->format('n'),
                'date_from' => $thisYear->toDateString(),
                'date_to' => $thisYear->copy()->endOfYear()->toDateString(),
            ]),
            'yoy' => array_merge($base, [
                'year' => (int) $today->format('Y'),
                'month' => (int) $today->format('n'),
                'date_from' => $today->copy()->startOfYear()->subYear()->toDateString(),
                'date_to' => $today->copy()->endOfYear()->toDateString(),
            ]),
            'mom' => array_merge($base, [
                'year' => (int) $today->format('Y'),
                'month' => (int) $today->format('n'),
                'date_from' => $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'date_to' => $today->copy()->endOfMonth()->toDateString(),
            ]),
        ];
    }

    private function codeOptions(Collection $rows, string $selectedCode): Collection
    {
        $codes = $rows->pluck('code_packaging')->filter()->map(fn($code) => trim((string) $code));

        if ($selectedCode !== '') {
            $codes->push($selectedCode);
        }

        return $codes->unique()->sort()->values();
    }

    private function changePercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function coverageText($coverage): string
    {
        if (!is_numeric($coverage)) {
            return '-';
        }

        return (float) $coverage >= 999999 ? 'INF' : number_format((float) $coverage, 2) . '%';
    }

    private function fillAnalysisSheet($sheet, string $title, array $headers, Collection $rows): void
    {
        $sheet->setTitle($title);
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $rowNumber);
            $rowNumber++;
        }

        $lastRow = max($rowNumber - 1, 1);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->freezePane('A2');

        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
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
            ->filter(fn($x) => $x !== null && trim((string) $x) !== '' && trim((string) $x) !== '-')
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
                if ($code === '-') {
                    continue;
                }

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
