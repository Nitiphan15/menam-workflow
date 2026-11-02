@extends('layouts.layout')
@section('title', 'รายการ DIE เสีย')
@section('page-title', 'DIE Reports')
@section('content')
<div class="dr-wrap">@include('formdie.damage.common')
<h2>รายการ DIE เสีย</h2><p class="dr-muted">หนึ่งรายการ = เสียหนึ่งครั้ง • DIE เดิมบันทึกได้หลายเหตุการณ์ • เก็บข้อมูลสำหรับรายงานย้อนหลัง</p>
@include('formdie.damage.filters')
<div class="dr-card dr-table"><table><thead><tr><th>ID</th><th>วันที่เสีย</th><th>Site</th><th>DIE CODE</th><th>ประเภท</th><th>สาเหตุ</th><th>ประเภทแตก</th><th>Line สุดท้าย</th><th>WO</th><th>จัดการ</th></tr></thead><tbody>
@forelse($records ?? [] as $record)<tr><td>{{ $record->id }}</td><td>{{ $record->damaged_at->format('d/m/Y') }}</td><td>{{ $record->site }}</td><td>{{ $record->die_code }}</td><td>{{ $record->die_type }}</td><td>{{ $record->reason }}</td><td>{{ $record->fracture_type }}</td><td>{{ $record->machine }}</td><td>{{ $record->workorder }}</td><td><div class="dr-actions"><a href="{{ route('die-reports.edit', $record) }}">ดู / แก้ไข</a><form method="post" action="{{ route('die-reports.destroy', $record) }}" onsubmit="return confirm('ยืนยันลบเหตุการณ์นี้?')">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $record->version }}"><button class="dr-button">ลบ</button></form></div></td></tr>
@empty<tr><td colspan="10">ไม่พบรายการตามตัวกรอง</td></tr>@endforelse
</tbody></table></div>@if($records)<p class="dr-muted">ทั้งหมด {{ number_format($records->total()) }} เหตุการณ์</p>{{ $records->links() }}@endif</div>
@endsection
