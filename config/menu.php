<?php

return [
    'project_name' => [
        'guest' => env('PROJECT_NAME_GUEST', 'Menam Online'),
        'auth' => env('PROJECT_NAME_AUTH', 'Menam Online'),
    ],

    'show_guest_menu_when_auth' => true,
    'hide_guest_menu_routes_when_auth' => ['login'],

    'menu' => [
        'guest' => [
            ['icon' => 'fa-solid fa-house',            'text' => 'หน้าหลัก', 'route' => 'home'],
            ['icon' => 'fa-solid fa-right-to-bracket', 'text' => 'เข้าสู่ระบบ', 'route' => 'login'],
            ['icon' => 'fa-box', 'text' => 'Packaging',  'route' => 'pkg.packaging.usage'],

            [
                'icon' => 'fa-solid fa-industry',
                'text' => 'Grating Performance',
                'children' => [
                    ['icon' => 'fa-solid fa-chart-line', 'text' => 'Dashboard', 'route' => 'grating-performance.index'],
                    ['icon' => 'fa-solid fa-table', 'text' => 'Inquiry', 'route' => 'grating-performance.inquiry'],
                ],
            ],

            [
                'icon' => 'fa-solid fa-screwdriver-wrench',
                'text' => 'DIE Tracking',
                'children' => [
                    ['icon' => 'fa-gauge-high',   'text' => 'Dashboard',       'route' => 'die.index'],
                    ['icon' => 'fa-database',     'text' => 'Die Master',      'route' => 'die.master'],
                    ['icon' => 'fa-file-lines',   'text' => 'WO Detail Sheet', 'route' => 'die.wo-detail'],
                ],
            ],

            ['icon' => 'fa-solid fa-clipboard-check',  'text' => 'Inspection', 'route' => 'isr.index'],
            ['icon' => 'fa-solid fa-gears', 'text' => 'Critical Spare Parts', 'route' => 'ssc.index', 'permission' => 'SSC'],
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
                'icon' => 'fa-boxes-stacked',
                'text' => 'Deadstock',
                // ไม่กำหนด permission: เปิดให้ทุกคนที่ login เห็น (ดูได้เหมือน guest, action ค่อยเช็ค auth)
                'children' => [
                    ['icon' => 'fa-boxes-stacked', 'text' => 'Deadstock Dashboard', 'route' => 'deadstock.dashboard'],
                    ['icon' => 'fa-clipboard-check', 'text' => 'Deadstock Monthly Review', 'route' => 'deadstock.review'],
                ],
            ],
            [
                'icon' => 'fa-edit',
                'text' => 'Delivery Plan',
                'children' => [
                    ['icon' => 'fa-solid fa-magnifying-glass', 'text' => 'รายการแผนจัดส่ง', 'route' => 'dp.inquiry'],
                    ['icon' => 'fa-solid fa-truck', 'text' => 'ตารางรถขนส่ง', 'route' => 'dp.dashboard.truck-board'],

                ],
            ],

            [
                'icon' => 'fa-chart-line',
                'text' => 'Sales Report',
                'children' => [
                    ['icon' => 'fa-calendar-check', 'text' => 'Order By Due Date', 'route' => 'wos.order_due_date'],
                    ['icon' => 'fa-table', 'text' => 'Delivery Volume', 'route' => 'wos.sales_unit_summary'],
                    ['icon' => 'fa-ranking-star', 'text' => 'Customer Ranking & Tier', 'route' => 'wos.customer_order_invoice.index'],

                ],
            ],

            [
                'icon' => 'fa-calculator',
                'text' => 'Accounting Reports',
                'children' => [
                    ['icon' => 'fa-file-invoice-dollar', 'text' => 'รายการเผื่อผลขาดทุน', 'route' => 'accounting.loss-provision.index'],
                    ['icon' => 'fa-calendar-alt', 'text' => 'รายการเผื่อผลขาดทุน — สรุปทั้งปี', 'route' => 'accounting.loss-provision.yearly'],
                    ['icon' => 'fa-chart-line', 'text' => 'วิเคราะห์ยอดคืนจากลูกค้า Dashboard', 'route' => 'accounting.loss-provision.recovery-dashboard'],
                    ['icon' => 'fa-table-list', 'text' => 'วิเคราะห์ยอดคืนจากลูกค้า Inquiry', 'route' => 'accounting.loss-provision.recovery-inquiry'],
                    ['icon' => 'fa-hand-holding-dollar', 'text' => 'Customer Payment Terms', 'route' => 'accounting.cpt.index'],
                    ['icon' => 'fa-sliders', 'text' => 'Payment Term Masters', 'route' => 'accounting.cpt.masters'],
                    ['icon' => 'fa-layer-group', 'text' => 'Division Group Master', 'route' => 'accounting.divisionGroup.master', 'permission' => ['VC', 'VCC']],
                    ['icon' => 'fa-list-check', 'text' => 'VC Account Master', 'route' => 'variable-cost.account-master', 'permission' => ['VC', 'VCC']],
                    ['icon' => 'fa-chart-pie', 'text' => 'Variable Cost', 'route' => 'variable-cost.summary', 'permission' => ['VC', 'VCA', 'VCL', 'VCPD', 'VCP', 'VCS', 'VCM']],
                    ['icon' => 'fa-sitemap', 'text' => 'Cost Center Report', 'route' => 'cost-center.summary'],
                ],
            ],
        ],

        'auth' => [
            ['icon' => 'fa fa-home', 'text' => 'หน้าหลัก', 'route' => 'home'],
            [
                'icon' => 'fa-boxes-stacked',
                'text' => 'Deadstock',
                // ไม่กำหนด permission: เปิดให้ทุกคนที่ login เห็น (ดูได้เหมือน guest, action ค่อยเช็ค auth)
                'children' => [
                    ['icon' => 'fa-boxes-stacked', 'text' => 'Deadstock Dashboard', 'route' => 'deadstock.dashboard'],
                    ['icon' => 'fa-clipboard-check', 'text' => 'Deadstock Monthly Review', 'route' => 'deadstock.review'],
                ],
            ],
            [
                'icon' => 'fa-screwdriver-wrench',
                'text' => 'DIE Tracking',
                // ไม่กำหนด permission: เปิดให้ทุกคนที่ login เห็น (route die.* เป็น public อยู่แล้ว)
                'children' => [
                    ['icon' => 'fa-gauge-high',  'text' => 'Dashboard',       'route' => 'die.index'],
                    ['icon' => 'fa-database',    'text' => 'Die Master',      'route' => 'die.master'],
                    ['icon' => 'fa-file-lines',  'text' => 'WO Detail Sheet', 'route' => 'die.wo-detail'],
                ],
            ],
        ],

        'wlm' => [
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
        'risk' =>
        [
            [
                'icon' => 'fa-triangle-exclamation',
                'text' => 'Production Risk',
                'children' => [
                    ['icon' => 'fa-table', 'text' => 'Risk List', 'route' => 'risk.index', 'permission' => 'RISK'],
                    ['icon' => 'fa-chart-column', 'text' => 'Risk Dashboard', 'route' => 'risk.dashboard', 'permission' => 'RISK'],
                ],
            ],
        ],

        'po' => [
            [
                'icon' => 'fa-shopping-cart',
                'text' => 'PO Online',
                'permission' => ['PO', 'POPUR', 'POM'],
                'children' => [
                    ['icon' => 'fa-tasks', 'text' => 'เอกสารที่ต้องทำ', 'route' => 'po.myActions', 'permission' => 'PO'],
                    ['icon' => 'fa-route', 'text' => 'ติดตาม PO แผนกของฉัน', 'route' => 'po.departmentTracking', 'permission' => ['PO', 'POPUR']],
                    ['icon' => 'fa-list', 'text' => 'รายการ PO', 'route' => 'po.index', 'permission' => 'POPUR'],
                    ['icon' => 'fa-file-pdf', 'text' => 'รวมไฟล์ PDF', 'route' => 'po.pdf-merge.index', 'permission' => 'POPUR'],
                    ['icon' => 'fa-users-gear', 'text' => 'PO Approver Master', 'route' => 'po.approver-master.index', 'permission' => 'POM'],
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
                    ['icon' => 'fa-chart-line', 'text' => 'วิเคราะห์ยอดคืนจากลูกค้า Dashboard', 'route' => 'accounting.loss-provision.recovery-dashboard'],
                    ['icon' => 'fa-table-list', 'text' => 'วิเคราะห์ยอดคืนจากลูกค้า Inquiry', 'route' => 'accounting.loss-provision.recovery-inquiry'],
                    ['icon' => 'fa-hand-holding-dollar', 'text' => 'Customer Payment Terms', 'route' => 'accounting.cpt.index'],
                    ['icon' => 'fa-sliders', 'text' => 'Payment Term Masters', 'route' => 'accounting.cpt.masters'],
                    ['icon' => 'fa-layer-group', 'text' => 'Division Group Master', 'route' => 'accounting.divisionGroup.master', 'permission' => ['VC', 'VCC']],
                    ['icon' => 'fa-list-check', 'text' => 'VC Account Master', 'route' => 'variable-cost.account-master', 'permission' => ['VC', 'VCC']],
                    ['icon' => 'fa-chart-pie', 'text' => 'Variable Cost', 'route' => 'variable-cost.summary', 'permission' => ['VC', 'VCA', 'VCL', 'VCPD', 'VCP', 'VCS', 'VCM']],
                    ['icon' => 'fa-sitemap', 'text' => 'Cost Center Report', 'route' => 'cost-center.summary'],
                ],
            ],
        ],

        'vc' => [
            [
                'icon' => 'fa-calculator',
                'text' => 'Accounting Reports',
                'permission' => ['VC', 'VCA', 'VCL', 'VCPD', 'VCP', 'VCS', 'VCM', 'VCC'],
                'children' => [
                    ['icon' => 'fa-layer-group', 'text' => 'Division Group Master', 'route' => 'accounting.divisionGroup.master', 'permission' => ['VC', 'VCC']],
                    ['icon' => 'fa-list-check', 'text' => 'VC Account Master', 'route' => 'variable-cost.account-master', 'permission' => ['VC', 'VCC']],
                    ['icon' => 'fa-chart-pie', 'text' => 'Variable Cost', 'route' => 'variable-cost.summary', 'permission' => ['VC', 'VCA', 'VCL', 'VCPD', 'VCP', 'VCS', 'VCM']],
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
                    ['icon' => 'fa-solid fa-magnifying-glass', 'text' => 'รายการแผนจัดส่ง', 'route' => 'dp.inquiry', 'permission' => ['DP', 'DPA', 'DPEMAIL', 'DPMAIL']],

                    ['icon' => 'fa-solid fa-truck-loading', 'text' => 'จัดรถส่งสินค้า', 'route' => 'dp.dashboard.logistics-summary', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-truck', 'text' => 'ตารางรถขนส่ง', 'route' => 'dp.dashboard.truck-board', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-truck-moving', 'text' => 'Master รถ', 'route' => 'dp.master.trucks', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-id-card', 'text' => 'Master พนักงานขับรถ', 'route' => 'dp.master.drivers', 'permission' => ['DPA']],
                    ['icon' => 'fa-solid fa-people-carry-box', 'text' => 'Master เด็กรถ', 'route' => 'dp.master.helpers', 'permission' => ['DPA']],
                ],
            ],
        ],


        'tdp' => [
            [
                'icon' => 'fa-solid fa-list-check',
                'text' => 'Production Status Tracking',
                'route' => 'dp.production-status',
                'permission' => 'TDP',

            ],
        ],


        'gp' => [
            [
                'icon' => 'fa-solid fa-industry',
                'text' => 'Grating Performance',
                'permission' => ['GP', 'GPM'],
                'children' => [
                    ['icon' => 'fa-solid fa-chart-line', 'text' => 'Dashboard', 'route' => 'grating-performance.index', 'permission' => 'GP'],
                    ['icon' => 'fa-solid fa-plus', 'text' => 'Input รายวัน', 'route' => 'grating-performance.entries.create', 'permission' => 'GP'],
                    ['icon' => 'fa-solid fa-table', 'text' => 'Inquiry', 'route' => 'grating-performance.inquiry', 'permission' => 'GP'],
                    ['icon' => 'fa-solid fa-sliders', 'text' => 'Masters', 'route' => 'grating-performance.masters', 'permission' => 'GPM'],
                ],
            ],
        ],


        'fc' => [
            [
                'icon' => 'fa-chart-line',
                'text' => 'Forecast',
                'permission' => ['FC', 'FC_PLN'],
                'children' => [
                    ['icon' => 'fa-table', 'text' => 'Forecast', 'route' => 'fc.index', 'permission' => 'FC'],
                    ['icon' => 'fa-users', 'text' => 'Sales Forecast', 'route' => 'fc.division', 'permission' => ['FC_PLN', 'D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9']],
                    ['icon' => 'fa-folder-open', 'text' => 'Sales Forecast Docs', 'route' => 'fc.division.documents', 'permission' => ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9']],
                    ['icon' => 'fa-user-check', 'text' => 'Sales Forecast Approval', 'route' => 'fc.division.approvals', 'permission' => 'FCAPPROVE'],
                    ['icon' => 'fa-list-check', 'text' => 'Planner Part Master', 'route' => 'fc.planner.master', 'permission' => 'FC_PLN'],
                    ['icon' => 'fa-sitemap', 'text' => 'Division Part Master', 'route' => 'fc.divisionPartMaster.index', 'permission' => ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9']],
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
                    ['icon' => 'fa-calendar-check', 'text' => 'Order By Due Date', 'route' => 'wos.order_due_date'],
                    ['icon' => 'fa-chart-column', 'text' => 'Weekly Summary', 'route' => 'wos.sales_weekly', 'permission' => 'WOS'],
                    ['icon' => 'fa-table', 'text' => 'Delivery Volume', 'route' => 'wos.sales_unit_summary', 'permission' => 'WDV'],
                    ['icon' => 'fa-scale-balanced', 'text' => 'Customer SO vs Invoice', 'route' => 'wos.customer_order_invoice.index', 'permission' => ['WOS', 'WDV']],
                    // Deadstock Dashboard/Review ย้ายไป menu.auth แล้ว (เปิดให้ทุกคนที่ login เห็น)
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
                    ['icon' => 'fa-user-plus', 'text' => 'ลงทะเบียนพนักงาน', 'route' => 'adminweb.users.register', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-building', 'text' => 'เพิ่มแผนก', 'route' => 'adminweb.dept.create', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-user-tie', 'text' => 'จัดการหัวหน้าแผนก', 'route' => 'adminweb.deptmgr.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-briefcase', 'text' => 'จัดการตำแหน่งในแผนก', 'route' => 'adminweb.deptroles.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-people-arrows', 'text' => 'ย้ายแผนก/ตำแหน่งพนักงาน', 'route' => 'adminweb.user-department-assignments.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-key', 'text' => 'เพิ่มสิทธิ์', 'route' => 'adminweb.roles.create', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-key', 'text' => 'กำหนดสิทธิ์ให้ User', 'route' => 'adminweb.user-permissions.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-list-check', 'text' => 'ประวัติการใช้งาน (Log)', 'route' => 'adminweb.activity-logs.index', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-broom', 'text' => 'ล้าง Cache', 'route' => 'adminweb.cache.clear', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-rotate', 'text' => 'Dump Autoload', 'route' => 'adminweb.autoload.dump', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-gears', 'text' => 'Deadstock Config', 'route' => 'adminweb.deadstock.config', 'permission' => 'ADMINWEB'],
                    ['icon' => 'fa-stethoscope', 'text' => 'ตรวจ Path Deadstock', 'route' => 'adminweb.deadstock.diag', 'permission' => 'ADMINWEB'],

                ],
            ],
        ],
    ],
];
