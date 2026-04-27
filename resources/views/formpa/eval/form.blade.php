{{-- resources/views/formpa/eval/form.blade.php --}}
@extends('layouts.layout')

@section('title', 'Evaluate')
@section('page-title', 'PA Online (Accounting)')

@section('content')
    @php
        // น้ำหนักรวมของแต่ละภาค (ปรับได้)
        $partWeights = $partWeights ?? ['A' => 80, 'B' => 20];
        $editable = $editable ?? true; // หัวหน้างานแก้ได้
    @endphp

    <form method="post" action="{{ route('pa.store', $employee->id) }}" id="eval-form" class="container-fluid px-4 pa-form">
        @csrf

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">ประเมินพนักงาน</h4>
            <div class="text-end small">
                <div class="fw-semibold">{{ $employee->name }}</div>
                <div class="text-muted">{{ $employee->email }}</div>
                <div class="text-muted">แผนก: {{ $employee->department->name ?? '-' }}</div>
            </div>
        </div>

        <div class="row flex-xl-nowrap align-items-start align-items-xl-stretch gx-0">
            {{-- ซ้าย: เนื้อหาแบบประเมิน (ขยาย) --}}
            <div class="col-12 col-xl" style="min-width:0">

                {{-- PART A: หัวข้อย่อย --}}
                @include('formpa.parts._partA', ['editable' => $editable])

                {{-- สรุป Part A --}}
                @include('formpa.parts._partA_summary')

                {{-- สรุปรวมถ่วงน้ำหนัก (หน้านี้ใช้เฉพาะ Part A) --}}
                @include('formpa.parts._final_summary', [
                    'showPartB' => false,
                    'partWeights' => $partWeights,
                ])

                <div class="text-end my-3 d-xxl-none">
                    <button type="submit" name="save_as" value="draft"
                        class="btn btn-outline-secondary">บันทึกแบบร่าง</button>
                    <button type="submit" class="btn btn-primary">บันทึกคะแนน</button>
                </div>
            </div>

            {{-- ขวา: สรุปแบบ sticky + สารบัญหมวด (โชว์บนจอใหญ่) --}}
            <aside class="col-12 col-xl-auto pa-aside">
                <div class="sticky-top pa-right">
                    <div class="card mb-3">
                        <div class="card-header"><strong>สรุปคะแนน</strong></div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-1">
                                <span>คะแนนถ่วงน้ำหนักรวม</span>
                                <span id="totalScore" class="fw-bold">0.0</span>
                            </div>
                            <div class="text-muted small">คิดจาก Σ(คะแนน × น้ำหนัก) / Σน้ำหนัก</div>
                            <hr class="my-2">
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small">
                                    <span>ความคืบหน้า</span>
                                    <span><span id="filledCount">0</span>/<span id="totalCount">0</span> ข้อ</span>
                                </div>
                                <div class="progress" style="height:8px">
                                    <div class="progress-bar" id="fillProgress" style="width:0%"></div>
                                </div>
                            </div>
                            <div id="unsavedHint" class="text-warning small d-none">มีการแก้ไขที่ยังไม่บันทึก…</div>
                        </div>
                        <div class="card-footer d-flex gap-2">
                            <button type="submit" name="save_as" value="draft"
                                class="btn btn-outline-secondary w-50">บันทึกแบบร่าง</button>
                            <button type="submit" class="btn btn-primary w-50">บันทึกคะแนน</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><strong>ไปยังหมวด</strong></div>
                        <ul class="list-group list-group-flush small" id="sectionIndex"><!-- เติมจาก JS --></ul>
                    </div>
                </div>
            </aside>
        </div>

        {{-- quick-pick 0.5 step (ใช้ร่วมกับ input[number]) --}}
        <datalist id="score-steps">
            <option value="1">
            <option value="1.5">
            <option value="2">
            <option value="2.5">
            <option value="3">
            <option value="3.5">
            <option value="4">
            <option value="4.5">
            <option value="5">
        </datalist>
    </form>

    {{-- Scripts: สรุป/ความคืบหน้ากรอก/สารบัญแบบ sticky --}}
    @push('scripts')
        <script>
            (function() {
                const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
                const snap05 = v => Math.round(v * 2) / 2;
                const fmt1 = n => (Math.round(n * 10) / 10).toFixed(1);

                // ========== สร้างสารบัญจาก card ของแต่ละหมวด ==========
                function buildSectionIndex() {
                    const list = document.getElementById('sectionIndex');
                    if (!list) return;
                    list.innerHTML = '';

                    document.querySelectorAll('[id^="sec"]').forEach((card) => {
                        const id = card.id;
                        if (id === 'sectionIndex') return; // กันติดตัวเอง

                        const title = card.querySelector('.card-header .fw-semibold')?.textContent?.trim() || id;
                        const li = document.createElement('li');
                        li.className = 'list-group-item d-flex justify-content-between align-items-center';
                        li.innerHTML = `
                        <a class="text-decoration-none" href="#${id}">${title}</a>
                        <span class="badge bg-light text-dark sec-total-badge" data-for="${id}">0.0</span>`;
                        list.appendChild(li);
                    });
                }

                // ========== คำนวณรวมหน้า/อัปเดตความคืบหน้า ==========
                function recalcAll() {
                    let sumWX = 0,
                        sumW = 0,
                        filled = 0,
                        total = 0;

                    document.querySelectorAll('[id^="sec"]').forEach(card => {
                        let secSum = 0,
                            secW = 0;
                        const secId = card.id;

                        card.querySelectorAll(`tr[data-sec="${secId}"]`).forEach(tr => {
                            total++;
                            const w = Number(tr.dataset.w || 0);
                            const inp = tr.querySelector('.score-input');
                            let v = inp && inp.value !== '' ? Number(inp.value) : '';
                            if (v !== '') {
                                v = snap05(clamp(v, 1, 5));
                                filled++;
                                secSum += w * v;
                                secW += w;

                                // แสดงรวมต่อข้อถ้ามี
                                const rowSum = tr.querySelector('.row-total');
                                if (rowSum) rowSum.textContent = fmt1(w * v);
                            } else {
                                const rowSum = tr.querySelector('.row-total');
                                if (rowSum) rowSum.textContent = '0.0';
                            }
                        });

                        const secTotal = card.querySelector(`.sec-total[data-for="${secId}"]`);
                        if (secTotal) secTotal.textContent = fmt1(secSum);

                        const badge = document.querySelector(`.sec-total-badge[data-for="${secId}"]`);
                        if (badge) badge.textContent = fmt1(secSum);

                        sumWX += secSum;
                        sumW += secW;

                        // อัปเดต % ของหมวดถ้ามี element
                        const percentEl = card.querySelector(`.sec-percent[data-for="${secId}"]`);
                        if (percentEl) {
                            const full = secW * 5;
                            percentEl.textContent = full ? (secSum / full * 100).toFixed(1) : '0.0';
                        }
                    });

                    const totalScoreEl = document.getElementById('totalScore');
                    totalScoreEl && (totalScoreEl.textContent = fmt1(sumW ? (sumWX / sumW) : 0));

                    // ความคืบหน้า
                    const pc = total ? Math.round(filled / total * 100) : 0;
                    const bar = document.getElementById('fillProgress');
                    if (bar) bar.style.width = pc + '%';
                    const t = document.getElementById('totalCount');
                    if (t) t.textContent = total;
                    const f = document.getElementById('filledCount');
                    if (f) f.textContent = filled;
                }

                // ========== snap + hint unsaved ==========
                let dirty = false;
                const unsaved = document.getElementById('unsavedHint');
                document.addEventListener('change', (e) => {
                    if (!e.target.classList.contains('score-input')) return;
                    if (e.target.value !== '') {
                        let n = snap05(clamp(Number(e.target.value), 1, 5));
                        e.target.value = fmt1(n);
                    }
                    dirty = true;
                    unsaved && unsaved.classList.toggle('d-none', !dirty);
                    recalcAll();
                });

                window.addEventListener('beforeunload', (e) => {
                    if (!dirty) return;
                    e.preventDefault();
                    e.returnValue = '';
                });


                // init
                window.addEventListener('DOMContentLoaded', () => {
                    buildSectionIndex();
                    recalcAll();
                });
            })();
        </script>
    @endpush

    {{-- สไตล์ย่อย --}}
    <style>
        :root {
            --pa-sticky-top: 60px;
            /* ระยะ offset บนสำหรับ sticky */
            --pa-aside-w: 260px;
            /* ความกว้างกล่องขวา */
        }

        /* กล่องสรุปขวาให้ sticky แน่นอน */
        .pa-right {
            position: sticky;
            top: var(--pa-sticky-top) !important;
            width: var(--pa-aside-w);
            max-width: 100%;
        }

        /* layout หลัก: ซ้ายยืด ขวาคงที่ */
        @media (min-width:1200px) {
            .pa-form .col-xl {
                flex: 1 1 auto !important;
                max-width: none !important;
                min-width: 0;
            }

            .pa-form .pa-aside {
                flex: 0 0 var(--pa-aside-w) !important;
                max-width: var(--pa-aside-w) !important;
            }

            /* ถ้า row มี align-items-start ให้ยืดใหม่ตอน xl ขึ้นไปเพื่อช่วย sticky */
            .pa-form .row.flex-xl-nowrap {
                align-items: stretch !important;
            }
        }

        /* ถ้าจอกว้างพอดี ๆ แล้วเริ่มคับ ให้หด aside ลงอัตโนมัติ (เลือกใช้ได้) */
        @media (min-width:1200px) and (max-width:1340px) {
            :root {
                --pa-aside-w: 250px;
            }
        }

        /* กัน ancestor ไปตัด sticky */
        .pa-form,
        .pa-form .row,
        .pa-form [class*="col-"] {
            overflow: visible !important;
        }

        /* กัน partial ข้างในไปบีบความกว้างตัวเอง */
        .pa-form .pa-left .card,
        .pa-form .pa-left .table-responsive {
            max-width: none !important;
            width: 100% !important;
        }

        .pa-form .pa-left .container,
        .pa-form .pa-left .container-fluid {
            max-width: none !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .pa-form .pa-left,
        .pa-form .pa-left * {
            min-width: 0;
        }

        /* ลดความหลวมในตาราง */
        .pa-form .table>:not(caption)>*>* {
            padding: .4rem .5rem;
            vertical-align: middle;
        }

        .pa-form table .form-control,
        .pa-form table .btn {
            padding: .25rem .5rem;
            height: 32px;
            font-size: .875rem;
        }

        /* ความกว้างคอลัมน์ (ชุดเดียวพอ) */
        .pa-form .col-wt {
            width: 72px;
        }

        /* น้ำหนัก */
        .pa-form .col-full {
            width: 72px;
        }

        /* เต็ม */
        .pa-form .col-rate {
            width: 84px;
        }

        /* ช่อง 1–5 */
        .pa-form .col-rowt {
            width: 80px;
        }

        /* รวมข้อ */
        .pa-form .col-note {
            min-width: 170px;
        }

        /* หมายเหตุ */
    </style>
@endsection
