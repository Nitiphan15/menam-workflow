@php
    $filterLabels = ['site'=>'Site','material_group'=>'Material Group','stock_status'=>'สถานะ Stock','action_status'=>'สถานะการติดตาม','follow_up'=>'กำหนดติดตาม','division'=>'Sales Division','trace_status'=>'Trace','unit'=>'Unit','item'=>'Part / Description','part_number_exact'=>'Part Number','serial'=>'Serial / Heat','customer'=>'Customer','purchase_from'=>'รับเข้าตั้งแต่','purchase_to'=>'ถึงวันที่','sort'=>'เรียงตาม'];
    $activeFilters = collect($filterLabels)->map(function ($label, $key) use ($selected) {
        $value = $selected[$key] ?? null;
        return !filled($value) || ($key === 'sort' && $value === 'age_desc') ? null : compact('key','label','value');
    })->filter()->values();
    if (!empty($selected['became_dead'])) $activeFilters->push(['key'=>'became_dead','label'=>'สถานะ','value'=>'เพิ่งข้าม 180 วัน']);
    $withoutFilter = fn ($key) => url()->current().(count(request()->except([$key,'page'])) ? '?'.http_build_query(request()->except([$key,'page'])) : '');
@endphp
<form id="wr-filter-form" method="GET" class="card wr-filter">
    <div class="wr-filter-head"><div class="wr-filter-title"><i class="fas fa-filter"></i> เลือกข้อมูลที่ต้องการดู</div><span class="text-muted small">เงื่อนไขที่เลือกจะใช้กับไฟล์ Export ด้วย</span></div>
    @if($activeFilters->isNotEmpty())
        <div class="wr-active-filters"><span class="small text-muted">ตัวกรองที่ใช้อยู่:</span>@foreach($activeFilters as $filter)<a class="wr-active-filter js-wr-remove-filter" data-filter-key="{{ $filter['key'] }}" href="{{ $withoutFilter($filter['key']) }}">{{ $filter['label'] }}: {{ $filter['value'] }} <i class="fas fa-xmark"></i></a>@endforeach<a class="btn btn-sm btn-outline-secondary js-wr-clear-filters" href="{{ url()->current() }}"><i class="fas fa-eraser me-1"></i>ล้างทั้งหมด</a></div>
    @endif
    <div class="card-body"><div class="row g-3 align-items-end">
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">Site</label><select name="site" class="form-select wr-filter-select" data-placeholder="ทุก Site"><option value="">ทุก Site</option>@foreach(['WIRE','PLUS'] as $v)<option value="{{ $v }}" @selected(($selected['site'] ?? '')===$v)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">สถานะ Stock</label><select name="stock_status" class="form-select wr-filter-select" data-placeholder="ทุกสถานะ"><option value="">ทุกสถานะ</option>@foreach(['INCOMING'=>'Incoming','DEAD'=>'Dead Stock','UNKNOWN'=>'Unknown Age / Data Issue'] as $v=>$label)<option value="{{ $v }}" @selected(($selected['stock_status'] ?? '')===$v)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">สถานะการติดตาม</label><select name="action_status" class="form-select wr-filter-select" data-placeholder="ทุกสถานะ"><option value="">ทุกสถานะ</option><option value="open" @selected(($selected['action_status'] ?? '')==='open')>ยังไม่ได้ติดตาม</option><option value="waiting_follow_up" @selected(($selected['action_status'] ?? '')==='waiting_follow_up')>กำลังติดตาม รอคำตอบ</option><option value="in_progress" @selected(($selected['action_status'] ?? '')==='in_progress')>มีกำหนดส่งมอบแล้ว</option></select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">กำหนดติดตาม</label><select name="follow_up" class="form-select wr-filter-select" data-placeholder="ทุกกำหนด"><option value="">ทุกกำหนด</option><option value="overdue" @selected(($selected['follow_up'] ?? '')==='overdue')>เลยกำหนด</option><option value="today" @selected(($selected['follow_up'] ?? '')==='today')>ครบกำหนดวันนี้</option><option value="next_3_days" @selected(($selected['follow_up'] ?? '')==='next_3_days')>ภายใน 3 วัน</option></select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">Material Group</label><select name="material_group" class="form-select wr-filter-select" data-placeholder="ทุกกลุ่ม"><option value="">ทุกกลุ่ม</option>@foreach($filters['groups'] as $v)<option value="{{ $v }}" @selected(($selected['material_group'] ?? '')===$v)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">Sales Division</label><select name="division" class="form-select wr-filter-select" data-placeholder="ทุก Division"><option value="">ทุก Division</option>@foreach($filters['divisions'] as $v)<option value="{{ $v }}" @selected(($selected['division'] ?? '')===$v)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">Trace Status</label><select name="trace_status" class="form-select wr-filter-select" data-placeholder="ทุกสถานะ"><option value="">ทุกสถานะ</option>@foreach($filters['trace_statuses'] as $v)<option value="{{ $v }}" @selected(($selected['trace_status'] ?? '')===$v)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label">Part / Description</label><select name="item" class="form-select wr-remote-select" data-type="item" data-placeholder="พิมพ์อย่างน้อย 2 ตัว">@if(filled($selected['item'] ?? null))<option value="{{ $selected['item'] }}" selected>{{ $selected['item'] }}</option>@endif</select></div>
        <div class="col-12 col-md-6 col-xl-2"><label class="form-label">Serial / Heat No.</label><select name="serial" class="form-select wr-remote-select" data-type="serial" data-placeholder="พิมพ์อย่างน้อย 2 ตัว">@if(filled($selected['serial'] ?? null))<option value="{{ $selected['serial'] }}" selected>{{ $selected['serial'] }}</option>@endif</select></div>
        <div class="col-12 col-md-6 col-xl-2"><label class="form-label">Customer</label><select name="customer" class="form-select wr-remote-select" data-type="customer" data-placeholder="พิมพ์อย่างน้อย 2 ตัว">@if(filled($selected['customer'] ?? null))<option value="{{ $selected['customer'] }}" selected>{{ $selected['customer'] }}</option>@endif</select></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">รับเข้าตั้งแต่</label><input type="date" name="purchase_from" class="form-control" value="{{ $selected['purchase_from'] ?? '' }}"></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">ถึงวันที่</label><input type="date" name="purchase_to" class="form-control" value="{{ $selected['purchase_to'] ?? '' }}"></div>
        <div class="col-12 col-md-4 col-xl-1"><label class="form-label">Unit</label><select name="unit" class="form-select wr-filter-select" data-placeholder="ทั้งหมด"><option value="">ทั้งหมด</option>@foreach(['KG','PCS','UNKNOWN'] as $v)<option value="{{ $v }}" @selected(($selected['unit'] ?? '')===$v)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-12 col-md-4 col-xl-2"><label class="form-label">เรียงตาม</label><select name="sort" class="form-select wr-filter-select" data-placeholder="เรียงตาม"><option value="age_desc" @selected(($selected['sort'] ?? 'age_desc')==='age_desc')>อายุ Stock มากสุด</option><option value="qty_desc" @selected(($selected['sort'] ?? '')==='qty_desc')>Qty มากสุด</option><option value="purchase_oldest" @selected(($selected['sort'] ?? '')==='purchase_oldest')>วันที่รับเข้าเก่าสุด</option><option value="follow_up_soon" @selected(($selected['sort'] ?? '')==='follow_up_soon')>วันติดตามใกล้สุด</option></select></div>
        <div class="col-12 col-md-4 col-xl-2"><button class="btn btn-outline-primary w-100"><i class="fas fa-filter me-1"></i> ใช้ตัวกรอง</button></div>
        <div class="col-12 col-md-4 col-xl-2"><a href="{{ url()->current() }}" class="btn btn-outline-secondary w-100 js-wr-clear-filters"><i class="fas fa-rotate-left me-1"></i> ล้าง</a></div>
    </div><div class="wr-quick-filters"><span class="fw-semibold small text-muted">ปุ่มลัด</span><button type="button" class="btn btn-sm {{ ($selected['site'] ?? '')==='WIRE'?'btn-primary':'btn-outline-primary' }} js-wr-quick" data-name="site" data-value="WIRE">WIRE</button><button type="button" class="btn btn-sm {{ ($selected['site'] ?? '')==='PLUS'?'btn-primary':'btn-outline-primary' }} js-wr-quick" data-name="site" data-value="PLUS">PLUS</button><button type="button" class="btn btn-sm btn-outline-danger js-wr-quick" data-name="stock_status" data-value="DEAD">Dead Stock</button><button type="button" class="btn btn-sm btn-outline-warning js-wr-quick" data-name="action_status" data-value="open">ยังไม่ได้ติดตาม</button><button type="button" class="btn btn-sm btn-outline-info js-wr-quick" data-name="trace_status" data-value="UNMAPPED">Unmapped</button><button type="button" class="btn btn-sm btn-outline-secondary js-wr-quick" data-name="trace_status" data-value="SALES_MAPPING_CONFLICT">Conflict</button><button type="button" class="btn btn-sm btn-outline-danger js-wr-quick" data-name="follow_up" data-value="overdue">เลยกำหนด</button><button type="button" class="btn btn-sm btn-outline-warning js-wr-quick" data-name="follow_up" data-value="today">ติดตามวันนี้</button><label class="form-check mb-0 ms-1"><input class="form-check-input" type="checkbox" name="became_dead" value="1" @checked(!empty($selected['became_dead']))><span class="form-check-label small">เพิ่งข้าม 180 วัน</span></label></div></div>
