@extends('layouts.layout')

@section('page-title', 'Workcenter Test Map')
@section('title', 'Workcenter Test Map')

@section('content')
    @php
        $fmtNum = function ($value, int $decimals = 2) {
            if ($value === null || $value === '') {
                return '-';
            }

            return is_numeric($value)
                ? number_format((float) $value, $decimals)
                : (string) $value;
        };

        $fmtLimit = function ($slot) use ($fmtNum) {
            $operator = trim((string) ($slot['operator'] ?? ''));
            $lower = $slot['lower'] ?? null;
            $upper = $slot['upper'] ?? null;
            $tab = $slot['tab'] ?? null;

            if ($operator !== '') {
                return trim($operator . ' ' . $fmtNum($lower, 4) . ' / ' . $fmtNum($upper, 4));
            }

            if ($tab !== null && $tab !== '') {
                return 'Tab ' . $tab;
            }

            return '-';
        };
    @endphp

    <div class="container-xxl py-3 isr-map">
        <div class="isr-map-header mb-3">
            <div>
                <div class="text-muted small text-uppercase fw-semibold">ISR Master Data</div>
                <h1 class="h4 mb-1">Workcenter Test Map</h1>
                <div class="text-muted">
                    workcenter + workcentertestval + workcentertestvalextend + workcentertestitems
                </div>
            </div>
            <a href="{{ route('isr.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i>Inspection
            </a>
        </div>

        <section class="isr-map-panel mb-3">
            <form method="get" action="{{ route('isr.workcenter-tests') }}" class="row g-2 align-items-end">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small text-muted mb-1">Site</label>
                    <select name="site" class="form-select form-select-sm">
                        <option value="wire" @selected(($filters['site'] ?? 'wire') === 'wire')>WIRE</option>
                        <option value="plus" @selected(($filters['site'] ?? 'wire') === 'plus')>PLUS</option>
                    </select>
                </div>
                <div class="col-md-5 col-sm-6">
                    <label class="form-label small text-muted mb-1">Workcenter / Config ID / Slot</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm"
                        placeholder="เช่น 103, DRW, v11, Packing">
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small text-muted mb-1">workcentertestval_id</label>
                    <input type="number" name="workcentertestval_id" value="{{ $filters['workcentertestval_id'] ?? '' }}"
                        class="form-control form-control-sm" placeholder="ระบุ id แม่โดยตรง">
                </div>
                <div class="col-md-2 col-sm-6">
                    <button class="btn btn-primary btn-sm w-100">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหา
                    </button>
                </div>
            </form>
        </section>

        <section class="isr-map-summary mb-3">
            <div>
                <div class="label">Configs</div>
                <div class="value">{{ number_format($summary['config_count'] ?? 0) }}</div>
            </div>
            <div>
                <div class="label">Slots</div>
                <div class="value">{{ number_format($summary['slot_count'] ?? 0) }}</div>
            </div>
            <div>
                <div class="label">Required</div>
                <div class="value">{{ number_format($summary['required_count'] ?? 0) }}</div>
            </div>
            <div>
                <div class="label">Actual Tests</div>
                <div class="value">{{ number_format($summary['actual_test_count'] ?? 0) }}</div>
            </div>
            <div>
                <div class="label">Test Items</div>
                <div class="value">{{ number_format($summary['actual_item_count'] ?? 0) }}</div>
            </div>
        </section>

        <section class="isr-map-panel mb-3">
            <div class="row g-3">
                <div class="col-lg-3">
                    <div class="small text-muted text-uppercase fw-semibold mb-2">Join Model</div>
                    <ol class="isr-join-list">
                        <li><code>workcenter.id</code> = <code>workcentertestval.workcenter_id</code></li>
                        <li><code>workcentertestval.id</code> = <code>workcentertestvalextend.workcentertestval_id</code></li>
                        <li><code>workcenter.id</code> = <code>workcentertest.workcenter_id</code></li>
                        <li><code>workcentertest.id</code> = <code>workcentertestitems.workcentertest_id</code></li>
                    </ol>
                </div>
                <div class="col-lg-9">
                    <div class="small text-muted text-uppercase fw-semibold mb-2">Recommended Display Shape</div>
                    <div class="isr-shape-grid">
                        <div><span>workcenter</span><strong>รหัส / ชื่อเครื่องหรือขั้นตอน</strong></div>
                        <div><span>config</span><strong>workcentertestval_id + valseq/reqf</strong></div>
                        <div><span>slot rows</span><strong>key, label, required, limit, source table</strong></div>
                        <div><span>actual usage</span><strong>จำนวน workcentertest และ workcentertestitems</strong></div>
                    </div>
                </div>
            </div>
        </section>

        @forelse ($rows as $row)
            <section class="isr-map-panel mb-3">
                <div class="isr-config-head">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge text-bg-dark">#{{ $row['id'] }}</span>
                            <span class="fw-bold">{{ $row['workcenter_number'] ?: 'WC ' . $row['workcenter_id'] }}</span>
                            <span class="text-muted">{{ $row['workcenter_description'] ?: '-' }}</span>
                        </div>
                        <div class="small text-muted">
                            workcenter_id {{ $row['workcenter_id'] }}
                            <span class="mx-2">|</span>
                            capacity {{ $fmtNum($row['capacity']) }}
                            <span class="mx-2">|</span>
                            workhour {{ $fmtNum($row['workhour']) }}
                        </div>
                    </div>
                    <div class="isr-config-metrics">
                        <span><strong>{{ number_format($row['slot_count']) }}</strong> slots</span>
                        <span><strong>{{ number_format($row['required_count']) }}</strong> required</span>
                        <span><strong>{{ number_format($row['test_count']) }}</strong> tests</span>
                        <span><strong>{{ number_format($row['item_count']) }}</strong> items</span>
                    </div>
                </div>

                <div class="isr-seq-line mt-2">
                    <span>valseq</span>
                    <code>{{ $row['valseq'] ?: '-' }}</code>
                </div>
                <div class="isr-seq-line">
                    <span>reqf</span>
                    <code>{{ $row['reqf'] ?: '-' }}</code>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle isr-slot-table mb-0">
                        <thead>
                            <tr>
                                <th class="text-end">#</th>
                                <th>Slot</th>
                                <th>Group</th>
                                <th>Label</th>
                                <th>Required</th>
                                <th>Spec / Tab</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($row['slots'] as $slot)
                                <tr>
                                    <td class="text-end text-muted">{{ $slot['position'] }}</td>
                                    <td><code>{{ $slot['key'] }}</code></td>
                                    <td>{{ $slot['group'] }}</td>
                                    <td class="fw-semibold">{{ $slot['label'] }}</td>
                                    <td>
                                        @if ($slot['required'])
                                            <span class="badge text-bg-danger">บังคับ</span>
                                        @elseif (($slot['required_flag'] ?? '') === 'o')
                                            <span class="badge text-bg-secondary">ไม่บังคับ</span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td><code>{{ $fmtLimit($slot) }}</code></td>
                                    <td class="text-muted">{{ $slot['source_table'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <section class="isr-map-panel">
                <div class="text-muted py-4 text-center">ไม่พบข้อมูลตามเงื่อนไข</div>
            </section>
        @endforelse
    </div>
@endsection

@push('styles')
    <style>
        .isr-map-header {
            align-items: flex-start;
            display: flex;
            gap: 16px;
            justify-content: space-between;
        }

        .isr-map-panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
        }

        .isr-map-summary {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .isr-map-summary > div {
            background: #0f172a;
            border-radius: 8px;
            color: #fff;
            min-height: 78px;
            padding: 14px 16px;
        }

        .isr-map-summary .label {
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .isr-map-summary .value {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
            margin-top: 6px;
        }

        .isr-join-list {
            margin: 0;
            padding-left: 18px;
        }

        .isr-join-list li {
            margin-bottom: 6px;
        }

        .isr-shape-grid {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .isr-shape-grid > div {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .isr-shape-grid span {
            color: #64748b;
            display: block;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .isr-shape-grid strong {
            display: block;
            font-size: 13px;
            margin-top: 4px;
        }

        .isr-config-head {
            align-items: flex-start;
            display: flex;
            gap: 12px;
            justify-content: space-between;
        }

        .isr-config-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .isr-config-metrics span {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            display: inline-flex;
            gap: 4px;
            padding: 4px 10px;
            white-space: nowrap;
        }

        .isr-seq-line {
            align-items: baseline;
            display: grid;
            gap: 10px;
            grid-template-columns: 70px minmax(0, 1fr);
            margin-top: 6px;
        }

        .isr-seq-line span {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .isr-seq-line code {
            overflow-wrap: anywhere;
            white-space: normal;
        }

        .isr-slot-table th {
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .isr-slot-table td {
            font-size: 13px;
        }

        @media (max-width: 991.98px) {
            .isr-map-summary,
            .isr-shape-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .isr-config-head {
                display: block;
            }

            .isr-config-metrics {
                justify-content: flex-start;
                margin-top: 10px;
            }
        }

        @media (max-width: 575.98px) {
            .isr-map-header {
                display: block;
            }

            .isr-map-header .btn {
                margin-top: 12px;
                width: 100%;
            }

            .isr-map-summary,
            .isr-shape-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush
