<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Menam Workflow Online')</title>


    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32" type="image/png">
    <link rel="icon" href="{{ asset('favicon-16.png') }}" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Flatpickr CSS -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <!-- Tom Select -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.bootstrap5.min.css">
    <!-- Gantt Chart -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/frappe-gantt@0.6.1/dist/frappe-gantt.css">
    <!-- dhtmlxGantt -->
    <link rel="stylesheet" href="https://cdn.dhtmlx.com/gantt/edge/dhtmlxgantt.css">
    <style>
        html,
        body {
            height: 100%;
            margin: 0;
        }

        .sidebar {
            background-color: #34495E;
            min-height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: .75rem 1rem;
            margin: .25rem 0;
            border-radius: .375rem;
            display: flex;
            /* ✅ เพิ่ม */
            align-items: center;
            /* ✅ เพิ่ม */
            gap: .5rem;
            /* ✅ เพิ่ม */
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }

        .nav-link {
            position: relative;
        }

        /* ==============================
       ✅ เพิ่มสำหรับ Responsive มือถือ
       ============================== */
        @media (max-width: 767.98px) {

            /* กดง่ายขึ้น */
            .sidebar .nav-link {
                padding: .95rem 1.05rem;
                margin: .35rem 0;
                border-radius: .65rem;
                font-size: 1.05rem;
            }

            /* ไอคอนขยับนิดให้สวย */
            .sidebar .nav-link i {
                width: 20px;
                text-align: center;
                opacity: .95;
            }

            /* offcanvas body ใช้ sidebar เดิมได้ แต่ต้องไม่ fix สูงแบบ 100vh */
            .offcanvas .sidebar {
                min-height: auto;
                max-height: none;
                overflow-y: visible;
            }

            /* ลดช่องว่างโลโก้ */
            .sidebar .brand img {
                max-width: 210px;
            }

            .sidebar .brand h4 {
                font-size: 1.2rem;
            }
        }

        /* ==============================
       ✅ ถ้าใช้ Offcanvas: ทำให้สี header เข้ากับ sidebar
       ============================== */
        .offcanvas-header.sidebar {
            background-color: #34495E;
            border-bottom: 1px solid rgba(255, 255, 255, .12);
        }
    </style>

    @yield('styles')
    @stack('styles')
</head>

<body>
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
                                                class="fas fa-user-cog me-2"></i>แก้ไขข้อมูลส่วนตัว</a></li>
                                    <li><a class="dropdown-item" href="{{ route('profile.change-password') }}"><i
                                                class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน</a></li>
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


                    {{-- Flash & Validation Errors --}}
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            {{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $e)
                                    <li>{{ $e }}</li>
                                @endforeach
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    {{-- Page Content --}}
                    @yield('content')

                    <footer class="mt-auto pt-3 text-muted small">
                        <hr>
                        <p style="font-size: 12px; color: gray;">Version {{ config('app.version') }} |
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

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (ถ้าจำเป็น) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Sweet alert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Tom Select -->
    <script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
    <!-- Gantt Chart -->
    <script src="https://cdn.jsdelivr.net/npm/frappe-gantt@0.6.1/dist/frappe-gantt.min.js"></script>
    <!-- dhtmlxGantt -->
    <script src="https://cdn.dhtmlx.com/gantt/edge/dhtmlxgantt.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    @stack('scripts')
</body>


@push('scripts')
    <script>
        function toggleStatus(id, isActive) {

            let title = isActive ? 'ยืนยันการปิดการใช้งาน?' : 'ยืนยันการเปิดการใช้งาน?';
            let text = isActive ? 'ฟอร์มนี้จะถูกปิดการใช้งาน' : 'ฟอร์มนี้จะถูกเปิดใช้งาน';
            let confirmBtn = isActive ? 'ใช่, ปิดการใช้งาน' : 'ใช่, เปิดการใช้งาน';
            let confirmColor = isActive ? '#d33' : '#28a745';

            Swal.fire({
                title: title,
                text: text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: confirmBtn,
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: confirmColor,
                cancelButtonColor: '#6c757d',
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('toggle-form-' + id).submit();
                }
            });
        }
    </script>
@endpush

</html>
