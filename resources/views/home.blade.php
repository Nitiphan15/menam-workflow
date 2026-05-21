@extends('layouts.layout')

@section('title', 'Home')
@section('page-title', 'Home')

@section('content')
    <div class="container py-4">
        <div class="bg-white border-0 shadow-sm rounded-3 p-4 p-lg-5 mb-4">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <div class="text-primary fw-semibold mb-2">MENAM Online</div>
                    <h2 class="fw-bold mb-3">ยินดีต้อนรับเข้าสู่ระบบ MENAM Online</h2>
                    <p class="text-muted mb-0">
                        เลือกเมนูด้านซ้ายเพื่อเริ่มใช้งานระบบที่ต้องการ หากไม่พบเมนูที่ต้องใช้
                        กรุณาติดต่อผู้ดูแลระบบเพื่อตรวจสอบสิทธิ์การเข้าใช้งาน
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="rounded-3 bg-primary-subtle text-primary p-3">
                        <div class="small fw-semibold">Version ล่าสุด</div>
                        <div class="h4 fw-bold mb-1">v 1.0.3</div>
                        <div class="small text-primary-emphasis">PO Online และการจัดการลายเซ็น</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h4 class="mb-1">ข่าวประกาศ</h4>
                        <div class="text-muted small">รายการอัปเดตล่าสุดของระบบ</div>
                    </div>
                    <div class="card-body px-4">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-primary">v 1.0.3</span>
                                    <strong>เพิ่มระบบ PO Online</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>เพิ่มเมนู PO Online สำหรับติดตามรายการ PO และเอกสารที่ต้องดำเนินการ</li>
                                    <li>รองรับการแนบเอกสาร ส่งเข้า Workflow อนุมัติ และ Download PDF</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-primary">v 1.0.3</span>
                                    <strong>เพิ่มหน้าจัดการลายเซ็นตัวเอง</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>ผู้ใช้งานสามารถอัปโหลดหรือเปลี่ยนลายเซ็นของตัวเองได้</li>
                                    <li>ลายเซ็นจะถูกใช้ประกอบเอกสาร PDF ตามขั้นตอนอนุมัติ</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-secondary">v 1.0.2</span>
                                    <strong>อัปเดต Forecast</strong>
                                </div>
                                <div class="text-muted">
                                    ปรับปรุงหน้าจอ Forecast และข้อมูลที่เกี่ยวข้องให้ใช้งานสะดวกขึ้น
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h4 class="mb-1">เริ่มใช้งานอย่างรวดเร็ว</h4>
                        <div class="text-muted small">เมนูที่ใช้งานบ่อย</div>
                    </div>
                    <div class="card-body px-4">
                        <div class="d-grid gap-2">
                            @can('POPUR')
                                <a href="{{ route('po.index') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-list me-2"></i> PO Online - รายการ PO
                                </a>
                            @endcan

                            @can('PO')
                                <a href="{{ route('po.myActions') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-tasks me-2"></i> PO My Actions
                                </a>
                            @endcan

                            <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary text-start">
                                <i class="fa-solid fa-signature me-2"></i> เพิ่ม / เปลี่ยนลายเซ็นตัวเอง
                            </a>
                        </div>

                        <div class="alert alert-info mt-4 mb-0">
                            หากไม่เห็นปุ่มหรือเมนูที่ต้องใช้ แสดงว่าสิทธิ์ยังไม่ถูกกำหนดให้บัญชีของคุณ
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
