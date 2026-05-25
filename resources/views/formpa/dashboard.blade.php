@extends('layouts.layout')

@section('title', 'PA Overview')
@section('page-title', 'PA Online (Accounting)')

@section('content')
    <div class="container-fluid px-3 pa-dashboard">
        <h4 class="mb-3">ภาพรวมการประเมิน</h4>

        {{-- Flash --}}
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif
        @if (session('err'))
            <div class="alert alert-danger">{{ session('err') }}</div>
        @endif

        {{-- สรุปตัวเลขการกระจายคะแนน (อ้างอิงกราฟระฆังคว่ำ) --}}
        <div class="row g-3">
            @php
                // ตัวแปรจาก Controller:
                // $total, $dist = ['excellent'=>n,'good'=>n,'avg'=>n,'improve'=>n,'fail'=>n]
                $total = $total ?? array_sum($dist ?? []);
                $p = fn($n) => $total ? number_format(($n * 100) / $total, 1) : '0.0';
            @endphp

            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <strong>การกระจายผลงาน (Distribution)</strong>
                        <a href="{{ route('pa.index') }}" class="btn btn-sm btn-primary">ประเมินพนักงาน</a>
                    </div>
                    <div class="card-body">
                        <canvas id="distChart" height="120"></canvas>
                        <div class="row text-center mt-3 small">
                            <div class="col"><span
                                    class="text-success fw-semibold">ดีเลิศ</span><br>{{ $dist['excellent'] ?? 0 }} คน •
                                {{ $p($dist['excellent'] ?? 0) }}%</div>
                            <div class="col"><span class="text-success">ดี</span><br>{{ $dist['good'] ?? 0 }} คน •
                                {{ $p($dist['good'] ?? 0) }}%</div>
                            <div class="col"><span class="text-secondary">เฉลี่ย</span><br>{{ $dist['avg'] ?? 0 }} คน •
                                {{ $p($dist['avg'] ?? 0) }}%</div>
                            <div class="col"><span
                                    class="text-warning">ต้องปรับปรุง</span><br>{{ $dist['improve'] ?? 0 }} คน •
                                {{ $p($dist['improve'] ?? 0) }}%</div>
                            <div class="col"><span class="text-danger">รับผลงานไม่ได้</span><br>{{ $dist['fail'] ?? 0 }}
                                คน • {{ $p($dist['fail'] ?? 0) }}%</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- การ์ดสรุปย่อย ๆ --}}
            <div class="col-12 col-xl-6">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="text-muted">จำนวนผู้ประเมิน</div>
                                <div class="display-6">{{ $total }}</div>
                                <div class="small text-secondary">รอบปัจจุบัน</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="text-muted">ค่าเฉลี่ยรวม</div>
                                <div class="display-6">{{ $avgScore ?? '-' }}</div>
                                <div class="small text-secondary">คำนวณจากน้ำหนักหัวข้อย่อย</div>
                            </div>
                        </div>
                    </div>

                    {{-- ตารางล่าสุด (ถ้ามี) --}}
                    @isset($latest)
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><strong>รายการล่าสุด</strong></div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>พนักงาน</th>
                                                <th>คะแนนรวม</th>
                                                <th>วันที่</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($latest as $row)
                                                <tr>
                                                    <td>{{ $row->name }}</td>
                                                    <td>{{ $row->total_score }}</td>
                                                    <td>{{ \Carbon\Carbon::parse($row->updated_at)->format('d/m/Y H:i') }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="3" class="text-center text-muted py-3">- ไม่มีข้อมูล -</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endisset
                </div>
            </div>
        </div>
    </div>

    <script>
        const ctx = document.getElementById('distChart');
        const data = {
            labels: ['ดีเลิศ (~5%)', 'ดี (10-15%)', 'เฉลี่ย (60-70%)', 'ต้องปรับปรุง (10-15%)', 'รับไม่ได้ (~5%)'],
            datasets: [{
                type: 'bar',
                data: [
                    {{ $dist['excellent'] ?? 0 }},
                    {{ $dist['good'] ?? 0 }},
                    {{ $dist['avg'] ?? 0 }},
                    {{ $dist['improve'] ?? 0 }},
                    {{ $dist['fail'] ?? 0 }},
                ]
            }]
        };
        new Chart(ctx, {
            type: 'bar',
            data,
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    </script>
@endsection
