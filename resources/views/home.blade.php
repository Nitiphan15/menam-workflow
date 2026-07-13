@extends('layouts.layout')

@section('title', 'Home')
@section('page-title', 'Home')

@section('content')
    @php
        $latestVersion = config('app.version', '1.2.0');
        $user = auth()->user();
        $canDp = $user && $user->hasRoleCode(['DP', 'DPA', 'DPEMAIL', 'DPMAIL']);
        $canDpa = $user && $user->hasRoleCode('DPA');
        $canPo = $user && $user->hasRoleCode(['PO', 'POPUR']);
    @endphp

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
                        <div class="h4 fw-bold mb-1">v {{ $latestVersion }}</div>
                        <div class="small text-primary-emphasis">Delivery Plan, Inquiry และ Logistics workflow</div>
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
                                    <span class="badge text-bg-primary">v {{ $latestVersion }}</span>
                                    <strong>อัปเดตระบบ: Deadstock, Grating Performance, DIE Tracking, Login, Password และ VC</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>Deadstock: เพิ่ม/ปรับหน้าตรวจสอบและติดตามรายการค้างสต็อกให้ดูสถานะงานได้ชัดขึ้น</li>
                                    <li>Grating Performance: เพิ่มหน้าบันทึกและติดตามผลงาน Grating Performance สำหรับดูงานรายวันและสรุปประสิทธิภาพ</li>
                                    <li>DIE Tracking: ปรับข้อมูลการติดตาม DIE และ claim ให้ค้นหา/ตรวจสอบงานได้สะดวกขึ้น</li>
                                    <li>Login: สามารถเข้าสู่ระบบด้วย username เช่น somchai_j หรือ email ได้</li>
                                    <li>Password: เปลี่ยนรหัสผ่านใหม่ขั้นต่ำ 4 ตัวอักษร</li>
                                    <li>VC: ปรับปรุงหน้าสรุปและรายละเอียด Variable Cost เพื่อรองรับการตรวจสอบข้อมูลต้นทุนได้ดีขึ้น</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-secondary">v 1.1.0</span>
                                    <strong>อัปเดตงานจัดรถ + ส่งเมลจัดรถ และการแจ้งเตือนทั้งระบบ</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>หน้า Inquiry: เปิดใช้ "จัดรถหลายงานพร้อมกัน" เลือกหลายรายการของวันส่งเดียวกันแล้วจัดรถคันเดียวได้</li>
                                    <li>หน้าจัดรถส่งสินค้า: เพิ่มช่อง "เบอร์โทร Shipping" บันทึกไว้กับการจัดรถแต่ละรายการ</li>
                                    <li>เพิ่มปุ่ม "ส่งเมลจัดรถ" แนบไฟล์ PDF + Excel ตารางจัดรถส่งให้ผู้เกี่ยวข้องอัตโนมัติ (หน้าจัดรถส่งสินค้า และ ตารางรถขนส่ง) มีให้ยืนยันก่อนส่งและกันส่งซ้ำ</li>
                                    <li>ปรับการแจ้งเตือนผลการทำงานทั้งระบบเป็นป๊อปอัปมุมจอ (toast) แสดงครั้งเดียว ไม่ซ้ำซ้อน</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-secondary">v 1.1.0</span>
                                    <strong>อัปเดต Delivery Plan Inquiry และเอกสารจัดส่ง</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>เพิ่ม Export PDF จากหน้า Inquiry และปรับข้อมูลในเอกสารให้ตรงกับหน้าจอใช้งานจริง</li>
                                    <li>รองรับข้อมูล Stock FG, Revision, เอกสารแนบ และหมายเหตุที่ใช้ในรอบจัดส่ง</li>
                                    <li>เพิ่มปุ่ม Sync Sale Order / Work Order จาก ERP สำหรับผู้ที่มีสิทธิ์ส่งแผน</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-secondary">v 1.1.0</span>
                                    <strong>ปรับปรุง Production Status Tracking</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>เพิ่มตัวกรองและสถานะติดตามงานผลิต เพื่อแยกรายการที่เลื่อนส่งหรือยังต้องติดตามได้ชัดขึ้น</li>
                                    <li>ปรับหน้ารายละเอียดให้เห็นข้อมูล Delivery Plan ที่เกี่ยวข้องกับ MFG/SO มากขึ้น</li>
                                    <li>เก็บหมายเหตุการยืนยันวันส่งสินค้า เพื่อช่วยให้ฝ่ายขายและวางแผนตามงานต่อได้ง่ายขึ้น</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-secondary">v 1.1.0</span>
                                    <strong>เพิ่มเครื่องมือ Logistics ก่อนจัดรถ</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>เพิ่มเมนูสรุปก่อนจัดรถ สำหรับมองภาพรวมงานที่ต้องวางแผนขนส่ง</li>
                                    <li>ปรับตารางงานรถให้รองรับคนขับและเด็กรถหลายคน พร้อมตรวจสอบการเลือกพนักงานซ้ำ</li>
                                    <li>ปรับเอกสาร/หน้าพิมพ์รถที่จัดแล้ว ให้ใช้ข้อมูลเที่ยวรถและผู้ช่วยครบถ้วนขึ้น</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-secondary">v 1.0.3</span>
                                    <strong>เพิ่มระบบ PO Online และลายเซ็นผู้ใช้งาน</strong>
                                </div>
                                <div class="text-muted">
                                    เพิ่มเมนูติดตาม PO, เอกสารแนบ, Workflow อนุมัติ, Download PDF
                                    และหน้าจัดการลายเซ็นของผู้ใช้งาน
                                </div>
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
                            @if ($canDp)
                                <a href="{{ route('dp.inquiry') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-magnifying-glass me-2"></i> Delivery Plan Inquiry
                                </a>

                                <a href="{{ route('dp.production-status') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-list-check me-2"></i> Production Status Tracking
                                </a>
                            @endif

                            @if ($canDpa)
                                <a href="{{ route('dp.dashboard.logistics-summary') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-truck-loading me-2"></i> จัดรถส่งสินค้า
                                </a>

                                <a href="{{ route('dp.dashboard.truck-board') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-truck me-2"></i> ตารางรถขนส่ง
                                </a>
                            @endif

                            @if ($canPo)
                                <a href="{{ route('po.myActions') }}" class="btn btn-outline-primary text-start">
                                    <i class="fa-solid fa-tasks me-2"></i> PO My Actions
                                </a>
                            @endif

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
