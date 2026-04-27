@extends('layouts.layout')

@section('title', 'HR List')
@section('page-title', 'PA Online (HR)')

@section('content')
    <div class="container-fluid px-4">
        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <h4 class="mb-0 me-auto">รายการพนักงานสำหรับประเมิน HR (รอบที่ {{ $periodId }})</h4>
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-0 small">แผนก</label>
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">— ทุกแผนก —</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected(request('dept') == $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">สถานะ</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">— ทั้งหมด —</option>
                        <option value="noform" @selected(request('status') === 'noform')>ยังไม่มีใบ</option>
                        <option value="pending" @selected(request('status') === 'pending')>รอดำเนินการ</option>
                        <option value="complete" @selected(request('status') === 'complete')>เสร็จแล้ว</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">ค้นหา</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="q" value="{{ request('q') }}" class="form-control"
                            placeholder="ชื่อ/อีเมล">
                        <button class="btn btn-outline-secondary">ค้นหา</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px">#</th>
                            <th>พนักงาน</th>
                            <th>แผนก</th>
                            <th class="text-center">Part A (pts)</th>
                            <th class="text-center">HR รวม</th>
                            <th>อัปเดต</th>
                            <th style="width:160px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $i => $r)
                            @php
                                $status = !$r->form_id ? 'noform' : ($r->score_total === null ? 'pending' : 'complete');
                                $badge = ['noform' => 'secondary', 'pending' => 'warning', 'complete' => 'success'][
                                    $status
                                ];
                                $label = [
                                    'noform' => 'ยังไม่มีใบ',
                                    'pending' => 'รอดำเนินการ',
                                    'complete' => 'เสร็จแล้ว',
                                ][$status];
                            @endphp
                            <tr>
                                <td>{{ $rows->firstItem() + $i }}</td>
                                <td class="fw-semibold">
                                    {{ $r->name }}
                                    <div class="small text-muted">{{ $r->email }}</div>
                                </td>
                                <td>{{ $r->dept_name }}</td>
                                <td class="text-center">{{ number_format((float) ($r->part_a_points_avg ?? 0), 2) }}</td>


                                <td class="text-center">
                                    <span
                                        class="badge bg-{{ $badge }}">{{ $r->score_total !== null ? number_format($r->score_total, 2) : $label }}</span>
                                </td>
                                <td class="small text-muted">
                                    {{ $r->updated_at ? \Carbon\Carbon::parse($r->updated_at)->format('d/m/Y H:i') : '—' }}
                                </td>
                                <td class="text-end">
                                    @if (!$r->form_id)
                                        <a href="{{ route('pa.hr.open', $r->user_id) }}"
                                            class="btn btn-sm btn-outline-primary">เริ่มใบ HR</a>
                                    @else
                                        <a href="{{ route('pa.hr.form', $r->form_id) }}"
                                            class="btn btn-sm btn-primary">ประเมิน HR</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">ไม่พบรายการ</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="card-footer">{{ $rows->withQueryString()->links() }}</div>
            @endif
        </div>
    </div>
@endsection
