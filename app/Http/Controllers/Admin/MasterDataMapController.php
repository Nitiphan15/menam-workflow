<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class MasterDataMapController extends Controller
{
    public function index()
    {
        return $this->render();
    }

    public function wireRod()
    {
        return $this->render('Wire Rod');
    }

    private function render(?string $initialModule = null)
    {
        $masters = $this->masters()
            ->map(function (array $master) {
                $available = Route::has($master['route']);

                return $master + [
                    'available' => $available,
                    'url' => $available ? route($master['route']) : null,
                ];
            });

        $modules = $masters->pluck('module')->unique()->sort()->values();
        $permissions = $masters->pluck('permission')->unique()->sort()->values();

        return view('adminweb.master-data-map.index', compact('masters', 'modules', 'permissions', 'initialModule'));
    }

    private function masters(): Collection
    {
        return collect([
            ['module' => 'Organization', 'name' => 'Organization Structure', 'description' => 'โครงสร้างแผนก ผู้จัดการ และสายบังคับบัญชา', 'route' => 'organization.index', 'permission' => 'Authenticated', 'source' => 'departments / users'],
            ['module' => 'Admin', 'name' => 'User Master', 'description' => 'ผู้ใช้ รหัสพนักงาน แผนก และสถานะใช้งาน', 'route' => 'adminweb.users.register', 'permission' => 'ADMINWEB', 'source' => 'users'],
            ['module' => 'Admin', 'name' => 'Department Master', 'description' => 'เพิ่มและแก้ไขหน่วยงานในระบบ', 'route' => 'adminweb.dept.create', 'permission' => 'ADMINWEB', 'source' => 'departments'],
            ['module' => 'Admin', 'name' => 'Department Manager', 'description' => 'กำหนดหัวหน้าแผนก', 'route' => 'adminweb.deptmgr.index', 'permission' => 'ADMINWEB', 'source' => 'departments / users'],
            ['module' => 'Admin', 'name' => 'Department Position', 'description' => 'ตำแหน่งงานภายในแต่ละแผนก', 'route' => 'adminweb.deptroles.index', 'permission' => 'ADMINWEB', 'source' => 'department_roles'],
            ['module' => 'Admin', 'name' => 'Role & Permission', 'description' => 'สิทธิ์ระบบและการกำหนดสิทธิ์ให้ผู้ใช้', 'route' => 'adminweb.user-permissions.index', 'permission' => 'ADMINWEB', 'source' => 'dept_roles / user_dept_roles'],
            ['module' => 'Admin', 'name' => 'User Signature', 'description' => 'ไฟล์ลายเซ็นสำหรับเอกสารและ workflow', 'route' => 'adminweb.users.signatures', 'permission' => 'ADMINWEB', 'source' => 'storage/public/signatures'],
            ['module' => 'FormDP', 'name' => 'Truck Master', 'description' => 'ทะเบียนรถและข้อมูลรถขนส่ง', 'route' => 'dp.master.trucks', 'permission' => 'DPA', 'source' => 'delivery_plan_truck_master'],
            ['module' => 'FormDP', 'name' => 'Driver Master', 'description' => 'ข้อมูลพนักงานขับรถ', 'route' => 'dp.master.drivers', 'permission' => 'DPA', 'source' => 'delivery_plan_truck_staff_master'],
            ['module' => 'FormDP', 'name' => 'Helper Master', 'description' => 'ข้อมูลพนักงานช่วยรถ', 'route' => 'dp.master.helpers', 'permission' => 'DPA', 'source' => 'delivery_plan_truck_staff_master'],
            ['module' => 'FormFC', 'name' => 'Planner Part Master', 'description' => 'ผูก Planner กับ Part/RM ที่รับผิดชอบ', 'route' => 'fc.planner.master', 'permission' => 'FC_PLN', 'source' => 'fc_rm_planner_part_master'],
            ['module' => 'FormFC', 'name' => 'Division Part Master', 'description' => 'ผูก Division กับ Part สำหรับ Forecast', 'route' => 'fc.divisionPartMaster.index', 'permission' => 'D1-D9', 'source' => 'fc_rm_division_part_master'],
            ['module' => 'FormFC', 'name' => 'Division Group Master', 'description' => 'จัดกลุ่ม Division สำหรับรายงานและต้นทุน', 'route' => 'accounting.divisionGroup.master', 'permission' => 'VC / VCC', 'source' => 'vc_department_division_groups'],
            ['module' => 'Stock Withdrawal', 'name' => 'Part Standard & Lead Time', 'description' => 'มาตรฐานกลุ่ม Part และระยะเวลาที่ใช้วางแผนเบิก', 'route' => 'stock-withdrawal.master.index', 'permission' => 'STOCK_ADMIN', 'source' => 'stock_withdrawal_standards'],
            ['module' => 'Stock Withdrawal', 'name' => 'Withdrawal Type', 'description' => 'ประเภทการเบิกและกติกาที่เกี่ยวข้อง', 'route' => 'stock-withdrawal.types.index', 'permission' => 'STOCK_ADMIN', 'source' => 'stock_withdrawal_types'],
            ['module' => 'Accounting', 'name' => 'Payment Term Master', 'description' => 'เงื่อนไขชำระเงินของลูกค้า', 'route' => 'accounting.cpt.masters', 'permission' => 'Accounting', 'source' => 'customer_payment_terms'],
            ['module' => 'Accounting', 'name' => 'VC Account Master', 'description' => 'ผูกบัญชีสำหรับรายงาน Variable Cost', 'route' => 'variable-cost.account-master', 'permission' => 'VC / VCC', 'source' => 'variable_cost_accounts'],
            ['module' => 'DIE', 'name' => 'DIE Master', 'description' => 'ข้อมูลแม่พิมพ์ ตำแหน่ง และประวัติการใช้งาน', 'route' => 'die.master', 'permission' => 'DIE', 'source' => 'ERP DIE master'],
            ['module' => 'Exam', 'name' => 'Exam Master', 'description' => 'แบบทดสอบ ประเภท หมวด คำถาม และตัวเลือก', 'route' => 'exam.master.index', 'permission' => 'EXAM', 'source' => 'form_exams / questions'],
            ['module' => 'Grating', 'name' => 'Performance Masters', 'description' => 'พนักงาน ขั้นตอน กิจกรรมหน้างาน และโครงการ', 'route' => 'grating-performance.masters', 'permission' => 'GPM', 'source' => 'grating performance masters'],
            ['module' => 'Wire Rod', 'name' => 'Wire Rod Master Map', 'description' => 'ศูนย์รวมข้อมูลอ้างอิงของ Wire Rod โดยไม่ปะปนหน้ารายงานและหน้า Review', 'route' => 'wr.master', 'permission' => 'WR', 'source' => 'ERP PO / receipt / stock / shipment'],
        ]);
    }
}
