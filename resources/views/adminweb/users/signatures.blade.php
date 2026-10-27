@extends('layouts.layout')

@section('title', 'จัดการลายเซ็นผู้ใช้')

@section('content')
    <div class="container py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h4 class="mb-1">จัดการลายเซ็นผู้ใช้</h4>
                <div class="text-muted">เลือกผู้ใช้ แล้วเพิ่มหรือเปลี่ยนลายเซ็นได้โดยไม่ต้องเข้าสู่ระบบแทนผู้ใช้</div>
            </div>
            <a href="{{ route('adminweb.users.register') }}" class="btn btn-outline-secondary">
                <i class="fa-solid fa-users me-1"></i> จัดการผู้ใช้
            </a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger mb-3">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white fw-semibold">
                <i class="fa-solid fa-signature me-2"></i>เพิ่ม / เปลี่ยนลายเซ็น
            </div>
            <div class="card-body p-4">
                <form id="signature-form" method="POST" action="" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <label for="user_id" class="form-label fw-semibold">ผู้ใช้</label>
                            <select id="user_id" class="form-select" required>
                                <option value="">— ค้นหาชื่อ รหัสพนักงาน Username หรืออีเมล —</option>
                                @foreach ($users as $user)
                                    @php
                                        $signature = $signatures->get($user->id);
                                        $signatureUrl = $signature
                                            ? $signature['url'] . '?v=' . $signature['version']
                                            : '';
                                    @endphp
                                    <option value="{{ $user->id }}"
                                        data-name="{{ $user->name }}"
                                        data-code="{{ $user->user_code ?: '-' }}"
                                        data-username="{{ $user->username ?: '-' }}"
                                        data-email="{{ $user->email ?: '-' }}"
                                        data-active="{{ $user->is_active ? 'Active' : 'Inactive' }}"
                                        data-signature-url="{{ $signatureUrl }}"
                                        data-uploaded-at="{{ $signature['updated_at'] ?? '' }}"
                                        {{ $selectedUserId === (int) $user->id ? 'selected' : '' }}>
                                        {{ $user->name }}
                                        @if ($user->user_code)
                                            [{{ $user->user_code }}]
                                        @endif
                                        — {{ $user->username }} — {{ $user->email }}{{ $user->is_active ? '' : ' (Inactive)' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">พิมพ์ชื่อ รหัสพนักงาน Username หรืออีเมลเพื่อค้นหา</div>

                            <div id="user-details" class="border rounded bg-light p-3 mt-3 d-none">
                                <div class="row g-2 small">
                                    <div class="col-sm-6"><span class="text-muted">ชื่อ:</span> <strong id="detail-name"></strong></div>
                                    <div class="col-sm-6"><span class="text-muted">รหัส:</span> <span id="detail-code"></span></div>
                                    <div class="col-sm-6"><span class="text-muted">Username:</span> <span id="detail-username"></span></div>
                                    <div class="col-sm-6"><span class="text-muted">สถานะ:</span> <span id="detail-active"></span></div>
                                    <div class="col-12"><span class="text-muted">อีเมล:</span> <span id="detail-email"></span></div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label for="signature" class="form-label fw-semibold">ไฟล์ลายเซ็น</label>
                                <input id="signature" name="signature" type="file"
                                    class="form-control @error('signature') is-invalid @enderror"
                                    accept="image/png,image/jpeg" required disabled>
                                <div class="form-text">รองรับ PNG/JPG ขนาดไม่เกิน 2MB ระบบจะตัดพื้นขาวและย่อรูปให้อัตโนมัติ</div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="border rounded h-100 p-3 text-center d-flex flex-column justify-content-center" style="min-height: 220px;">
                                <div class="small text-muted mb-2">ลายเซ็นปัจจุบัน</div>
                                <img id="signature-preview" src="" alt="ลายเซ็นปัจจุบัน"
                                    class="img-fluid mx-auto d-none" style="max-height: 130px; object-fit: contain;">
                                <div id="signature-empty" class="text-muted py-4">กรุณาเลือกผู้ใช้</div>
                                <div id="signature-date" class="small text-muted mt-2"></div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button id="save-button" type="submit" class="btn btn-primary px-4" disabled>
                            <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกลายเซ็น
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('user_id');
            const form = document.getElementById('signature-form');
            const fileInput = document.getElementById('signature');
            const saveButton = document.getElementById('save-button');
            const details = document.getElementById('user-details');
            const preview = document.getElementById('signature-preview');
            const empty = document.getElementById('signature-empty');
            const date = document.getElementById('signature-date');
            const updateBaseUrl = @json(url('adminweb/users'));

            function refreshSelectedUser() {
                const option = select.options[select.selectedIndex];
                const userId = select.value;
                const hasUser = userId !== '';

                fileInput.disabled = !hasUser;
                saveButton.disabled = !hasUser;
                details.classList.toggle('d-none', !hasUser);
                form.action = hasUser ? `${updateBaseUrl}/${encodeURIComponent(userId)}/signature` : '';

                if (!hasUser) {
                    preview.classList.add('d-none');
                    preview.removeAttribute('src');
                    empty.textContent = 'กรุณาเลือกผู้ใช้';
                    empty.classList.remove('d-none');
                    date.textContent = '';
                    return;
                }

                document.getElementById('detail-name').textContent = option.dataset.name;
                document.getElementById('detail-code').textContent = option.dataset.code;
                document.getElementById('detail-username').textContent = option.dataset.username;
                document.getElementById('detail-email').textContent = option.dataset.email;
                document.getElementById('detail-active').textContent = option.dataset.active;

                if (option.dataset.signatureUrl) {
                    preview.src = option.dataset.signatureUrl;
                    preview.classList.remove('d-none');
                    empty.classList.add('d-none');
                    date.textContent = option.dataset.uploadedAt ? `อัปโหลดล่าสุด ${option.dataset.uploadedAt}` : '';
                } else {
                    preview.classList.add('d-none');
                    preview.removeAttribute('src');
                    empty.textContent = 'ผู้ใช้นี้ยังไม่มีลายเซ็น';
                    empty.classList.remove('d-none');
                    date.textContent = '';
                }
            }

            if (window.TomSelect) {
                new TomSelect(select, {
                    allowEmptyOption: true,
                    maxOptions: 1000,
                    searchField: ['text'],
                    onChange: refreshSelectedUser,
                });
            } else {
                select.addEventListener('change', refreshSelectedUser);
            }

            refreshSelectedUser();
        });
    </script>
@endpush
