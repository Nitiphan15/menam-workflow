@extends('layouts.layout')

@section('title', 'MFG Defect Analysis')
@section('page-title', 'MFG Defect Analysis')

@section('content')
    <style>
        .mfgd-page { font-size: .9rem; }
        .mfgd-card { border: 0; border-radius: 14px; box-shadow: 0 4px 18px rgba(15, 23, 42, .08); }
        .mfgd-kpi { border-left: 4px solid #0d6efd; }
        .mfgd-kpi.danger { border-left-color: #dc3545; }
        .mfgd-kpi.success { border-left-color: #198754; }
        .mfgd-kpi.warning { border-left-color: #fd7e14; }
        .mfgd-kpi .label { color: #64748b; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
        .mfgd-kpi .value { color: #0f172a; font-size: 1.45rem; font-weight: 700; }
        .mfgd-table { white-space: nowrap; }
        .mfgd-table thead th { position: sticky; top: 0; z-index: 2; background: #1e293b; color: white; font-size: .78rem; }
        .mfgd-table td { vertical-align: middle; }
        .waste-high { background: #fee2e2 !important; color: #991b1b; font-weight: 700; }
        .waste-mid { background: #ffedd5 !important; color: #9a3412; font-weight: 600; }
        .prefix-rank { min-width: 290px; }
    </style>

    <div class="container-fluid py-4 mfgd-page">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">MFG Defect Analysis</h1>
                <div class="text-muted">วิเคราะห์ Work Order จากข้อมูล CPA 30 · ของเสีย % = ของเสีย ÷ (ของดี + ของเสีย) × 100</div>
            </div>
            <span class="badge rounded-pill text-bg-dark px-3 py-2">
                {{ $filters['all_dates'] ? 'ทุกวันที่' : (($filters['date_from'] ?: 'เริ่มต้น') . ' ถึง ' . ($filters['date_to'] ?: 'ปัจจุบัน')) }}
            </span>
        </div>

        @if (session('warning'))
            <div class="alert alert-warning py-2">{{ session('warning') }}</div>
        @endif

        @foreach ($dataErrors as $error)
            <div class="alert alert-warning py-2">โหลดข้อมูลบางส่วนไม่สำเร็จ: {{ $error }}</div>
        @endforeach

        <div class="card mfgd-card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('mfg-defect.index') }}" class="row g-3 align-items-end">
                    <div class="col-6 col-lg-2">
                        <label class="form-label">วันที่เริ่มต้น</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label">วันที่สิ้นสุด</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                    </div>
                    <div class="col-12 col-md-4 col-lg-2">
                        <label class="form-label">MFG / เลขที่ผลิต</label>
                        <input type="text" name="mfg" class="form-control" value="{{ $filters['mfg'] }}" placeholder="เช่น W12345">
                    </div>
                    <div class="col-6 col-md-2 col-lg-1">
                        <label class="form-label">ขึ้นต้นด้วย</label>
                        <input type="text" name="prefix" class="form-control text-uppercase" value="{{ $filters['prefix'] }}" placeholder="W / F / G">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label">โรงงาน</label>
                        <select name="site" class="form-select">
                            <option value="all" @selected($filters['site'] === 'all')>Wire + Plus</option>
                            <option value="wire" @selected($filters['site'] === 'wire')>Wire</option>
                            <option value="plus" @selected($filters['site'] === 'plus')>Plus</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2 col-lg-1">
                        <label class="form-label">แถว/หน้า</label>
                        <select name="per_page" class="form-select">
                            @foreach ([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-lg-2 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary"><i class="fas fa-search me-1"></i>ค้นหา</button>
                        <a href="{{ route('mfg-defect.export', request()->query()) }}" class="btn btn-success">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </a>
                        <a href="{{ route('mfg-defect.index', ['all_dates' => 1]) }}" class="btn btn-outline-dark">ทุกวันที่</a>
                        <a href="{{ route('mfg-defect.index') }}" class="btn btn-outline-secondary">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-2">
                <div class="card mfgd-card mfgd-kpi h-100"><div class="card-body">
                    <div class="label">Work Orders</div><div class="value">{{ number_format($summary['workorders']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-2">
                <div class="card mfgd-card mfgd-kpi success h-100"><div class="card-body">
                    <div class="label">ของดี</div><div class="value">{{ number_format($summary['good_qty'], 2) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-2">
                <div class="card mfgd-card mfgd-kpi danger h-100"><div class="card-body">
                    <div class="label">ของเสีย</div><div class="value">{{ number_format($summary['defect_qty'], 2) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-2">
                <div class="card mfgd-card mfgd-kpi danger h-100"><div class="card-body">
                    <div class="label">% ของเสียรวม</div><div class="value">{{ number_format($summary['defect_pct'], 2) }}%</div>
                </div></div>
            </div>
            <div class="col-6 col-xl-2">
                <div class="card mfgd-card mfgd-kpi warning h-100"><div class="card-body">
                    <div class="label">Balance</div><div class="value">{{ number_format($summary['balance_qty'], 2) }}</div>
                </div></div>
            </div>
            <div class="col-12 col-xl-2">
                @php $topPrefix = $prefixSummary->first(); @endphp
                <div class="card mfgd-card mfgd-kpi danger h-100"><div class="card-body">
                    <div class="label">Prefix เสียสูงสุด</div>
                    <div class="value">{{ $topPrefix->prefix ?? '-' }}</div>
                    <small class="text-muted">{{ isset($topPrefix) ? number_format($topPrefix->defect_pct, 2) . '%' : 'ไม่มีข้อมูล' }}</small>
                </div></div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-5">
                <div class="card mfgd-card h-100">
                    <div class="card-header bg-white fw-semibold">อันดับ MFG Prefix ที่มี % ของเสียสูง</div>
                    <div class="table-responsive prefix-rank">
                        <table class="table table-sm table-hover mb-0">
                            <thead><tr><th>#</th><th>ขึ้นต้น</th><th class="text-end">WO</th><th class="text-end">ของดี</th><th class="text-end">ของเสีย</th><th class="text-end">% เสีย</th></tr></thead>
                            <tbody>
                                @forelse ($prefixSummary->take(10) as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td><a href="{{ route('mfg-defect.index', array_merge(request()->except('page'), ['prefix' => $item->prefix])) }}" class="badge text-bg-dark text-decoration-none">{{ $item->prefix }}</a></td>
                                        <td class="text-end">{{ number_format($item->workorders) }}</td>
                                        <td class="text-end">{{ number_format($item->good_qty, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->defect_qty, 2) }}</td>
                                        <td class="text-end fw-bold text-danger">{{ number_format($item->defect_pct, 2) }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-7">
                <div class="card mfgd-card h-100">
                    <div class="card-body">
                        <h2 class="h6">หลักการอ่านผล</h2>
                        <ul class="mb-0 text-muted">
                            <li>แถวทั้งหมดรวมทั้ง WO ที่ Balance เป็นศูนย์หรือติดลบ เพื่อให้ตรวจย้อนหลังได้ครบ</li>
                            <li>สีแดงคือของเสียตั้งแต่ 5% ขึ้นไป สีส้มคือตั้งแต่ 1% แต่ต่ำกว่า 5%</li>
                            <li>อันดับ Prefix คำนวณจากยอดของดีและของเสียรวมของทุก WO ในตัวกรองปัจจุบัน</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mfgd-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">รายละเอียด Work Order</span>
                <span class="text-muted">{{ number_format($rows->total()) }} รายการ</span>
            </div>
            <div class="table-responsive" style="max-height: 68vh;">
                <table class="table table-sm table-striped table-hover mfgd-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th><th>โรงงาน</th><th>วันที่</th><th>รายการเลขที่</th><th>รหัสสินค้า</th><th>ชื่อสินค้า</th>
                            <th class="text-end">จำนวนสั่งผลิต</th><th class="text-end">เบิกวัตถุดิบ</th><th class="text-end">ของดี</th>
                            <th class="text-end">ของเสีย</th><th class="text-end">% ของเสีย</th><th class="text-end">คืนวัตถุดิบ</th>
                            <th class="text-end">Balance</th><th>ประเภทสินค้า</th><th>รหัสกลุ่มสินค้า</th><th>กลุ่มสินค้า</th>
                            <th>รหัสหมวดสินค้า</th><th>หมวดสินค้า</th><th>เลขที่คำสั่งขาย</th><th>รหัสลูกค้า</th>
                            <th>ชื่อลูกค้า</th><th>กำหนดส่ง</th><th>วันที่เบิก</th><th>Packaging</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $rate = (float) $row->defect_pct;
                                $rateClass = $rate >= 5 ? 'waste-high' : ($rate >= 1 ? 'waste-mid' : '');
                            @endphp
                            <tr>
                                <td>{{ $rows->firstItem() + $loop->index }}</td>
                                <td><span class="badge {{ $row->site === 'Wire' ? 'text-bg-primary' : 'text-bg-success' }}">{{ $row->site }}</span></td>
                                <td>{{ \Illuminate\Support\Str::of($row->document_date)->substr(0, 10) }}</td>
                                <td><strong>{{ $row->workorder_no }}</strong></td>
                                <td>{{ $row->part_no }}</td><td>{{ $row->part_name }}</td>
                                <td class="text-end">{{ number_format($row->order_qty, 2) }}</td>
                                <td class="text-end">{{ number_format($row->issued_qty, 2) }}</td>
                                <td class="text-end">{{ number_format($row->good_qty, 2) }}</td>
                                <td class="text-end">{{ number_format($row->defect_qty, 2) }}</td>
                                <td class="text-end {{ $rateClass }}">{{ number_format($rate, 2) }}%</td>
                                <td class="text-end">{{ number_format($row->return_rm_qty, 2) }}</td>
                                <td class="text-end">{{ number_format($row->balance_qty, 2) }}</td>
                                <td>{{ $row->part_type }}</td><td>{{ $row->group_code }}</td><td>{{ $row->group_name }}</td>
                                <td>{{ $row->category_code }}</td><td>{{ $row->category_name }}</td><td>{{ $row->sales_order_no }}</td>
                                <td>{{ $row->customer_code }}</td><td>{{ $row->customer_name }}</td>
                                <td>{{ \Illuminate\Support\Str::of($row->due_date)->substr(0, 10) }}</td>
                                <td>{{ \Illuminate\Support\Str::of($row->issued_at)->substr(0, 19) }}</td>
                                <td>{{ $row->packaging }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="24" class="text-center text-muted py-5">ไม่พบ Work Order ตามตัวกรอง</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="card-footer bg-white">{{ $rows->links() }}</div>
            @endif
        </div>
    </div>
@endsection
