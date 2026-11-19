<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // ป้ายบอกฐาน SQL Server ที่ใช้อยู่ (แสดงเฉพาะเครื่องที่ไม่ใช่ production)
        $dbBadge = null;
        if (! app()->environment('production')) {
            if (config('database.targets_prod')) {
                $dbBadge = ['label' => 'PROD', 'class' => 'bg-danger'];
            } elseif (config('database.profile')) {
                $dbBadge = ['label' => strtoupper(config('database.profile')), 'class' => 'bg-success'];
            } else {
                $dbBadge = ['label' => config('database.connections.sqlsrv_menam.database'), 'class' => 'bg-secondary'];
            }
        }
    @endphp
    <title>{{ $dbBadge ? '[' . $dbBadge['label'] . '] ' : '' }}@yield('title', 'Menam Workflow Online')</title>
    @auth
        {{-- เก็บ JavaScript error ส่งไป server (ต้องโหลดก่อน script อื่นเพื่อจับ error ได้ครบ) --}}
        <script src="{{ asset('js/client-error-reporter.js') }}?v={{ filemtime(public_path('js/client-error-reporter.js')) }}"
            data-endpoint="{{ route('client-errors.store') }}"></script>
    @endauth


    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32" type="image/png">
    <link rel="icon" href="{{ asset('favicon-16.png') }}" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

    <!-- Local vendor CSS for intranet/offline clients -->
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/fontawesome/css/all.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/tom-select/css/tom-select.bootstrap5.min.css') }}">
    {{-- dhtmlxgantt โหลดเฉพาะหน้า formps/gantt; frappe-gantt ไม่มีหน้าไหนใช้แล้ว --}}
    <link rel="stylesheet" href="{{ asset('css/layout-base.css') }}?v={{ filemtime(public_path('css/layout-base.css')) }}">

    @yield('styles')
    @stack('styles')
</head>

<body>
    {{-- เตือนเมื่อเครื่อง local ชี้ฐาน PROD (ดู DB_PROFILE ใน config/database.php) --}}
    @if (config('database.targets_prod') && ! app()->environment('production'))
        <div class="position-fixed top-0 start-50 translate-middle-x mt-1 px-3 py-1 rounded-pill bg-danger text-white fw-bold shadow"
            style="z-index: 2000; pointer-events: none; font-size: .85rem;">
            <i class="fas fa-triangle-exclamation me-1"></i>
            LOCAL กำลังใช้ฐาน PROD ({{ config('database.connections.sqlsrv_menam.database') }})
        </div>
    @endif

    <div class="container-fluid h-100">
        <div class="row h-100">

            {{-- Sidebar --}}
            @include('layouts._sidebar')

            {{-- Main / Public Content --}}
            <div class="col-md-9 col-lg-10 h-100 overflow-auto main-col">
                <div class="main-content h-100 p-4 overflow-auto">

                    {{-- Header (เมื่อล็อกอิน) --}}

                    <div class="d-flex justify-content-between align-items-center mb-4">

                        <div>
                            <h2 class="mb-0">@yield('page-title', 'หน้าหลัก')</h2>
                            @auth
                                <p class="text-muted mb-0">ยินดีต้อนรับ, {{ auth()->user()->name }}!</p>
                            @endauth
                        </div>

                        @auth
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown">
                                    <i class="fas fa-user me-2"></i>{{ auth()->user()->name }}
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i
                                                class="fas fa-user-cog me-2"></i>แก้ไขข้อมูลส่วนตัว</a>
                                    </li>
                                    <li><a class="dropdown-item" href="{{ route('profile.change-password') }}"><i
                                                class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน</a>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li><a class="dropdown-item" href="#"
                                            onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                            <i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ
                                        </a></li>
                                </ul>
                            </div>
                        @endauth
                    </div>


                    {{-- Flash & Validation Errors → SweetAlert toast (จุดเดียวทั้งระบบ) --}}
                    @if (session('success') || session('error') || $errors->any())
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                if (!window.Swal) return;
                                // ถ้า scroll-safe-swal.js โหลดไม่ขึ้น ให้ fallback เป็น Swal ปกติ (toast จะไม่หายเงียบ)
                                var SwalLib = window.ScrollSafeSwal || window.Swal;
                                var Toast = SwalLib.mixin({
                                    toast: true,
                                    position: 'top-end',
                                    showConfirmButton: false,
                                    timer: 4000,
                                    timerProgressBar: true,
                                    didOpen: function (el) {
                                        el.addEventListener('mouseenter', Swal.stopTimer);
                                        el.addEventListener('mouseleave', Swal.resumeTimer);
                                    },
                                });
                                @if (session('success'))
                                    Toast.fire({
                                        icon: 'success',
                                        @if (session('postpone_view_url'))
                                            timer: 8000,
                                            html: @json(session('success')) +
                                                ' <a href="' + @json(session('postpone_view_url')) +
                                                '" class="fw-semibold text-decoration-underline">ดูแผนใหม่</a>',
                                        @else
                                            title: @json(session('success')),
                                        @endif
                                    });
                                @endif
                                @if (session('error'))
                                    Toast.fire({ icon: 'error', timer: 6000, title: @json(session('error')) });
                                @endif
                                @if ($errors->any())
                                    Toast.fire({ icon: 'error', timer: 6000, title: @json(implode("\n", $errors->all())) });
                                @endif
                            });
                        </script>
                    @endif

                    {{-- Page Content --}}
                    @yield('content')

                    <footer class="mt-auto pt-3 text-muted small">
                        <hr>
                        <p style="font-size: 12px; color: gray;">Version {{ config('app.version') }} |
                            @if ($dbBadge)
                                <span class="badge {{ $dbBadge['class'] }} ms-1"
                                    title="SQL Server: {{ config('database.connections.sqlsrv_menam.database') }}">DB: {{ $dbBadge['label'] }}</span>
                            @endif
                            ติดต่อผู้ดูแลระบบ:
                            nitiphan@menamstainless.co.th</p>
                    </footer>
                </div>
            </div>
        </div>
    </div>

    {{-- Logout Form --}}
    <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none;">
        @csrf
    </form>

    <!-- Local vendor JS for intranet/offline clients -->
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendor/tom-select/js/tom-select.complete.min.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('vendor/chartjs-plugin-datalabels/chartjs-plugin-datalabels.min.js') }}"></script>
    @stack('scripts')
</body>



</html>
