@extends('layouts.layout')

@section('title', 'รวมไฟล์ PDF')
@section('page-title', 'รวมไฟล์ PDF')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-flex align-items-center justify-content-center flex-shrink-0"
                            style="width: 52px; height: 52px;">
                            <i class="fas fa-file-pdf fa-lg"></i>
                        </div>
                        <div>
                            <h4 class="mb-1">รวม PDF หลายไฟล์เป็นไฟล์เดียว</h4>
                            <p class="text-muted mb-0">เลือกไฟล์ตามลำดับที่ต้องการ หรือจัดลำดับใหม่ก่อนกดรวมไฟล์</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('po.pdf-merge.merge') }}" enctype="multipart/form-data" id="pdfMergeForm">
                        @csrf

                        <div class="mb-4">
                            <label for="pdfFiles" class="form-label fw-semibold">ไฟล์ PDF</label>
                            <input type="file" id="pdfFiles" name="pdf_files[]"
                                class="form-control @error('pdf_files') is-invalid @enderror"
                                accept="application/pdf,.pdf" multiple>
                            <div class="form-text">อย่างน้อย 2 ไฟล์, สูงสุด 20 ไฟล์ และไม่เกิน 20 MB ต่อไฟล์</div>
                            @error('pdf_files')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            @error('pdf_files.*')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div id="emptyState" class="border rounded-3 bg-light text-center text-muted py-5 mb-4">
                            <i class="fas fa-cloud-upload-alt fa-2x mb-2"></i>
                            <div>ยังไม่ได้เลือกไฟล์</div>
                        </div>
                        <div id="fileList" class="list-group mb-4 d-none"></div>

                        <div class="mb-4">
                            <label for="outputName" class="form-label fw-semibold">ชื่อไฟล์ผลลัพธ์</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="outputName" name="output_name"
                                    value="{{ old('output_name', 'merged-purchase-orders') }}" maxlength="120">
                                <span class="input-group-text">.pdf</span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" id="mergeButton" class="btn btn-danger px-4" disabled>
                                <i class="fas fa-object-group me-2"></i>รวมและดาวน์โหลด PDF
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const input = document.getElementById('pdfFiles');
            const form = document.getElementById('pdfMergeForm');
            const list = document.getElementById('fileList');
            const emptyState = document.getElementById('emptyState');
            const mergeButton = document.getElementById('mergeButton');
            const mergeButtonHtml = mergeButton.innerHTML;
            let files = [];

            const formatSize = bytes => bytes >= 1048576
                ? (bytes / 1048576).toFixed(1) + ' MB'
                : Math.max(1, Math.round(bytes / 1024)) + ' KB';

            function syncInputFiles() {
                const transfer = new DataTransfer();
                files.forEach(file => transfer.items.add(file));
                input.files = transfer.files;
            }

            function render() {
                syncInputFiles();
                list.innerHTML = '';
                list.classList.toggle('d-none', files.length === 0);
                emptyState.classList.toggle('d-none', files.length !== 0);
                mergeButton.disabled = files.length < 2;

                files.forEach((file, index) => {
                    const row = document.createElement('div');
                    row.className = 'list-group-item d-flex align-items-center gap-3 py-3';
                    row.innerHTML = `
                        <span class="badge bg-secondary rounded-pill">${index + 1}</span>
                        <i class="fas fa-file-pdf text-danger"></i>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="text-truncate fw-semibold"></div>
                            <small class="text-muted">${formatSize(file.size)}</small>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary move-up" title="เลื่อนขึ้น" ${index === 0 ? 'disabled' : ''}><i class="fas fa-arrow-up"></i></button>
                            <button type="button" class="btn btn-outline-secondary move-down" title="เลื่อนลง" ${index === files.length - 1 ? 'disabled' : ''}><i class="fas fa-arrow-down"></i></button>
                            <button type="button" class="btn btn-outline-danger remove-file" title="นำออก"><i class="fas fa-times"></i></button>
                        </div>`;
                    row.querySelector('.text-truncate').textContent = file.name;
                    row.querySelector('.move-up').addEventListener('click', () => move(index, -1));
                    row.querySelector('.move-down').addEventListener('click', () => move(index, 1));
                    row.querySelector('.remove-file').addEventListener('click', () => {
                        files.splice(index, 1);
                        render();
                    });
                    list.appendChild(row);
                });
            }

            function move(index, direction) {
                const destination = index + direction;
                [files[index], files[destination]] = [files[destination], files[index]];
                render();
            }

            function showMessage(icon, message) {
                if (window.Swal) {
                    Swal.fire({ icon: icon, text: message });
                    return;
                }
                window.alert(message);
            }

            input.addEventListener('change', function () {
                files = Array.from(input.files).slice(0, 20);
                render();
            });

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (files.length < 2) {
                    showMessage('warning', 'กรุณาเลือกไฟล์ PDF อย่างน้อย 2 ไฟล์');
                    return;
                }

                mergeButton.disabled = true;
                mergeButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังรวมไฟล์...';

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    if (!response.ok) {
                        const data = await response.json().catch(() => ({}));
                        const messages = Object.values(data.errors || {}).flat();
                        throw new Error(messages[0] || data.message || 'ไม่สามารถรวมไฟล์ PDF ได้');
                    }

                    const blob = await response.blob();
                    const objectUrl = URL.createObjectURL(blob);
                    const outputName = document.getElementById('outputName').value.trim()
                        .replace(/\.pdf$/i, '').replace(/[\\/:*?"<>|]+/g, '_') || 'merged-purchase-orders';
                    const link = document.createElement('a');
                    link.href = objectUrl;
                    link.download = outputName + '.pdf';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);
                } catch (error) {
                    showMessage('error', error.message || 'ไม่สามารถรวมไฟล์ PDF ได้');
                } finally {
                    mergeButton.disabled = files.length < 2;
                    mergeButton.innerHTML = mergeButtonHtml;
                }
            });
        });
    </script>
@endpush
