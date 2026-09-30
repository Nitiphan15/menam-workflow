@php
    $comparisonRows = $comparison['rows'] ?? [];
    $originRows = $comparison['due_origins'] ?? [];
    $comparisonPeriod = $comparison['period'] ?? sprintf('%04d-%02d', $year, $month);
    $varianceClass = fn($value) => (float) $value < 0 ? 'text-danger' : ((float) $value > 0 ? 'text-success' : 'text-muted');
@endphp

<section class="delivery-analysis mb-4" id="delivery-performance">
    <div class="delivery-section-heading d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <div class="delivery-section-eyebrow">DELIVERY PERFORMANCE</div>
            <h4 class="mb-1">Order Due Date vs Actual Delivery</h4>
            <p class="text-muted small mb-0">
                เปรียบเทียบ Order ที่ Due ใน {{ $thaiMonths[$month] ?? $month }} {{ $year }} กับยอดส่งจริงในเดือนเดียวกัน
            </p>
        </div>
        <div class="text-end">
            <div class="small text-muted mb-1">Division ที่เลือก</div>
            <span class="badge bg-secondary delivery-division-badge">{{ implode(', ', $selectedDivisions) }}</span>
        </div>
    </div>

    <div class="delivery-definition-bar mb-3">
        <span><strong>Actual: Same Due</strong> = ส่งเดือนนี้และ Original Due อยู่เดือนนี้</span>
        <span><strong>Actual: Other Due</strong> = ส่งเดือนนี้แต่ Original Due อยู่เดือนอื่น</span>
        <span><strong>แหล่งวันที่:</strong> Actual = predm.transdate · Due = orderitems.reqdate</span>
    </div>

    <div class="row g-3 mb-3">
        @foreach ([['key' => 'qty', 'label' => 'D1-D7/D9', 'unit' => 'Qty'], ['key' => 'baht', 'label' => 'D8', 'unit' => 'Baht']] as $metricGroup)
            @php
                $totals = $comparison['totals'][$metricGroup['key']] ?? [];
                $orderDue = (float) ($totals['order_due'] ?? 0);
                $actualSame = (float) ($totals['actual_same_due_month'] ?? 0);
                $actualOther = (float) ($totals['actual_other_due_month'] ?? 0);
                $actualTotal = (float) ($totals['actual_total'] ?? 0);
                $fulfillment = $orderDue > 0 ? round(($actualSame / $orderDue) * 100, 1) : null;
                $otherMix = $actualTotal > 0 ? round(($actualOther / $actualTotal) * 100, 1) : null;
            @endphp
            <div class="col-12 col-xl-6">
                <div class="card delivery-summary-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">{{ $metricGroup['label'] }}</span>
                        <span class="badge text-bg-light border">{{ $metricGroup['unit'] }}</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 text-center">
                            <div class="col-6 col-lg-3 delivery-kpi delivery-kpi-order">
                                <div class="delivery-kpi-label">Order Due</div>
                                <div class="delivery-kpi-value">{{ $fmt($orderDue) }}</div>
                            </div>
                            <div class="col-6 col-lg-3 delivery-kpi delivery-kpi-same">
                                <div class="delivery-kpi-label">Actual: Same Due</div>
                                <div class="delivery-kpi-value">{{ $fmt($actualSame) }}</div>
                            </div>
                            <div class="col-6 col-lg-3 delivery-kpi delivery-kpi-other">
                                <div class="delivery-kpi-label">Actual: Other Due</div>
                                <div class="delivery-kpi-value">{{ $fmt($actualOther) }}</div>
                            </div>
                            <div class="col-6 col-lg-3 delivery-kpi delivery-kpi-total">
                                <div class="delivery-kpi-label">Actual Total</div>
                                <div class="delivery-kpi-value">{{ $fmt($actualTotal) }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex flex-wrap gap-2">
                        <span class="delivery-stat-chip">ส่งตรง Due {{ $fulfillment === null ? '-' : number_format($fulfillment, 1) . '%' }}</span>
                        <span class="delivery-stat-chip">สัดส่วน Due เดือนอื่น {{ $otherMix === null ? '-' : number_format($otherMix, 1) . '%' }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card delivery-data-card mb-3">
        <div class="card-header delivery-table-toolbar">
            <div>
                <h5 class="mb-1">รายละเอียดตาม Division และ Product</h5>
                <div class="text-muted small">คลิกตัวเลขสีน้ำเงินเพื่อ Drill Down ถึงรายการ Order หรือ Delivery</div>
            </div>
            <div class="input-group input-group-sm delivery-search">
                <span class="input-group-text" aria-hidden="true"><i class="fas fa-search"></i></span>
                <input type="search" class="form-control" id="dueComparisonSearch"
                    placeholder="ค้นหา Division / Product" aria-label="ค้นหา Division หรือ Product">
            </div>
        </div>
        <div class="due-report-table-wrap delivery-comparison-wrap">
            <table class="table table-sm table-bordered table-hover align-middle mb-0 due-comparison-table">
                <caption class="visually-hidden">Order Due Date เทียบ Actual Delivery แยกตาม Division และ Product</caption>
                <thead class="table-light">
                    <tr class="text-center">
                        <th scope="col">Division</th>
                        <th scope="col">Product</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Order Due</th>
                        <th scope="col">Actual: Same Due</th>
                        <th scope="col">Due Variance</th>
                        <th scope="col">Actual: Other Due</th>
                        <th scope="col">Actual Total</th>
                        <th scope="col">Monthly Variance</th>
                    </tr>
                </thead>
                <tbody id="dueComparisonBody">
                    @forelse ($comparisonRows as $row)
                        @php
                            $detailBase = ['year' => $year, 'month' => $month, 'mode' => 'actual', 'group_code' => $row['group_code'], 'type_name' => $row['product_type']];
                        @endphp
                        <tr data-comparison-row data-search="{{ strtolower($row['group_code'] . ' ' . $row['product_type'] . ' ' . $row['metric_unit']) }}">
                            <th scope="row" class="fw-semibold">{{ $row['group_code'] }}</th>
                            <td>{{ $row['product_type'] }}</td>
                            <td class="text-center"><span class="badge text-bg-light border">{{ $row['metric_unit'] }}</span></td>
                            <td class="text-end">
                                @if ((float) $row['order_due'] !== 0.0)
                                    <a href="{{ route('wos.order_due_date.detail', ['year' => $year, 'month' => $month, 'group_code' => $row['group_code'], 'type_name' => $row['product_type']]) }}">{{ $fmt($row['order_due']) }}</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ((float) $row['actual_same_due_month'] !== 0.0)
                                    <a href="{{ route('wos.order_due_date.detail', array_merge($detailBase, ['due_period' => 'SAME_DUE_MONTH'])) }}">{{ $fmt($row['actual_same_due_month']) }}</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold {{ $varianceClass($row['due_performance_variance']) }}">{{ $fmt($row['due_performance_variance']) }}</td>
                            <td class="text-end">
                                @if ((float) $row['actual_other_due_month'] !== 0.0)
                                    <a href="{{ route('wos.order_due_date.detail', array_merge($detailBase, ['due_period' => 'OTHER_DUE_MONTH'])) }}">{{ $fmt($row['actual_other_due_month']) }}</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">
                                @if ((float) $row['actual_total'] !== 0.0)
                                    <a href="{{ route('wos.order_due_date.detail', $detailBase) }}">{{ $fmt($row['actual_total']) }}</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold {{ $varianceClass($row['monthly_variance']) }}">{{ $fmt($row['monthly_variance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No data</td></tr>
                    @endforelse
                    <tr id="dueComparisonNoMatch" class="d-none">
                        <td colspan="9" class="text-center text-muted py-4">ไม่พบ Division หรือ Product ที่ค้นหา</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card delivery-data-card">
        <div class="card-header">
            <h5 class="mb-1">Actual Delivery มาจาก Original Due Date เดือนไหน</h5>
            <div class="text-muted small">ใช้ตรวจสอบว่ายอดส่งจริงเดือนนี้มาจาก Order Due เดือนใดบ้าง</div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle mb-0 delivery-origin-table">
                <caption class="visually-hidden">Actual Delivery แยกตาม Original Due Month</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Original Due Month</th>
                        <th scope="col" class="text-end">Actual Qty</th>
                        <th scope="col" class="text-end">D8 Actual Baht</th>
                        <th scope="col" class="text-end">Shipment Lines</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($originRows as $origin)
                        <tr class="{{ $origin['due_period'] === $comparisonPeriod ? 'delivery-origin-current' : '' }}">
                            <th scope="row">
                                <a href="{{ route('wos.order_due_date.detail', ['year' => $year, 'month' => $month, 'mode' => 'actual', 'due_period' => $origin['due_period'], 'divisions' => $selectedDivisions]) }}">{{ $origin['due_label'] }}</a>
                                @if ($origin['due_period'] === $comparisonPeriod)
                                    <span class="badge bg-primary ms-2">Due เดือนที่เลือก</span>
                                @endif
                            </th>
                            <td class="text-end">{{ $fmt($origin['qty_actual']) }}</td>
                            <td class="text-end">{{ $fmt($origin['d8_baht']) }}</td>
                            <td class="text-end">{{ number_format($origin['line_count']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No actual delivery</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
