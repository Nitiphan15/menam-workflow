@extends('layouts.layout')
@section('title','Wire Rod Dead Stock by Item')
@section('page-title','Wire Rod Dead Stock by Item')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
    <header class="wr-page-head">
        <div><h3>ยอดรวม Wire Rod ราย Item</h3><p>รวม Serial ตาม Site + Material Group + Part Number โดยคอลัมน์ Qty แสดงเฉพาะ KG</p></div>
        <div class="wr-actions">@include('formwr.deadstock._export_actions')<a href="{{ route('wr.deadstock.dashboard', request()->query()) }}" class="btn btn-outline-primary"><i class="fas fa-gauge-high"></i> กลับหน้าภาพรวม</a></div>
    </header>
    @include('formwr.deadstock._filters')
    <section class="wr-panel">
        <div class="wr-panel-head"><h4>Item Summary</h4><div class="d-flex flex-wrap align-items-center gap-3"><span class="text-muted small">{{ number_format($items->total()) }} Items · คลิก Part Number เพื่อดู Serial</span>@include('formwr.deadstock._table_tools',['columns'=>['site'=>'Site','group'=>'Material Group','part'=>'Part Number','description'=>'Description','serials'=>'Serial ทั้งหมด','dead'=>'Dead Serial','qty'=>'Qty KG','value'=>'Value THB']])</div></div>
        <div class="table-responsive wr-table-scroll"><table class="table wr-table"><thead><tr><th data-wr-col="site">Site</th><th data-wr-col="group">Material Group</th><th data-wr-col="part">Part Number</th><th data-wr-col="description">Description</th><th data-wr-col="serials" class="text-end">Serial ทั้งหมด</th><th data-wr-col="dead" class="text-end">Dead Serial</th><th data-wr-col="qty" class="text-end">Qty KG</th><th data-wr-col="value" class="text-end">Value THB</th></tr></thead><tbody>
        @forelse($items as $row)
            <tr><td data-wr-col="site">{{ $row->site_code }}</td><td data-wr-col="group">{{ $row->material_group }}</td><td data-wr-col="part"><div class="d-flex align-items-center gap-1"><a href="{{ route('wr.deadstock.review', array_merge(request()->except('page'), ['site'=>$row->site_code,'material_group'=>$row->material_group,'part_number_exact'=>$row->part_number])) }}">{{ $row->part_number }} →</a><button type="button" class="wr-copy-btn" data-wr-copy="{{ $row->part_number }}" title="คัดลอก Part Number"><i class="far fa-copy"></i></button></div></td><td data-wr-col="description">{{ $row->part_description ?: '-' }}</td><td data-wr-col="serials" class="text-end wr-number">{{ number_format($row->serial_count) }}</td><td data-wr-col="dead" class="text-end wr-number">{{ number_format($row->dead_serial_count) }}</td><td data-wr-col="qty" class="text-end wr-number">{{ number_format($row->qty_kg,4) }}</td><td data-wr-col="value" class="text-end wr-number">{{ number_format($row->value_thb,2) }}</td></tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูลตาม Filter</td></tr>@endforelse
        </tbody></table></div>
        <div class="p-3 border-top">{{ $items->links() }}</div>
    </section>
</div>
@endsection
@include('formwr.deadstock._qol_scripts')
