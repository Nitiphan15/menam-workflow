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

    <!-- Local vendor CSS for intranet/offline clients -->
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/fontawesome/css/all.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/tom-select/css/tom-select.bootstrap5.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/frappe-gantt/frappe-gantt.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/dhtmlxgantt/dhtmlxgantt.css') }}">
    <link rel="stylesheet" href="{{ asset('css/layout-base.css') }}?v={{ filemtime(public_path('css/layout-base.css')) }}">

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

    <!-- Local vendor JS for intranet/offline clients -->
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendor/tom-select/js/tom-select.complete.min.js') }}"></script>
    <script src="{{ asset('vendor/frappe-gantt/frappe-gantt.min.js') }}"></script>
    <script src="{{ asset('vendor/dhtmlxgantt/dhtmlxgantt.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('vendor/chartjs-plugin-datalabels/chartjs-plugin-datalabels.min.js') }}"></script>
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
