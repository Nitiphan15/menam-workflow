@extends('layouts.layout')
@section('title', 'วางแผนรายการเบิก')
@section('page-title', 'วางแผนรายการเบิก')
@section('content')
<style>
.bom-items{display:grid;gap:12px}.bom-item-card{border:1px solid #dfe6ee;border-radius:10px;background:#fff;overflow:hidden}.bom-item-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5eaf0}.bom-part{display:flex;align-items:flex-start;gap:10px;min-width:0}.bom-part .part-code{font-size:1rem}.bom-part .part-description{color:#64748b;font-size:.82rem;margin-top:2px}.bom-qty{text-align:right;white-space:nowrap}.bom-qty strong{font-size:1.05rem}.bom-item-fields{display:grid;grid-template-columns:minmax(175px,1fr) minmax(260px,1.4fr) minmax(310px,1.55fr) minmax(180px,1fr);gap:16px;padding:14px 16px;align-items:start}.bom-field{min-width:0}.field-caption{display:block;color:#64748b;font-size:.75rem;font-weight:600;margin-bottom:.3rem}.schedule-grid{display:grid;grid-template-columns:minmax(145px,1fr) minmax(125px,.85fr);gap:10px}.completion-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;min-height:38px;padding:.42rem .55rem}.lead-time-value{display:inline-block;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:.35rem .65rem;white-space:nowrap}.split-total{display:block;margin-top:.2rem;white-space:nowrap}#mfg-add-form .ts-control{display:flex;flex-wrap:wrap;gap:6px;padding:8px}#mfg-add-form .ts-control>input{order:-1;flex:1 0 100%!important;width:100%!important;min-width:100%!important;border-bottom:1px solid #e2e8f0!important;padding:2px 2px 8px!important;margin-bottom:2px!important}#mfg-add-form .ts-control>.item{order:1;margin:0!important;background:#eef2ff!important;border:1px solid #c7d2fe!important;border-radius:999px!important;padding:5px 9px!important}.save-dock{position:sticky;z-index:20;bottom:10px;background:rgba(255,255,255,.97);border:1px solid #dbe3ea;border-radius:10px;box-shadow:0 -4px 18px rgba(15,23,42,.12);backdrop-filter:blur(8px);overflow:hidden}.save-dock-inner{padding:12px 16px;display:flex;align-items:center;gap:18px}.save-progress-wrap{flex:1;min-width:180px}.save-progress{height:7px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:7px}.save-progress-bar{height:100%;width:0;background:#198754;transition:width .2s ease}.mfg-plan.is-complete>.card-header{border-left:5px solid #198754}.mfg-plan.is-incomplete>.card-header{border-left:5px solid #f59e0b}@media(max-width:1200px){.bom-item-fields{grid-template-columns:repeat(2,minmax(260px,1fr))}}@media(max-width:767px){.bom-item-head{align-items:flex-start}.bom-item-fields{grid-template-columns:1fr}.schedule-grid{grid-template-columns:1fr 1fr}.save-dock-inner{padding:10px 12px;gap:10px}.save-dock .btn{white-space:nowrap}}
</style>
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="fw-semibold">MFG ที่เลือก {{ $mfgs->count() }} รายการ</div><div class="small text-muted">จำนวนเบิกห้ามเกิน BOM Qty</div></div><a href="{{ route('stock-withdrawal.all-mfg') }}" class="btn btn-outline-secondary">กลับงานที่ผลิตทั้งหมด</a></div>
    <form method="GET" action="{{ route('stock-withdrawal.create') }}" class="card card-body border-0 shadow-sm mb-3" id="mfg-add-form">
        <label class="form-label fw-semibold">ค้นหาและเลือก MFG เพิ่ม</label>
        <div class="d-flex gap-2 align-items-start"><div class="flex-grow-1"><select id="mfg-multi-lookup" name="selected[]" multiple placeholder="พิมพ์เลข MFG หรือ Part Number...">@foreach($mfgs as $mfg)<option value="{{ $mfg->site }}|{{ $mfg->workorder_id }}" selected>{{ $mfg->site }} · {{ $mfg->mfg }} · {{ $mfg->partnumber }}</option>@endforeach</select></div><button class="btn btn-primary text-nowrap">โหลดรายการที่เลือก</button></div>
        <div class="form-text">พิมพ์ค้นหาด้านบน · MFG ที่เลือกจะแสดงเป็นป้ายด้านล่าง และกด × เพื่อเอาออกได้</div>
    </form>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if($mfgs->isEmpty())<div class="alert alert-warning">ยังไม่ได้เลือก MFG กรุณากลับไปเลือกรายการก่อน</div>@else
    <form method="POST" action="{{ route('stock-withdrawal.plans.store') }}" id="plan-form" class="plan-form">@csrf
        @foreach($mfgs as $planIndex => $mfg)
        <section class="card border-0 shadow-sm mb-4 mfg-plan" data-index="{{ $planIndex }}" data-site="{{ $mfg->site }}" data-workorder="{{ $mfg->workorder_id }}" data-mfg="{{ $mfg->mfg }}">
            <div class="card-header bg-white"><span class="badge bg-dark">{{ $mfg->site }}</span> <strong class="ms-1">{{ $mfg->mfg }}</strong> <span class="text-primary ms-2">{{ $mfg->partnumber }}</span></div>
            <div class="card-body">
                <input type="hidden" name="plans[{{ $planIndex }}][site]" value="{{ $mfg->site }}"><input type="hidden" name="plans[{{ $planIndex }}][workorder_id]" value="{{ $mfg->workorder_id }}">
                <div class="row g-3 mb-3">
                    <div class="col-md-2"><div class="text-muted small">ขนาด</div><strong>{{ $mfg->size ?: '-' }}</strong></div><div class="col-md-2"><div class="text-muted small">จำนวน MFG</div><strong>{{ number_format((float)$mfg->quantity,2) }} {{ $mfg->unit }}</strong></div><div class="col-md-8"><div class="text-muted small">ลูกค้า</div><strong>{{ $mfg->customer_name ?: '-' }}</strong></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">กำหนดส่งมอบ <span class="text-danger">*</span></label><input type="date" name="plans[{{ $planIndex }}][delivery_date]" value="{{ $mfg->delivery_date }}" class="form-control" required><div class="form-text">จากใบสั่งผลิต แก้ได้</div></div><div class="col-md-3"><label class="form-label fw-semibold">วันที่ต้องขึ้นผลิต <span class="text-danger">*</span></label><input type="date" name="plans[{{ $planIndex }}][production_date]" class="form-control production-date" required></div><div class="col-md-6"><label class="form-label">หมายเหตุแผน</label><input name="plans[{{ $planIndex }}][plan_note]" class="form-control"></div>
                </div>
                <div class="bom-items"><div class="text-center text-muted py-3">กำลังโหลด BOM...</div></div>
            </div>
        </section>
        @endforeach
        <div class="save-dock" aria-live="polite"><div class="save-dock-inner"><div class="save-progress-wrap"><div class="d-flex justify-content-between align-items-center gap-2"><strong id="ready-count">พร้อมบันทึก 0/{{ $mfgs->count() }} MFG</strong><span class="small text-muted" id="remaining-count">เหลือ {{ $mfgs->count() }} MFG</span></div><div class="save-progress"><div class="save-progress-bar" id="save-progress-bar"></div></div></div><button class="btn btn-primary px-4" id="save-btn" disabled>บันทึกแผนทั้งหมด</button></div></div>
    </form>
    @endif
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const localDateText = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const completionDate = (text, days) => { if (!text) return ''; const [year,month,day]=text.split('-').map(Number); const d=new Date(year,month-1,day); let left=Math.max(1,Number(days)||1)-1; while(left>0){d.setDate(d.getDate()+1);if(d.getDay()!==0)left--;} return localDateText(d); };
    const sections = [...document.querySelectorAll('.mfg-plan')];
    const planForm = document.getElementById('plan-form');
    const sectionComplete = section => Boolean(section._bom?.length) && [...section.querySelectorAll('[required]')].every(field => field.checkValidity());
    const updateProgress = () => {
        const complete = sections.filter(sectionComplete).length, total = sections.length, remaining = total - complete;
        sections.forEach(section => { const done=sectionComplete(section); section.classList.toggle('is-complete',done); section.classList.toggle('is-incomplete',!done); });
        document.getElementById('ready-count').textContent=`พร้อมบันทึก ${complete}/${total} MFG`;
        document.getElementById('remaining-count').textContent=remaining ? `เหลือ ${remaining} MFG` : 'กรอกครบแล้ว';
        document.getElementById('save-progress-bar').style.width=`${total ? complete/total*100 : 0}%`;
        document.getElementById('save-btn').disabled=total===0||complete!==total;
    };
    if (typeof TomSelect !== 'undefined') new TomSelect('#mfg-multi-lookup', {
        valueField:'selection_key', labelField:'text', searchField:['text'], maxItems:null, create:false, persist:false, plugins:['remove_button'], loadThrottle:300,
        load(query, callback) { if((query||'').trim().length<1)return callback(); fetch(`{{ route('stock-withdrawal.mfg-search') }}?q=${encodeURIComponent(query)}&limit=30`).then(r=>r.json()).then(data=>callback((data.results||[]).map(row=>({...row,selection_key:`${String(row.meta?.site||'').toUpperCase()}|${row.meta?.workorder_id}`})))).catch(()=>callback()); },
        render:{option(data,escape){return `<div><strong>${escape(data.meta?.site||'')} · ${escape(data.id||'')}</strong><div class="small text-muted">${escape(data.meta?.partnumber||'')} · ${escape(data.meta?.customer_name||'-')} · ส่ง ${escape(data.meta?.due_date||'-')}</div></div>`;},item(data,escape){return `<div>${escape(data.text||data.selection_key)}</div>`;}}
    });
    const render = section => {
        const date = section.querySelector('.production-date').value;
        const forcedS = String(section.dataset.mfg).replace(/^\+/, '').startsWith('S');
        const rows = section._bom || [];
        section.querySelector('.bom-items').innerHTML = rows.length ? rows.map((item, i) => {
            item.splits ||= [Number(item.quantity || 0)]; item.job_type_code ||= item.job_type_code;
            const split = item.splits.length > 1, baseLead = Number(section._jobTypes?.[item.job_type_code]?.lead_time_days || item.lead_time_days || 0);
            item.request_date ||= localDateText(new Date());
            const lead = forcedS || split ? 3 : baseLead, due = completionDate(item.request_date, lead), qty = Number(item.quantity || 0);
            const options = Object.entries(section._jobTypes || {}).map(([code, meta]) => `<option value="${code}" ${code===item.job_type_code?'selected':''}>${escapeHtml(meta.name)}</option>`).join('');
            const total = item.splits.reduce((sum, amount) => sum + Number(amount || 0), 0);
            const splitInputs = item.splits.map((amount, j) => `<div class="d-flex align-items-center gap-2 mb-2">${split?`<span class="small text-muted text-nowrap" style="width:42px">ก้อน ${j+1}</span>`:''}<div class="input-group input-group-sm flex-grow-1"><input type="number" step="0.0001" min="0.0001" max="${qty}" name="plans[${section.dataset.index}][items][${i}][split_quantities][]" value="${amount || ''}" class="form-control split-qty" data-item="${i}" data-split="${j}" required><span class="input-group-text">${escapeHtml(item.unit||'')}</span></div>${split?`<button type="button" class="btn btn-sm btn-outline-danger remove-split" data-item="${i}" data-split="${j}" title="ลบก้อน">×</button>`:''}</div>`).join('');
            const typeLabel=item.type_code==='ACID'?'กัดกรด':item.type_code,typeClass=item.type_code==='FG'?'bg-primary':(item.type_code==='ACID'?'bg-warning text-dark':'bg-success');
            return `<article class="bom-item-card" data-item-row="${i}"><div class="bom-item-head"><div class="bom-part"><span class="badge ${typeClass} mt-1">${escapeHtml(typeLabel)}</span><div><strong class="part-code">${escapeHtml(item.partnumber)}</strong><div class="part-description">${escapeHtml(item.part_description||'-')}</div></div></div><div class="bom-qty"><span class="field-caption mb-0">BOM Qty</span><strong>${qty.toLocaleString()} ${escapeHtml(item.unit||'')}</strong></div></div><div class="bom-item-fields"><input type="hidden" name="plans[${section.dataset.index}][items][${i}][source_part_id]" value="${item.source_part_id}"><div class="bom-field"><span class="field-caption">ประเภทงาน <span class="text-danger">*</span></span><select name="plans[${section.dataset.index}][items][${i}][job_type_code]" class="form-select form-select-sm job-type" data-item="${i}" required>${options}</select><div class="mt-2"><strong class="lead-time-value">${lead} วัน</strong><span class="small text-muted ms-1">วันทำงาน</span>${split?'<span class="badge bg-warning text-dark ms-2">งานแบ่ง</span>':''}</div></div><div class="bom-field"><span class="field-caption">จำนวนที่จะเบิก <span class="text-danger">*</span></span><div class="split-box">${splitInputs}<button type="button" class="btn btn-sm btn-link px-0 text-decoration-none add-split" data-item="${i}">+ แบ่งจำนวน</button><span class="small split-total ${total>qty?'text-danger fw-bold':'text-muted'}">รวม ${total.toLocaleString()} / ${qty.toLocaleString()} ${escapeHtml(item.unit||'')}</span></div></div><div class="bom-field"><div class="schedule-grid"><div><span class="field-caption">วันที่แจ้งเบิก <span class="text-danger">*</span></span><input type="date" name="plans[${section.dataset.index}][items][${i}][request_date]" value="${item.request_date}" class="form-control request-date" data-item="${i}" required></div><div><span class="field-caption">วันที่ต้องเบิก</span><div class="completion-box"><strong class="completion-date-value">${due||'-'}</strong><div class="small text-muted">นับรวมวันแจ้ง</div></div></div></div></div><div class="bom-field"><span class="field-caption">หมายเหตุ</span><input name="plans[${section.dataset.index}][items][${i}][remark]" class="form-control" placeholder="ระบุเพิ่มเติม..."></div></div></article>`;
        }).join('') : '<div class="text-center text-danger py-3">ไม่พบ BOM Part ที่ขึ้นต้นด้วย F, R, N หรือ S</div>';
        section.querySelectorAll('[data-item-row]').forEach((row, itemIndex) => {
            const field = row.querySelector('.bom-field');
            field?.insertAdjacentHTML('beforeend', `<div class="form-check mt-2"><input type="checkbox" value="1" name="plans[${section.dataset.index}][items][${itemIndex}][is_special]" class="form-check-input" id="special-${section.dataset.index}-${itemIndex}"><label class="form-check-label text-danger fw-semibold" for="special-${section.dataset.index}-${itemIndex}">Special · ต้องเบิกวันนี้</label></div>`);
        });
        updateProgress();
    };
    const updateRowSchedule = (section, itemIndex) => {
        const item=section._bom[itemIndex], row=section.querySelector(`[data-item-row="${itemIndex}"]`); if(!item||!row)return;
        const split=item.splits.length>1, forcedS=String(section.dataset.mfg).replace(/^\+/,'').startsWith('S');
        const baseLead=Number(section._jobTypes?.[item.job_type_code]?.lead_time_days||item.lead_time_days||0);
        const lead=forcedS||split?3:baseLead;
        row.querySelector('.lead-time-value').textContent=`${lead} วัน`;
        row.querySelector('.completion-date-value').textContent=completionDate(item.request_date,lead)||'-';
    };
    const updateSplitTotal = (section, itemIndex) => {
        const item=section._bom[itemIndex], row=section.querySelector(`[data-item-row="${itemIndex}"]`); if(!item||!row)return;
        const total=item.splits.reduce((sum,amount)=>sum+Number(amount||0),0), qty=Number(item.quantity||0), label=row.querySelector('.split-total');
        label.textContent=`รวม ${total.toLocaleString()} / ${qty.toLocaleString()} ${item.unit||''}`;
        label.className=`small split-total ${total>qty?'text-danger fw-bold':'text-muted'}`;
    };
    sections.forEach(section => {
        section.addEventListener('input', e => { if(e.target.matches('.split-qty')) { const i=+e.target.dataset.item; section._bom[i].splits[+e.target.dataset.split]=Number(e.target.value||0); updateSplitTotal(section,i); } updateProgress(); });
        section.addEventListener('change', e => { const i=+e.target.dataset.item; if(e.target.matches('.job-type')) { section._bom[i].job_type_code=e.target.value; updateRowSchedule(section,i); } if(e.target.matches('.request-date')) {section._bom[i].request_date=e.target.value;updateRowSchedule(section,i);} updateProgress(); });
        section.addEventListener('click', e => { const add=e.target.closest('.add-split'), remove=e.target.closest('.remove-split'); if(add){section._bom[+add.dataset.item].splits.push(0); section._bom[+add.dataset.item].planned_date=null; render(section);} if(remove){section._bom[+remove.dataset.item].splits.splice(+remove.dataset.split,1); section._bom[+remove.dataset.item].planned_date=null; render(section);} });
        section.addEventListener('change', e => { if(!e.target.matches('[name$="[is_special]"]'))return;const row=e.target.closest('[data-item-row]'),i=+row.dataset.itemRow;if(e.target.checked)row.querySelector('.completion-date-value').textContent=@json(now('Asia/Bangkok')->toDateString());else updateRowSchedule(section,i); });
        fetch(`{{ url('/stock-withdrawal/mfg') }}/${section.dataset.site}/${section.dataset.workorder}/bom`).then(r => r.ok ? r.json() : Promise.reject()).then(data => { section._bom=data.items||[]; section._jobTypes=data.job_types||{}; render(section); }).catch(() => { section._bom=[]; render(section); });
    });
    planForm?.addEventListener('submit', event => { updateProgress(); if(sections.every(sectionComplete)) return; event.preventDefault(); const first=sections.find(section=>!sectionComplete(section)); first?.scrollIntoView({behavior:'smooth',block:'center'}); first?.querySelector(':invalid')?.reportValidity(); });
    updateProgress();
});
</script>
@endsection
