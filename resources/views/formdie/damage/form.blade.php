@extends('layouts.layout')
@section('title', 'บันทึก DIE เสีย')
@section('page-title', 'DIE Reports')
@section('content')
<div class="dr-wrap">@include('formdie.damage.common')
<h2>{{ $record->exists ? 'แก้ไขเหตุการณ์ #'.$record->id : 'บันทึก DIE เสีย' }}</h2>
<form method="post" action="{{ $record->exists ? route('die-reports.update',$record) : route('die-reports.store') }}" id="damageForm">
@csrf @if($record->exists) @method('PUT') <input type="hidden" name="version" value="{{ old('version',$record->version) }}">@endif
<div class="dr-card"><h4>เหตุการณ์</h4><div class="dr-grid">
<label>Site *<select name="site">@foreach(['W'=>'Wire','P'=>'Plus'] as $key=>$label)<option value="{{ $key }}" @selected(old('site',$record->site)===$key)>{{ $label }}</option>@endforeach</select></label>
<label>DIE CODE *<input name="die_code" required maxlength="100" value="{{ old('die_code',$record->die_code) }}"></label>
<label>วันที่เสีย *<input type="date" name="damaged_at" required max="{{ date('Y-m-d') }}" value="{{ old('damaged_at',$record->damaged_at?->format('Y-m-d')) }}"></label>
</div><div class="dr-actions" style="margin-top:14px"><button type="button" class="dr-button" id="lookupDie">ดึงข้อมูล PCM / MFG</button><span class="dr-muted">เติมเฉพาะช่องว่าง ตรวจสอบค่าก่อนบันทึก</span></div><div id="lookupResult" role="status" aria-live="polite"></div></div>
<div class="dr-card"><h4>รายละเอียดตามแบบ Excel</h4><div class="dr-grid">
@foreach(\App\Services\FormDIE\DieDamageReportService::FIELDS as $field=>$label)
<label>{{ $label }}{{ in_array($field,['reason','die_type']) ? ' *' : '' }}<input name="{{ $field }}" value="{{ old($field,$record->$field) }}" @required(in_array($field,['reason','die_type'])) @if(in_array($field,\App\Services\FormDIE\DieDamageReportService::NUMBERS)) type="number" min="0" step="{{ $field==='usage_count' ? '1' : '0.0001' }}" @else maxlength="255" list="options-{{ $field }}" @endif></label>
@endforeach
<label>THB/Kg (คำนวณ)<input id="thbKg" readonly></label><label>Size Diff (คำนวณ)<input id="sizeDiff" readonly></label><label style="grid-column:1/-1">หมายเหตุ<textarea name="notes" maxlength="4000" rows="3">{{ old('notes',$record->notes) }}</textarea></label>
</div><p class="dr-muted">เว้นว่างเมื่อยังไม่มีข้อมูล • THB/Kg = ราคา ÷ น้ำหนักผลิต • น้ำหนักเป็นศูนย์หรือไม่ทราบ จะไม่คำนวณอัตรา</p></div>
@foreach(['die_type'=>['Nano Diamond','Diamond','TC','N/A'],'reason'=>['แตก','หมดอายุ','ลวดติด','กินข้างเดียว','รอยขีด','รูเบี้ยว'],'fracture_type'=>['แตกทั้งเม็ด','แตกบางส่วน','แตกNIBหลุด','แตกNibข้างนอก','แตกNibข้างใน','แตกNibระเบิด','แตกCASEระเบิด']] as $field=>$options)<datalist id="options-{{ $field }}">@foreach($options as $option)<option value="{{ $option }}">@endforeach</datalist>@endforeach
<div class="dr-actions"><button class="dr-button dr-primary" type="submit">บันทึกเหตุการณ์</button><a class="dr-button" href="{{ route('die-reports.index') }}">ยกเลิก</a></div>
@if($record->source_snapshot)<details class="dr-card"><summary>แหล่งข้อมูลที่ดึงไว้</summary><pre style="white-space:pre-wrap">{{ json_encode($record->source_snapshot, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
</form></div>
<script src="{{ asset('js/formdie/autocomplete.js') }}?v={{ filemtime(public_path('js/formdie/autocomplete.js')) }}"></script>
<script>
(() => {
 const form=document.getElementById('damageForm'), result=document.getElementById('lookupResult'), button=document.getElementById('lookupDie');
 const field=n=>form.elements.namedItem(n);
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const getSite=()=>field('site').value;
 DieAutocomplete.attach(field('die_code'),{url:@json(route('die.api.suggest',['type'=>'die'])),getSiteFn:getSite,renderItem:r=>`<div class="main">${esc(r.value)}</div><div class="sub">${esc(r.description)}${r.status?' · '+esc(r.status):''}</div>`});
 DieAutocomplete.attach(field('workorder'),{url:@json(route('die.api.suggest',['type'=>'wo'])),getSiteFn:getSite,renderItem:r=>`<div class="main">${esc(r.value)}</div><div class="sub">${[r.brand,r.fcat,r.fsize].filter(Boolean).map(esc).join(' · ')}</div>`});
 const calculate=()=>{const kg=field('production_kg').value,price=field('price').value,a=field('initial_size').value,b=field('current_size').value;document.getElementById('thbKg').value=kg!==''&&Number(kg)>0&&price!==''?(Number(price)/Number(kg)).toFixed(4):'';document.getElementById('sizeDiff').value=a!==''&&b!==''?(Number(b)-Number(a)).toFixed(4):'';};
 form.addEventListener('input',calculate); calculate();
 button.addEventListener('click',async()=>{
 const keys=['site','die_code','damaged_at','workorder'], values=Object.fromEntries(keys.map(k=>[k,field(k).value]));
 if(!values.die_code||!values.damaged_at){result.textContent='ระบุ DIE และวันที่เสียก่อน';return;}
 button.disabled=true;result.textContent='กำลังดึงข้อมูล…';
 try{const response=await fetch(@json(route('die-reports.lookup')),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':field('_token').value},body:JSON.stringify(values)});const data=await response.json();if(!response.ok)throw new Error(data.message||'ดึงข้อมูลไม่สำเร็จ');
 if(keys.some(k=>field(k).value!==values[k]))throw new Error('ข้อมูลค้นหาเปลี่ยนแล้ว กรุณาดึงข้อมูลใหม่');
 Object.entries(data.values||{}).forEach(([k,v])=>{if(field(k)&&field(k).value==='')field(k).value=v;});result.replaceChildren();
 const add=t=>{const p=document.createElement('p');p.className='dr-muted';p.textContent=t;result.appendChild(p);};(data.warnings||[]).forEach(add);
 if(data.master){add('Master ปัจจุบัน: '+(data.master.die_description||'')+' | Brand '+(data.master.brand||'—')+' | ขนาดแรกเข้า '+(data.master.ordered_size??'—')+' | ขนาดปัจจุบัน '+(data.master.present_diameter??'—'));
 const apply=document.createElement('button');apply.type='button';apply.className='dr-button';apply.textContent='ใช้ Brand และขนาดจาก Master ในช่องว่าง (ยืนยันว่าใช้กับเหตุการณ์นี้ได้)';apply.onclick=()=>{if(['site','die_code','damaged_at'].some(k=>field(k).value!==values[k])){add('ข้อมูลเหตุการณ์เปลี่ยนแล้ว กรุณาดึงข้อมูลใหม่');return;}Object.entries({supplier:data.master.brand,initial_size:data.master.ordered_size,current_size:data.master.present_diameter}).forEach(([k,v])=>{if(field(k).value===''&&v!==null&&v!==undefined)field(k).value=v;});calculate();apply.disabled=true;};result.appendChild(apply);}
 if(data.workorders?.length)add('WO ในประวัติถึงวันที่เสีย: '+data.workorders.join(', '));if(data.machines?.length)add('เครื่องจักรของ WO ถึงวันที่เสีย: '+data.machines.join(', '));calculate();
 }catch(error){result.textContent=error.message;}finally{button.disabled=false;}
 });
})();
</script>
@endsection
