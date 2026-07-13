{{-- resources/views/pa/periods/index.blade.php --}}
@extends('layouts.layout')

@section('title', 'กำหนดรอบประเมิน')
@section('page-title', 'กำหนดรอบประเมิน (Periods)')

@section('content')
    <div class="container">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif
        <div class="row g-3">
            {{-- ===== Create new period ===== --}}
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><strong>เพิ่มรอบประเมินใหม่</strong></div>
                    <div class="card-body">
                        <form method="post" action="{{ route('paadmin.periods.store') }}" class="row g-3">
                            @csrf
                            <div class="col-4">
                                <label class="form-label">รหัส</label>
                                <input name="code" class="form-control" placeholder="เช่น 2025H2" required>
                            </div>
                            <div class="col-8">
                                <label class="form-label">ชื่อรอบ</label>
                                <input name="name" class="form-control" placeholder="รอบครึ่งหลัง 2025" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">วันที่เริ่ม</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">วันที่สิ้นสุด</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                            <div class="col-12 form-check">
                                <input class="form-check-input" id="p_active" type="checkbox" name="is_active"
                                    value="1">
                                <label class="form-check-label" for="p_active">ตั้งเป็นรอบที่ใช้งาน</label>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary">บันทึก</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ===== List / quick edit ===== --}}
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>รายการรอบประเมิน</strong>
                        <small class="text-muted">แอคทีฟได้ครั้งละ 1 รอบ</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 80px;">รหัส</th>
                                    <th>ชื่อ</th>
                                    <th style="width: 160px;">ช่วงเวลา</th>
                                    <th style="width: 120px;" class="text-center">สถานะ</th>
                                    <th style="width: 200px;" class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($periods as $p)
                                    @php $fid = "p-{$p->id}"; @endphp
                                    <tr @class(['table-success' => $p->is_active == 1])>
                                        <td class="text-muted">{{ $p->code }}</td>
                                        <td>
                                            <form id="{{ $fid }}" method="post"
                                                action="{{ route('paadmin.periods.update', $p->id) }}" class="d-flex gap-2">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="code" value="{{ $p->code }}">
                                                <input type="text" name="name" value="{{ $p->name }}"
                                                    class="form-control form-control-sm">
                                            </form>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <input type="date" form="{{ $fid }}" name="start_date"
                                                    value="{{ $p->start_date?->format('Y-m-d') }}"
                                                    class="form-control form-control-sm">
                                                <input type="date" form="{{ $fid }}" name="end_date"
                                                    value="{{ $p->end_date?->format('Y-m-d') }}"
                                                    class="form-control form-control-sm">
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            @if ($p->is_active)
                                                <span class="badge bg-success">ใช้งาน</span>
                                            @else
                                                <span class="badge bg-secondary">ปิด</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary"
                                                form="{{ $fid }}">บันทึก</button>

                                            @if (!$p->is_active)
                                                <form method="post"
                                                    action="{{ route('paadmin.periods.activate', $p->id) }}"
                                                    class="d-inline">
                                                    @csrf @method('PUT')
                                                    <button class="btn btn-sm btn-outline-success"
                                                        onclick="return confirm('ตั้งรอบนี้เป็นรอบที่ใช้งาน? จะปิดรอบอื่นอัตโนมัติ')">ตั้งใช้งาน</button>
                                                </form>
                                            @else
                                                <button class="btn btn-sm btn-success" disabled>กำลังใช้งาน</button>
                                            @endif

                                            <form method="post" action="{{ route('paadmin.periods.destroy', $p->id) }}"
                                                class="d-inline"
                                                onsubmit="return confirm('ลบรอบนี้และข้อมูลที่เกี่ยวข้อง ใช่ไหม?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">ยังไม่มีรอบประเมิน</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (method_exists($periods, 'links'))
                        <div class="card-footer">{{ $periods->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
