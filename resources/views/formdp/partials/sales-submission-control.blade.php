@php
    $controlDate = $salesSubmissionStatusDate ?? now()->toDateString();
    $controlLocked = (bool) ($salesSubmissionLocked ?? false);
    $lockedDates = $lockedSalesSubmissionDates ?? [];
    $controlDateText = \Carbon\Carbon::parse($controlDate)->format('d/m/Y');
@endphp

<div class="d-flex flex-column gap-1">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge {{ $controlLocked ? 'text-bg-danger' : 'text-bg-success' }} px-3 py-2">
            <i class="fas {{ $controlLocked ? 'fa-lock' : 'fa-lock-open' }} me-1"></i>
            {{ $controlLocked ? 'ล็อกอยู่' : 'เปิดให้ Sales Submit' }}: {{ $controlDateText }}
        </span>

        @if ($canDpa ?? false)
            <button type="button"
                class="btn btn-sm {{ $controlLocked ? 'btn-outline-success' : 'btn-outline-danger' }}"
                data-bs-toggle="modal" data-bs-target="#salesSubmissionControlModal">
                <i class="fas {{ $controlLocked ? 'fa-lock-open' : 'fa-lock' }} me-1"></i>
                {{ $controlLocked ? 'ปลดล็อกให้ Sales' : 'ล็อก Sales Submit คืน' }}
            </button>
        @endif
    </div>

    <div class="small text-muted">
        <span class="fw-semibold">วันที่กำลังล็อก (วันนี้เป็นต้นไป):</span>
        @forelse ($lockedDates as $lockedDate)
            <span class="badge bg-light text-danger border ms-1">{{ \Carbon\Carbon::parse($lockedDate)->format('d/m/Y') }}</span>
        @empty
            <span>ไม่มี</span>
        @endforelse
    </div>
</div>

@if ($canDpa ?? false)
    <div class="modal fade" id="salesSubmissionControlModal" tabindex="-1"
        aria-labelledby="salesSubmissionControlModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST"
                action="{{ $controlLocked ? route('dp.inquiry.unlock-sales-submission') : route('dp.inquiry.lock-sales-submission') }}"
                class="modal-content">
                @csrf
                <input type="hidden" name="ship_date" value="{{ $controlDate }}">

                <div class="modal-header">
                    <h5 class="modal-title" id="salesSubmissionControlModalLabel">
                        {{ $controlLocked ? 'ปลดล็อกให้ Sales' : 'ล็อก Sales Submit คืน' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert {{ $controlLocked ? 'alert-warning' : 'alert-danger' }} py-2">
                        วันที่ส่งสินค้า <strong>{{ $controlDateText }}</strong>
                    </div>

                    <label for="salesSubmissionControlRemark" class="form-label fw-semibold">
                        {{ $controlLocked ? 'เหตุผลที่อนุญาตให้ Sales แก้ไข' : 'หมายเหตุการล็อกคืน' }}
                        @if ($controlLocked)
                            <span class="text-danger">*</span>
                        @endif
                    </label>
                    <textarea id="salesSubmissionControlRemark" name="remark" class="form-control" rows="3"
                        maxlength="500" {{ $controlLocked ? 'required' : '' }}
                        placeholder="{{ $controlLocked ? 'เช่น Sales ขอเพิ่ม SO หลังส่งแผนจัดรถ' : 'ระบุเหตุผลเพิ่มเติม (ถ้ามี)' }}">{{ old('remark') }}</textarea>
                    @error('remark', 'salesSubmissionControl')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                    <div class="form-text">ระบบจะบันทึกข้อความ ผู้ดำเนินการ และเวลาไว้ในประวัติ</div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn {{ $controlLocked ? 'btn-success' : 'btn-danger' }}">
                        <i class="fas {{ $controlLocked ? 'fa-lock-open' : 'fa-lock' }} me-1"></i>
                        ยืนยัน{{ $controlLocked ? 'ปลดล็อก' : 'ล็อกคืน' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // modal ถูก include อยู่ใน .top-bar (position: sticky + z-index + backdrop-filter)
        // ทำให้ backdrop ของ Bootstrap ทับตัว modal → หน้าจอค้างกดอะไรไม่ได้
        // จึงย้าย modal ไปไว้ใต้ <body> ก่อนเปิด
        (function() {
            function moveSalesSubmissionModalToBody() {
                var modalElement = document.getElementById('salesSubmissionControlModal');
                if (modalElement && modalElement.parentElement !== document.body) {
                    document.body.appendChild(modalElement);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', moveSalesSubmissionModalToBody);
            } else {
                moveSalesSubmissionModalToBody();
            }
        })();
    </script>

    @if ($errors->salesSubmissionControl->any())
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var modalElement = document.getElementById('salesSubmissionControlModal');
                if (modalElement && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            });
        </script>
    @endif
@endif
