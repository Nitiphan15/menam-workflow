@extends('layouts.layout')

@section('title', 'Shotblast Spare Parts Stock')
@section('page-title', 'Shotblast – Spare Parts Stock On Hand')

@section('content')
    <style>
        .shotblast-page {
            background: #f3f4f6;
            padding-bottom: 32px;
        }

        .shotblast-hero {
            background: radial-gradient(circle at top left, #1e3a8a 0, #020617 55%);
            color: #f9fafb;
            border-radius: 22px;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 22px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.35);
        }

        .shotblast-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 10% 0%, rgba(59, 130, 246, .26), transparent 55%),
                radial-gradient(circle at 90% 0%, rgba(56, 189, 248, .22), transparent 55%);
            mix-blend-mode: screen;
            opacity: .9;
        }

        .shotblast-hero-icon {
            z-index: 1;
            width: 86px;
            height: 86px;
            border-radius: 26px;
            background: rgba(15, 23, 42, 0.55);
            border: 1px solid rgba(148, 163, 184, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            backdrop-filter: blur(10px);
        }

        .shotblast-hero-text {
            z-index: 1;
        }

        .shotblast-hero-text h2 {
            margin: 4px 0 6px;
            font-weight: 800;
            letter-spacing: 0.03em;
        }

        .shotblast-hero-text p {
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.88;
        }

        .shotblast-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            padding: 3px 11px;
            border-radius: 999px;
            background: rgba(22, 163, 74, 0.16);
            color: #bbf7d0;
            border: 1px solid rgba(74, 222, 128, 0.6);
        }

        .shotblast-hero-actions {
            z-index: 1;
            margin-left: auto;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .shotblast-summary {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .shotblast-summary-card {
            background: #f9fafb;
            border-radius: 14px;
            padding: 10px 14px;
            min-width: 130px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.18);
        }

        .shotblast-summary-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
        }

        .shotblast-summary-value {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            /* สีกรมเข้ม อ่านง่าย */
        }

        .shotblast-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.10);
            padding: 18px 20px 6px;
            border: 1px solid #e5e7eb;
        }

        .shotblast-table-container {
            max-height: 540px;
            overflow: auto;
            border-radius: 12px;
        }

        .shotblast-table {
            margin-bottom: 0;
        }

        .shotblast-table thead {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f9fafb;
        }

        .shotblast-table thead th {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            border-bottom-width: 2px;
            border-color: #e5e7eb !important;
            color: #6b7280;
            white-space: nowrap;
        }

        .shotblast-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .shotblast-table tbody tr:hover {
            background-color: #ecfeff;
        }

        .shotblast-table tbody td {
            vertical-align: middle;
            font-size: 0.85rem;
            border-color: #e5e7eb;
        }

        .status-pill {
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-ok {
            background: rgba(22, 163, 74, 0.12);
            color: #15803d;
            border: 1px solid rgba(34, 197, 94, 0.6);
        }

        .status-low {
            background: rgba(248, 113, 113, 0.12);
            color: #b91c1c;
            border: 1px solid rgba(248, 113, 113, 0.7);
        }

        .required-number {
            font-weight: 600;
            color: #111827;
        }

        .diff-positive {
            color: #16a34a;
        }

        .diff-negative {
            color: #dc2626;
            font-weight: 600;
        }

        .cpa-link {
            font-weight: 600;
            text-decoration: none;
        }

        .cpa-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .shotblast-hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .shotblast-hero-actions {
                width: 100%;
                align-items: stretch;
            }

            .shotblast-summary {
                width: 100%;
            }
        }
    </style>

    <div class="container-fluid shotblast-page">

        @php
            $totalParts = $rows->count();
            $lowCount = $rows
                ->filter(function ($r) {
                    return isset($r->required_qty, $r->onhand) && $r->onhand < $r->required_qty;
                })
                ->count();
            $lastUpdate = $rows->max('transdate');
        @endphp

        {{-- HERO --}}
        <div class="shotblast-hero">
            <div class="shotblast-hero-icon">
                <i class="fa-solid fa-gears"></i>
            </div>

            <div class="shotblast-hero-text">
                <div class="shotblast-badge">
                    <i class="fa-solid fa-shield-heart"></i>
                    <span>Spare parts stock safety – Shotblast</span>
                </div>
                <h2>Spare Parts Shotblast – Stock On Hand</h2>
                <p>รายงานสต็อกอะไหล่สำคัญของเครื่อง Shotblast เปรียบเทียบกับจำนวนสำรองขั้นต่ำที่ต้องมีในระบบ</p>
            </div>

            <div class="shotblast-hero-actions">
                <div class="shotblast-summary">
                    <div class="shotblast-summary-card">
                        <div class="shotblast-summary-label">จำนวนอะไหล่</div>
                        <div class="shotblast-summary-value">{{ $totalParts }} รายการ</div>
                    </div>
                    <div class="shotblast-summary-card">
                        <div class="shotblast-summary-label">ต่ำกว่ามาตรฐาน</div>
                        <div class="shotblast-summary-value">
                            {{ $lowCount }} รายการ
                        </div>
                    </div>
                    <div class="shotblast-summary-card">
                        <div class="shotblast-summary-label">อัปเดตล่าสุด</div>
                        <div class="shotblast-summary-value">
                            {{ $lastUpdate ? \Carbon\Carbon::parse($lastUpdate)->format('d/m/Y') : '-' }}
                        </div>
                    </div>
                </div>

                <a href="{{ route('ssc.export') }}" class="btn btn-success btn-lg mt-1">
                    <i class="fa-solid fa-file-excel me-1"></i>
                    Export Excel
                </a>
            </div>
        </div>

        {{-- TABLE CARD --}}
        <div class="shotblast-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Spare parts list</h5>
                <span class="text-muted small">
                    แสดงรายการอะไหล่สำหรับ Shotblast ทั้งหมด {{ $totalParts }} รายการ
                </span>
            </div>

            <div class="shotblast-table-container">
                <table class="table table-hover align-middle shotblast-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">No.</th>
                            <th>CPA Code</th>
                            <th>Name</th>
                            <th>Drawing No.</th>
                            <th class="text-end">ต้องมีสำรอง<br>(ชิ้น)</th>
                            <th class="text-end">Stock On hand</th>
                            <th class="text-end">ส่วนต่าง</th>
                            <th>สถานะ</th>
                            <th>นำไปใช้โดย / วันที่ล่าสุด</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $index => $row)
                            @php
                                $required = $row->required_qty;
                                $onhand = $row->onhand;
                                $diff = isset($required, $onhand) ? $onhand - $required : null;
                                $isLow = isset($required, $onhand) && $onhand < $required;
                            @endphp
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    {{-- ถ้า CPA กดไปหน้าอื่นได้ ค่อยใส่ href จริงทีหลัง --}}
                                    {{-- <a href="javascript:void(0)" class="cpa-link text-primary"> --}}
                                    {{ $row->partnumber }}
                                    {{-- </a> --}}
                                </td>
                                <td>{{ $row->description }}</td>
                                <td>{{ $row->drawing }}</td>
                                <td class="text-end required-number">{{ $required }}</td>
                                <td class="text-end">{{ $onhand }}</td>
                                <td class="text-end">
                                    @if (!is_null($diff))
                                        <span class="{{ $diff >= 0 ? 'diff-positive' : 'diff-negative' }}">
                                            {{ $diff }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($isLow)
                                        <span class="status-pill status-low">
                                            <i class="fa-solid fa-circle-exclamation"></i>
                                            ต่ำกว่าขั้นต่ำ
                                        </span>
                                    @else
                                        <span class="status-pill status-ok">
                                            <i class="fa-solid fa-circle-check"></i>
                                            เพียงพอ
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($row->employee_name || $row->transdate)
                                        <div class="small">
                                            @if ($row->employee_name)
                                                <strong>{{ $row->employee_name }}</strong>
                                            @endif
                                            @if ($row->transdate)
                                                <span class="text-muted">
                                                    · {{ \Carbon\Carbon::parse($row->transdate)->format('d/m/Y') }}
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td>{{ $row->notes }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    ไม่พบข้อมูลอะไหล่สำหรับ Shotblast
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
