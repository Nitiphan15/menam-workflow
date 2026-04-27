@php
    $editable = $editable ?? true;

@endphp

<div class="row g-3 pa-form">
    <div class="col-12 col-xl-8">
        @foreach ($sections as $s)
            @php
                $sid = 'sec' . $s->id;
                $sumW = (float) $s->questions->sum('weight');
                $full = $sumW * 5; // คะแนนเต็มของหมวด
            @endphp

            <div id="{{ $sid }}" class="card shadow-sm border-0 mb-4" data-sumw="{{ $sumW }}"
                data-full="{{ $full }}">
                <div class="card-header bg-white border-0 py-3 d-flex align-items-center justify-content-between">
                    <div class="fw-semibold">
                        <span
                            class="badge rounded-pill bg-primary-subtle text-primary me-2">{{ $loop->iteration }}</span>
                        {{ $s->name }}
                    </div>
                    <small class="text-muted">
                        น้ำหนักรวม: <span class="fw-semibold">{{ number_format($sumW, 1) }}</span> |
                        คะแนนเต็มหมวด: <span class="fw-semibold">{{ number_format($full, 1) }}</span>
                    </small>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light position-sticky top-0" style="z-index:5">
                            <tr class="text-center">
                                <th style="width:60px">ลำดับ</th>
                                <th class="th-topic">หัวข้อย่อย</th>
                                <th class="text-center col-wt">น้ำหนัก</th>
                                <th class="text-center col-full">เต็ม</th>
                                <th class="text-center col-rate">ประเมิน (1–5)</th>
                                <th class="text-end col-rowt">รวมข้อ</th>
                                <th class="text-start col-note">หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($s->questions as $q)
                                @php
                                    $oldScore = $scores[$q->id]['score'] ?? null;
                                    $oldRemark = $scores[$q->id]['comment'] ?? '';
                                @endphp
                                <tr data-sec="{{ $sid }}" data-w="{{ $q->weight }}">

                                    <td class="text-center">{{ $loop->iteration }}</td>
                                    <td class="td-topic">{{ $q->text }}</td>
                                    <td class="text-center">
                                        <span
                                            class="badge rounded-pill bg-light text-dark">{{ number_format($q->weight, 1) }}</span>
                                    </td>
                                    <td class="text-center text-muted">{{ number_format($q->weight * 5, 1) }}</td>
                                    <td>
                                        <input type="number" name="scores[{{ $q->id }}]"
                                            class="form-control form-control-sm text-end score-input" min="1"
                                            max="5" step="0.5" list="score-steps"
                                            value="{{ $oldScore }}" @disabled(!$editable) placeholder="1–5">
                                    </td>
                                    <td class="text-end"><span class="row-total fw-semibold">0.0</span></td>
                                    <td>
                                        <input type="text" name="remarks[{{ $q->id }}]"
                                            class="form-control form-control-sm" value="{{ $oldRemark }}"
                                            @disabled(!$editable) placeholder="หมายเหตุ (ถ้ามี)">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-light">
                                <th colspan="2" class="text-end">รวมหมวด</th>
                                <th class="text-center">{{ number_format($sumW, 1) }}</th>
                                <th class="text-center">{{ number_format($full, 1) }}</th>
                                <th class="text-end text-muted">รวมที่ได้</th>
                                <th class="text-end"><span class="sec-total fw-bold"
                                        data-for="{{ $sid }}">0.0</span></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="p-3 bg-light-subtle border-top d-flex align-items-center justify-content-between">
                    <div class="small text-muted">
                        ได้ {{ number_format($full, 1) }} คะแนนเต็มหมวด |
                        คิดเป็น <span class="sec-percent fw-semibold" data-for="{{ $sid }}">0.0</span>%
                        ของหมวดนี้
                    </div>
                    <div class="w-50">
                        <textarea name="section_remarks[{{ $s->id }}]" rows="2" class="form-control form-control-sm"
                            @disabled(!$editable) placeholder="บันทึกสรุป/ข้อเสนอแนะของหมวดนี้ (ถ้ามี)"> {{ old('section_remarks.' . $s->id, $sectionNotes[$s->id]->note ?? '') }}</textarea>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- datalist quick pick step 0.5 --}}
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
</div>
