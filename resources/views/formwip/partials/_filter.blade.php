{{--
    แผง filter ร่วม — @include('formwip.partials._filter', ['mode' => 'dashboard' | 'list'])
    dashboard : site, ค้นหา, แผนก/WC/เครื่อง, ช่วงข้อมูล
    list      : ครบทุกกลุ่ม (สถานะ, สินค้า/ลูกค้า, Due, การเรียง)
--}}
@php
    $isList = ($mode ?? 'list') === 'list';
    $formRoute = $isList ? 'wip.list' : 'wip.index';
    $fq = fn(array $over) => array_merge(request()->except(['page', 'refresh']), $over);
    $siteLabels = ['WIRE' => 'Wire', 'PLUS' => 'Plus', 'ALL' => 'ทั้งหมด'];
    $dayOptions = [90, 180, 365, 730];
    $dueLabels = ['overdue' => 'เลยกำหนดแล้ว', '7' => 'ครบกำหนดใน 7 วัน', '30' => 'ครบกำหนดใน 30 วัน', 'none' => 'ไม่มี Due date'];
    $sortLabels = ['aging' => 'ค้างนานสุด', 'wip' => 'WIP มากสุด', 'due' => 'Due ใกล้สุด', 'open' => 'เปิด WO ล่าสุด', 'wo' => 'เลขที่ WO'];

    // ป้ายของ filter ที่ใช้งานอยู่ (กด × เพื่อเอาออกทีละตัว)
    $chipDefs = [
        'status' => fn($v) => 'สถานะ: ' . ($statuses[$v]['label'] ?? $v),
        'dept' => fn($v) => 'แผนก: ' . $v,
        'wc' => fn($v) => 'WC: ' . $v,
        'machine' => fn($v) => 'เครื่อง: ' . $v,
        'product' => fn($v) => 'Product: ' . $v,
        'customer' => fn($v) => 'Customer: ' . \Illuminate\Support\Str::limit($v, 30),
        'grade' => fn($v) => 'Grade: ' . $v,
        'size' => fn($v) => 'Size: ' . $v,
        'date_from' => fn($v) => 'เปิดตั้งแต่ ' . \Carbon\Carbon::parse($v)->format('d/m/Y'),
        'date_to' => fn($v) => 'เปิดถึง ' . \Carbon\Carbon::parse($v)->format('d/m/Y'),
        'due' => fn($v) => 'Due: ' . ($dueLabels[$v] ?? $v),
        'aging' => fn($v) => 'Aging: ' . ($agingBands[$v]['label'] ?? $v),
        'q' => fn($v) => 'ค้นหา: ' . $v,
    ];
    $chips = [];
    foreach ($chipDefs as $key => $label) {
        if (($filters[$key] ?? '') !== '') {
            $chips[] = [$label($filters[$key]), $key];
        }
    }
    $panelKeys = $isList
        ? ['status', 'dept', 'wc', 'machine', 'product', 'customer', 'grade', 'size', 'date_from', 'date_to', 'due', 'aging']
        : ['dept', 'wc', 'machine', 'date_from', 'date_to'];
    $advancedCount = collect($panelKeys)->filter(fn($k) => ($filters[$k] ?? '') !== '')->count()
        + ($filters['days'] !== 180 ? 1 : 0) + ($filters['scope'] !== 'pk' ? 1 : 0) + (!$filters['only_wip'] ? 1 : 0)
        + ($isList && $filters['sort'] !== 'aging' ? 1 : 0);
    $wcByDept = $options['wc']->groupBy('dept')->sortKeys();
    $machineByWc = $options['machine']->groupBy('wc');
    // filter ที่ไม่มีช่องในแผงของหน้านี้ → ส่งต่อแบบ hidden เพื่อไม่ให้หายตอนกดกรอง
    $hiddenKeys = $isList ? [] : ['status', 'aging', 'product', 'customer', 'grade', 'size', 'due', 'sort'];
