@extends('layouts.layout')

@section('title', 'Home')
@section('page-title', 'Home')

@section('content')
    @php
        $latestVersion = config('app.version', '1.5.0');
        $user = auth()->user();
        $canDp = $user && $user->hasRoleCode(['DP', 'DPA', 'DPEMAIL', 'DPMAIL']);
        $canDpa = $user && $user->hasRoleCode('DPA');
        $canPo = $user && $user->hasRoleCode(['PO', 'POPUR']);
        $canStock = $user && $user->hasRoleCode(['STOCK', 'STOCK_ACTION', 'STOCK_ADMIN']);
    @endphp

    <div class="container py-4">
        <section class="mb-4" aria-labelledby="home-welcome-title">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h2 class="h3 fw-bold mb-0" id="home-welcome-title">ยินดีต้อนรับ</h2>
                <span class="badge rounded-pill text-bg-light border text-primary">Version {{ $latestVersion }}</span>
            </div>
            <p class="text-muted mb-0">เลือกงานที่ต้องการจากรายการตามสิทธิ์ของคุณ</p>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="quick-access-title">
            <div class="card-body p-4">
                <div class="mb-3">
                    <h3 class="h5 fw-bold mb-1" id="quick-access-title">งานที่ใช้บ่อย</h3>
                    <p class="text-muted small mb-0">ระบบจะแสดงเฉพาะงานที่บัญชีของคุณมีสิทธิ์ใช้งาน</p>
                </div>

                <div class="row g-2">
                    @if ($canDp)
                        <div class="col-md-6 col-xl-4">
                            <a href="{{ route('dp.inquiry') }}" class="btn btn-outline-primary text-start w-100 h-100 py-3">
                                <i class="fa-solid fa-magnifying-glass me-2"></i> Delivery Plan Inquiry
                            </a>
                        </div>

                        <div class="col-md-6 col-xl-4">
                            <a href="{{ route('dp.production-status') }}" class="btn btn-outline-primary text-start w-100 h-100 py-3">
                                <i class="fa-solid fa-list-check me-2"></i> Production Status Tracking
                            </a>
                        </div>
                    @endif

                    @if ($canDpa)
                        <div class="col-md-6 col-xl-4">
                            <a href="{{ route('dp.dashboard.logistics-summary') }}" class="btn btn-outline-primary text-start w-100 h-100 py-3">
                                <i class="fa-solid fa-truck-loading me-2"></i> จัดรถส่งสินค้า
                            </a>
                        </div>

                        <div class="col-md-6 col-xl-4">
                            <a href="{{ route('dp.dashboard.truck-board') }}" class="btn btn-outline-primary text-start w-100 h-100 py-3">
                                <i class="fa-solid fa-truck me-2"></i> ตารางรถขนส่ง
                            </a>
                        </div>
                    @endif

                    @if ($canPo)
                        <div class="col-md-6 col-xl-4">
                            <a href="{{ route('po.myActions') }}" class="btn btn-outline-primary text-start w-100 h-100 py-3">
                                <i class="fa-solid fa-tasks me-2"></i> PO My Actions
                            </a>
                        </div>
                    @endif

                    @if ($canStock)
                        <div class="col-md-6 col-xl-4">
                            <a href="{{ route('stock-withdrawal.index') }}" class="btn btn-outline-primary text-start w-100 h-100 py-3">
                                <i class="fa-solid fa-boxes-stacked me-2"></i> แผนเบิกงาน
                            </a>
                        </div>
                    @endif

                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary text-start w-100 h-100 py-3">
                            <i class="fa-solid fa-signature me-2"></i> เพิ่ม / เปลี่ยนลายเซ็นตัวเอง
                        </a>
                    </div>
                </div>

                <p class="text-muted small mt-3 mb-0">
                    ไม่พบงานที่ต้องใช้? ติดต่อผู้ดูแลระบบเพื่อตรวจสอบสิทธิ์บัญชี
                </p>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="latest-update-title">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="badge text-bg-primary">v {{ $latestVersion }}</span>
                    <h3 class="h5 fw-bold mb-0" id="latest-update-title">อัปเดตล่าสุด</h3>
                </div>
                <ul class="text-muted mb-0">
                    <li>เพิ่ม Deadstock Background Export: สร้าง Excel ผ่าน Queue พร้อมหน้าประวัติ สถานะ และดาวน์โหลดไฟล์เฉพาะผู้ร้องขอ</li>
                    <li>เพิ่ม Queue connection และ SQL schema สำหรับงาน Export ขนาดใหญ่ โดยไม่บล็อกหน้าเว็บ</li>
                    <li>ขยายข้อความ Approval Remarks ในอีเมลปิด Form PO ให้อ่านง่ายขึ้น</li>
                    <li>อีเมลปิด Form PO แสดง Remark จากการอนุมัติ Step 2 และ Step 3 พร้อมชื่อผู้อนุมัติและเวลา</li>
                    <li>เพิ่มอีเมลแจ้งคิวแผนเบิกประจำวัน เรียงตาม Special และลำดับที่ Planner จัด</li>
                    <li>อีเมลและ Excel แสดงปลายทางการเบิก Work Center พร้อมยอดคงเหลือที่ยังต้องเบิก</li>
                    <li>ปรับปรุงความเร็ว Deadstock Excel Export และยกเลิกการตรึงแถวใน FG Stock by Due Month</li>
                    <li>Deadstock Excel เพิ่ม Sheet “Sales by Delivery Status” แยกสถานะการส่งตามพนักงานขาย</li>
                    <li>แผนการเบิกถือว่ารายการจบเมื่อยอดเบิก ERP ตั้งแต่ 90% โดยยังตรวจสอบข้อมูลเดิมย้อนหลังได้</li>
                    <li>แก้ไข FG Stock by Due Month ให้ใช้เดือนจาก Snapshot เดียวกันตลอดทั้ง Sheet</li>
                </ul>
            </div>
        </section>

        <section class="card border-0 shadow-sm" aria-labelledby="release-history-title">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h3 class="h5 fw-bold mb-1" id="release-history-title">ประวัติเวอร์ชัน</h3>
                        <p class="text-muted small mb-0">รายละเอียดการปรับปรุงระบบทั้งหมด</p>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse"
                        data-bs-target="#releaseHistory" aria-expanded="false" aria-controls="releaseHistory">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> ดูประวัติทั้งหมด
                    </button>
                </div>

                <div class="collapse" id="releaseHistory">
                    <div class="list-group list-group-flush mt-3">
                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.5.0</span>
                                <strong>เพิ่ม Deadstock Background Export</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>สร้างไฟล์ Wire Rod Deadstock Excel ผ่าน Queue เพื่อลดเวลารอและรองรับข้อมูลจำนวนมาก</li>
                                <li>เพิ่มหน้าประวัติ สถานะ และดาวน์โหลดไฟล์ โดยตรวจสิทธิ์เจ้าของคำขอก่อนดาวน์โหลด</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.4.2</span>
                                <strong>ปรับขนาดข้อความ Remark ในอีเมล Form PO</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>ขยายหัวข้อ Step, ข้อความ Remark และข้อมูลผู้อนุมัติในอีเมลปิด PO ให้อ่านง่ายขึ้น</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.4.1</span>
                                <strong>เพิ่ม Remark การอนุมัติในอีเมลปิด Form PO</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>แสดงเฉพาะ APPROVE Remark ที่มีข้อความจาก Step 2 Purchase Head และ Step 3 Head of Department</li>
                                <li>แสดงชื่อผู้อนุมัติและเวลาที่อนุมัติ เพื่อให้ตรวจสอบย้อนหลังได้ง่ายขึ้น</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.4.0</span>
                                <strong>เพิ่มอีเมลแจ้งเตือนคิวแผนเบิกประจำวัน</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>แจ้งรายการคิววันนี้ รวมงานค้างที่ยังเบิกไม่ครบ โดยเรียง Special ก่อนลำดับที่ Planner จัด</li>
                                <li>รองรับผู้รับหลายคน และแสดง Work Center ปลายทางในอีเมลกับ Excel</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v {{ $latestVersion }}</span>
                                <strong>ปรับปรุงความเร็ว Deadstock Excel Export</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>ลดการอ่านข้อมูลซ้ำ โดยรวมการคำนวณ Summary และ Sheet รายละเอียดที่เกี่ยวข้องไว้ในรอบเดียว</li>
                                <li>ยกเลิกการตรึงแถวใน Sheet "FG Stock by Due Month" เพื่อเลื่อนดูหลายตารางได้ต่อเนื่อง</li>
                            </ul>
                        </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-primary">v {{ $latestVersion }}</span>
                                    <strong>เพิ่ม Deadstock Excel แยกสถานะการส่งตาม Sales</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>เพิ่ม Sheet "Sales by Delivery Status" แยกรายการยังไม่ส่งและส่งแล้วตามพนักงานขาย</li>
                                    <li>เรียง Qty จากมากไปน้อยภายในแต่ละสถานะ และแสดงรายการส่งแล้วด้วยพื้นสีเขียว</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-primary">v {{ $latestVersion }}</span>
                                    <strong>ปรับเกณฑ์จบรายการในแผนการเบิก</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>รายการ MFG + Part ที่มียอดเบิก ERP ตั้งแต่ 90% ของยอดที่ต้องเบิก ถือว่าจบและซ่อนจากรายการรอวางแผน</li>
                                    <li>ข้อมูลเดิมไม่ถูกลบและยังตรวจสอบย้อนหลังได้</li>
                                </ul>
                            </div>

                            <div class="list-group-item px-0 py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge text-bg-primary">v {{ $latestVersion }}</span>
                                    <strong>แก้ไข Deadstock Excel Sheet 2</strong>
                                </div>
                                <ul class="mb-0 text-muted">
                                    <li>ปรับ FG Stock by Due Month ให้หัวตาราง การแบ่งกำหนดส่ง และรายการที่เคลียร์ ใช้เดือนจาก Snapshot เดียวกัน</li>
                                    <li>ป้องกันยอดเดือนถัดไปคลาดเคลื่อนเมื่อ Export ช่วงวันที่รับเข้าหลายเดือนหรือทั้งปี</li>
                                </ul>
                            </div>

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
        </section>
    </div>
@endsection
