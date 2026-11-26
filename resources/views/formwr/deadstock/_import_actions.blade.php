@canany(['WR_DS_EDIT_OWN','WR_DS_MANAGE_ALL','WR_DS_ADMIN'])
<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#wrReviewImportModal" title="บันทึกการติดตามหลาย Lot จากไฟล์ Excel">
    <i class="fas fa-file-import"></i> Import Excel
</button>
@once
@push('scripts')
<div class="modal fade" id="wrReviewImportModal" tabindex="-1" aria-labelledby="wrReviewImportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="{{ route('wr.deadstock.review.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="wrReviewImportTitle"><i class="fas fa-file-import me-1"></i> Import การติดตามจาก Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <ol class="small text-muted ps-3 mb-3">
                    <li>ดาวน์โหลด Template หรือใช้ไฟล์จากปุ่ม <strong>Export Excel</strong> (Sheet Serial Detail)</li>
                    <li>กรอก Review Status, Next Follow-up, Review Detail, Corrective / Preventive Action, Sales Remark</li>
                    <li>ระบบจับคู่ด้วย <strong>Serial / Heat</strong> (+ Site / Part Number ถ้ามี) · ช่องที่เว้นว่างจะไม่แก้ค่าเดิม</li>
                    <li>บันทึกได้เฉพาะ Lot ที่คุณมีสิทธิ์แก้ไข</li>
                </ol>
                <a class="btn btn-sm btn-outline-secondary mb-3" href="{{ route('wr.deadstock.review.import-template') }}"><i class="fas fa-download me-1"></i> ดาวน์โหลด Template</a>
                <div class="mb-3">
                    <label class="form-label" for="wrReviewImportFile">ไฟล์ Excel (.xlsx, .xls ไม่เกิน 10 MB)</label>
                    <input class="form-control" type="file" id="wrReviewImportFile" name="review_file" accept=".xlsx,.xls" required>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="wrReviewImportDryRun" name="dry_run" value="1" checked>
                    <label class="form-check-label" for="wrReviewImportDryRun">ทดลองก่อน (Dry run) — ตรวจผลโดยยังไม่บันทึก</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i> Import</button>
            </div>
        </form>
    </div>
</div>
@endpush
@endonce
@endcanany
