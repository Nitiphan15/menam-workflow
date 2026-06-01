<?php

return [
    'project_name' => [
        'guest' => env('PROJECT_NAME_GUEST', 'Menam Online'),
        'auth' => env('PROJECT_NAME_AUTH', 'Menam Online'),
    ],

    'menu' => [
        'guest' => [
            ['icon' => 'fa-solid fa-house',            'text' => 'หน้าหลัก', 'route' => 'home'],
            ['icon' => 'fa-solid fa-right-to-bracket', 'text' => 'เข้าสู่ระบบ', 'route' => 'login'],
            ['icon' => 'fa-box', 'text' => 'Packaging',  'route' => 'pkg.packaging.usage'],
            ['icon' => 'fa-box', 'text' => 'Packaging Dashboard', 'route' => 'pkg.packaging.analysis'],
            [
                'icon' => 'fa-triangle-exclamation',
                'text' => 'Production Risk',
                'children' => [
                    ['icon' => 'fa-table', 'text' => 'Risk List', 'route' => 'risk.index'],
                    ['icon' => 'fa-chart-column', 'text' => 'Risk Dashboard', 'route' => 'risk.dashboard'],
                ],
            ],
            [
                'icon' => 'fa-chart-line',
                'text' => 'Sales Report',
                'children' => [
                    ['icon' => 'fa-table', 'text' => 'Delivery Volume', 'route' => 'wos.sales_unit_summary'],
                    ['icon' => 'fa-calendar-check', 'text' => 'Order Due Date', 'route' => 'wos.order_due_date'],
                    ['icon' => 'fa-boxes-stacked', 'text' => 'Deadstock Dashboard', 'route' => 'deadstock.dashboard'],
                    ['icon' => 'fa-clipboard-check', 'text' => 'Deadstock Monthly Review', 'route' => 'deadstock.review'],
                ],
            ],



            [
                'icon' => 'fa-chart-line',
                'text' => 'Accounting Report',
                'children' => [
                    ['icon' => 'fa fa-chart-pie', 'text' => 'Variable Cost', 'route' => 'variable-cost.summary'],
                    ['icon' => 'fa-list-check', 'text' => 'VC Account Master', 'route' => 'variable-cost.account-master'],
                    ['icon' => 'fa fa-sitemap', 'text' => 'Cost Center Report', 'route' => 'cost-center.summary'],
                    ['icon' => 'fa-file-invoice-dollar', 'text' => 'รายการเผื่อผลขาดทุน', 'route' => 'accounting.loss-provision.index'],
                ],
            ],

            [
                'icon' => 'fa-chart-line',
                'text' => 'Accounting Admin',
                'children' => [
                    ['icon' => 'fa-solid fa-hand-holding-dollar', 'text' => 'Customer Payment Terms', 'route' => 'accounting.cpt.index'],
                    ['icon' => 'fa-solid fa-sliders', 'text' => 'Payment Term Masters', 'route' => 'accounting.cpt.masters'],
                    ['icon' => 'fa-solid fa-layer-group', 'text' => 'Division Group Master', 'route' => 'accounting.divisionGroup.master'],
                    ['icon' => 'fa-solid fa-list-check', 'text' => 'VC Account Master', 'route' => 'variable-cost.account-master'],
                ],
            ],

            ['icon' => 'fa-solid fa-magnifying-glass', 'text' => 'Delivery Plan Inquiry',  'route'  => 'dp.inquiry'],
            ['icon' => 'fa-solid fa-list-check', 'text' => 'Production Status Tracking', 'route' => 'dp.production-status'],
            ['icon' => 'fa-solid fa-clipboard-check',  'text' => 'Inspection', 'route' => 'isr.index'],
            ['icon' => 'fa-solid fa-pen-to-square',    'text' => 'ทำแบบทดสอบ (User Test)', 'route'  => 'exam.select'],



            [
                'icon' => 'fa-pen-to-square',
                'text' => 'WO Change Request Form',
                'children' => [
                    ['icon' => 'fa fa-calendar', 'text' => 'เปิดใบขอแก้ไขใหม่', 'route' => 'wocr.index'],
                    ['icon' => 'fa fa-user', 'text' => 'เอกสารของฉัน', 'route' => 'wocr.mine'],
                    ['icon' => 'fa fa-folder-open', 'text' => 'เอกสารทั้งหมด', 'route' => 'wocr.all'],
                ],
            ],

            [
                'icon' => 'fa fa-industry',
                'text' => 'Workload Machine',

                'children' => [
                    ['icon' => 'fa fa-chart-column', 'text' => 'Dashboard', 'route' => 'machine-load.dashboard'],
                    ['icon' => 'fa fa-magnifying-glass-chart', 'text' => 'Inquiry', 'route' => 'machine-load.inquiry'],
                    ['icon' => 'fa fa-gears', 'text' => 'Settings', 'route' => 'machine-load.settings'],
                ],
            ],
        ],


        'auth' => [
            ['icon' => 'fa fa-home', 'text' => 'หน้าหลัก', 'route' => 'home'],
        ],

        'exam' => [
            [
                'icon' => 'fa-clipboard-list',
                'text' => 'Exam Management',
                'permission' => 'EXAM',
                'children' => [
                    [
                        'icon' => 'fa-folder-open',
                        'text' => 'คลังข้อสอบ',
                        'route' => 'exam.master.index',
                        'permission' => 'EXAM',
                    ],
                    [
                        'icon' => 'fa-plus-circle',
                        'text' => 'สร้างข้อสอบใหม่',
                        'route' => 'exam.master.create',
                        'permission' => 'EXAM',
                    ],
                ],
            ],
        ],

        'pr' => [
            [
                'icon' => 'fa-file-alt',
                'text' => 'PR Online',
                'permission' => 'PR',
                'children' => [
                    ['icon' => 'fa-file-alt', 'text' => 'เอกสารที่ต้องทำ', 'route' => 'pr.my_actions', 'permission' => 'PR'],
                    ['icon' => 'fa-plus', 'text' => 'สร้างใบขอซื้อใหม่', 'route' => 'pr.create', 'permission' => 'PR'],
                    ['icon' => 'fa-list', 'text' => 'รายการ PR ทั้งหมด', 'route' => 'login', 'permission' => 'PR'],
                ],
            ],
        ],

        'po' => [
            [
                'icon' => 'fa-shopping-cart',
                'text' => 'PO Online',
                'permission' => ['PO', 'POPUR'],
                'children' => [
                    ['icon' => 'fa-tasks', 'text' => 'เอกสารที่ต้องทำ', 'route' => 'po.myActions', 'permission' => 'PO'],
                    ['icon' => 'fa-list', 'text' => 'รายการ PO', 'route' => 'po.index', 'permission' => 'POPUR'],
                ],
            ],
        ],

        'pa' => [
            [
                'icon' => 'fa fa-info-circle',
                'text' => 'PA Online (Accounting)',
                'permission' => 'PA',
                'children' => [

                    ['icon' => 'fa fa-tachometer-alt', 'text' => 'แดชบอร์ด', 'route' => 'pa.dashboard', 'permission' => 'PA'],
                    ['icon' => 'fa fa-user-check', 'text' => 'ประเมินพนักงาน', 'route' => 'pa.index', 'permission' => 'PA'],
                    ['icon' => 'fa fa-users-cog', 'text' => 'ประเมินพนักงาน [HR]', 'route' => 'pa.hr.index', 'permission' => 'PAHR'],
                ],
            ],
        ],

        'paadmin' => [
            [
                'icon' => 'fa-cog',
                'text' => 'PA Admin (Accounting)',
                'permission' => 'PAADMIN',
                'children' => [
                    ['icon' => 'fa fa-clipboard-list', 'text' => 'จัดการแบบประเมิน/คำถาม', 'route' => 'paadmin.index', 'permission' => 'PAADMIN'],
                    ['icon' => 'fa fa-calendar-alt', 'text' => 'กำหนดช่วงประเมิน', 'route' => 'paadmin.periods.index', 'permission' => 'PAADMIN'],
                ],
            ],
        ],

        'accounting' => [
            [
                'icon' => 'fa-calculator',
                'text' => 'Accounting Reports',
                'children' => [
                    ['icon' => 'fa-file-invoice-dollar', 'text' => 'รายการเผื่อผลขาดทุน', 'route' => 'accounting.loss-provision.index'],
                    ['icon' => 'fa-calendar-alt', 'text' => 'รายการเผื่อผลขาดทุน — สรุปทั้งปี', 'route' => 'accounting.loss-provision.yearly'],
                    ['icon' => 'fa-hand-holding-dollar', 'text' => 'Customer Payment Terms', 'route' => 'accounting.cpt.index'],
                    ['icon' => 'fa-sliders', 'text' => 'Payment Term Masters', 'route' => 'accounting.cpt.masters'],
                    ['icon' => 'fa-layer-group', 'text' => 'Division Group Master', 'route' => 'accounting.divisionGroup.master'],
                    ['icon' => 'fa-list-check', 'text' => 'VC Account Master', 'route' => 'variable-cost.account-master'],
                    ['icon' => 'fa-chart-pie', 'text' => 'Variable Cost', 'route' => 'variable-cost.summary'],
                    ['icon' => 'fa-sitemap', 'text' => 'Cost Center Report', 'route' => 'cost-center.summary'],
                ],
            ],
        ],

        'dp' => [
            [
                'icon' => 'fa-edit',
                'text' => 'Delivery Plan',
                'permission' => ['DP', 'DPA', 'DPEMAIL', 'DPMAIL'],
                'children' => [
                    ['icon' => 'fa-solid fa-plus', 'text' => 'เปิดแผนการจัดส่งใหม่', 'route' => 'dp.index', 'permission' => 'DP'],
                    ['icon' => 'fa-solid fa-magnifying-glass', 'text' => 'Inquiry', 'route' => 'dp.inquiry', 'permission' => ['DP', 'DPA', 'DPEMAIL', 'DPMAIL']],
                    ['icon' => 'fa-solid fa-list-check', 'text' => 'Production Status Tracking', 'route' => 'dp.production-status', 'permission' => ['DP', 'DPA']],
                    ['icon' => 'fa-solid fa-truck', 'text' => 'ตารางงานรถขนส่ง', 'route' => 'dp.dashboard.truck-board', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-truck-moving', 'text' => 'Master รถ', 'route' => 'dp.master.trucks', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-id-card', 'text' => 'Master พนักงานขับรถ', 'route' => 'dp.master.drivers', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-people-carry-box', 'text' => 'Master เด็กรถ', 'route' => 'dp.master.helpers', 'permission' => ['DPA']],
                ],
            ],
        ],



        'fc' => [
            [
                'icon' => 'fa-chart-line',
                'text' => 'Forecast',
                'permission' => 'FC',
                'children' => [
                    ['icon' => 'fa-table', 'text' => 'Forecast', 'route' => 'fc.index', 'permission' => 'FC'],
                    ['icon' => 'fa-users', 'text' => 'Sales Forecast', 'route' => 'fc.division', 'permission' => ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9']],
                    ['icon' => 'fa-folder-open', 'text' => 'Sales Forecast Docs', 'route' => 'fc.division.documents', 'permission' => ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9']],
                    ['icon' => 'fa-user-check', 'text' => 'Sales Forecast Approval', 'route' => 'fc.division.approvals', 'permission' => 'FCAPPROVE'],
                    ['icon' => 'fa-list-check', 'text' => 'Planner Part Master', 'route' => 'fc.planner.master', 'permission' => 'FC_PLN'],
                    ['icon' => 'fa-sitemap', 'text' => 'Division Part Master', 'route' => 'fc.divisionPartMaster.index', 'permission' => ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9']],
                    ['icon' => 'fa-layer-group', 'text' => 'Division Group', 'route' => 'fc.divisionGroup.index'],
                    ['icon' => 'fa-calendar-check', 'text' => 'Planner Forecast', 'route' => 'fc.planner.index', 'permission' => 'FC_PLN'],
                ],
            ],
        ],

        'pp' => [
            [
                'icon' => 'fa fa-building',
                'text' => 'Production Planning Form',
                'permisson' => 'PP',
                'children' => [
                    ['icon' => 'fa fa-calendar', 'text' => 'เปิดแผนการผลิตใหม่', 'route' => 'pp.index', 'permission' => 'PP'],
                    ['icon' => 'fa fa-tasks', 'text' => 'เอกสารที่รอดำเนินการ', 'route' => 'pp.pending', 'permission' => 'PP'],
                    ['icon' => 'fa fa-user', 'text' => 'เอกสารของฉัน', 'route' => 'pp.mine', 'permission' => 'PP'],
                    ['icon' => 'fa fa-folder-open', 'text' => 'เอกสารทั้งหมด', 'route' => 'pp.all', 'permission' => 'PP'],
                ],
            ],
        ],

        /*'wlm' => [
            [
                'icon' => 'fa fa-industry',
                'text' => 'Workload Machine',
                'permission' => 'WLM',
                'children' => [
                    ['icon' => 'fa fa-chart-column', 'text' => 'Dashboard', 'route' => 'machine-load.dashboard', 'permission' => 'WLM'],
                    ['icon' => 'fa fa-magnifying-glass-chart', 'text' => 'Inquiry', 'route' => 'machine-load.inquiry', 'permission' => 'WLM'],
                    ['icon' => 'fa fa-gears', 'text' => 'Settings', 'route' => 'machine-load.settings', 'permission' => 'WLM'],
                ],
            ],
        ],*/

        'wr' => [
            [
                'icon' => 'fa-solid fa-warehouse',
                'text' => 'Wirerod Incoming',
                'permission' => 'WR',
                'children' => [
                    ['icon' => 'fa-solid fa-tape', 'text' => 'Wirerod', 'route' => 'wr.index', 'permission' => 'WR'],
                ],
            ],
        ],

        'wocr' => [
            [
                'icon' => 'fa-pen-to-square',
                'text' => 'WO Change Request Form',
                'permisson' => 'WOCR',
                'children' => [
                    ['icon' => 'fa fa-calendar', 'text' => 'เปิดใบขอแก้ไขใหม่', 'route' => 'wocr.index', 'permission' => 'WOCR'],
                    ['icon' => 'fa fa-tasks', 'text' => 'เอกสารที่รอดำเนินการ', 'route' => 'wocr.pending', 'permission' => 'WOCR'],
                    ['icon' => 'fa fa-user', 'text' => 'เอกสารของฉัน', 'route' => 'wocr.mine', 'permission' => 'WOCR'],
                    ['icon' => 'fa fa-folder-open', 'text' => 'เอกสารทั้งหมด', 'route' => 'wocr.all', 'permission' => 'WOCR'],
                ],
            ],
        ],

        'wos' => [
            [
                'icon' => 'fa-chart-line',
                'text' => 'Sales Report',
                'permission' => ['WOS', 'WDV'],
                'children' => [
                    ['icon' => 'fa-chart-column', 'text' => 'Weekly Summary', 'route' => 'wos.sales_weekly', 'permission' => 'WOS'],
                    ['icon' => 'fa-calendar-check', 'text' => 'Order Due Date', 'route' => 'wos.order_due_date', 'permission' => 'WOS'],
                    ['icon' => 'fa-chart-line', 'text' => 'Order Due Dashboard', 'route' => 'wos.order_due_date.dashboard', 'permission' => 'WOS'],
                    ['icon' => 'fa-table', 'text' => 'Delivery Volume', 'route' => 'wos.sales_unit_summary', 'permission' => 'WDV'],
                    ['icon' => 'fa-boxes-stacked', 'text' => 'Deadstock Dashboard', 'route' => 'deadstock.dashboard', 'permission' => ['WOS', 'WDV']],
                    ['icon' => 'fa-clipboard-check', 'text' => 'Deadstock Monthly Review', 'route' => 'deadstock.review', 'permission' => ['WOS', 'WDV']],
                    ['icon' => 'fa-paper-plane', 'text' => 'Deadstock Manual Mail', 'route' => 'deadstock.manual', 'permission' => ['WOS', 'WDV']],
                ],
            ],
        ],

        'isr' => [
            ['icon' => 'fa-clipboard-check', 'text' => 'Inspection', 'route' => 'isr.index', 'permission' => 'ISR'],
        ],

        'adminpr' => [
            [
                'icon' => 'fa-cog',
                'text' => 'PR Admin',
                'permission' => 'PRADMIN',
                'children' => [
                    ['icon' => 'fa-cog', 'text' => 'ตั้งค่าระบบ PR', 'route' => 'login', 'permission' => 'PRADMIN'],
                    ['icon' => 'fa-tasks', 'text' => 'รายการเอกสารทั้งหมด', 'route' => 'login', 'permission' => 'PRADMIN'],
                    ['icon' => 'fa-users', 'text' => 'ผู้ใช้งาน', 'route' => 'login', 'permission' => 'PRADMIN'],
                ],
            ],
        ],

        'adminweb' => [
            [
                'icon' => 'fa-cog',
                'text' => 'Admin',
                'permission' => 'ADMINWEB',
                'children' => [
                    ['icon' => 'fa-boxes-stacked', 'text' => 'Deadstock Config', 'route' => 'adminweb.deadstock.config', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-user-plus', 'text' => 'ลงทะเบียนพนักงาน', 'route' => 'adminweb.users.register', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-building', 'text' => 'เพิ่มแผนก', 'route' => 'adminweb.dept.create', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-user-tie', 'text' => 'จัดการหัวหน้าแผนก', 'route' => 'adminweb.deptmgr.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-briefcase', 'text' => 'จัดการโครงสร้างแผนก', 'route' => 'adminweb.deptroles.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-id-card', 'text' => 'กำหนดแผนก/ตำแหน่ง User', 'route' => 'adminweb.user-department-assignments.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-key', 'text' => 'เพิ่มสิทธิ์', 'route' => 'adminweb.roles.create', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-key', 'text' => 'กำหนดสิทธิ์ให้ User', 'route' => 'adminweb.user-permissions.index', 'permission' => 'ADMINWEB'],
                ],
            ],
        ],
    ],
];
