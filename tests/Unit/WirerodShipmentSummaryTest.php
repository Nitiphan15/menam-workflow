<?php
namespace Tests\Unit;
use App\Services\FormWR\ShipmentSummaryService;
use App\Exports\WirerodShipmentSummarySheet;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
class WirerodShipmentSummaryTest extends TestCase
{
    public function test_purchase_units_convert_without_scaling_kg_as_tonnes(): void
    {
        $s=new ShipmentSummaryService;
        $row=(object)['unit'=>'01','base_unit'=>'01','purchase_unit'=>'15','purchase_unit_qty'=>1000,'qty'=>516,'item'=>'R1'];
        $this->assertSame(0.516,$s->tonnes($row));
        $row->unit='15';$row->qty=3.758;
        $this->assertSame(3.758,$s->tonnes($row));
    }
    public function test_dates_do_not_mix_years_or_drop_overdue_orders(): void
    {
        $s=new ShipmentSummaryService;$d=Carbon::parse('2026-09-07');
        $this->assertSame('Carried forward',$s->period('2025-12-01',$d));
        $this->assertSame('2026-09',$s->period('2026-09-01',$d));
        $this->assertSame('After year end',$s->period('2027-01-01',$d));
        $this->assertSame('Undated',$s->period(null,$d));
    }
    public function test_month_end_does_not_skip_february_and_blank_rows_keep_formula_positions(): void
    {
        $sheet=new WirerodShipmentSummarySheet(['date'=>'2026-01-31','stock'=>[0,0],'excluded'=>[0,0],'received'=>[],'incoming'=>[],'orders'=>[]]);
        $rows=$sheet->array();$labels=array_column($rows,0);
        $this->assertSame(2,count(array_filter($labels,fn($v)=>$v==='Feb 2026')));
        $this->assertNotContains([],$rows);
        $target=array_search('Target (editable)',$labels,true)+1;
        $over=array_search('Over stock / (Shortfall)',$labels,true);
        $this->assertStringEndsWith('-B'.$target,$rows[$over][1]);
    }
}
