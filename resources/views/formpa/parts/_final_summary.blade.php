@php
    $showPartB = $showPartB ?? false;
    $partWeights = $partWeights ?? ['A' => 80, 'B' => 20];
@endphp

<div class="row g-3 my-3">
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><strong>สรุปผลถ่วงน้ำหนัก</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <span>ร้อยละ PART A</span>
                    <span id="final_A_percent_txt" class="fw-bold">-</span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>น้ำหนัก PART A</span>
                    <span>{{ $partWeights['A'] }}%</span>
                </div>

                @if ($showPartB)
                    <hr class="my-2">
                    <div class="d-flex justify-content-between">
                        <span>ร้อยละ PART B</span>
                        <span id="final_B_percent_txt" class="fw-bold">-</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>น้ำหนัก PART B</span>
                        <span>{{ $partWeights['B'] }}%</span>
                    </div>
                @endif

                <hr>
                <div class="d-flex justify-content-between">
                    <span>คะแนนถ่วงน้ำหนักรวม</span>
                    <span id="final_weighted_txt" class="fw-bold">-</span>
                </div>

                {{-- เผื่อบันทึกฝั่ง server --}}
                <input type="hidden" name="calc[final][A_percent]" id="final_A_percent">
                <input type="hidden" name="calc[final][B_percent]" id="final_B_percent">
                <input type="hidden" name="calc[final][weighted]" id="final_weighted">
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (function() {
            const fmt1 = n => (Math.round(n * 10) / 10).toFixed(1);
            const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
            const snap05 = v => Math.round(v * 2) / 2;

            // คำนวณ PART A – per section
            function recalcSection(secId) {
                let sum = 0,
                    sumW = 0;
                document.querySelectorAll(`tr[data-sec="${secId}"]`).forEach(tr => {
                    const w = Number(tr.dataset.w || 0);
                    const el = tr.querySelector('.score-input');
                    let val = (el && el.value !== '') ? Number(el.value) : 0;
                    if (val) val = snap05(clamp(val, 1, 5));
                    tr.querySelector('.row-total').textContent = fmt1(w * (val || 0));
                    if (val) {
                        sum += w * val;
                        sumW += w;
                    }
                });

                const card = document.getElementById(secId);
                const full = Number(card?.dataset.full || 0);
                const totalEl = document.querySelector(`.sec-total[data-for="${secId}"]`);
                const percentEl = document.querySelector(`.sec-percent[data-for="${secId}"]`);
                if (totalEl) totalEl.textContent = fmt1(sum);
                if (percentEl) percentEl.textContent = full ? fmt1((sum / full) * 100) : '0.0';
            }

            function recalcPartA() {
                let total = 0,
                    full = 0;
                document.querySelectorAll('[id^="sec"]').forEach(card => {
                    recalcSection(card.id);
                    const secTotal = Number(document.querySelector(`.sec-total[data-for="${card.id}"]`)
                        ?.textContent || 0);
                    total += secTotal;
                    full += Number(card.dataset.full || 0);
                });

                const percent = full ? (total / full * 100) : 0;

                // แสดงผล
                document.getElementById('partA_total_txt')?.replaceChildren(document.createTextNode(fmt1(total)));
                document.getElementById('partA_full_txt')?.replaceChildren(document.createTextNode(fmt1(full)));
                document.getElementById('partA_percent_txt')?.replaceChildren(document.createTextNode(fmt1(percent)));

                // hidden (ใช้ .value ไม่ใช่ setAttribute)
                const A_total = document.getElementById('partA_total');
                const A_full = document.getElementById('partA_full');
                const A_percent = document.getElementById('partA_percent');
                if (A_total) A_total.value = total;
                if (A_full) A_full.value = full;
                if (A_percent) A_percent.value = percent;

                const FA = document.getElementById('final_A_percent');
                if (FA) FA.value = percent;
                document.getElementById('final_A_percent_txt')?.replaceChildren(document.createTextNode(fmt1(percent)));

                recalcFinal();
            }

            // HR – PART B
            function recalcPartB() {
                let total = 0;
                const full = Number(document.getElementById('partB_full')?.value || 0);

                document.querySelectorAll('.hr-score').forEach(inp => {
                    let v = inp.value === '' ? 0 : Number(inp.value);
                    const mx = Number(inp.dataset.max || 0);
                    v = Math.min(mx, Math.max(0, snap05(v))); // 0..max step .5
                    inp.value = v ? fmt1(v) : '';
                    total += v;
                });

                const percent = full ? (total / full * 100) : 0;

                document.getElementById('partB_total_txt')?.replaceChildren(document.createTextNode(fmt1(total)));
                document.getElementById('partB_percent_txt')?.replaceChildren(document.createTextNode(fmt1(percent)));

                const B_total = document.getElementById('partB_total');
                const B_percent = document.getElementById('partB_percent');
                if (B_total) B_total.value = total;
                if (B_percent) B_percent.value = percent;

                const FB = document.getElementById('final_B_percent');
                if (FB) FB.value = percent;
                document.getElementById('final_B_percent_txt')?.replaceChildren(document.createTextNode(fmt1(percent)));

                recalcFinal();
            }

            // Final weighted
            function recalcFinal() {
                const A = Number(document.getElementById('final_A_percent')?.value || 0);
                const B = Number(document.getElementById('final_B_percent')?.value || 0);
                const wA = {{ (int) ($partWeights['A'] ?? 80) }};
                const wB = {{ (int) ($partWeights['B'] ?? 20) }};

                const finalPct = (A * wA + B * wB) / 100;

                const FW = document.getElementById('final_weighted');
                if (FW) FW.value = finalPct;
                document.getElementById('final_weighted_txt')?.replaceChildren(document.createTextNode(fmt1(finalPct)));
            }

            // Events
            document.addEventListener('change', e => {
                if (e.target.classList.contains('score-input')) {
                    if (e.target.value !== '') {
                        let n = snap05(clamp(Number(e.target.value), 1, 5));
                        e.target.value = fmt1(n);
                    }
                    const tr = e.target.closest('tr');
                    recalcSection(tr.getAttribute('data-sec'));
                    recalcPartA();
                }
                if (e.target.classList.contains('hr-score')) recalcPartB();
            });

            window.addEventListener('DOMContentLoaded', () => {
                recalcPartA();
                recalcPartB();
            });
        })();
    </script>
@endpush