@endphp

        <form method="GET" action="{{ route($formRoute) }}" class="card shadow-sm mb-3 wip-toolbar" id="wipFilter">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Site">
                        @foreach ($siteLabels as $v => $l)
                            <input type="radio" class="btn-check" name="site" id="site-{{ $v }}" value="{{ $v }}" @checked($filters['site'] === $v) onchange="this.form.requestSubmit()">
                            <label class="btn btn-outline-primary" for="site-{{ $v }}">{{ $l }}</label>
                        @endforeach
                    </div>

                    <div class="flex-grow-1 wip-search" style="min-width: 240px; max-width: 460px">
                        <i class="fa-solid fa-magnifying-glass wip-search-icon"></i>
                        <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" autocomplete="off"
                            data-wip-suggest="wo,product,customer,so" data-wip-submit="1"
                            placeholder="ค้นหาเร็ว: WO, Product, Customer, SO">
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="collapse" data-bs-target="#wipMoreFilters" aria-expanded="{{ $advancedCount ? 'true' : 'false' }}">
                        <i class="fa-solid fa-sliders"></i> ตัวกรองเพิ่มเติม
                        @if ($advancedCount)
                            <span class="badge rounded-pill text-bg-primary ms-1">{{ $advancedCount }}</span>
                        @endif
                    </button>
                    <button class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> กรอง</button>

                    <div class="ms-auto d-flex align-items-center gap-2 small text-secondary">
                        <span class="badge rounded-pill text-bg-warning">Prototype</span>
                        @php $isSnapshot = $dataSources && collect($dataSources)->every(fn($s) => $s === 'snapshot'); @endphp
                        <span title="{{ $isSnapshot ? 'อ่านจาก snapshot ที่ sync อัตโนมัติทุก 15 นาที' : 'query สดจาก WMSOne + ERP (cache 10 นาที)' }}">
                            <i class="fa-regular fa-clock"></i> {{ $fetchedAt ? \Carbon\Carbon::parse($fetchedAt)->format('d/m/Y H:i') : '-' }}
                            <span class="badge rounded-pill {{ $isSnapshot ? 'text-bg-light border' : 'text-bg-light border text-warning-emphasis' }}">{{ $isSnapshot ? 'Auto sync' : 'Live' }}</span>
                        </span>
                        <a href="{{ route($formRoute, $fq(['refresh' => 1])) }}" class="btn btn-sm btn-outline-secondary" title="ดึงข้อมูลใหม่จาก WMSOne + ERP">
                            <i class="fa-solid fa-rotate"></i><span class="d-none d-md-inline"> อัปเดต</span>
                        </a>
                        <a href="{{ route('wip.export', $fq([])) }}" class="btn btn-sm btn-success" title="Export ตาม filter ที่เลือกอยู่">
                            <i class="fa-solid fa-file-excel"></i><span class="d-none d-md-inline"> Export Excel</span>
                        </a>
                    </div>
                </div>

                <div class="collapse {{ $advancedCount ? 'show' : '' }}" id="wipMoreFilters">
                    <div class="wip-filter-grid {{ $isList ? '' : 'is-compact' }} pt-3 mt-2 border-top">
                        @if ($isList)
                        {{-- ---------- สถานะ ---------- --}}
                        <fieldset>
                            <legend><i class="fa-solid fa-signal"></i> สถานะ</legend>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">WIP Status</label>
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="">ทุกสถานะ</option>
                                        @foreach ($statuses as $k => $s)
                                            @continue($k === 'WAITING_QC')
                                            <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $s['label'] }} · {{ $s['th'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Aging</label>
                                    <select name="aging" class="form-select form-select-sm">
                                        <option value="">ทั้งหมด</option>
                                        @foreach ($agingBands as $k => $b)
                                            <option value="{{ $k }}" @selected($filters['aging'] === $k)>{{ $b['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">แสดง WO</label>
                                    <select name="only_wip" class="form-select form-select-sm">
                                        <option value="1" @selected($filters['only_wip'])>มี WIP ค้าง</option>
                                        <option value="0" @selected(!$filters['only_wip'])>ที่เปิดทั้งหมด</option>
                                    </select>
                                </div>
                            </div>
                        </fieldset>
                        @endif

                        {{-- ---------- ตำแหน่งงาน ---------- --}}
                        <fieldset>
                            <legend><i class="fa-solid fa-industry"></i> แผนก / Work Center / เครื่อง</legend>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">แผนก (Department)</label>
                                    <select name="dept" class="form-select form-select-sm" data-wip-select>
                                        <option value="">ทุกแผนก</option>
                                        @foreach ($options['dept'] as $d)
                                            <option value="{{ $d }}" @selected($filters['dept'] === $d)>{{ $d }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Work Center</label>
                                    <select name="wc" class="form-select form-select-sm" data-wip-select>
                                        <option value="">ทั้งหมด</option>
                                        @foreach ($wcByDept as $dept => $wcs)
                                            <optgroup label="{{ $dept }}">
                                                @foreach ($wcs as $wc)
                                                    <option value="{{ $wc['code'] }}" @selected($filters['wc'] === $wc['code'])>{{ $wc['code'] }} — {{ $wc['name'] }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Machine</label>
                                    <select name="machine" class="form-select form-select-sm" data-wip-select>
                                        <option value="">ทั้งหมด</option>
                                        @foreach ($machineByWc as $wc => $machines)
                                            <optgroup label="{{ $wc }}">
                                                @foreach ($machines as $m)
                                                    <option value="{{ $m['machine'] }}" @selected($filters['machine'] === $m['machine'])>{{ $m['machine'] }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Route</label>
                                    <select name="scope" class="form-select form-select-sm">
                                        <option value="pk" @selected($filters['scope'] === 'pk')>เฉพาะที่จบด้วย PK (Pilot)</option>
                                        <option value="all" @selected($filters['scope'] === 'all')>ทุก Route</option>
                                    </select>
                                </div>
                            </div>
                        </fieldset>

                        @if ($isList)
                        {{-- ---------- สินค้า / ลูกค้า ---------- --}}
                        <fieldset>
                            <legend><i class="fa-solid fa-box"></i> สินค้า / ลูกค้า</legend>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Product Code / Description</label>
                                    <input type="text" name="product" value="{{ $filters['product'] }}" class="form-control form-control-sm" autocomplete="off"
                                        data-wip-suggest="product" placeholder="พิมพ์รหัสหรือชื่อ เช่น FSER309, S50C">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Customer</label>
                                    <select name="customer" class="form-select form-select-sm" data-wip-select>
                                        <option value="">ทุกลูกค้า</option>
                                        @foreach ($options['customer'] as $c)
                                            <option value="{{ $c }}" @selected($filters['customer'] === $c)>{{ $c }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Grade</label>
                                    <select name="grade" class="form-select form-select-sm" data-wip-select>
                                        <option value="">ทั้งหมด</option>
                                        @foreach ($options['grade'] as $g)
                                            <option value="{{ $g }}" @selected($filters['grade'] === $g)>{{ $g }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Size</label>
                                    <select name="size" class="form-select form-select-sm" data-wip-select>
                                        <option value="">ทั้งหมด</option>
                                        @foreach ($options['size'] as $s)
                                            <option value="{{ $s }}" @selected($filters['size'] === $s)>{{ $s }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </fieldset>
                        @endif

                        {{-- ---------- วันที่ / การเรียง ---------- --}}
                        <fieldset>
                            <legend><i class="fa-regular fa-calendar"></i> {{ $isList ? 'วันที่ / การเรียง' : 'ช่วงข้อมูล' }}</legend>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">WO เปิดย้อนหลัง</label>
                                    <select name="days" class="form-select form-select-sm">
                                        @foreach ($dayOptions as $d)
                                            <option value="{{ $d }}" @selected($filters['days'] === $d)>{{ $d }} วัน</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">วันเปิด WO ตั้งแต่</label>
                                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control form-control-sm">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">ถึง</label>
                                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control form-control-sm">
                                </div>
                                @unless ($isList)
                                <div class="col-12">
                                    <label class="form-label">แสดง WO</label>
                                    <select name="only_wip" class="form-select form-select-sm">
                                        <option value="1" @selected($filters['only_wip'])>มี WIP ค้าง</option>
                                        <option value="0" @selected(!$filters['only_wip'])>ที่เปิดทั้งหมด</option>
                                    </select>
                                </div>
                                @endunless
                                @if ($isList)
                                <div class="col-6">
                                    <label class="form-label">Due date</label>
                                    <select name="due" class="form-select form-select-sm">
                                        <option value="">ทั้งหมด</option>
                                        @foreach ($dueLabels as $k => $l)
                                            <option value="{{ $k }}" @selected($filters['due'] === $k)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">เรียงตาม</label>
                                    <select name="sort" class="form-select form-select-sm">
                                        @foreach ($sortLabels as $k => $l)
                                            <option value="{{ $k }}" @selected($filters['sort'] === $k)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @endif
                            </div>
                        </fieldset>
                    </div>

                    @foreach ($hiddenKeys as $hk)
                        @if (($filters[$hk] ?? '') !== '' && !($hk === 'sort' && $filters[$hk] === 'aging'))
                            <input type="hidden" name="{{ $hk }}" value="{{ $filters[$hk] }}">
                        @endif
                    @endforeach

                    <div class="d-flex justify-content-end gap-2 pt-2">
                        <a href="{{ route($formRoute, ['site' => $filters['site']]) }}" class="btn btn-sm btn-outline-secondary">ล้างตัวกรองทั้งหมด</a>
                        <button class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> ใช้ตัวกรอง</button>
                    </div>
                </div>

                @if ($chips)
                    <div class="d-flex flex-wrap align-items-center gap-2 pt-2">
                        @foreach ($chips as [$text, $key])
                            <a href="{{ route($formRoute, $fq([$key => null])) }}" class="wip-chip">{{ $text }} <i class="fa-solid fa-xmark"></i></a>
                        @endforeach
                        <a href="{{ route($formRoute, ['site' => $filters['site']]) }}" class="small text-secondary">ล้างทั้งหมด</a>
                    </div>
                @endif
            </div>
        </form>

@push('scripts')
    <script>
        // dropdown ที่มีตัวเลือกเยอะ → ค้นหาได้ (TomSelect มีใน layout อยู่แล้ว)
        if (window.TomSelect) {
            document.querySelectorAll('#wipFilter select[data-wip-select]').forEach(function (el) {
                new TomSelect(el, {
                    allowEmptyOption: true,
                    maxOptions: 500,
                    placeholder: el.options[0] ? el.options[0].text : '',
                    plugins: ['dropdown_input'],
                });
            });
        }

        // ---------- autocomplete ช่องข้อความ (พิมพ์อิสระได้ + เลือกจากรายการแนะนำ) ----------
        (function () {
            if (!window.TomSelect) return;
            const all = @json($options['suggest'] ?? []);
            const groups = {
                wo: 'WO (เลือกเพื่อเปิดรายละเอียด)',
                product: 'Product',
                customer: 'Customer',
                so: 'SO',
            };
            const esc = function (s) { return String(s ?? '').replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };

            document.querySelectorAll('#wipFilter input[data-wip-suggest]').forEach(function (el) {
                const want = el.dataset.wipSuggest.split(',');
                const opts = all.filter(function (o) { return want.includes(o.group); })
                    .map(function (o, i) { return Object.assign({ id: o.group + ':' + o.value + ':' + i }, o); });
                const current = el.value;
                if (current && !opts.some(function (o) { return o.value === current; })) {
                    opts.unshift({ id: 'typed:' + current, value: current, desc: '', group: '_typed' });
                }

                const ts = new TomSelect(el, {
                    options: opts,
                    items: current ? [opts.find(function (o) { return o.value === current; }).id] : [],
                    optgroups: want.map(function (g) { return { value: g, label: groups[g] || g }; }),
                    optgroupField: 'group',
                    valueField: 'id',
                    labelField: 'value',
                    searchField: ['value', 'desc'],
                    maxItems: 1,
                    maxOptions: 60,
                    create: function (input) { return { id: 'typed:' + input, value: input, desc: '', group: '_typed' }; },
                    createOnBlur: true,
                    persist: false,
                    selectOnTab: true,
                    placeholder: el.getAttribute('placeholder'),
                    render: {
                        option: function (o) {
                            return '<div class="wip-sg"><span class="wip-sg-v">' + esc(o.value) + '</span>'
                                + (o.desc ? '<span class="wip-sg-d">' + esc(o.desc) + '</span>' : '') + '</div>';
                        },
                        item: function (o) { return '<div>' + esc(o.value) + '</div>'; },
                        option_create: function (data) {
                            return '<div class="create">ค้นหาคำว่า <strong>' + esc(data.input) + '</strong></div>';
                        },
                        no_results: function () { return '<div class="no-results">ไม่พบในข้อมูลที่โหลดอยู่ — กด Enter เพื่อค้นหาคำนี้</div>'; },
                    },
                    onItemAdd: function (id) {
                        const o = this.options[id];
                        if (o && o.url) { window.location.href = o.url; return; }   // เลือก WO → เปิดรายละเอียด
                        if (el.dataset.wipSubmit) { el.form.requestSubmit(); }       // ช่องค้นหาเร็ว → กรองทันที
                    },
                    onItemRemove: function () {
                        if (el.dataset.wipSubmit) { el.form.requestSubmit(); }
                    },
                });

                // ส่งค่าจริง (ข้อความ) แทน id ภายในตอน submit
                el.form.addEventListener('formdata', function (e) {
                    const id = ts.getValue();
                    const o = id ? ts.options[id] : null;
                    e.formData.set(el.name, o ? o.value : '');
                    if (!o) e.formData.delete(el.name);
                });
            });
        })();

        // ไม่ส่งช่องที่ว่าง / ค่า default ใน URL ให้ลิงก์สั้นและแชร์ง่าย
        // กลับมาด้วยปุ่ม Back แล้วช่องไม่ค้างสถานะ disabled
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('#wipFilter [disabled]').forEach(function (el) { el.disabled = false; });
        });
        document.getElementById('wipFilter').addEventListener('submit', function () {
            const defaults = { days: '180', scope: 'pk', only_wip: '1', sort: 'aging' };
            this.querySelectorAll('input[name], select[name]').forEach(function (el) {
                if (el.type === 'radio' && !el.checked) return;
                if (el.value === '' || defaults[el.name] === el.value) el.disabled = true;
            });
        });

        // คลิกทั้งแถวเพื่อเปิดรายละเอียด
        document.querySelectorAll('.wip tr[data-href]').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select')) return;
                window.location.href = tr.dataset.href;
            });
        });
    </script>
@endpush
