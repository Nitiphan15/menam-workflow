@extends('layouts.layout')
@section('title', 'เพิ่มแผนรายการเบิก')
@section('page-title', 'เพิ่มแผนรายการเบิก')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">เพิ่มแผนรายการเบิก</h4><div class="text-muted">เลือก MFG แล้วระบบดึง Part Number จาก BOM อัตโนมัติ</div></div>
        <a href="{{ route('stock-withdrawal.index') }}" class="btn btn-outline-secondary">กลับรายการ</a>
    </div>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('stock-withdrawal.plans.store') }}">@csrf
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <label class="form-label fw-semibold">ค้นหา MFG</label>
            <select id="mfg-lookup" placeholder="พิมพ์เลข MFG..."></select>
            <input type="hidden" name="site" id="site"><input type="hidden" name="workorder_id" id="workorder_id">
            <div class="row g-3 mt-2">
                @foreach(['mfg'=>'MFG','size'=>'ขนาด','quantity'=>'จำนวน','customer'=>'ลูกค้า','delivery'=>'วันส่งมอบ','note'=>'หมายเหตุ'] as $id=>$label)
                    <div class="col-md-{{ $id==='note' ? 12 : 4 }}"><div class="text-muted small">{{ $label }}</div><div class="fw-semibold" id="show-{{ $id }}">-</div></div>
                @endforeach
            </div>
        </div></div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-3"><label class="form-label fw-semibold">วันที่ต้องขึ้นผลิต</label><input type="date" name="production_date" id="production_date" value="{{ old('production_date') }}" class="form-control" required></div>
            <div class="col-md-9"><label class="form-label">หมายเหตุแผน</label><input name="plan_note" value="{{ old('plan_note') }}" class="form-control"></div>
        </div></div></div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>รายการ BOM ที่ต้องเบิก</strong><div class="small text-muted">F* = FG, R* = RM · NCR ยังไม่ใช้</div></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>กลุ่ม</th><th>Part Number / รายละเอียด</th><th>ขนาด</th><th class="text-end">จำนวน</th><th>Lead Time</th><th>วันที่แนะนำ</th><th style="min-width:160px">วันที่ต้องเบิก</th><th>หมายเหตุ</th></tr></thead>
                <tbody id="items"><tr><td colspan="8" class="text-center text-muted py-4">กรุณาเลือก MFG</td></tr></tbody>
            </table></div>
        </div>
        <div class="text-end"><button class="btn btn-primary px-4" id="save-btn" disabled>บันทึกแผนรายการเบิก</button></div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.getElementById('items');
    const productionDate = document.getElementById('production_date');
    let bomItems = [];
    const subtractWorkingDays = (text, days) => {
        if (!text || days === null) return '';
        const date = new Date(text + 'T00:00:00');
        while (days > 0) { date.setDate(date.getDate() - 1); if (date.getDay() !== 0) days--; }
        return date.toISOString().slice(0, 10);
    };
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    const renderItems = () => {
        if (!bomItems.length) {
            items.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">ไม่พบ BOM Part ที่ขึ้นต้นด้วย F หรือ R</td></tr>';
            document.getElementById('save-btn').disabled = true;
            return;
        }
        let valid = true;
        items.innerHTML = bomItems.map((item, index) => {
            const recommended = subtractWorkingDays(productionDate.value, item.lead_time_days);
            if (!item.type_id) valid = false;
            return `<tr><td><span class="badge ${item.type_code === 'FG' ? 'bg-primary' : 'bg-success'}">${escapeHtml(item.type_code)}</span></td>
                <td><strong>${escapeHtml(item.partnumber)}</strong><div class="small text-muted">${escapeHtml(item.part_description || '-')}</div><input type="hidden" name="items[${index}][source_part_id]" value="${item.source_part_id}"></td>
                <td>${escapeHtml(item.size || '-')}</td><td class="text-end">${Number(item.quantity || 0).toLocaleString()} ${escapeHtml(item.unit || '')}</td>
                <td>${item.type_id ? `${item.lead_time_days} วันทำงาน` : '<span class="text-danger">ยังไม่มี Master</span>'}</td><td>${recommended || '-'}</td>
                <td><input type="date" name="items[${index}][planned_withdraw_date]" value="${recommended}" class="form-control" required></td>
                <td><input name="items[${index}][remark]" class="form-control"></td></tr>`;
        }).join('');
        document.getElementById('save-btn').disabled = !valid || !productionDate.value;
    };
    const loadBom = async (site, workorderId) => {
        items.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">กำลังโหลด BOM...</td></tr>';
        const response = await fetch(`{{ url('/stock-withdrawal/mfg') }}/${site}/${workorderId}/bom`);
        if (!response.ok) throw new Error('โหลด BOM ไม่สำเร็จ');
        bomItems = (await response.json()).items || [];
        renderItems();
    };
    productionDate.addEventListener('change', renderItems);
    new TomSelect('#mfg-lookup', {
        valueField: 'id', labelField: 'text', searchField: ['text'], maxItems: 1, create: false, loadThrottle: 300,
        load(query, callback) { if (query.length < 1) return callback(); fetch(`{{ route('stock-withdrawal.mfg-search') }}?q=${encodeURIComponent(query)}&limit=20`).then(response => response.json()).then(json => callback(json.results || [])).catch(() => callback()); },
        render: {
            option(data, escape) { return `<div><strong>${escape(data.meta.site)} · ${escape(data.id)}</strong><div class="small text-muted">${escape(data.meta.partnumber || '')} · ${escape(data.meta.customer_name || '-')} · ส่ง ${escape(data.meta.due_date || '-')}</div></div>`; },
            item(data, escape) { return `<div>${escape(data.meta.site)} · ${escape(data.id)}</div>`; }
        },
        onChange(value) {
            const data = this.options[value]?.meta; if (!data) return;
            const site = String(data.site || '').toUpperCase() === 'WIRE' ? 'WIRE' : 'PLUS';
            document.getElementById('site').value = site; document.getElementById('workorder_id').value = data.workorder_id || '';
            document.getElementById('show-mfg').textContent = value; document.getElementById('show-size').textContent = data.size || data.area_label || '-';
            document.getElementById('show-quantity').textContent = `${data.wo_qty ?? '-'} ${data.unit || ''}`; document.getElementById('show-customer').textContent = data.customer_name || '-';
            document.getElementById('show-delivery').textContent = data.due_date || '-'; document.getElementById('show-note').textContent = data.wo_notes || data.plan_description || '-';
            loadBom(site, data.workorder_id).catch(error => { bomItems = []; items.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">${escapeHtml(error.message)}</td></tr>`; });
        }
    });
});
</script>
@endsection
