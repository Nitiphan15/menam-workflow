@extends('layouts.layout')
@section('title', 'DIE Reports')
@section('page-title', 'DIE Reports')
@section('content')
<div class="dr-wrap">@include('formdie.damage.common')
<h2>รายงาน DIE เสีย</h2><p class="dr-muted">อ้างอิงรูปแบบ DIE เสีย 2024 • นับเหตุการณ์ที่บันทึกและยังไม่ถูกลบ • ไม่รวมข้อมูลปี 2024 จากไฟล์อัตโนมัติ</p>
@include('formdie.damage.filters')
<div class="dr-card"><h3>{{ $pivot['title'] }}</h3><p>{{ number_format($pivot['total']) }} เหตุการณ์</p>
@if($report === 'weight')
<p class="dr-muted">แสดงน้ำหนักและต้นทุนต่อเหตุการณ์ ไม่บวกน้ำหนักสะสมข้ามเหตุการณ์ของ DIE เดียวกัน เพราะอาจนับการผลิตซ้ำ</p>
<div class="dr-table"><table><thead><tr><th>วันที่</th><th>Site / DIE</th><th>น้ำหนักผลิต (Kg)</th><th>ราคา (บาท)</th><th>THB/Kg</th><th>Size Diff</th></tr></thead><tbody>
@forelse($records as $r)@php($metrics=$service->metrics($r->toArray()))<tr><td>{{ $r->damaged_at->format('d/m/Y') }}</td><td>{{ $r->site }} / {{ $r->die_code }}</td><td>{{ $r->production_kg === null ? '—' : number_format($r->production_kg,4) }}</td><td>{{ $r->price === null ? '—' : number_format($r->price,2) }}</td><td>{{ $metrics['thb_kg'] === null ? '—' : number_format($metrics['thb_kg'],4) }}</td><td>{{ $metrics['size_diff'] === null ? '—' : number_format($metrics['size_diff'],4) }}</td></tr>@empty<tr><td colspan="6">ไม่พบข้อมูล</td></tr>@endforelse
</tbody></table></div>
@else
@if(in_array($report,['fracture','fracture-type']))<p class="dr-muted">นับเฉพาะเหตุการณ์ที่ระบุประเภทแตก</p>@endif
<div class="dr-table"><table><thead><tr><th>กลุ่ม</th>@foreach($pivot['columns'] as $column)<th>{{ $column }}</th>@endforeach<th>รวม</th></tr></thead><tbody>
@forelse($pivot['rows'] as $label=>$cells)<tr><td>{{ $label }}</td>@foreach($pivot['columns'] as $column)<td>{{ number_format($cells[$column] ?? 0) }}</td>@endforeach<th>{{ number_format(array_sum($cells)) }}</th></tr>@empty<tr><td>ไม่พบข้อมูล</td></tr>@endforelse
</tbody><tfoot><tr><th>รวมทั้งหมด</th>@foreach($pivot['columns'] as $column)<th>{{ number_format(array_sum(array_column($pivot['rows'],$column))) }}</th>@endforeach<th>{{ number_format($pivot['total']) }}</th></tr></tfoot></table></div>
@endif
</div></div>
@endsection
