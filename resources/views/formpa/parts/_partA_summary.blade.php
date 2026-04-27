<div class="col-12 col-xl-4">
    <div class="card position-sticky" style="top:1rem">
        <div class="card-header"><strong>สรุป PART A</strong></div>
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <span>คะแนนรวม PART A</span>
                <span id="partA_total_txt" class="fw-bold">-</span>
            </div>
            <div class="d-flex justify-content-between small text-muted">
                <span>คะแนนเต็ม PART A</span>
                <span id="partA_full_txt">-</span>
            </div>
            <hr>
            <div class="d-flex justify-content-between">
                <span>คิดเป็นร้อยละ PART A</span>
                <span id="partA_percent_txt" class="fw-bold">-</span>
            </div>

            {{-- เผื่อบันทึกฝั่ง server --}}
            <input type="hidden" name="calc[partA][total]" id="partA_total">
            <input type="hidden" name="calc[partA][full]" id="partA_full">
            <input type="hidden" name="calc[partA][percent]" id="partA_percent">
        </div>
    </div>
</div>