</form>
@once
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.TomSelect) {
        document.querySelectorAll('.wr-filter-select').forEach(function (element) {
            if (element.tomselect) return;
            new TomSelect(element, {
                create: false,
                persist: false,
                allowEmptyOption: true,
                maxOptions: 1000,
                dropdownParent: 'body',
                placeholder: element.dataset.placeholder || '',
                searchField: ['text'],
                render: {
                    no_results: function () { return '<div class="no-results">ไม่พบตัวเลือกที่ค้นหา</div>'; }
                }
            });
        });
        document.querySelectorAll('.wr-remote-select').forEach(function (element) {
            if (element.tomselect) return;
            new TomSelect(element, {
                valueField: 'value',
                labelField: 'text',
                searchField: ['text'],
                create: true,
                createOnBlur: true,
                maxItems: 1,
                maxOptions: 30,
                loadThrottle: 300,
                dropdownParent: 'body',
                placeholder: element.dataset.placeholder || '',
                shouldLoad: function (query) { return query.trim().length >= 2; },
                load: function (query, callback) {
                    const url = @json(route('wr.deadstock.autocomplete'))+'?type='+encodeURIComponent(element.dataset.type)+'&q='+encodeURIComponent(query);
                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (response) { return response.ok ? response.json() : []; })
                        .then(callback)
                        .catch(function () { callback(); });
                },
                render: {
                    no_results: function (data, escape) {
                        return '<div class="no-results">'+(data.input.trim().length < 2 ? 'พิมพ์อย่างน้อย 2 ตัว' : 'ไม่พบข้อมูลที่ค้นหา')+'</div>';
                    }
                }
            });
        });
    }
    document.querySelectorAll('.js-wr-quick').forEach(function (button) {
        button.addEventListener('click', function () {
            const form = document.getElementById('wr-filter-form');
            const field = form?.elements.namedItem(button.dataset.name);
            if (field?.tomselect) field.tomselect.setValue(button.dataset.value || '', true);
            else if (field) field.value = button.dataset.value || '';
            form?.requestSubmit();
        });
    });
});
</script>
@endpush
@endonce
