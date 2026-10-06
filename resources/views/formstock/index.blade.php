@extends('layouts.layout')

@section('title', 'Stock Withdrawal Alert')
@section('page-title', 'Stock Withdrawal Alert')
@push('head')<meta http-equiv="refresh" content="300">@endpush

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">รายการเตรียมเบิกวัตถุดิบ</h4><div class="text-muted">ดูงานที่ใกล้ถึงกำหนดเบิก โดยคำนวณจาก Due Date และระยะเวลาเตรียมของ · อัปเดต {{ $refreshedAt->format('d/m/Y H:i') }}</div></div>
        <div>@if(auth()->user()?->hasRoleCode('STOCK_ADMIN'))<a href="{{ route('stock-withdrawal.master.index') }}" class="btn btn-outline-secondary">Standard Part Master</a>@endif
            <a href="{{ route('stock-withdrawal.export', request()->query()) }}" class="btn btn-success">Export CSV</a></div>
    </div>
    <div class="row g-2 mb-3">
        @foreach(['GREEN'=>'ยังไม่ถึงกำหนด','YELLOW'=>'ใกล้ถึงกำหนด','ORANGE'=>'ควรเบิกวันนี้','RED'=>'เกินกำหนด','GRAY'=>'รอกำหนดมาตรฐาน'] as $code=>$label)
        <div class="col"><div class="card border-0 shadow-sm"><div class="card-body"><span class="risk-dot risk-{{ strtolower($code) }}"></span>{{ $label }}<div class="fs-3 fw-bold">{{ number_format($summary[$code]) }}</div></div></div></div>
        @endforeach
    </div>
    <form method="GET" class="card card-body border-0 shadow-sm mb-3"><div class="row g-2 align-items-end">
        <div class="col-lg-1 col-md-2"><label class="form-label">โรงงาน</label><select name="site" class="form-select"><option value="">ทั้งหมด</option>@foreach(['WIRE','PLUS'] as $site)<option @selected(($filters['site'] ?? '')===$site)>{{ $site }}</option>@endforeach</select></div>
        <div class="col-lg-2 col-md-3"><label class="form-label">เลข MFG</label><input id="stock-mfg-filter" name="mfg" value="{{ $filters['mfg'] ?? '' }}" class="form-control" placeholder="พิมพ์เพื่อค้นหา MFG"></div>
        <div class="col-lg-2 col-md-3"><label class="form-label">Part</label><input id="stock-part-filter" name="part" value="{{ $filters['part'] ?? '' }}" class="form-control" placeholder="พิมพ์รหัสหรือชื่อ Part"></div>
        <div class="col-lg-2 col-md-3"><label class="form-label">Due Date ตั้งแต่</label><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control"></div>
        <div class="col-lg-2 col-md-3"><label class="form-label">ถึงวันที่</label><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control"></div>
        <div class="col-lg-1 col-md-2"><label class="form-label">สถานะ</label><select name="status" class="form-select"><option value="">ทั้งหมด</option>@foreach(['ยังไม่เบิก','เบิกบางส่วน','เบิกครบแล้ว'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ $s }}</option>@endforeach</select></div>
        <div class="col-lg-1 col-md-2"><label class="form-label">ความเสี่ยง</label><select name="risk" class="form-select"><option value="">ทั้งหมด</option>@foreach(['GREEN'=>'ปกติ','YELLOW'=>'ใกล้กำหนด','ORANGE'=>'วันนี้','RED'=>'เกินกำหนด','GRAY'=>'ไม่มีมาตรฐาน'] as $v=>$t)<option value="{{ $v }}" @selected(($filters['risk'] ?? '')===$v)>{{ $t }}</option>@endforeach</select></div>
        <div class="col-lg-1 col-md-2"><label class="form-label">ข้อมูล</label><select name="mode" class="form-select"><option value="mock" @selected(($filters['mode'] ?? 'mock')==='mock')>ตัวอย่าง</option><option value="live" @selected(($filters['mode'] ?? '')==='live')>ตามจริง</option></select></div>
        <div class="col-lg-2 col-md-3"><label class="form-label">เรียงลำดับ</label><select name="sort" class="form-select">
            @foreach(['risk_desc'=>'เสี่ยงที่สุดก่อน','due_asc'=>'Due Date ใกล้ก่อน','due_desc'=>'Due Date ไกลก่อน','withdraw_asc'=>'วันที่ควรเบิกใกล้ก่อน','mfg_asc'=>'เลข MFG','part_asc'=>'รหัส Part','remaining_desc'=>'จำนวนคงเหลือมากก่อน'] as $value=>$label)
                <option value="{{ $value }}" @selected(($filters['sort'] ?? 'risk_desc')===$value)>{{ $label }}</option>
            @endforeach
        </select></div>
        <div class="col-lg-1 col-md-2"><label class="form-label">ต่อหน้า</label><select name="per_page" class="form-select">@foreach([25,50,100] as $size)<option value="{{ $size }}" @selected(($filters['per_page'] ?? 50)==$size)>{{ $size }}</option>@endforeach</select></div>
        <div class="col-lg-1 col-md-2 d-flex gap-1"><button class="btn btn-primary flex-fill">ค้นหา</button><a href="{{ route('stock-withdrawal.index') }}" class="btn btn-outline-secondary" title="ล้างตัวกรอง">ล้าง</a></div>
    </div></form>
    <div class="d-flex justify-content-between align-items-center mb-2"><div class="text-muted small">พบ {{ number_format($rows->total()) }} รายการ</div><div class="text-muted small">หน้า {{ $rows->currentPage() }} / {{ max(1, $rows->lastPage()) }}</div></div>
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="stock-list-head d-none d-lg-grid px-3 py-2 text-muted small fw-semibold">
            <div>MFG / PART</div><div>กำหนดการ</div><div class="text-end">จำนวน</div><div>สถานะ</div><div>ความเสี่ยง</div><div></div>
        </div>
        <div class="list-group list-group-flush">
        @forelse($rows as $row)
            <div class="list-group-item stock-list-item risk-border-{{ strtolower($row['risk_code']) }} px-3 py-3">
                <div class="stock-list-grid">
                    <div>
                        <div class="d-flex align-items-center gap-2"><span class="badge bg-dark">{{ $row['site'] }}</span><strong>{{ $row['mfg'] }}</strong></div>
                        <div class="fw-semibold text-primary mt-1">{{ $row['partnumber'] }}</div>
                        <div class="text-muted small text-truncate" title="{{ $row['description'] }}">{{ $row['description'] ?: '-' }}</div>
                    </div>
                    <div class="small">
                        <div><span class="text-muted">Due:</span> <strong>{{ $row['due_date'] }}</strong></div>
                        <div><span class="text-muted">ควรเบิก:</span> <strong>{{ $row['withdraw_date'] ?: '-' }}</strong></div>
                        <div class="text-muted">Standard {{ $row['standard_days'] === null ? '-' : $row['standard_days'].' วัน' }}</div>
                    </div>
                    <div class="text-lg-end small">
                        <div><span class="text-muted">ต้องใช้</span> {{ number_format($row['required_qty'],2) }} <span class="badge bg-warning text-dark">MOCK</span></div>
                        <div><span class="text-muted">เบิกแล้ว</span> <a href="{{ route('stock-withdrawal.issues', ['site'=>$row['site'],'workorder_id'=>$row['workorder_id'],'parts_id'=>$row['parts_id'],'mfg'=>$row['mfg'],'partnumber'=>$row['partnumber']]) }}">{{ number_format($row['issued_qty'],2) }}</a></div>
                        <div><span class="text-muted">คงเหลือ</span> <strong>{{ number_format($row['remaining_qty'],2) }}</strong></div>
                    </div>
                    <div><span class="badge bg-light text-dark border">{{ $row['withdrawal_status'] }}</span>@if($row['over_issued_qty'] > 0)<div class="small text-danger mt-1">เบิกเกิน {{ number_format($row['over_issued_qty'],2) }}</div>@endif</div>
                    <div><span class="badge risk-bg-{{ strtolower($row['risk_code']) }}">{{ $row['risk_label'] }}</span></div>
                    <div class="text-lg-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('stock-withdrawal.issues', ['site'=>$row['site'],'workorder_id'=>$row['workorder_id'],'parts_id'=>$row['parts_id'],'mfg'=>$row['mfg'],'partnumber'=>$row['partnumber']]) }}">ดูรายการเบิก</a></div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-5">ไม่พบข้อมูล</div>
        @endforelse
        </div>
    </div>
    @if($rows->hasPages())<div class="d-flex justify-content-center mt-3">{{ $rows->onEachSide(1)->links() }}</div>@endif
