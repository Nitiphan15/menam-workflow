@extends('layouts.layout')
@section('title', 'Supplier Need to Order')
@section('page-title', 'Supplier Need to Order')

@section('content')
    <div class="container-fluid">
        <style>
            .fc-card {
                border: 0;
                border-radius: 8px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
            }

            .summary-table th,
            .summary-table td {
                white-space: nowrap;
                vertical-align: middle;
            }

            .summary-table thead th {
                position: sticky;
                top: 0;
                z-index: 2;
                background: #f8fafc;
            }

            .table-wrap {
                max-height: 58vh;
                overflow: auto;
                border: 1px solid #e9ecef;
                border-radius: 8px;
            }

            .kpi-tile {
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 12px 14px;
                background: #fff;
                min-height: 86px;
            }

            .kpi-label {
                color: #6c757d;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0;
            }

            .kpi-value {
                font-size: 24px;
                font-weight: 700;
                line-height: 1.2;
            }

            .source-badge {
                display: inline-flex;
                align-items: center;
                min-width: 62px;
                justify-content: center;
            }

            .supplier-group-row td {
                background: #eef6ff;
                border-top: 2px solid #b6d7ff;
                font-weight: 700;
            }

            .supplier-group-meta {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                justify-content: flex-end;
                font-size: 12px;
                font-weight: 600;
            }

            .supplier-group-meta span {
                font-variant-numeric: tabular-nums;
            }
        </style>

        <div class="card fc-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <div class="fw-semibold">Supplier Summary Filter</div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="{{ route('fc.index', request()->query()) }}" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-table me-1"></i> Forecast RM
                    </a>
                    <a href="{{ route('fc.supplierShortage.export', request()->query()) }}" class="btn btn-sm btn-success">
                        <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                    </a>
                </div>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" action="{{ route('fc.supplierShortage') }}">
                    <div class="col-md-2">
                        <label class="form-label">RM Part</label>
                        <input class="form-control form-control-sm" name="sku" value="{{ $skuLike }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Forecast Month</label>
                        <input type="month" class="form-control form-control-sm" name="plan_month"
                            value="{{ \Carbon\Carbon::parse($planMonth)->format('Y-m') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select class="form-select form-select-sm" name="suppliers[]" multiple>
                            @foreach ($supplierOptions as $sp)
                                <option value="{{ $sp['supplier_code'] }}" @selected(in_array($sp['supplier_code'], $selectedSuppliers ?? [], true))>
                                    {{ $sp['supplier_code'] }} - {{ $sp['supplier_name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Grade</label>
                        <select class="form-select form-select-sm" name="grades[]" multiple>
                            @foreach ($gradeOptions as $gr)
                                <option value="{{ $gr }}" @selected(in_array($gr, $selectedGrades ?? [], true))>{{ $gr }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Mode</label>
                        <select class="form-select form-select-sm" name="source_mode">
                            <option value="final" @selected($sourceMode === 'final')>Final order</option>
                            <option value="auto" @selected($sourceMode === 'auto')>Auto only</option>
                            <option value="manual" @selected($sourceMode === 'manual')>Manual only</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="shortage_only" value="1"
                                id="shortageOnly" @checked($shortageOnly ?? false)>
                            <label class="form-check-label small" for="shortageOnly">เฉพาะต้องสั่งเพิ่ม</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-filter me-1"></i> Apply
                        </button>
                        <a href="{{ route('fc.supplierShortage') }}" class="btn btn-sm btn-outline-secondary">
                            Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="kpi-tile">
                    <div class="kpi-label">Suppliers</div>
                    <div class="kpi-value">{{ number_format((int) ($kpi['supplier_count'] ?? 0)) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-tile">
                    <div class="kpi-label">RM Parts</div>
                    <div class="kpi-value">{{ number_format((int) ($kpi['rm_count'] ?? 0)) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-tile">
                    <div class="kpi-label">ต้องสั่งเพิ่ม</div>
                    <div class="kpi-value text-danger">{{ number_format((float) ($kpi['shortage_qty'] ?? 0), 2) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-tile">
                    <div class="kpi-label">คงเหลือเพียงพอ / Manual</div>
                    <div class="kpi-value text-success">{{ number_format((float) ($kpi['surplus_qty'] ?? 0), 2) }}</div>
                    <div class="small text-muted">Manual: {{ number_format((float) ($kpi['manual_order_qty'] ?? 0), 2) }}</div>
                </div>
            </div>
        </div>

        <div class="card fc-card mb-3">
            <div class="card-header bg-white fw-semibold">Supplier Summary</div>
            <div class="card-body p-0">
                <div class="table-wrap">
                    <table class="table table-sm table-hover summary-table mb-0">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th class="text-end">RM Count</th>
                                <th class="text-end">ต้องสั่งเพิ่ม</th>
                                <th class="text-end">คงเหลือเพียงพอ</th>
                                <th class="text-end">Manual Order</th>
                                <th class="text-end">รวมตัวเลขที่แสดง</th>
                                <th class="text-end">Manual Lines</th>
                                <th class="text-end">Auto Lines</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($summaryRows as $r)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $r['supplier_name'] }}</div>
                                        <div class="small text-muted">{{ $r['supplier_code'] ?: $r['supplier_key'] }}</div>
                                    </td>
                                    <td class="text-end">{{ number_format((int) $r['rm_count']) }}</td>
                                    <td class="text-end">
                                        <span class="text-danger">{{ number_format((float) ($r['shortage_qty'] ?? 0), 2) }}</span>
                                    </td>
                                    <td class="text-end text-success">{{ number_format((float) ($r['surplus_qty'] ?? 0), 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $r['manual_order_qty'], 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $r['final_order_qty'], 2) }}</td>
                                    <td class="text-end">{{ number_format((int) $r['manual_line_count']) }}</td>
                                    <td class="text-end">{{ number_format((int) $r['auto_line_count']) }}</td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary"
                                            href="#supplier-{{ md5($r['supplier_key']) }}">Detail</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No supplier rows found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card fc-card">
            <div class="card-header bg-white fw-semibold">Supplier Detail</div>
            <div class="card-body p-0">
                @php
                    $detailGroups = collect($detailRows)->groupBy('supplier_key');
                    $summaryBySupplier = collect($summaryRows)->keyBy('supplier_key');
                @endphp

                <div class="table-wrap">
                    <table class="table table-sm table-hover summary-table mb-0">
                        <thead>
                            <tr>
                                <th>RM Part</th>
                                <th>Description</th>
                                <th>Grade</th>
                                <th class="text-end">Forecast + SO</th>
                                <th class="text-end">Supply</th>
                                <th class="text-end">ต้องสั่งเพิ่ม</th>
                                <th class="text-end">Manual</th>
                                <th class="text-end">รวมตัวเลขที่แสดง</th>
                                <th>Source</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($detailGroups as $supplierKey => $lines)
                                @php
                                    $first = $lines->first();
                                    $summary = $summaryBySupplier->get($supplierKey);
                                    $supplierId = 'supplier-' . md5($supplierKey);
                                @endphp
                                <tr class="supplier-group-row" id="{{ $supplierId }}">
                                    <td colspan="10">
                                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                                            <div>
                                                {{ $first['supplier_name'] }}
                                                <span class="text-muted small ms-2">{{ $first['supplier_code'] ?: $supplierKey }}</span>
                                            </div>
                                            <div class="supplier-group-meta">
                                                <span>RM: {{ number_format((int) ($summary['rm_count'] ?? $lines->pluck('rm_partnumber')->unique()->count())) }}</span>
                                                <span class="text-danger">ต้องสั่งเพิ่ม: {{ number_format((float) ($summary['shortage_qty'] ?? $lines->where('need_status', 'SHORTAGE')->sum('auto_need_to_order')), 2) }}</span>
                                                <span class="text-success">คงเหลือเพียงพอ: {{ number_format((float) ($summary['surplus_qty'] ?? $lines->where('need_status', 'SURPLUS')->sum('auto_need_to_order')), 2) }}</span>
                                                <span>Manual: {{ number_format((float) ($summary['manual_order_qty'] ?? $lines->sum('manual_order_qty')), 2) }}</span>
                                                <span>รวม: {{ number_format((float) ($summary['final_order_qty'] ?? $lines->sum('final_order_qty')), 2) }}</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                @foreach ($lines as $r)
                                    @php
                                        $supply = (float) $r['onhand'] + (float) $r['fg'] + (float) $r['po_total'] + (float) $r['wip'];
                                    @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('fc.index', array_merge(request()->query(), ['sku' => $r['rm_partnumber']])) }}"
                                                class="text-decoration-none fw-semibold">
                                                {{ $r['rm_partnumber'] }}
                                            </a>
                                        </td>
                                        <td>{{ $r['description'] }}</td>
                                        <td>{{ $r['grade'] ?: '-' }}</td>
                                        <td class="text-end">{{ number_format((float) $r['total_forecast_so'], 2) }}</td>
                                        <td class="text-end">{{ number_format($supply, 2) }}</td>
                                        <td class="text-end {{ $r['need_status'] === 'SURPLUS' ? 'text-success' : 'text-danger' }}">{{ number_format((float) $r['auto_need_to_order'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $r['manual_order_qty'], 2) }}</td>
                                        <td class="text-end fw-semibold">{{ number_format((float) $r['final_order_qty'], 2) }}</td>
                                        <td>
                                            <span class="badge source-badge {{ $r['source'] === 'MANUAL' ? 'bg-warning text-dark' : 'bg-secondary' }}">
                                                {{ $r['source'] }}
                                            </span>
                                        </td>
                                        <td>{{ $r['remark'] }}</td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No detail rows found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
