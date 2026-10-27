<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\{FromArray, WithTitle, WithStyles, WithColumnWidths, WithEvents, WithCustomValueBinder};
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class WirerodShipmentSummarySheet extends \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder implements WithCustomValueBinder, FromArray, WithTitle, WithStyles, WithColumnWidths, WithEvents
{
    public function __construct(private array $data) {}
    public function title(): string { return 'Stock & Shipment Summary'; }
    public function columnWidths(): array { return ['A'=>42, 'B'=>22, 'C'=>22, 'D'=>22]; }

    public function array(): array
    {
        $d = Carbon::parse($this->data['date']);
        $rows = [['STOCK WIRE ROD & MATERIALS SHIPMENT'], ['Update: '.$d->format('d M Y').' | Latest data'], ['Unit: MT (1 MT = 1,000 KG)'], ['', 'Stainless (MT)', 'Carbon Steel (MT)', 'Total (MT)']];
        $add = function(string $label, array $values) use (&$rows): int {
            $n = count($rows)+1;
            $rows[] = [$label,$values[0],$values[1], '=SUM(B'.$n.':C'.$n.')'];
            return $n;
        };
        $stock = $add('Stock on hand '.$d->format('d M Y'), $this->data['stock']);
        $add('Excluded Slit Coil (reference only)', $this->data['excluded']);
        $rows[]=['']; $rows[]=['Already arrived - '.$d->format('M Y')];
        $first=count($rows)+1;
        $week=$d->copy()->startOfMonth()->startOfWeek(Carbon::SATURDAY);
        while($week->lte($d)) {
            $end=$week->copy()->addDays(6)->min($d);
            $start=$week->copy()->max($d->copy()->startOfMonth());
            $add($start->format('d M').' - '.$end->format('d M'),$this->data['received'][$week->toDateString()] ?? [0,0]);
            $week->addWeek();
        }
        $last=count($rows);$add('Total arrived', ['=SUM(B'.$first.':B'.$last.')','=SUM(C'.$first.':C'.$last.')']);
        $totals=[];
        foreach(['incoming'=>'To be arrived (through Dec '.$d->year.')','orders'=>'Order - remaining MFG reservations'] as $field=>$label) {
            $rows[]=[''];$rows[]=[$label];$first=count($rows)+1;
            $add('Carried forward', $this->data[$field]['Carried forward'] ?? [0,0]);
            for($month=$d->month;$month<=12;$month++) {
                $date=$d->copy()->startOfMonth()->month($month);
                $add($date->format('M Y'), $this->data[$field][$date->format('Y-m')] ?? [0,0]);
            }
            $last=count($rows);$totals[$field]=$add('TOTAL '.$field, ['=SUM(B'.$first.':B'.$last.')','=SUM(C'.$first.':C'.$last.')']);
        }
        $rows[]=[''];
        $overall=$add('Over all stock', ['=B'.$stock.'+B'.$totals['incoming'],'=C'.$stock.'+C'.$totals['incoming']]);
        $end=$add('Stock End Year (estimate)', ['=B'.$overall.'-B'.$totals['orders'],'=C'.$overall.'-C'.$totals['orders']]);
        $target=$add('Target (editable)', [2000,1100]);
        $add('Over stock / (Shortfall)', ['=B'.$end.'-B'.$target,'=C'.$end.'-C'.$target]);
        $rows[]=[''];$rows[]=['Outside year-end estimate (reference only)'];
        foreach(['incoming'=>'Incoming','orders'=>'Order'] as $field=>$label) {
            foreach(['Undated','After year end'] as $bucket) $add($label.' - '.$bucket,$this->data[$field][$bucket] ?? [0,0]);
        }
        $rows[]=[''];
        $rows[]=['Scope: selected company/Item; vendor, PO and year filters do not limit this current-year summary.'];
        $rows[]=['Slit Coil excluded throughout this sheet; the other sheets retain their existing scope.'];
        $rows[]=['Order = MFG quantity less issued; not a sales forecast. Undated/next-year rows excluded from estimate.'];
        $rows[]=['Already arrived is included in current stock; it is not added again. Target cells B/C may be edited.'];
        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A:D')->getFont()->setName('Arial')->setSize(11);
        $sheet->getStyle('B5:D'.$sheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0.000;[Red](#,##0.000);0.000');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true)->setSize(17);
        $sheet->getStyle('A4:D4')->getFont()->setBold(true);
        $sheet->getStyle('A4:D4')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8EEF5');
        foreach($this->array() as $index=>$row) {
            $n=$index+1;$label=$row[0] ?? '';
            if(count($row)===1 && $label!=='') {
                $sheet->mergeCells('A'.$n.':D'.$n);
                $sheet->getStyle('A'.$n)->getAlignment()->setWrapText(true);
                $sheet->getRowDimension($n)->setRowHeight($n>4 ? 30 : 27);
            }
            if(str_starts_with($label,'TOTAL') || in_array($label,['Over all stock','Stock End Year (estimate)','Over stock / (Shortfall)','Target (editable)','Total arrived'])) {
                $sheet->getStyle('A'.$n.':D'.$n)->getFont()->setBold(true);
                $sheet->getStyle('A'.$n.':D'.$n)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFF4BF');
            }
            if($label==='Target (editable)') {
                $sheet->getStyle('B'.$n.':C'.$n)->getFont()->getColor()->setARGB('FF005BBB');
                $sheet->getComment('B'.$n)->getText()->createTextRun('Editable target in MT. Default from supplied reference PDF.');
                $sheet->getComment('C'.$n)->getText()->createTextRun('Editable target in MT. Default from supplied reference PDF.');
            }
        }
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class=>function(AfterSheet $event) {
            $s=$event->sheet->getDelegate();$s->freezePane('B5');
            $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
            $s->getPageSetup()->setPrintArea('A1:D'.$s->getHighestRow());
        }];
    }
}