</div>
<style>
.risk-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:8px}.risk-green,.risk-bg-green{background:#198754}.risk-yellow,.risk-bg-yellow{background:#ffc107;color:#212529}.risk-orange,.risk-bg-orange{background:#fd7e14}.risk-red,.risk-bg-red{background:#dc3545}.risk-gray,.risk-bg-gray{background:#6c757d}.risk-bg-green,.risk-bg-orange,.risk-bg-red,.risk-bg-gray{color:#fff}
.stock-list-head,.stock-list-grid{display:grid;grid-template-columns:minmax(240px,2fr) minmax(155px,1.2fr) minmax(170px,1.2fr) minmax(120px,.8fr) minmax(150px,1fr) minmax(110px,.7fr);gap:1rem;align-items:center}.stock-list-head{background:#f8f9fa}.stock-list-item{border-left:5px solid transparent!important}.risk-border-green{border-left-color:#198754!important}.risk-border-yellow{border-left-color:#ffc107!important}.risk-border-orange{border-left-color:#fd7e14!important}.risk-border-red{border-left-color:#dc3545!important}.risk-border-gray{border-left-color:#6c757d!important}@media(max-width:991.98px){.stock-list-grid{grid-template-columns:1fr 1fr}.stock-list-grid>div:first-child{grid-column:1/-1}}@media(max-width:575.98px){.stock-list-grid{grid-template-columns:1fr}.stock-list-grid>div:first-child{grid-column:auto}}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const site = () => document.querySelector('[name="site"]')?.value || '';
    const setupSuggest = (selector, type, placeholder) => {
        const el = document.querySelector(selector);
        if (!el || typeof TomSelect === 'undefined') return;
        new TomSelect(el, {
            valueField: 'value', labelField: 'text', searchField: ['value', 'text'],
            maxItems: 1, create: true, persist: false, preload: false,
            placeholder: placeholder,
            loadThrottle: 250,
            load(query, callback) {
                if ((query || '').trim().length < 1) return callback();
                const url = `{{ url('/stock-withdrawal/suggest') }}/${type}?q=${encodeURIComponent(query)}&site=${encodeURIComponent(site())}`;
                fetch(url, {headers: {'Accept': 'application/json'}})
                    .then(response => response.ok ? response.json() : [])
                    .then(rows => callback(rows || []))
                    .catch(() => callback());
            },
            render: {
                option(data, escape) {
                    return `<div class="py-1"><div class="fw-semibold">${escape(data.value)}</div><div class="small text-muted">${escape(data.text)}</div></div>`;
                },
                item(data, escape) { return `<div>${escape(data.value)}</div>`; }
            }
        });
    };
    setupSuggest('#stock-mfg-filter', 'mfg', 'ค้นหาเลข MFG');
    setupSuggest('#stock-part-filter', 'part', 'ค้นหา Part');
    window.setTimeout(() => window.location.reload(), 300000);
});
</script>
@endsection
