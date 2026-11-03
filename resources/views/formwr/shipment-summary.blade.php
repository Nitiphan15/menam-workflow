{{-- สรุป Stock & Shipment รายสัปดาห์ ตัวเลขเดียวกับชีต Stock & Shipment Summary ใน Excel --}}
<div class="wr-card mb-3">
    <div class="wr-panel-head">
        <div>
            <h5 class="wr-panel-title">สรุป Stock Wire Rod และกำหนดเข้าโรงงาน</h5>
            <div class="wr-panel-subtitle">ยอดล่าสุด {{ $stockReport['retrieved_at'] }} · กรองตามบริษัท/Item เท่านั้น · Target แสดงเฉพาะเมื่อไม่กรอง Item · สัปดาห์นับเสาร์-ศุกร์ · กำหนดเข้าโรงงานใช้วันที่กำหนดรับใน PO</div>
        </div>
        <span class="wr-badge-soft">หน่วย MT</span>
    </div>

    <div class="wr-panel-body p-3">
        @if ($shipmentSummary)
            <div class="row g-3">
                @foreach ($shipmentSummary as $section)
                    <div class="col-lg-6">
                        <div class="fw-semibold mb-1">{{ $section['title'] }}</div>
                        <div class="table-responsive border rounded">
                            <table class="table table-sm align-middle wr-table">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th class="text-end">Stainless</th>
                                        <th class="text-end">Carbon</th>
                                        <th class="text-end">รวม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['rows'] as $row)
                                        <tr class="{{ $row['total'] ? 'fw-semibold table-warning' : '' }}">
                                            <td class="text-nowrap">{{ $row['label'] }}</td>
                                            @foreach ([$row['values'][0], $row['values'][1], $row['values'][0] + $row['values'][1]] as $value)
                                                <td class="text-end {{ $value < 0 ? 'text-danger' : '' }}">{{ number_format($value, 1) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="wr-empty text-danger">ไม่สามารถคำนวณสรุปนี้ได้ กรุณาแจ้งผู้ดูแลระบบ ตารางอื่นในหน้านี้ยังใช้งานได้ตามปกติ</div>
        @endif
    </div>
</div>
