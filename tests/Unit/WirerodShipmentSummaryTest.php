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
    public function test_first_week_keeps_days_from_previous_month_like_the_report(): void
    {
        $weeks=ShipmentSummaryService::weeks(Carbon::parse('2026-07-31'));
        $this->assertCount(5,$weeks);
        $this->assertSame('2026-06-27',$weeks[0]['start']->toDateString());
        $this->assertSame('2026-07-03',$weeks[0]['end']->toDateString());
        $this->assertSame('2026-07-25',$weeks[4]['start']->toDateString());
        $this->assertSame('2026-07-31',$weeks[4]['end']->toDateString());
        $partial=ShipmentSummaryService::weeks(Carbon::parse('2026-07-07'));
        $this->assertSame('2026-07-07',$partial[1]['end']->toDateString());
    }
    public function test_page_sections_match_the_sheet_calculation(): void
    {
        $sections=(new ShipmentSummaryService)->sections(['date'=>'2026-11-03','stock'=>[100.0,50.0],'excluded'=>[20.0,0.0],
            'received'=>['2026-10-31'=>[5.0,1.0]],'incoming'=>['Carried forward'=>[1.0,0.0],'2026-12'=>[9.0,2.0]],'orders'=>['2026-11'=>[30.0,4.0]]]);
        $byLabel=[];foreach($sections as $section) foreach($section['rows'] as $row) $byLabel[$row['label']]=$row['values'];
        $this->assertSame([5.0,1.0],$byLabel['31 Oct - 03 Nov']);
        $this->assertSame([10.0,2.0],$byLabel['รวมกำหนดเข้า']);
        $this->assertSame([110.0,52.0],$byLabel['Over all stock']);
        $this->assertSame([80.0,48.0],$byLabel['Stock End Year']);
        $this->assertSame([-1920.0,-1052.0],$byLabel['Over stock / (Shortfall)']);
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
