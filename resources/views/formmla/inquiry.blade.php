@extends('layouts.layout')

@section('title', 'รายละเอียดภาระงานเครื่องจักร')
@section('page-title', 'รายละเอียดภาระงานเครื่องจักร')

@section('content')
    @php
        $filters = $filters ?? [];
        $rows = collect($rows ?? []);
        $selectedSources = $filters['source'] ?? [];
        $selectedWorkCenters = array_map('strval', $filters['workcenter_ids'] ?? []);
        $selectedMachines = array_map('strval', $filters['machine_ids'] ?? []);
        $fmt = fn($value, $decimals = 0) => is_numeric($value) ? number_format((float) $value, $decimals) : '-';
    @endphp

    <style>
        .mla-wrap { background:#f5f7fa; border-radius:8px; padding:16px; }
        .mla-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:hidden; }
        .mla-head { padding:12px 16px; border-bottom:1px solid #e8edf2; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .mla-table-wrap { max-height:650px; overflow:auto; }
        .mla-table th { position:sticky; top:0; z-index:2; background:#edf4ff; white-space:nowrap; }
        .mla-table td { vertical-align:middle; }
        .mla-table td.nowrap { white-space:nowrap; }
        .mla-table td.cust { max-width:220px; white-space:normal; word-break:break-word; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
        .key-number { font-weight:700; color:#1f2937; }
        .next-date { font-weight:700; color:#0f766e; }
        .ts-dropdown { z-index:3000 !important; }
    </style>

    <div class="mla-wrap">
        <div class="mla-panel mb-3">
            <div class="mla-head">
                <span>ตัวกรองข้อมูล</span>
                <a href="{{ route('machine-load.dashboard', request()->query()) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-column me-1"></i> Dashboard</a>
            </div>
            <form method="GET" action="{{ route('machine-load.inquiry') }}" class="p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ชนิดวันที่</label>
                        <select name="date_type" class="form-select">
                            <option value="dateopen" {{ ($filters['date_type'] ?? '') === 'dateopen' ? 'selected' : '' }}>วันที่เปิด WO</option>
                            <option value="reqdate" {{ ($filters['date_type'] ?? '') === 'reqdate' ? 'selected' : '' }}>กำหนดส่ง / Due Date</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ตั้งแต่วันที่</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">เริ่มคำนวณวันว่าง</label>
                        <input type="datetime-local" name="start_at" class="form-control" value="{{ isset($filters['start_at']) ? str_replace(' ', 'T', $filters['start_at']) : '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">แหล่งข้อมูล</label>
                        <select name="source[]" class="form-select mla-select" multiple data-placeholder="ทั้งหมด">
                            @foreach ($sourceOptions ?? $siteOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" {{ in_array($option['value'], $selectedSources, true) ? 'selected' : '' }}>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Work Center</label>
                        <select name="workcenter_ids[]" class="form-select mla-select" multiple data-placeholder="ทุก Work Center">
                            @foreach ($workcenterOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" data-site="{{ $option['site'] ?? '' }}" {{ in_array((string) $option['value'], $selectedWorkCenters, true) ? 'selected' : '' }}>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">เครื่องจักร</label>
                        <select name="machine_ids[]" class="form-select mla-select" multiple data-placeholder="ทุกเครื่องจักร">
                            @foreach ($machineOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" data-site="{{ $option['site'] ?? '' }}" {{ in_array((string) $option['value'], $selectedMachines, true) ? 'selected' : '' }}>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">สถานะ WO</label>
                        <select name="status" class="form-select">
                            <option value="open" {{ ($filters['status'] ?? '') === 'open' ? 'selected' : '' }}>ยังไม่ปิด</option>
                            <option value="closed" {{ ($filters['status'] ?? '') === 'closed' ? 'selected' : '' }}>ปิดแล้ว</option>
                            <option value="all" {{ ($filters['status'] ?? '') === 'all' ? 'selected' : '' }}>ทั้งหมด</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Quick Filter</label>
                        <select name="quick_filter" class="form-select">
                            <option value="all" {{ ($filters['quick_filter'] ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                            <option value="load_80" {{ ($filters['quick_filter'] ?? '') === 'load_80' ? 'selected' : '' }}>Load &gt;= 80%</option>
                            <option value="load_100" {{ ($filters['quick_filter'] ?? '') === 'load_100' ? 'selected' : '' }}>Load &gt;= 100%</option>
                            <option value="due_risk" {{ ($filters['quick_filter'] ?? '') === 'due_risk' ? 'selected' : '' }}>Late Risk</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Per Page</label>
                        <select name="per_page" class="form-select">
                            @foreach ([50, 100, 200, 500] as $opt)
                                <option value="{{ $opt }}" {{ (int) ($perPage ?? 50) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-5 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i> ค้นหา</button>
                        <a href="{{ route('machine-load.export', request()->query()) }}" class="btn btn-success flex-fill"><i class="fas fa-file-excel me-1"></i> ส่งออก</a>
                        <a href="{{ route('machine-load.inquiry') }}" class="btn btn-outline-secondary flex-fill"><i class="fas fa-eraser me-1"></i> Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="mla-panel">
            <div class="mla-head">
                <span>รายการ WO ค้างผลิต</span>
                <span class="text-muted small">ทั้งหมด {{ $fmt($totalRows ?? $rows->count()) }} รายการ</span>
            </div>
            <div class="mla-table-wrap">
                <table class="table table-bordered table-sm mla-table mb-0">
                    <thead>
                        <tr>
                            <th>แหล่งข้อมูล</th>
                            <th>Work Center</th>
                            <th>เครื่องจักร</th>
                            <th>WO</th>
                            <th class="num">ค้างผลิต</th>
                            <th class="num">ชั่วโมงงาน</th>
                            <th class="num">โหลด %</th>
                            <th>ว่างวันที่</th>
                            <th>กำหนดส่ง</th>
                            <th>วันที่เปิด WO</th>
                            <th class="num">จำนวนสั่งผลิต</th>
                            <th class="num">ผลิตแล้ว</th>
                            <th class="num">กำลังผลิต/ชม.</th>
                            <th class="num">ชม./วัน</th>
                            <th class="num">นาที/หน่วย</th>
                            <th class="num">Setup นาที</th>
                            <th>ลูกค้า</th>
                            <th>Connection</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="nowrap">{{ ucfirst(strtolower($row->source_site)) }}</td>
                                <td class="nowrap">{{ $row->workcenter_label }}</td>
                                <td class="nowrap">{{ $row->machine_label }}</td>
                                <td class="nowrap">{{ $row->workordernumber }}</td>
                                <td class="num key-number">{{ $fmt($row->balance_qty, 2) }}</td>
                                <td class="num key-number">{{ $fmt($row->required_hours, 2) }}</td>
                                <td class="num key-number">{{ $fmt($row->load_pct, 2) }}%</td>
                                <td class="next-date nowrap">{{ $row->next_available_at }}</td>
                                <td class="nowrap">{{ $row->reqdate }}</td>
                                <td class="nowrap">{{ $row->dateopen }}</td>
                                <td class="num">{{ $fmt($row->order_qty, 2) }}</td>
                                <td class="num">{{ $fmt($row->produced_qty, 2) }}</td>
                                <td class="num">{{ $fmt($row->capacity_per_hour, 4) }}</td>
                                <td class="num">{{ $fmt($row->work_hours_per_day, 2) }}</td>
                                <td class="num">{{ $fmt($row->cycle_time_minutes, 4) }}</td>
                                <td class="num">{{ $fmt($row->setup_time_minutes, 2) }}</td>
                                <td class="cust">{{ $row->customer_name }}</td>
                                <td class="nowrap">{{ $row->source_conn }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="18" class="text-center text-muted py-4">ไม่พบรายการค้างผลิต</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (isset($paginator) && $paginator->hasPages())
                <div class="p-3 d-flex justify-content-end">
                    {{ $paginator->onEachSide(1)->links() }}
                </div>
            @endif
        </div>
    </div>

    @push('scripts')
        <script>
            const mlaWcOptions = @json($workcenterOptions ?? []);
            const mlaMcOptions = @json($machineOptions ?? []);

            document.addEventListener('DOMContentLoaded', function () {
                const tomBySelect = new Map();
                document.querySelectorAll('.mla-select').forEach(function (el) {
                    const ts = new TomSelect(el, {
                        plugins: ['remove_button'],
                        maxOptions: 1000,
                        placeholder: el.dataset.placeholder || 'ทั้งหมด',
                        dropdownParent: 'body'
                    });
                    tomBySelect.set(el.name, { el, ts });
                });

                const source = tomBySelect.get('source[]');
                const wc = tomBySelect.get('workcenter_ids[]');
                const mc = tomBySelect.get('machine_ids[]');
                if (!source) return;

                function repopulate(entry, fullOptions) {
                    if (!entry) return;
                    const ts = entry.ts;
                    const allow = new Set((source.ts.getValue() || []).map(v => String(v).toUpperCase()));
                    const current = ts.getValue() || [];

                    const filtered = fullOptions.filter(o => allow.size === 0 || allow.has(String(o.site || '').toUpperCase()));
                    const filteredValues = new Set(filtered.map(o => String(o.value)));

                    ts.clearOptions();
                    filtered.forEach(o => ts.addOption({ value: String(o.value), text: o.label, site: o.site }));
                    ts.refreshOptions(false);

                    const kept = current.filter(v => filteredValues.has(String(v)));
                    ts.setValue(kept, true);
                }

                function applySourceFilter() {
                    repopulate(wc, mlaWcOptions);
                    repopulate(mc, mlaMcOptions);
                }

                source.ts.on('change', applySourceFilter);
                applySourceFilter();
            });
        </script>
    @endpush
@endsection
