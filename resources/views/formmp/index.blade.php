{{-- resources/views/reports/ap-index.blade.php --}}
@extends('layouts.layout')

@section('title', 'รายงานค่าใช้จ่ายเครื่องจักร')
@section('page-title', 'รายงานค่าใช้จ่ายเครื่องจักร')

@section('content')
    <style>
        .page-wrapper {
            max-width: 1400px;
            /* เดิม 1200 */
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        .group-header {
            background: #f5f7fb;
            font-weight: 600;
        }

        .group-summary {
            background: #fafafa;
            font-weight: 600;
            border-top: 1px solid #e0e0e0;
        }

        .card {
            background: #ffffff;
            border-radius: 18px;
            padding: 20px 24px;
            box-shadow:
                0 10px 15px -3px rgba(15, 23, 42, 0.12),
                0 4px 6px -2px rgba(15, 23, 42, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 16px;
            gap: 16px;
        }

        .card-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #0f172a;
        }

        .card-subtitle {
            font-size: 0.85rem;
            color: #64748b;
        }


        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin: 16px 0 8px;
        }

        .summary-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 16px 20px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .summary-card-label {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 6px;
        }

        .summary-card-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .summary-card-sub {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .table-wrapper {
            max-width: 100%;
            overflow-x: auto;
        }

        /* กันหัวตารางให้ติดด้านบนตอน scroll ลง */
        .data-table thead th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            z-index: 2;
        }

        .col-product,
        .col-notes {
            max-width: 350px;
            white-space: normal !important;
            word-break: break-word;
            line-height: 1.35;
        }



        .filter-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-label {
            font-size: 0.8rem;
            color: #6b7280;
        }

        .filter-input {
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            padding: 6px 12px;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.15s ease;
        }

        .filter-input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 1px rgba(79, 70, 229, 0.4);
        }

        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 4px;
        }

        .btn {
            border-radius: 999px;
            border: none;
            padding: 7px 16px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #ffffff;
        }

        .btn-outline {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            color: #4b5563;
        }

        .btn-primary:hover {
            filter: brightness(1.05);
        }

        .btn-outline:hover {
            background: #f9fafb;
        }

        .table-wrapper {
            margin-top: 10px;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .data-table thead {
            background: #f9fafb;
        }

        .data-table th,
        .data-table td {
            padding: 8px 10px;
            text-align: left;
            white-space: nowrap;
        }

        .data-table th {
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.75rem;
        }

        .data-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .data-table tbody tr:hover {
            background: #eef2ff;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-blue {
            background: #e0f2fe;
            color: #0369a1;
        }

        .badge-purple {
            background: #ede9fe;
            color: #5b21b6;
        }

        .text-right {
            text-align: right;
        }

        .text-muted {
            color: #9ca3af;
        }

        .summary-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            font-size: 0.8rem;
        }

        .summary-total {
            font-weight: 600;
            color: #111827;
        }

        .summary-label {
            color: #6b7280;
        }

        .pill-link {
            display: inline-flex;
            padding: 3px 10px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
        }

        .col-po {
            white-space: normal !important;
            line-height: 1.25;
        }


        @media (max-width: 1024px) {
            .filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .table-wrapper {
                overflow-x: auto;
            }
        }
    </style>

    <div class="page-wrapper">
        <div class="card">
            <div class="card-header">
                <div>

                    <div class="card-subtitle">
                        ช่วงวันที่:
                        <strong>{{ $fromDate }}</strong>
                        –
                        <strong>{{ $toDate }}</strong>
                    </div>
                </div>
                <div class="card-subtitle">
                    รายการทั้งหมด: <strong>{{ number_format($rows->total()) }}</strong> รายการ
                </div>
            </div>

            {{-- Filter Form --}}
            <form method="GET" action="{{ route('mp.index') }}">
                <div class="filter-form">

                    <div class="filter-group">
                        <label class="filter-label">Inv. Number</label>
                        <input type="text" name="invnumber" value="{{ $filters['invnumber'] ?? '' }}"
                            class="filter-input" placeholder="">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Ord. Number</label>
                        <input type="text" name="ordnumber" value="{{ $filters['ordnumber'] ?? '' }}"
                            class="filter-input" placeholder=" ">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">วันที่ (ตรงตัว)</label>
                        <input type="date" name="exact_transdate" value="{{ $filters['exact_transdate'] ?? '' }}"
                            class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">จากวันที่ (From)</label>
                        <input type="date" name="from_date" value="{{ $fromDate }}" class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">ถึงวันที่ (To)</label>
                        <input type="date" name="to_date" value="{{ $toDate }}" class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">แหล่งข้อมูล (Site)</label>

                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="site_plus" name="site_plus" value="1"
                                {{ request()->has('site_plus') ? (request('site_plus') ? 'checked' : '') : 'checked' }}>
                            <label class="form-check-label" for="site_plus">Plus</label>
                        </div>

                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="site_wire" name="site_wire" value="1"
                                {{ request()->has('site_wire') ? (request('site_wire') ? 'checked' : '') : 'checked' }}>
                            <label class="form-filter-label" for="site_wire">Wire</label>
                        </div>
                    </div>


                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        🔍 ค้นหา
                    </button>
                    <a href="{{ route('mp.index') }}" class="btn btn-outline">
                        ✨ ล้างเงื่อนไข
                    </a>
                    <a href="{{ route('mp.export', request()->query()) }}" class="btn btn-success">
                        📤 Export Excel
                    </a>
                </div>


            </form>

            <div class="summary-cards">

                <div class="summary-card">
                    <div class="summary-card-label">ยอดรวมทั้งหมด</div>
                    <div class="summary-card-value">{{ number_format($totalPrice, 2) }}</div>
                    <div class="summary-card-sub">บาท</div>
                </div>

                <div class="summary-card">
                    <div class="summary-card-label">จำนวนเครื่องที่มีรายการ</div>
                    <div class="summary-card-value">{{ $groupedRows->count() }}</div>
                    <div class="summary-card-sub">เครื่อง</div>
                </div>

                <div class="summary-card">
                    <div class="summary-card-label">จำนวนรายการทั้งหมด</div>
                    <div class="summary-card-value">{{ number_format($rows->total()) }}</div>
                    <div class="summary-card-sub">
                        แสดงหน้า {{ $rows->currentPage() }} / {{ $rows->lastPage() }}
                    </div>
                </div>
            </div>



            {{-- Table --}}
            <div class="table-wrapper">
                @if ($rows->isEmpty())
                    <div class="summary-bar">
                        <span class="summary-label">ไม่มีข้อมูลตามเงื่อนไขที่เลือก</span>
                    </div>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>ใบกำกับเลขที่</th>
                                <th>รายการเลขที่</th>
                                <th>บัญชี</th>
                                <th class="col-product">ชื่อสินค้า</th>
                                <th>จำนวน</th>
                                <th>ราคาต่อหน่วย</th>
                                <th>ราคา</th>
                                <th>หมายเหตุ</th>
                                <th>แผนก</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($groupedRows as $machineName => $items)
                                {{-- หัวกลุ่มเครื่อง --}}
                                <tr class="group-header">
                                    <td colspan="10">
                                        @if ($machineName === 'ไม่มีชื่อเครื่องจักร')
                                            <strong>ไม่มีชื่อเครื่องจักร (ไม่ได้ระบุในหมายเหตุ)</strong>
                                        @else
                                            เครื่องจักร: <strong>{{ $machineName }}</strong>
                                        @endif
                                    </td>
                                </tr>

                                @php
                                    $groupQty = $items->sum('qty');
                                    $groupTotal = $items->sum('price');
                                @endphp

                                {{-- รายการในกลุ่ม --}}
                                @foreach ($items as $row)
                                    <tr>
                                        <td>{{ $row->transdate }}</td>
                                        <td><span class="pill-link">{{ $row->invnumber }}</span></td>
                                        <td class="col-po">{!! str_replace(',', '<br>', $row->ordnumber) !!}</td>

                                        <td>{{ $row->chart_description }}</td>
                                        <td class="col-product" title="{{ $row->f3 }}">
                                            {{ $row->f3 }}
                                        </td>
                                        <td class="text-right">{{ number_format($row->qty, 2) }}</td>
                                        <td class="text-right">{{ number_format($row->sellprice, 2) }}</td>
                                        <td class="text-right">{{ number_format($row->price, 2) }}</td>
                                        <td class="col-notes" title="{{ $row->notes }}">
                                            {{ $row->notes }}
                                        </td>
                                        <td class="text-center">{{ $row->f1 }}</td>
                                    </tr>
                                @endforeach

                                {{-- แถวสรุปของเครื่องนี้ --}}
                                <tr class="group-summary">
                                    <td colspan="5" class="text-right"><strong>รวมเครื่อง {{ $machineName }}</strong>
                                    </td>
                                    <td class="text-right"><strong>{{ number_format($groupQty, 2) }}</strong></td>
                                    <td></td>
                                    <td class="text-right"><strong>{{ number_format($groupTotal, 2) }}</strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="summary-bar">
                        <div class="summary-label">
                            รวมรายการทั้งหมด:
                            <strong>{{ number_format($rows->total()) }}</strong> แถว
                            (แสดง {{ $rows->firstItem() }} – {{ $rows->lastItem() }})
                        </div>
                        <div class="summary-total">
                            ยอดรวม Amount: {{ number_format($totalPrice, 2) }}
                        </div>
                    </div>

                    <div style="margin-top: 8px; display: flex; justify-content: flex-end;">
                        {{ $rows->onEachSide(1)->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
