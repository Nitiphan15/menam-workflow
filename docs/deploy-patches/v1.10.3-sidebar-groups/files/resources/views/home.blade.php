@extends('layouts.layout')

@section('title', 'Home')
@section('page-title', 'Home')

@section('content')
    @php
        $latestVersion = config('app.version', '1.10.3');
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
                    <li>เมนู: ย้าย Critical Spare Parts ไปหมวด Planning, เพิ่มหมวด Die / Grating, ย้าย Accounting Reports และพิมพ์หน้าซองจดหมายไปหมวด Accounting</li>
                    <li>Delivery Plan: แก้ปุ่ม “ดึงข้อมูล ERP” และกล่องแจ้งเตือนในหน้า Inquiry ที่กดแล้วไม่ทำงาน</li>
                    <li>Delivery Plan: แก้ปุ่ม “ปลดล็อกให้ Sales” ที่กดแล้วหน้าจอค้าง</li>
                    <li>Delivery Plan: เมื่อ Logistics ปลดล็อกวันส่งแล้ว Sales แก้ไขรายการที่จัดรถแล้วได้ (ห้ามเปลี่ยนวันส่ง และห้ามลด KG ต่ำกว่ายอดที่จัดรถไว้)</li>
                    <li>Activity Log: บันทึกการเข้าสู่ระบบ / เข้าสู่ระบบไม่สำเร็จ / ออกจากระบบ พร้อมแผนกของผู้ใช้ ณ เวลาที่ทำรายการ</li>
                    <li>Activity Log: แยกประเภทการกระทำชัดเจนขึ้น (อนุมัติ, ไม่อนุมัติ, ส่งออก/พิมพ์, ดาวน์โหลด, ส่งเมล) และไม่เก็บการเรียกข้อมูลเบื้องหลัง (AJAX)</li>
                    <li>Admin: เพิ่มหน้าประวัติการแก้ไขข้อมูล (ก่อน/หลัง) ของผู้ใช้ สิทธิ์ Role และแผนก</li>
                    <li>ส่วนท้ายหน้าเว็บแสดงผู้ใช้ แผนก เวลาที่โหลดหน้า และป้ายบอกสภาพแวดล้อมทดสอบ</li>
                    <li>Delivery Plan: แสดงสถานะล็อกของวันที่เลือก พร้อมปุ่มสลับล็อก/ปลดล็อกและบันทึกเหตุผลของ Logistics</li>
                    <li>Delivery Plan: หลังส่งเมลตารางจัดรถ ระบบล็อกไม่ให้ Sales เพิ่มหรือแก้ไขข้อมูลวันนั้น จนกว่า Logistics จะกดอนุญาต Sales Submit</li>
                    <li>Delivery Plan: หน้า Inquiry, Export PDF, อีเมลแผนส่งสินค้า และใบพิมพ์รอบรถ โหลดเร็วขึ้น และเรียงรายการคงที่ทุกครั้ง</li>
                    <li>Delivery Plan: หน้าจัดรถ (Truck Board) โหลดเร็วขึ้น ไม่ค้างเมื่อฐานข้อมูลมีงานหนัก</li>
                    <li>Delivery Plan: ช่องค้นหา Sales Order แสดงรายการได้เร็วขึ้น ไม่ค้างเมื่อฐานข้อมูลมีงานหนัก และแสดงยอด Stock ในรายการแนะนำ</li>
                    <li>FormFC: Manager Export Excel แสดง Sales Remark ของแต่ละรายการตามหน้าจอ</li>
                    <li>PO Online: อีเมลรายการรออนุมัติแสดงชื่อขั้นตอนจริงแทนข้อความ Step 3</li>
                    <li>PO Online: แจ้งเตือนเอกสารรออนุมัติ Step 3 เวลา 08:00 และ 13:00 วันจันทร์-ศุกร์ แยกตามผู้อนุมัติ</li>
                    <li>FormFC: แปลง SO ของ FG Part ลงท้าย E หน่วย 03 จากจำนวนเส้นเป็น KG ตาม Dia และ Length</li>
                    <li>FormFC: หน้า SO Detail แสดงทั้ง SO Qty (KG) และจำนวนเส้น พร้อมใช้ค่า SO (KG) เดียวกันใน Excel Export</li>
                    <li>Wirerod Incoming: เพิ่มสรุป Stock Wire Rod รับเข้ารายสัปดาห์ (เสาร์-ศุกร์) กำหนดเข้าโรงงาน และยอดจองผลิต แยก Stainless / Carbon</li>
                    <li>Wirerod Incoming: ยอดจองวัตถุดิบไม่นับ MFG ที่เบิกแล้วตั้งแต่ 95% ของจำนวน MFG</li>
                    <li>แก้ไข FG Stock by Due Month ให้หัวเดือนและการจัดกลุ่มกำหนดส่งตรงตามเดือนที่เลือกใน Filter</li>
                    <li>เพิ่ม Deadstock Background Export: สร้าง Excel ผ่าน Queue พร้อมหน้าประวัติ สถานะ และดาวน์โหลดไฟล์เฉพาะผู้ร้องขอ</li>
                    <li>เพิ่ม Queue connection และ SQL schema สำหรับงาน Export ขนาดใหญ่ โดยไม่บล็อกหน้าเว็บ</li>
                    <li>ขยายข้อความ Approval Remarks ในอีเมลปิด Form PO ให้อ่านง่ายขึ้น</li>
                    <li>อีเมลปิด Form PO แสดง Remark จากการอนุมัติ Step 2 และ Step 3 พร้อมชื่อผู้อนุมัติและเวลา</li>
                    <li>เพิ่มอีเมลแจ้งคิวแผนเบิกประจำวัน เรียงตาม Special และลำดับที่ Planner จัด</li>
                    <li>อีเมลและ Excel แสดงปลายทางการเบิก Work Center พร้อมยอดคงเหลือที่ยังต้องเบิก</li>
                    <li>ปรับปรุงความเร็ว Deadstock Excel Export และยกเลิกการตรึงแถวใน FG Stock by Due Month</li>
                    <li>Deadstock Excel เพิ่ม Sheet “Sales by Delivery Status” แยกสถานะการส่งตามพนักงานขาย</li>
                    <li>แผนการเบิกถือว่ารายการจบเมื่อยอดเบิก ERP ตั้งแต่ 90% โดยยังตรวจสอบข้อมูลเดิมย้อนหลังได้</li>
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
                                <span class="badge text-bg-primary">v 1.10.3</span>
                                <strong>จัดหมวดเมนูใหม่</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>Critical Spare Parts ย้ายไปหมวด Planning</li>
                                <li>เพิ่มหมวด Die (DIE Tracking) และหมวด Grating (Grating Performance)</li>
                                <li>Accounting Reports และพิมพ์หน้าซองจดหมาย ย้ายไปหมวด Accounting</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.10.2</span>
                                <strong>Delivery Plan: แก้ปุ่มดึงข้อมูล ERP ในหน้า Inquiry</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>ปุ่ม “ดึงข้อมูล ERP” (Sale Order / Work Order) ในหน้า Inquiry กดแล้วทำงานตามปกติ</li>
                                <li>กล่องแจ้งเตือน/ยืนยันในหน้า Inquiry แสดงผลได้ ไม่เงียบหาย</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.10.1</span>
                                <strong>Delivery Plan: ปลดล็อกให้ Sales แก้ไขงานที่จัดรถแล้ว</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>แก้ปุ่ม “ปลดล็อกให้ Sales” ในหน้า Truck Board / Inquiry ที่กดแล้วหน้าจอค้างและบันทึกการปลดล็อกไม่ได้</li>
                                <li>เมื่อ Logistics ปลดล็อกวันส่งแล้ว Sales แก้ไขรายการที่จัดรถหรือกำหนดเป็นงานพิเศษแล้วได้ โดยห้ามเปลี่ยนวันส่ง และห้ามลด KG ต่ำกว่ายอดที่จัดรถไว้</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.10.0</span>
                                <strong>Activity Log และประวัติการแก้ไขข้อมูล</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>บันทึก login, login ไม่สำเร็จ และ logout พร้อมชื่อผู้ใช้จริงและแผนก</li>
                                <li>แยกประเภทการกระทำเป็น อนุมัติ / ไม่อนุมัติ / ส่งออก / ดาวน์โหลด / ส่งเมล และไม่เก็บการเรียกข้อมูลเบื้องหลัง</li>
                                <li>เพิ่มหน้าประวัติการแก้ไขข้อมูล (ก่อน/หลัง) สำหรับผู้ใช้ สิทธิ์ Role และแผนก</li>
                                <li>หน้า Activity Log ค้นหาเร็วขึ้น และลบ log ที่เก่ากว่า 1 ปีอัตโนมัติ</li>
                                <li>ส่วนท้ายหน้าเว็บแสดงผู้ใช้ แผนก และเวลาที่โหลดหน้า เพื่อใช้แจ้งปัญหา</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.9.0</span>
                                <strong>Delivery Plan: แสดงสถานะและควบคุม Sales Submit</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>แสดงสถานะล็อก/เปิดของวันที่เลือกและรายการวันที่กำลังล็อกทั้งหมดในหน้า Inquiry และ Truck Board</li>
                                <li>Logistics ใช้ปุ่มเดียวสลับล็อกหรือปลดล็อก โดยการปลดล็อกต้องระบุเหตุผลและระบบเก็บผู้ดำเนินการกับเวลา</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.8.0</span>
                                <strong>Delivery Plan: ล็อกข้อมูลหลังส่งเมลตารางจัดรถ</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>เมื่อ Logistics ส่งเมลตารางจัดรถสำเร็จ Sales จะเพิ่มหรือแก้ไขข้อมูลของวันส่งสินค้านั้นไม่ได้</li>
                                <li>Logistics สามารถกด “อนุญาต Sales Submit” เพื่อเปิดให้แก้ไข และการส่งเมลตารางจัดรถครั้งถัดไปจะล็อกใหม่อัตโนมัติ</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.7.5</span>
                                <strong>Delivery Plan: ปรับความเร็วหน้า Inquiry และเอกสารส่งสินค้า</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>หน้า Inquiry, Export PDF, อีเมลแผนส่งสินค้า, ใบพิมพ์รอบรถ และรายการส่งพิเศษ ใช้หน่วยความจำฐานข้อมูลน้อยลง จึงไม่ต้องรอคิวนาน</li>
                                <li>ลำดับรายการและเลขลำดับใน PDF/อีเมลคงที่ทุกครั้ง และแบ่งหน้าใน Inquiry ไม่ซ้ำหรือหายข้ามหน้า</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.7.4</span>
                                <strong>Delivery Plan: ปรับความเร็วหน้าจัดรถ (Truck Board)</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>ลดการใช้หน่วยความจำของฐานข้อมูลตอนโหลดรายการจัดรถ จึงไม่ต้องรอคิวนาน</li>
                                <li>ลำดับรายการเหมือนเดิม และเรียงคงที่ทุกครั้งเมื่อข้อมูลเวลาและเลข SO เท่ากัน</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.7.3</span>
                                <strong>Delivery Plan: ปรับความเร็วช่องค้นหา Sales Order</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>ค้นหา SO ไม่ต้องรอคิวหน่วยความจำของฐานข้อมูล จึงไม่ค้างนานหรือขึ้น Server Error</li>
                                <li>รายการแนะนำแสดงยอด Stock และแก้ไขการดึงรายการบรรทัดของ SO ที่เคยเกิด Error</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.7.2</span>
                                <strong>FormFC: แก้ไข Sales Remark ใน Manager Excel Export</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>Excel จากหน้า Manager Approval ดึง Sales Remark รายการเดียวกับที่แสดงบนหน้าจอ แทนการอ่านช่อง Approval Remark ที่ว่าง</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">                                <span class="badge text-bg-primary">v 1.7.1</span>
                                <strong>PO Online: แสดงชื่อขั้นตอนอนุมัติจริงในอีเมล</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>หัวข้อและ Subject ของอีเมลดึงชื่อจาก Workflow Step เช่น Department Manager Approval แทนข้อความ Step 3</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">                                <span class="badge text-bg-primary">v 1.7.0</span>
                                <strong>PO Online: แจ้งเตือนรายการรออนุมัติ Step 3 ตามรอบเวลา</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>ส่งตาราง PO ที่ยังรออนุมัติแยกตามผู้อนุมัติ เวลา 08:00 และ 13:00 วันจันทร์-ศุกร์</li>
                                <li>แต่ละรายการมีปุ่มเปิดหน้า PO โดยตรง และรองรับโหมด Dry Run กับผู้รับทดสอบก่อนเปิดใช้งานจริง</li>
                            </ul>
                        </div>
                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.6.1</span>
                                <strong>FormFC: แสดง SO ของสินค้าหน่วยเส้นเป็น KG</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>FG Part ลงท้าย E หน่วย 03 คำนวณน้ำหนัก SO จาก Dia และ Length ก่อนรวมเข้า RM Part</li>
                                <li>หน้า FormFC, SO Detail และ Excel Export ใช้ค่า SO (KG) ตรงกัน โดย SO Detail ยังคงแสดงจำนวนเส้นสำหรับตรวจสอบ</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.6.0</span>
                                <strong>Wirerod Incoming: สรุปรายสัปดาห์และยอดจองวัตถุดิบ</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>เพิ่มการ์ดสรุป Stock on hand, รับเข้ารายสัปดาห์ (เสาร์-ศุกร์), กำหนดเข้าโรงงาน, ยอดจองผลิต และประมาณการสิ้นปี หน่วย MT</li>
                                <li>MFG ที่เบิกแล้วตั้งแต่ 95% ของจำนวน MFG ไม่นับเป็นยอดจองวัตถุดิบ</li>
                                <li>สัปดาห์แรกของเดือนนับรวมวันท้ายเดือนก่อน เช่น 27 มิ.ย. - 3 ก.ค. นับเป็นของเดือน ก.ค.</li>
                                <li>MFG ที่ใช้หลาย Heat แสดงเป็นคำเตือนแทนการทำให้หน้าใช้งานไม่ได้</li>
                            </ul>
                        </div>

                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary">v 1.5.1</span>
                                <strong>แก้ไขเดือนใน Deadstock FG Stock by Due Month</strong>
                            </div>
                            <ul class="mb-0 text-muted">
                                <li>หัวเดือนและการจัดกลุ่มกำหนดส่งใช้เดือนที่เลือกใน Filter เหมือน Sheet Summary</li>
                                <li>แถววันรับเข้า Stock ยังอิงเดือนจาก Snapshot ล่าสุด และแสดงกำหนดส่ง 3 เดือนถัดไปเหมือนเดิม</li>
                                <li>งานที่กำหนดส่งใน 3 เดือนถัดไปแสดงในคอลัมน์เดือนนั้น แม้กำหนดส่งจะข้ามปี</li>
                            </ul>
                        </div>

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
