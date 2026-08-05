@php
    $weightToleranceContent = <<<'HTML'
<div class="small">
    <div class="fw-semibold mb-2">น้ำหนักงาน / งานค้างที่ยอมรับ</div>
    <table class="table table-sm table-bordered mb-1 align-middle pst-weight-tolerance-table">
        <tbody>
            <tr><td>5 - 99 กก.</td><td class="text-end">10%</td></tr>
            <tr><td>100 - 499 กก.</td><td class="text-end">5%</td></tr>
            <tr><td>500 - 999 กก.</td><td class="text-end">4%</td></tr>
            <tr><td>1,000 - 4,999 กก.</td><td class="text-end">3%</td></tr>
            <tr><td>5,000 - 9,999 กก.</td><td class="text-end">2.50%</td></tr>
            <tr><td>10,000 - 19,999 กก.</td><td class="text-end">2%</td></tr>
            <tr><td>ตั้งแต่ 20,000 กก.</td><td class="text-end">1.50%</td></tr>
        </tbody>
    </table>
    <div class="text-muted">ขั้นตอนถือว่า Completed เมื่อน้ำหนักที่รับไม่น้อยกว่าน้ำหนักงานหลังหักค่ายอมรับ</div>
</div>
HTML;
@endphp

<button type="button" class="pst-weight-tolerance-help" data-pst-weight-tolerance
    data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="auto" data-bs-html="true"
    data-bs-custom-class="pst-weight-tolerance-popover"
    data-bs-title="เกณฑ์น้ำหนัก Form Tracking" data-bs-content="{{ $weightToleranceContent }}"
    aria-label="ดูเกณฑ์ช่วงน้ำหนัก" title="ดูเกณฑ์ช่วงน้ำหนัก">?</button>

@once
    <style>
        .pst-weight-tolerance-help {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            padding: 0;
            border: 1px solid #2563eb;
            border-radius: 50%;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            cursor: help;
            vertical-align: middle;
        }

        .pst-weight-tolerance-help:hover,
        .pst-weight-tolerance-help:focus {
            background: #2563eb;
            color: #fff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .18);
        }

        .pst-weight-tolerance-popover {
            max-width: 360px;
        }
    </style>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-pst-weight-tolerance]').forEach(function (element) {
                    bootstrap.Popover.getOrCreateInstance(element, {
                        container: 'body'
                    });
                });
            });
        </script>
    @endpush
@endonce
