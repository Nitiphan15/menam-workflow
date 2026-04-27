@php
    // เกณฑ์คิดคะแนน (เต็ม 10) ตามช่วงจำนวนวัน
    // 0–3 => 10, >3–5 => 8.5, >5–10 => 7.5, >10–15 => 5, >15 => 0
    $attnSteps = [
        ['max' => 3, 'score' => 10.0],
        ['max' => 5, 'score' => 8.5],
        ['max' => 10, 'score' => 7.5],
        ['max' => 15, 'score' => 5.0],
        ['max' => 999, 'score' => 0.0],
    ];

    $num = fn($k) => old($k, isset($hr->$k) ? 0 + $hr->$k : 0); // เป็นตัวเลขแน่ ๆ
    $int = fn($k) => old($k, isset($hr->$k) ? (int) $hr->$k : 0);
@endphp

<div class="card mb-3">
    <div class="card-header">
        <strong>PART B (HR)</strong> — คะแนนเต็มรวม <b>20</b>
    </div>
    <div class="card-body">

        {{-- 1) สถิติการมาปฏิบัติงาน/ลา/ขาด/สาย (เต็ม 10) --}}
        <div class="mb-3">
            <h6 class="mb-2">1) สถิติการมาปฏิบัติงาน/ลา/ขาด/สาย <span class="text-muted">(เต็ม 10)</span></h6>

            <div class="row g-2 align-items-end">
                <div class="col-sm-3">
                    <label class="form-label">สาย (ชั่วโมง)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input" name="late_hours"
                        value="{{ $num('late_hours') }}">

                </div>
                <div class="col-sm-3">
                    <label class="form-label">ขาดงาน (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="absent_days" value="{{ $num('absent_days') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">ลาป่วย (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input" name="sick_days"
                        value="{{ $num('sick_days') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">ลากิจ (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="bizleave_days" value="{{ $num('bizleave_days') }}">
                </div>

                <div class="col-sm-3">
                    <label class="form-label">ลาราชการทหาร (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="military_days" value="{{ $num('military_days') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">ลาคลอด (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="maternity_days" value="{{ $num('maternity_days') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">ลาอุปสมบท (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="ordination_days" value="{{ $num('ordination_days') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">ลาพักผ่อนประจำปี (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="annual_days" value="{{ $num('annual_days') }}">
                </div>

                <div class="col-sm-3">
                    <label class="form-label">ลาอื่น ๆ (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="other_days" value="{{ $num('other_days') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">ลากินสิทธิ์ (วัน)</label>
                    <input type="number" step="0.1" min="0" class="form-control attn-input"
                        name="unpaid_days" value="{{ $num('unpaid_days') }}">
                </div>
            </div>

            <div class="small text-muted mt-2">
                8 ชั่วโมง = 1 วัน | เกณฑ์: 0–3 = 10, &gt;3–5 = 8.5, &gt;5–10 = 7.5, &gt;10–15 = 5, &gt;15 = 0
            </div>

            <div class="mt-2 d-flex justify-content-between">
                <div>
                    รวมวันทั้งปี: <b id="attn_total_days">0.0</b> วัน
                </div>
                <div>
                    ได้ <b id="attn_score">10.0</b> / <b>10</b>
                </div>
            </div>
        </div>

        {{-- 2) วินัย/ความประพฤติ/อุบัติการณ์ (เต็ม 10) --}}
        <div class="mb-3">
            <h6 class="mb-2">2) วินัย/ความประพฤติ/อุบัติการณ์ <span class="text-muted">(เต็ม 10)</span></h6>
            <div class="row g-2">
                <div class="col-sm-3">
                    <label class="form-label">หนังสือเตือน (ฉบับ) รวมปี</label>
                    <input type="number" min="0" step="1" class="form-control disc-input"
                        name="warnings" value="{{ $int('warnings') }}">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">พักงาน (ครั้ง) รวมปี</label>
                    <input type="number" min="0" step="1" class="form-control disc-input"
                        name="suspends" value="{{ $int('suspends') }}">
                </div>
            </div>
            <div class="small text-muted mt-2">
                เกณฑ์ตัดคะแนน: หนังสือเตือน 1 ฉบับหัก 5 | พักงาน 1 ครั้งหัก 10 | คะแนนขั้นต่ำ 0 สูงสุด 10
            </div>
            <div class="mt-2 d-flex justify-content-between">
                <span>คะแนนวินัย: <b id="disc_score">10.0</b> / <b>10</b></span>
            </div>
        </div>

        <hr>
        <div class="fs-6">
            รวม PART B: <b id="partB_total">0.0</b> / <b>20</b>
        </div>

        {{-- น้ำหนักรวม ใช้ 80/20 เป็นค่าเริ่มต้น --}}
        <input type="hidden" name="weight_hr" value="20">
    </div>
</div>

@push('scripts')
    <script>
        (function() {
            const getVal = sel => {
                const el = document.querySelector(sel);
                const v = el ? Number(el.value || 0) : 0;
                return isNaN(v) ? 0 : v;
            };

            function calcAttendanceScore(days) {
                // 0–3 => 10, >3–5 => 8.5, >5–10 => 7.5, >10–15 => 5, >15 => 0
                if (days <= 3) return 10.0;
                if (days <= 5) return 8.5;
                if (days <= 10) return 7.5;
                if (days <= 15) return 5.0;
                return 0.0;
            }

            function recalc() {
                // 1) รวมวันจากประเภทลา + สาย (8 ชม. = 1 วัน)
                const lateHours = getVal('input[name="late_hours"]');
                const daysFromLate = lateHours / 8.0;

                const days =
                    getVal('input[name="absent_days"]') +
                    getVal('input[name="sick_days"]') +
                    getVal('input[name="bizleave_days"]') +
                    getVal('input[name="military_days"]') +
                    getVal('input[name="maternity_days"]') +
                    getVal('input[name="ordination_days"]') +
                    getVal('input[name="annual_days"]') +
                    getVal('input[name="other_days"]') +
                    getVal('input[name="unpaid_days"]') +
                    daysFromLate;

                const attnScore = calcAttendanceScore(days);

                // 2) วินัย: เตือน x5, พักงาน x10 (ขั้นต่ำ 0 สูงสุด 10)
                const warn = getVal('input[name="warnings"]');
                const susp = getVal('input[name="suspends"]');
                let discScore = 10 - (warn * 5) - (susp * 10);
                discScore = Math.max(0, Math.min(10, discScore));

                // รวม PART B
                const partB = attnScore + discScore;

                // แสดงผล
                document.getElementById('attn_total_days').textContent = days.toFixed(1);
                document.getElementById('attn_score').textContent = attnScore.toFixed(1);
                document.getElementById('disc_score').textContent = discScore.toFixed(1);
                document.getElementById('partB_total').textContent = partB.toFixed(1);
            }

            // bind
            document.addEventListener('input', e => {
                if (e.target.classList.contains('attn-input') || e.target.classList.contains('disc-input')) {
                    recalc();
                }
            });
            window.addEventListener('DOMContentLoaded', recalc);
        })();
    </script>
@endpush
