@extends('layouts.layout')

@section('title', 'ภาพรวม Deadstock')
@section('page-title', 'ภาพรวม Deadstock')

@push('styles')
    <style>
        .deadstock-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }

        .deadstock-kpi-link {
            border: 1px solid transparent;
            border-radius: 8px;
            color: inherit;
            display: block;
            height: 100%;
            margin: -8px;
            padding: 8px;
            text-decoration: none;
        }

        .deadstock-kpi-link:hover {
            background: #f8fafc;
            border-color: #dbe4ef;
            color: inherit;
        }

        .deadstock-page-head {
            align-items: center;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .deadstock-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .deadstock-section-title {
            color: #334155;
            font-size: .82rem;
            font-weight: 700;
            margin: 4px 0 10px;
            text-transform: uppercase;
        }

        .deadstock-kpi-label {
            color: #64748b;
            font-size: .78rem;
            font-weight: 700;
        }

        .deadstock-kpi-value {
            color: #0f172a;
            font-size: 1.45rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .deadstock-kpi-sub {
            color: #64748b;
            font-size: .78rem;
            min-height: 1.1rem;
        }

        .deadstock-change {
            border-radius: 999px;
            display: inline-flex;
            font-size: .75rem;
            font-weight: 700;
            line-height: 1;
            padding: .28rem .48rem;
        }

        .deadstock-change.up {
            background: #fee2e2;
            color: #991b1b;
        }

        .deadstock-change.down {
            background: #dcfce7;
            color: #166534;
        }

        .deadstock-change.flat {
            background: #e2e8f0;
            color: #334155;
        }

        .deadstock-insight-note {
            color: #475569;
            font-size: .82rem;
            margin-top: .55rem;
        }

        .deadstock-chart {
            align-items: end;
            border-bottom: 1px solid #e2e8f0;
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(var(--count), minmax(18px, 1fr));
            height: 250px;
            padding: 12px 4px 0;
        }

        .deadstock-bar {
            background: #0f766e;
            border-radius: 4px 4px 0 0;
            min-height: 3px;
        }

        .deadstock-chart-labels {
            color: #64748b;
            display: grid;
            font-size: .72rem;
            gap: 8px;
            grid-template-columns: repeat(var(--count), minmax(18px, 1fr));
            margin-top: 8px;
            text-align: center;
        }

        .deadstock-rank-row {
            align-items: center;
            border-bottom: 1px solid #edf2f7;
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(0, 1fr) auto;
            padding: .55rem 0;
        }

        .deadstock-rank-row:last-child {
            border-bottom: 0;
        }

        .deadstock-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .deadstock-meter {
            background: #e2e8f0;
            border-radius: 999px;
            height: 7px;
            overflow: hidden;
        }

        .deadstock-meter>span {
            background: #0f766e;
            display: block;
            height: 100%;
            min-width: 2px;
        }

        .deadstock-table-wrap {
            max-height: 440px;
            overflow: auto;
        }

        .deadstock-sticky th {
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .deadstock-compact-table {
            max-height: 340px;
            overflow: auto;
        }

        .deadstock-attention {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-top: 14px;
            padding: 12px 14px;
        }

        .deadstock-attention ul {
            margin-bottom: 0;
            padding-left: 18px;
        }

        @media (max-width: 768px) {
            .deadstock-page-head {
                flex-direction: column;
            }

            .deadstock-actions {
                justify-content: flex-start;
                width: 100%;
            }
        }
    </style>
@endpush

@section('content')
    @php
        $totals = $latest['totals'] ?? [];
        $reviewMonthLabel = $reviewMonth?->snapshot_month?->format('M Y');
        $maxTrend = max(1, (float) $trend->max('qty'));
        $chartCount = max(1, $trend->count());
        $fmtPct = fn($value) => $value === null ? 'ใหม่' : number_format((float) $value, 1) . '%';
        $changeClass = fn($value) => abs((float) $value) < 0.00001 ? 'flat' : ((float) $value > 0 ? 'up' : 'down');
        $changeText = fn($value) => ((float) $value > 0 ? '+' : '') . number_format((float) $value, 0);
        $pctText = fn($value) => ((float) $value > 0 ? '+' : '') . $fmtPct($value);
        $totalReviewItems = (int) ($reviewSummary->total_items ?? 0);
        $activeItems = (int) ($reviewSummary->active_items ?? 0);
        $changedItems = (int) ($reviewSummary->changed_items ?? 0);
        $clearedItems = (int) ($reviewSummary->cleared_items ?? 0);
        $clearRate = $totalReviewItems > 0 ? ($clearedItems / $totalReviewItems) * 100 : 0;
        $openQty = (float) ($reviewSummary->open_qty ?? 0);
        $clearedQty = (float) ($reviewSummary->cleared_qty ?? 0);
        $snapshotQty = (float) ($reviewSummary->snapshot_qty ?? 0);
        $currentTotalQty = (float) ($reviewSummary->current_total_qty ?? 0);
        $noActionItems = (int) ($reviewSummary->no_action_items ?? 0);
        $dueFollowUpItems = (int) ($reviewSummary->due_follow_up_items ?? 0);
        $closedActionItems = (int) ($reviewSummary->closed_action_items ?? 0);
        $qtyClearRate = $snapshotQty > 0 ? ($clearedQty / $snapshotQty) * 100 : 0;
        $reviewMonthQuery = $reviewMonth ? ['month_ids' => [(int) $reviewMonth->id]] : [];
        $activeLink = route('deadstock.review', $reviewMonthQuery + ['status' => 'active']);
        $changedLink = route('deadstock.review', $reviewMonthQuery + ['status' => 'changed']);
        $clearedLink = route('deadstock.review', $reviewMonthQuery + ['status' => 'cleared']);
        $noActionLink = route('deadstock.review', $reviewMonthQuery + ['action_status' => 'no_action']);
        $dueFollowUpLink = route('deadstock.review', $reviewMonthQuery + ['action_status' => 'due_follow_up']);
        $closedActionLink = route('deadstock.review', $reviewMonthQuery + ['action_status' => 'closed']);
    @endphp

    <div class="container-fluid py-3">
        <div class="deadstock-page-head">
            <div>
                <div class="text-muted small">
                    Snapshot ล่าสุด:
                    {{ $latest['recv_date'] ?? ($latest['as_of'] ?? 'No snapshot') }}
                    @if (!empty($latest['generated']))
                        · สร้างเมื่อ {{ $latest['generated'] }}
                    @endif
                </div>
            </div>
            <div class="deadstock-actions">
                <a class="btn btn-outline-primary" href="{{ route('deadstock.review') }}">
                    <i class="fa fa-clipboard-check me-1"></i> ตรวจสถานะรายเดือน
                </a>
                {{-- <a class="btn btn-primary" href="{{ route('deadstock.manual') }}">
                    <i class="fa fa-paper-plane me-1"></i> ส่งเมลเอง
                </a> --}}
            </div>
        </div>

        @if (!$latest)
            <div class="alert alert-warning">
                ยังไม่มี snapshot ที่อ่านได้จาก {{ $sourcePath }}
            </div>
        @endif

        <div class="deadstock-section-title">สถานะปัจจุบันเทียบกับ snapshot</div>
        <div class="row g-3 mb-3">
            <div class="col-12">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="mb-3">
                            <h5 class="mb-1">สถานะเทียบข้อมูลปัจจุบัน</h5>
                            <div class="text-muted small">
                                ใช้ snapshot จากเมลรายวัน{{ $reviewMonthLabel ? " รอบ {$reviewMonthLabel}" : '' }}
                                เทียบกับข้อมูลปัจจุบัน เพื่อดูว่ายังต่อเนื่องอยู่ เคลียร์แล้ว หรือเปลี่ยนแปลงระหว่างทาง
                            </div>
                        </div>
                        @if ($reviewSummary)
                            <div class="row g-3">
                                <div class="col-6 col-xl-3">
                                    <a class="deadstock-kpi-link" href="{{ $activeLink }}">
                                        <div class="deadstock-kpi-label">ยังค้าง</div>
                                        <div class="deadstock-kpi-value">
                                            {{ number_format((int) ($reviewSummary->active_items ?? 0)) }}</div>
                                    </a>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <a class="deadstock-kpi-link" href="{{ $changedLink }}">
                                        <div class="deadstock-kpi-label">เปลี่ยนแปลง</div>
                                        <div class="deadstock-kpi-value">
                                            {{ number_format((int) ($reviewSummary->changed_items ?? 0)) }}</div>
                                    </a>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <a class="deadstock-kpi-link" href="{{ $clearedLink }}">
                                        <div class="deadstock-kpi-label">เคลียร์แล้ว</div>
                                        <div class="deadstock-kpi-value">
                                            {{ number_format((int) ($reviewSummary->cleared_items ?? 0)) }}</div>
                                    </a>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="deadstock-kpi-label">Qty ที่ยังต้องติดตาม</div>
                                    <div class="deadstock-kpi-value">{{ number_format($openQty, 2) }}</div>
                                </div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-6 col-xl-3">
                                    <div class="deadstock-kpi-label">Qty หลังเทียบล่าสุด</div>
                                    <div class="deadstock-kpi-value">{{ number_format($currentTotalQty, 2) }}</div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <a class="deadstock-kpi-link" href="{{ $noActionLink }}">
                                        <div class="deadstock-kpi-label">ยังไม่มีงานติดตาม</div>
                                        <div class="deadstock-kpi-value">{{ number_format($noActionItems) }}</div>
                                    </a>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <a class="deadstock-kpi-link" href="{{ $dueFollowUpLink }}">
                                        <div class="deadstock-kpi-label">ถึงวันติดตาม</div>
                                        <div class="deadstock-kpi-value">{{ number_format($dueFollowUpItems) }}</div>
                                    </a>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <a class="deadstock-kpi-link" href="{{ $closedActionLink }}">
                                        <div class="deadstock-kpi-label">ปิดการติดตามแล้ว</div>
                                        <div class="deadstock-kpi-value">{{ number_format($closedActionItems) }}</div>
                                    </a>
                                </div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-6 col-xl-3">
                                    <div class="deadstock-kpi-label">อัตราเคลียร์</div>
                                    <div class="deadstock-kpi-value">{{ number_format($clearRate, 1) }}%</div>
                                    <div class="deadstock-kpi-sub">{{ number_format($clearedItems) }} /
                                        {{ number_format($totalReviewItems) }} รายการ</div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="deadstock-kpi-label">Qty เคลียร์แล้ว</div>
                                    <div class="deadstock-kpi-value">{{ number_format($clearedQty, 2) }}</div>
                                    <div class="deadstock-kpi-sub">{{ number_format($qtyClearRate, 1) }}% ของ snapshot
                                    </div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="deadstock-kpi-label">Qty เปลี่ยนแปลง</div>
                                    <div class="deadstock-kpi-value">
                                        {{ number_format((float) ($reviewSummary->changed_qty ?? 0), 2) }}</div>
                                </div>
                                <div class="col-6 col-xl-3">
                                    <div class="deadstock-kpi-label">Qty ยังตรง snapshot</div>
                                    <div class="deadstock-kpi-value">
                                        {{ number_format((float) ($reviewSummary->active_qty ?? 0), 2) }}</div>
                                </div>
                            </div>
                            <div class="deadstock-attention">
                                <div class="fw-semibold mb-2">ข้อควรดูวันนี้</div>
                                <ul>
                                    @if ($changedItems > 0)
                                        <li>มี {{ number_format($changedItems) }} รายการที่ Qty หรือกำหนดส่งเปลี่ยน
                                            ควรเปิดดูรายละเอียดก่อน</li>
                                    @else
                                        <li>ยังไม่มีรายการที่เปลี่ยนจาก snapshot ล่าสุด</li>
                                    @endif
                                    @if ($activeItems > 0)
                                        <li>ยังมี {{ number_format($activeItems) }} รายการค้างอยู่ ให้ติดตามตาม Sales
                                            หรือสาเหตุหลักด้านล่าง</li>
                                    @endif
                                    <li>เคลียร์แล้ว {{ number_format($clearedItems) }} จาก
                                        {{ number_format($totalReviewItems) }} รายการ
                                        ({{ number_format($clearRate, 1) }}%)</li>
                                </ul>
                            </div>
                            @if ($urgentItems->isNotEmpty())
                                <div class="deadstock-attention">
                                    <div class="fw-semibold mb-2">รายการที่ควรเปิดดูก่อน</div>
                                    @foreach ($urgentItems as $urgent)
                                        <div class="deadstock-rank-row">
                                            <div>
                                                <div class="fw-semibold">
                                                    {{ $urgent->part_description ?: $urgent->partnumber ?: '-' }}</div>
                                                <div class="text-muted small">
                                                    {{ $urgent->customer_name ?: '-' }} ·
                                                    {{ $urgent->salesperson_name ?: '-' }}
                                                    @if ((int) ($urgent->item_count ?? 0) > 1)
                                                        · {{ number_format((int) $urgent->item_count) }} รายการย่อย
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <strong>{{ number_format((float) ($urgent->urgent_qty ?? 0), 2) }}</strong>
                                                <div class="text-muted small">
                                                    {{ (int) ($urgent->status_priority ?? 2) === 1 ? 'มีเปลี่ยนแปลง' : 'ยังค้าง' }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <div class="text-muted">ยังไม่มี snapshot ที่ import เข้าหน้าตรวจสถานะรายเดือน</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="deadstock-section-title">ภาพรวมจาก snapshot ล่าสุด</div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">Qty รวม</div>
                        <div class="deadstock-kpi-value">{{ number_format((float) ($totals['total_qty'] ?? 0), 0) }}</div>
                        <div class="text-muted small">KG</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">มูลค่ารวม</div>
                        <div class="deadstock-kpi-value">{{ number_format((float) ($totals['total_value'] ?? 0), 2) }}
                        </div>
                        <div class="text-muted small">Baht</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">ลูกค้า</div>
                        <div class="deadstock-kpi-value">{{ number_format((int) ($totals['customers'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">Part</div>
                        <div class="deadstock-kpi-value">{{ number_format((int) ($totals['parts'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">เปลี่ยนจาก snapshot ก่อนหน้า</div>
                        <div class="deadstock-kpi-value">{{ $changeText($insights['qty_change'] ?? 0) }}</div>
                        <div class="deadstock-kpi-sub">
                            <span class="deadstock-change {{ $changeClass($insights['qty_change'] ?? 0) }}">
                                {{ $pctText($insights['qty_change_pct'] ?? 0) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">เทียบค่าเฉลี่ย 7 snapshot</div>
                        <div class="deadstock-kpi-value">{{ $changeText($insights['vs_avg_seven_qty'] ?? 0) }}</div>
                        <div class="deadstock-kpi-sub">
                            เฉลี่ย {{ number_format((float) ($insights['avg_seven_qty'] ?? 0), 0) }} KG
                            <span class="deadstock-change {{ $changeClass($insights['vs_avg_seven_qty'] ?? 0) }}">
                                {{ $fmtPct($insights['vs_avg_seven_pct'] ?? 0) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">มูลค่าต่อ KG</div>
                        <div class="deadstock-kpi-value">{{ number_format((float) ($insights['value_per_kg'] ?? 0), 2) }}
                        </div>
                        <div class="deadstock-kpi-sub">Baht / KG</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <div class="deadstock-kpi-label">สัดส่วน Sales สูงสุด 3 อันดับ</div>
                        <div class="deadstock-kpi-value">
                            {{ number_format((float) ($insights['top_sales_share'] ?? 0), 1) }}%</div>
                        <div class="deadstock-kpi-sub">สัดส่วนจากยอดรวม</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">แนวโน้ม Qty จาก snapshot ล่าสุด</h5>
                        @if ($trend->isNotEmpty())
                            <div class="deadstock-chart" style="--count: {{ $chartCount }}">
                                @foreach ($trend as $point)
                                    <div class="deadstock-bar"
                                        title="{{ $point['date'] }}: {{ number_format($point['qty'], 0) }}"
                                        style="height: {{ max(3, round(($point['qty'] / $maxTrend) * 230)) }}px"></div>
                                @endforeach
                            </div>
                            <div class="deadstock-chart-labels" style="--count: {{ $chartCount }}">
                                @foreach ($trend as $point)
                                    <span>{{ substr($point['date'], 5) }}</span>
                                @endforeach
                            </div>
                        @else
                            <div class="text-muted">ยังไม่มีข้อมูล trend</div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">Sales ที่มี Qty สูงสุด</h5>
                        @forelse ($topSales as $name => $qty)
                            <div class="deadstock-rank-row">
                                <div class="deadstock-name">{{ $name ?: 'ไม่ระบุ' }}</div>
                                <strong>{{ number_format((float) $qty, 0) }}</strong>
                            </div>
                        @empty
                            <div class="text-muted">ยังไม่มีข้อมูล</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-5">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">สาเหตุหลัก</h5>
                        @forelse ($topReasons as $name => $qty)
                            <div class="deadstock-rank-row">
                                <div class="deadstock-name">{{ $name ?: 'ไม่ระบุ' }}</div>
                                <strong>{{ number_format((float) $qty, 0) }}</strong>
                            </div>
                        @empty
                            <div class="text-muted">ยังไม่มีข้อมูล</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-7">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">Snapshot ย้อนหลัง</h5>
                        <div class="table-responsive deadstock-compact-table">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>วันที่</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">มูลค่า</th>
                                        <th class="text-end">ลูกค้า</th>
                                        <th class="text-end">Part</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($snapshots->take(12) as $snap)
                                        <tr>
                                            <td>{{ $snap['recv_date'] ?? ($snap['as_of'] ?? ($snap['_date'] ?? '')) }}</td>
                                            <td class="text-end">
                                                {{ number_format((float) data_get($snap, 'totals.total_qty', 0), 0) }}</td>
                                            <td class="text-end">
                                                {{ number_format((float) data_get($snap, 'totals.total_value', 0), 2) }}
                                            </td>
                                            <td class="text-end">
                                                {{ number_format((int) data_get($snap, 'totals.customers', 0)) }}</td>
                                            <td class="text-end">
                                                {{ number_format((int) data_get($snap, 'totals.parts', 0)) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-muted">ยังไม่มี snapshot</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="deadstock-section-title">สัดส่วนและรายการที่เพิ่มขึ้น</div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-6">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">สัดส่วนตาม Sales</h5>
                        @forelse ($paretoSales as $row)
                            <div class="mb-3">
                                <div class="d-flex justify-content-between gap-2 small mb-1">
                                    <span class="deadstock-name">{{ $row['name'] ?: 'ไม่ระบุ' }}</span>
                                    <strong>{{ number_format((float) $row['share'], 1) }}%</strong>
                                </div>
                                <div class="deadstock-meter">
                                    <span style="width: {{ min(100, max(0, (float) $row['share'])) }}%"></span>
                                </div>
                                <div class="text-muted small mt-1">{{ number_format((float) $row['qty'], 0) }} KG</div>
                            </div>
                        @empty
                            <div class="text-muted">ยังไม่มีข้อมูล</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">สัดส่วนตามสาเหตุ</h5>
                        @forelse ($paretoReasons as $row)
                            <div class="mb-3">
                                <div class="d-flex justify-content-between gap-2 small mb-1">
                                    <span class="deadstock-name">{{ $row['name'] ?: 'ไม่ระบุ' }}</span>
                                    <strong>{{ number_format((float) $row['share'], 1) }}%</strong>
                                </div>
                                <div class="deadstock-meter">
                                    <span style="width: {{ min(100, max(0, (float) $row['share'])) }}%"></span>
                                </div>
                                <div class="text-muted small mt-1">{{ number_format((float) $row['qty'], 0) }} KG</div>
                            </div>
                        @empty
                            <div class="text-muted">ยังไม่มีข้อมูล</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-4">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">กลุ่มที่ควรจัดการ</h5>
                        @forelse ($actionGroups as $row)
                            <div class="deadstock-rank-row">
                                <div>
                                    <div class="fw-semibold">{{ $row['name'] }}</div>
                                    <div class="text-muted small">{{ $row['hint'] }}</div>
                                </div>
                                <div class="text-end">
                                    <strong>{{ number_format((float) $row['qty'], 0) }}</strong>
                                    <div class="text-muted small">{{ number_format((float) $row['share'], 1) }}%</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted">ยังไม่มีข้อมูล</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">Sales ที่เพิ่มขึ้น</h5>
                        @forelse ($salesMovers as $row)
                            <div class="deadstock-rank-row">
                                <div class="deadstock-name">{{ $row['name'] ?: 'ไม่ระบุ' }}</div>
                                <div class="text-end">
                                    <strong>{{ $changeText($row['change']) }}</strong>
                                    <div class="text-muted small">{{ $fmtPct($row['change_pct']) }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted">ไม่มีรายการที่เพิ่มขึ้นจาก snapshot ก่อนหน้า</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">สาเหตุที่เพิ่มขึ้น</h5>
                        @forelse ($reasonMovers as $row)
                            <div class="deadstock-rank-row">
                                <div class="deadstock-name">{{ $row['name'] ?: 'ไม่ระบุ' }}</div>
                                <div class="text-end">
                                    <strong>{{ $changeText($row['change']) }}</strong>
                                    <div class="text-muted small">{{ $fmtPct($row['change_pct']) }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted">ไม่มีสาเหตุที่เพิ่มขึ้นจาก snapshot ก่อนหน้า</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="deadstock-section-title">ตารางรายละเอียด</div>
        <div class="row g-3">
            <div class="col-12 col-xl-6">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">รายละเอียดตาม Sales</h5>
                        <div class="table-responsive deadstock-table-wrap">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light deadstock-sticky">
                                    <tr>
                                        <th>Sales</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">% รวม</th>
                                        <th class="text-end">เปลี่ยน</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($salesDrilldown as $row)
                                        <tr>
                                            <td>{{ $row['name'] ?: 'ไม่ระบุ' }}</td>
                                            <td class="text-end">{{ number_format((float) $row['qty'], 0) }}</td>
                                            <td class="text-end">{{ number_format((float) $row['share'], 1) }}%</td>
                                            <td class="text-end">
                                                <span class="deadstock-change {{ $changeClass($row['change']) }}">
                                                    {{ $changeText($row['change']) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-muted">ยังไม่มีข้อมูล</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="card deadstock-card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">รายละเอียดตามสาเหตุ</h5>
                        <div class="table-responsive deadstock-table-wrap">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light deadstock-sticky">
                                    <tr>
                                        <th>สาเหตุ</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">% รวม</th>
                                        <th class="text-end">เปลี่ยน</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($reasonDrilldown as $row)
                                        <tr>
                                            <td>{{ $row['name'] ?: 'ไม่ระบุ' }}</td>
                                            <td class="text-end">{{ number_format((float) $row['qty'], 0) }}</td>
                                            <td class="text-end">{{ number_format((float) $row['share'], 1) }}%</td>
                                            <td class="text-end">
                                                <span class="deadstock-change {{ $changeClass($row['change']) }}">
                                                    {{ $changeText($row['change']) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-muted">ยังไม่มีข้อมูล</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
