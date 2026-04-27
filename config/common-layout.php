<?php
return [
    'project_name' => [
        'guest' => env('PROJECT_NAME_GUEST', 'Menam Workflow Online'),
        'auth'  => env('PROJECT_NAME_AUTH', 'Menam Workflow Online'),
    ],

    'menu' => [
        'guest' => [
            ['icon' => 'fa-right-to-bracket', 'text' => 'เข้าสู่ระบบ', 'route' => 'login'],
            ['icon' => 'fa-user-plus', 'text' => 'สมัครสมาชิก', 'route' => 'register'],
        ],
        'auth' => [
            [
                'icon'     => 'fa-file-alt',
                'text'     => 'PR Online',
                'permission' => 'pr',
                'children' => [
                    ['icon' => 'fa-file-alt', 'text' => 'คำขอของฉัน', 'route' => 'login',   'permission' => 'pr'],
                    ['icon' => 'fa-plus',    'text' => 'สร้างคำขอใหม่', 'route' => 'login', 'permission' => 'pr'],
                    ['icon' => 'fa-list',    'text' => 'คำขอทั้งหมด',  'route' => 'login',  'permission' => 'pr'],
                ],
            ],
            [
                'icon' => 'fa fa-info-circle',
                'text' => 'Performance Appraisal Online',
                'permission' => 'pa',
                'children' => [
                    ['icon' => 'fa-file-alt', 'text' => 'คำขอของฉัน', 'route' => 'login',   'permission' => 'pa'],
                    ['icon' => 'fa-plus',    'text' => 'สร้างคำขอใหม่', 'route' => 'login', 'permission' => 'pa'],
                    ['icon' => 'fa-list',    'text' => 'คำขอทั้งหมด',  'route' => 'login',  'permission' => 'pa'],
                ],
            ]

        ],
        'admin' => [
            [
                'icon'      => 'fa-cog',
                'text'      => 'PR Admin',
                'permission' => 'pradmin',
                'children' => [
                    ['icon' => 'fa-cog',   'text' => 'หน้าหลักผู้ดูแล', 'route' => 'login', 'permission' => 'pradmin'],
                    ['icon' => 'fa-tasks', 'text' => 'จัดการคำขอทั้งหมด', 'route' => 'login',  'permission' => 'pradmin'],
                    ['icon' => 'fa-users', 'text' => 'ผู้ใช้งาน',        'route' => 'login',     'permission' => 'pradmin'],
                ],
            ],
        ],
    ],
];
