@extends('layouts.layout')
@section('title','ติดตามสถานะการผลิต')
@section('page-title','ติดตามสถานะการผลิต')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h4 class="mb-1">ติดตามสถานะการผลิต</h4><div class="text-muted">แผนที่ Planner บันทึก เทียบกับยอดเบิกจริงจาก ERP</div></div>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2"><span class="small text-muted" id="erp-latest-time"><i class="far fa-clock me-1" aria-hidden="true"></i>ข้อมูลล่าสุดเมื่อ {{ $refreshedAt?->format('d/m/Y H:i') ?? 'ยังไม่เคยดึงข้อมูล' }}</span><a href="{{ route('stock-withdrawal.export',request()->query()) }}" class="btn btn-success">Export CSV</a></div>
    </div>
    <div class="row g-2 mb-3">
        @foreach(['NOT_STARTED'=>['ยังไม่เบิก','secondary'],'PARTIAL'=>['เบิกบางส่วน','warning'],'COMPLETED'=>['เบิกครบแล้ว','success'],'PLANNER_CLOSED'=>['ปิดโดย Planner','primary'],'CANCELED'=>['ยกเลิก','danger']] as $code=>$meta)
        <div class="col-6 col-lg"><div class="card border-0 shadow-sm"><div class="card-body py-2"><span class="text-muted">{{ $meta[0] }}</span><div class="fs-4 fw-bold text-{{ $meta[1] }}" data-summary-status="{{ $code }}">{{ number_format($summary[$code] ?? 0) }}</div></div></div></div>
        @endforeach
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3" aria-label="มุมมองด่วน">
        @foreach(['today'=>'วันนี้','backlog'=>'งานค้าง','special'=>'Special','unfinished'=>'ยังไม่ครบ','overdue'=>'เลยกำหนด'] as $key=>$label)
            <a class="btn btn-sm {{ ($filters['quick']??'')===$key?'btn-primary':'btn-outline-primary' }}" href="{{ route('stock-withdrawal.part-alert',array_merge(request()->except('page','quick','refresh'),['quick'=>$key])) }}">{{ $label }}</a>
        @endforeach
        <span class="border-start mx-1"></span>
        @foreach(['WIRE'=>'WIRE','PLUS'=>'PLUS'] as $site=>$label)
            <a class="btn btn-sm {{ ($filters['site']??'')===$site?'btn-dark':'btn-outline-dark' }}" href="{{ route('stock-withdrawal.part-alert',array_merge(request()->except('page','site','refresh'),['site'=>$site])) }}">{{ $label }}</a>
        @endforeach
        @if(!empty($filters['site']))<a class="btn btn-sm btn-outline-secondary" href="{{ route('stock-withdrawal.part-alert',request()->except('page','site','refresh')) }}">ทุกโรงงาน</a>@endif
        <button class="btn btn-sm btn-outline-success" type="button" id="refresh-visible-erp" data-url="{{ route('stock-withdrawal.refresh-erp') }}" @disabled(!$snapshotEnabled)><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>ดึงยอด ERP ล่าสุด (หน้านี้)</button>
        <span class="small text-muted align-self-center d-none" id="erp-refresh-progress" role="status" aria-live="polite"></span>
    </div>
    <div class="nav nav-pills mb-3" aria-label="กลุ่มแผนเบิก">
        <a class="nav-link {{ empty($filters['section'])?'active':'' }}" href="{{ route('stock-withdrawal.part-alert',request()->except('section','page')) }}">ทั้งหมด</a>
        <a class="nav-link {{ ($filters['section']??'')==='1'?'active':'' }}" href="{{ route('stock-withdrawal.part-alert',array_merge(request()->except('page'),['section'=>1])) }}">RM / กัดกรด</a>
        <a class="nav-link {{ ($filters['section']??'')==='2'?'active':'' }}" href="{{ route('stock-withdrawal.part-alert',array_merge(request()->except('page'),['section'=>2])) }}">FG / NCR / S</a>
    </div>
    <form method="GET" id="tracking-filter-form">
        <input type="hidden" name="quick" value="{{ $filters['quick']??'' }}">
        <input type="hidden" name="section" value="{{ $filters['section']??'' }}">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body"><div class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label">วันที่เข้าคิว ตั้งแต่</label><input type="date" name="date_from" value="{{ $filters['date_from']??'' }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">ถึงวันที่</label><input type="date" name="date_to" value="{{ $filters['date_to']??'' }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">สถานะ</label><select name="status" class="form-select"><option value="">ทั้งหมด</option>@foreach(['NOT_STARTED'=>'ยังไม่เบิก','PARTIAL'=>'เบิกบางส่วน','COMPLETED'=>'เบิกครบแล้ว','PLANNER_CLOSED'=>'ปิดโดย Planner','CANCELED'=>'ยกเลิก'] as $v=>$t)<option value="{{ $v }}" @selected(($filters['status']??'')===$v)>{{ $t }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Special</label><select name="special" class="form-select"><option value="">ทั้งหมด</option><option value="1" @selected(($filters['special']??'')==='1')>Special</option><option value="0" @selected(($filters['special']??'')==='0')>งานปกติ</option></select></div>
            <div class="col-md-2"><label class="form-label">เรียงลำดับ</label><select name="sort" class="form-select">@foreach(['queue_asc'=>'วันที่เข้าคิว','production_asc'=>'วันที่ขึ้นผลิต','withdraw_asc'=>'วันที่ต้องเบิก','mfg_asc'=>'MFG','remaining_desc'=>'คงเหลือมากก่อน'] as $v=>$t)<option value="{{ $v }}" @selected(($filters['sort']??'queue_asc')===$v)>{{ $t }}</option>@endforeach</select></div>
            <div class="col-md-1"><label class="form-label">ต่อหน้า</label><select name="per_page" class="form-select">@foreach([50,100,150,200] as $n)<option value="{{ $n }}" @selected((int)($filters['per_page']??50)===$n)>{{ $n }}</option>@endforeach</select></div>
            <div class="col-md-1 d-flex gap-1"><button class="btn btn-primary">ค้นหา</button><a href="{{ route('stock-withdrawal.part-alert') }}" class="btn btn-outline-secondary">ล้าง</a></div>
        </div></div></div>
        @php
            $filterRemoveMap = [
                'มุมมองด่วน' => ['quick'], 'กลุ่มแผน' => ['section'],
                'วันที่เข้าคิวตั้งแต่' => ['date_from', 'date_to'], 'ถึงวันที่' => ['date_from', 'date_to'],
                'สถานะ' => ['status'], 'Special' => ['special'], 'MFG' => ['mfg'],
                'โรงงาน' => ['site'], 'Part' => ['part'], 'รายละเอียด' => ['description'],
                'ลูกค้า' => ['customer'], 'ประเภทงาน' => ['job_type'],
            ];
        @endphp
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 px-3 bg-light border rounded">
            <strong class="text-dark">ตัวกรองที่ใช้:</strong>
            @forelse($activeFilters as $label => $value)
                <span class="badge rounded-pill bg-white text-dark border d-inline-flex align-items-center gap-1">{{ $label }}: {{ $value }}<a href="{{ route('stock-withdrawal.part-alert',request()->except(array_merge(['page','refresh'],$filterRemoveMap[$label]??[]))) }}" class="text-danger text-decoration-none fs-6 lh-1" title="ลบตัวกรอง {{ $label }}" aria-label="ลบตัวกรอง {{ $label }}">&times;</a></span>
            @empty
                <span class="badge rounded-pill bg-white text-dark border">ข้อมูลทั้งหมด</span>
            @endforelse
            <span class="badge rounded-pill bg-primary">เรียง: {{ $sortLabel }}</span>
            <span class="badge rounded-pill bg-secondary">{{ $filters['per_page'] }} รายการ/หน้า</span>
        </div>
        <div class="small text-muted mb-2">พบ {{ number_format($rows->total()) }} รายการ</div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                <span class="small text-muted"><i class="fas fa-filter me-1" aria-hidden="true"></i>กรอกตัวกรองในหัวตาราง แล้วกดปุ่มนี้</span>
                <div class="d-flex gap-2">
                    <div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">ซ่อนคอลัมน์</button><div class="dropdown-menu dropdown-menu-end p-3" id="stock-column-picker" style="min-width:230px"></div></div>
                    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter me-1" aria-hidden="true"></i>ใช้ตัวกรองตาราง</button>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('stock-withdrawal.part-alert',request()->except('page','mfg','site','part','description','customer','job_type')) }}">ล้างตัวกรองตาราง</a>
                </div>
            </div>
            <div class="table-responsive stock-table-wrap">
            <table class="table table-sm table-hover align-middle mb-0" id="stock-tracking-table" data-status-filter="{{ $filters['status'] ?? '' }}" data-quick-filter="{{ $filters['quick'] ?? '' }}">
                <caption class="visually-hidden">สถานะแผนเบิกเทียบยอดเบิกจริงจาก ERP</caption>
                <thead class="table-light"><tr>
                    @foreach(['MFG','โรงงาน','Part','รายละเอียด','ลูกค้า','Work Center แรก','วันที่ขึ้นผลิต','วันส่งมอบ','วันที่เข้าคิว','วันที่ต้องเบิก','ประเภท','จำนวนที่ต้องเบิก (KG)','เบิกแล้ว (KG)','คงเหลือ (KG)','สถานะ','หมายเหตุ Planner','จัดการ'] as $heading)<th scope="col" @class(['text-danger' => $heading === 'วันที่ต้องเบิก'])>{{ $heading }}</th>@endforeach
                </tr><tr class="filter-row">
                    <th><input name="mfg" value="{{ $filters['mfg']??'' }}" class="form-control form-control-sm" placeholder="ค้นหา MFG"></th>
                    <th><select name="site" class="form-select form-select-sm"><option value="">ทั้งหมด</option>@foreach(['WIRE','PLUS'] as $v)<option @selected(($filters['site']??'')===$v)>{{ $v }}</option>@endforeach</select></th>
                    <th><input name="part" value="{{ $filters['part']??'' }}" class="form-control form-control-sm" placeholder="ค้นหา Part"></th>
                    <th><input name="description" value="{{ $filters['description']??'' }}" class="form-control form-control-sm" placeholder="ค้นหารายละเอียด"></th>
                    <th><input name="customer" value="{{ $filters['customer']??'' }}" class="form-control form-control-sm" placeholder="ค้นหาลูกค้า"></th>
                    <th></th><th></th><th></th><th></th><th></th>
                    <th><select name="job_type" class="form-select form-select-sm"><option value="">ทั้งหมด</option>@foreach($jobTypes as $code=>$type)<option value="{{ $code }}" @selected(($filters['job_type']??'')===$code)>{{ $type['name'] }}</option>@endforeach</select></th>
                    <th></th><th></th><th></th><th></th><th></th><th></th>
                </tr></thead>
                <tbody>@forelse($rows as $item)
                    <tr data-item-id="{{ $item->id }}" data-status-code="{{ $item->withdrawal_status_code }}" data-erp-stale="{{ !$item->erp_synced_at || $item->erp_synced_at->lt(now('Asia/Bangkok')->subMinutes(5)) ? '1' : '0' }}" class="{{ $item->is_special?'table-danger':($item->day_offset<0&&!in_array($item->withdrawal_status_code,['COMPLETED','PLANNER_CLOSED'])?'table-warning':'') }}">
                        <td><strong>{{ $item->plan->mfg }}</strong>@if($item->is_special)<div><span class="badge bg-danger">SPECIAL</span></div>@endif</td><td>{{ $item->plan->site }}</td>
                        <td class="text-primary fw-semibold">{{ $item->partnumber }}</td><td>{{ $item->part_description?:'-' }}</td><td>{{ $item->plan->customer_name?:'-' }}</td><td class="erp-work-center">{{ $item->first_work_center ?: '-' }}</td>
                        <td>{{ $item->plan->production_date?->format('d/m/Y')?:'-' }}</td><td>{{ $item->plan->delivery_date?->format('d/m/Y')?:'-' }}</td><td>{{ $item->queue_date?->format('d/m/Y')?:'-' }}</td><td><strong class="text-danger">{{ $item->planned_withdraw_date?->format('d/m/Y')?:'-' }}</strong></td><td>{{ $item->job_type_name === 'สินค้าลวด' ? 'ลวด' : ($item->job_type_name ?: '-') }}</td>
                        <td class="text-end">{{ number_format((float)($item->withdraw_quantity??$item->quantity),2) }}</td><td class="text-end"><a class="erp-withdrawn-qty" href="{{ route('stock-withdrawal.issues',['site'=>$item->plan->site,'workorder_id'=>$item->plan->workorder_id,'parts_id'=>$item->source_part_id,'mfg'=>$item->plan->mfg,'partnumber'=>$item->partnumber]) }}">{{ number_format((float)$item->withdrawn_qty,2) }}</a></td><td class="text-end fw-bold erp-remaining-qty">{{ number_format((float)$item->remaining_qty,2) }}</td>
                        <td><span class="badge erp-status-badge {{ ['NOT_STARTED'=>'bg-secondary','PARTIAL'=>'bg-warning text-dark','COMPLETED'=>'bg-success','PLANNER_CLOSED'=>'bg-primary','CANCELED'=>'bg-danger'][$item->withdrawal_status_code] }}">{{ $item->withdrawal_status }}</span></td><td>{{ $item->remark ?: '-' }}@if($item->plan->plan_note && $item->plan->plan_note !== $item->remark)<div class="small text-muted">MFG: {{ $item->plan->plan_note }}</div>@endif</td>
                        <td><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-primary stock-action-btn stock-action-tooltip" href="{{ route('stock-withdrawal.issues',['site'=>$item->plan->site,'workorder_id'=>$item->plan->workorder_id,'parts_id'=>$item->source_part_id,'mfg'=>$item->plan->mfg,'partnumber'=>$item->partnumber]) }}" title="ดูรายการเบิก" aria-label="ดูรายการเบิก"><i class="fas fa-box-open" aria-hidden="true"></i></a><button type="button" class="btn btn-sm btn-outline-success stock-action-btn stock-action-tooltip refresh-one-erp" data-item-id="{{ $item->id }}" data-url="{{ route('stock-withdrawal.refresh-erp') }}" title="ดึงยอด ERP รายการนี้" aria-label="ดึงยอด ERP รายการนี้" @disabled(!$snapshotEnabled)><i class="fas fa-sync-alt" aria-hidden="true"></i></button><a class="btn btn-sm btn-outline-secondary stock-action-btn stock-action-tooltip" href="{{ route('stock-withdrawal.items.history',$item) }}" title="ดูประวัติการทำรายการ (Log)" aria-label="ดูประวัติการทำรายการ"><i class="fas fa-history" aria-hidden="true"></i></a></div></td>
                    </tr>
                @empty<tr><td colspan="17" class="text-center text-muted py-5">ไม่พบแผนรายการเบิก</td></tr>@endforelse</tbody>
            </table>
        </div></div>
    </form>
    @if($rows->hasPages())<div class="d-flex justify-content-center mt-3">{{ $rows->onEachSide(1)->links() }}</div>@endif
</div>
<style>.stock-table-wrap{max-height:70vh}.stock-table-wrap table{min-width:2200px}.stock-table-wrap thead{position:sticky;top:0;z-index:5}.stock-table-wrap thead th{white-space:nowrap;background:#f1f5f9}.filter-row th{background:#fff!important;padding:5px}.filter-row .form-control,.filter-row .form-select{min-width:115px}.stock-action-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:32px;padding:0}.erp-row-updated{animation:erp-row-flash 1.2s ease-out}@keyframes erp-row-flash{0%{box-shadow:inset 0 0 0 9999px rgba(25,135,84,.18)}100%{box-shadow:none}}</style>
<script>document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('.stock-action-tooltip').forEach(element=>new bootstrap.Tooltip(element,{container:'body',placement:'top'}));const table=document.getElementById('stock-tracking-table'),picker=document.getElementById('stock-column-picker');if(table&&picker){const heads=[...table.tHead.rows[0].cells],key='stock-withdrawal-tracking-hidden-columns-v5',hidden=JSON.parse(localStorage.getItem(key)||'[]');const apply=()=>{[...table.rows].forEach(row=>[...row.cells].forEach((cell,index)=>cell.style.display=hidden.includes(index)?'none':''));localStorage.setItem(key,JSON.stringify(hidden));};heads.forEach((th,index)=>{const label=document.createElement('label');label.className='d-block text-nowrap mb-2';label.innerHTML=`<input type="checkbox" class="form-check-input me-2" ${hidden.includes(index)?'':'checked'}> ${th.textContent.trim()}`;label.querySelector('input').addEventListener('change',e=>{const pos=hidden.indexOf(index);if(e.target.checked&&pos>=0)hidden.splice(pos,1);if(!e.target.checked&&pos<0)hidden.push(index);apply();});picker.appendChild(label);});apply();}
const refreshButton=document.getElementById('refresh-visible-erp'),refreshProgress=document.getElementById('erp-refresh-progress'),statusClasses={NOT_STARTED:'bg-secondary',PARTIAL:'bg-warning text-dark',COMPLETED:'bg-success',PLANNER_CLOSED:'bg-primary',CANCELED:'bg-danger'};
const updateSummary=(oldStatus,newStatus)=>{if(oldStatus===newStatus)return;const statusFilter=table.dataset.statusFilter,quickFilter=table.dataset.quickFilter,isIncluded=status=>statusFilter?status===statusFilter:(!['unfinished','overdue'].includes(quickFilter)||['NOT_STARTED','PARTIAL'].includes(status));[[oldStatus,-1],[newStatus,1]].forEach(([status,change])=>{if(!isIncluded(status))return;const element=document.querySelector(`[data-summary-status="${status}"]`);if(!element)return;const current=Number(element.textContent.replace(/,/g,''))||0;element.textContent=Math.max(0,current+change).toLocaleString('en-US');});};
const updateErpRow=data=>{const row=document.querySelector(`#stock-tracking-table tbody tr[data-item-id="${data.id}"]`);if(!row)return;const oldStatus=row.dataset.statusCode;row.querySelector('.erp-withdrawn-qty').textContent=Number(data.withdrawn_qty).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});row.querySelector('.erp-remaining-qty').textContent=Number(data.remaining_qty).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});row.querySelector('.erp-work-center').textContent=data.first_work_center;const badge=row.querySelector('.erp-status-badge');badge.className=`badge erp-status-badge ${statusClasses[data.status_code]||'bg-secondary'}`;badge.textContent=data.status_label;row.dataset.statusCode=data.status_code;row.dataset.erpStale='0';updateSummary(oldStatus,data.status_code);row.classList.remove('erp-row-updated');void row.offsetWidth;row.classList.add('erp-row-updated');};
const refreshErp=async(itemIds,button,{silent=false}={})=>{if(!itemIds.length)return;const original=button.innerHTML;button.disabled=true;button.innerHTML=button.classList.contains('refresh-one-erp')?'<span class="spinner-border spinner-border-sm"></span>':'<span class="spinner-border spinner-border-sm me-1"></span>กำลังดึงยอด ERP...';refreshProgress.className='small text-primary align-self-center';refreshProgress.textContent=`กำลังตรวจ ERP ${itemIds.length} รายการ...`;try{const response=await fetch(button.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({item_ids:itemIds,force:!silent})});const result=await response.json();if(!response.ok)throw new Error(result.message||'ดึงยอด ERP ไม่สำเร็จ');result.rows.forEach(updateErpRow);const latest=result.rows.find(row=>row.synced_at)?.synced_at;if(latest)document.getElementById('erp-latest-time').innerHTML=`<i class="far fa-clock me-1" aria-hidden="true"></i>ข้อมูลล่าสุดเมื่อ ${latest.slice(0,16)}`;refreshProgress.className='small text-success align-self-center';refreshProgress.textContent=`อัปเดตแล้ว ${result.rows.length} รายการ`;setTimeout(()=>refreshProgress.classList.add('d-none'),3000);}catch(error){refreshProgress.className='small text-danger align-self-center';refreshProgress.textContent='ดึงยอด ERP ไม่สำเร็จ';if(!silent)await Swal.fire({icon:'error',title:'ดึงยอด ERP ไม่สำเร็จ',text:error.message});}finally{button.disabled=false;button.innerHTML=original;}};
refreshButton?.addEventListener('click',()=>refreshErp([...document.querySelectorAll('#stock-tracking-table tbody tr[data-item-id]')].map(row=>Number(row.dataset.itemId)),refreshButton));document.querySelectorAll('.refresh-one-erp').forEach(button=>button.addEventListener('click',()=>refreshErp([Number(button.dataset.itemId)],button)));const staleItemIds=[...document.querySelectorAll('#stock-tracking-table tbody tr[data-erp-stale="1"]')].map(row=>Number(row.dataset.itemId));if(refreshButton&&!refreshButton.disabled&&staleItemIds.length)refreshErp(staleItemIds,refreshButton,{silent:true});
});</script>
@endsection
