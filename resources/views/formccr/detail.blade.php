@extends('layouts.layout')

@section('title', 'Cost Center - รายละเอียด')
@section('page-title', 'Cost Center Report')

@section('content')
    @php
        $rows = collect($rows ?? []);
        $kpis = $kpis ?? [];
        $fmt = fn($v, $d = 2) => is_null($v) || $v === '' ? '' : number_format((float) $v, $d);
        $fmtInt = fn($v) => number_format((int) $v);
        $classGroups = $rows->groupBy(fn($r) => $r->classnumber . '|' . ($r->class_group ?? ''));
    @endphp

    @include('formccr.partials.styles')

    <div class="ccr-wrap">

        <div class="ccr-card mb-3">
            <div class="ccr-card-header">
                <span><i class="fas fa-filter me-1 text-primary"></i> ตัวกรอง — รายละเอียด</span>
                <span class="ccr-meta">เลือก Class ก่อนเพื่อโหลด detail</span>
            </div>
            <form method="GET" action="{{ route('cost-center.detail') }}" class="ccr-card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">ปี</label>
                        <input type="number" name="year" class="form-control" value="{{ $year }}" min="2000" max="2100">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            <option value="ALL" {{ $site === 'ALL' ? 'selected' : '' }}>ALL</option>
                            <option value="WIRE" {{ $site === 'WIRE' ? 'selected' : '' }}>WIRE</option>
                            <option value="PLUS" {{ $site === 'PLUS' ? 'selected' : '' }}>PLUS</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label">Class</label>
                        <input type="text" name="classnumber" class="form-control" value="{{ $classnumber }}" list="ccrClassDetailOptions" placeholder="ระบุเลข class">
                        @if (!empty($classOptionsList) && $classOptionsList->isNotEmpty())
                            <datalist id="ccrClassDetailOptions">
                                @foreach ($classOptionsList as $opt)
                                    <option value="{{ $opt->value }}">{{ $opt->label }}</option>
                                @endforeach
                            </datalist>
                        @endif
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">To (exclusive)</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-1 col-md-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>

        @include('formccr.partials.header')

        @if (!empty($needClassPrompt))
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-1"></i>
                กรุณาเลือก <strong>Class</strong> ก่อนเปิดหน้ารายละเอียด เพื่อหลีกเลี่ยงการดึงข้อมูลที่มีจำนวนมากในครั้งเดียว
                หรือกลับไปที่หน้า <a href="{{ route('cost-center.summary', ['year' => $year, 'site' => $site]) }}">สรุปทั้งปี</a>
                แล้วคลิกปุ่ม Detail ในแถว class ที่ต้องการ
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi">
                    <div class="label">ยอดรวมในตาราง</div>
                    <div class="value">{{ $fmt($kpis['total_amount'] ?? 0) ?: '-' }}</div>
                    <div class="sub">บาท</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi kpi-amber">
                    <div class="label">จำนวนรายการ</div>
                    <div class="value">{{ $fmtInt($kpis['line_count'] ?? 0) }}</div>
                    <div class="sub">บรรทัด</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi kpi-green">
                    <div class="label">จำนวนเอกสาร</div>
                    <div class="value">{{ $fmtInt($kpis['invoice_count'] ?? 0) }}</div>
                    <div class="sub">ใบ</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi kpi-rose">
                    <div class="label">จำนวนบัญชี</div>
                    <div class="value">{{ $fmtInt($kpis['account_count'] ?? 0) }}</div>
                    <div class="sub">accounts</div>
                </div>
            </div>
        </div>

        <div class="ccr-card">
            <div class="ccr-card-header">
                <span><i class="fas fa-list me-1 text-primary"></i> รายละเอียดค่าใช้จ่ายตาม Cost Center</span>
                <span class="ccr-meta">{{ $fmtInt($kpis['line_count'] ?? 0) }} รายการ · เรียงตาม class → บัญชี → วันที่</span>
            </div>

            <div class="ccr-detail-scroll">
                <table class="table table-sm ccr-detail-table mb-0">
                    <thead>
                        <tr>
                            <th style="width:170px">Class</th>
                            <th style="width:100px">วันที่</th>
                            <th style="width:130px">เลขที่ใบ</th>
                            <th style="width:120px">ใบสั่งซื้อ</th>
                            <th style="width:200px">บัญชี</th>
                            <th style="width:240px">ชื่อสินค้า</th>
                            <th class="num" style="width:80px">จำนวน</th>
                            <th class="num" style="width:110px">ราคา/หน่วย</th>
                            <th class="num" style="width:120px">รวม</th>
                            <th class="num" style="width:130px">คงเหลือ</th>
                            <th style="width:60px">Site</th>
                            <th style="width:240px">หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($classGroups as $classKey => $classRows)
                            @php
                                $classFirst = $classRows->first();
                                $accountGroups = $classRows->groupBy('account_label');
                            @endphp
                            <tr class="ccr-subtotal">
                                <td colspan="12" style="background:#0f172a !important; color:#fff !important; font-size:.82rem;">
                                    <i class="fas fa-folder-open me-1"></i>
                                    {{ $classFirst->class_group ?? $classKey }}
                                </td>
                            </tr>

                            @foreach ($accountGroups as $accountLabel => $accountRows)
                                @php
                                    $accountTotal = (float) $accountRows->sum('line_amount');
                                    $accountCount = $accountRows->count();
                                    $lastRunningBalance = (float) ($accountRows->last()->running_balance ?? 0);
                                @endphp
                                @foreach ($accountRows as $row)
                                    <tr>
                                        <td>{{ $row->class_group ?? '' }}</td>
                                        <td>{{ $row->report_date }}</td>
                                        <td>{{ $row->invoice_no ?: '-' }}</td>
                                        <td>{{ $row->order_no ?: '' }}</td>
                                        <td>{{ $row->account_label }}</td>
                                        <td>{{ $row->item_name }}</td>
                                        <td class="num">{{ $fmt($row->qty, 0) }}</td>
                                        <td class="num">{{ $fmt($row->unit_price) }}</td>
                                        <td class="num">{{ $fmt($row->line_amount) }}</td>
                                        <td class="num">{{ $fmt($row->running_balance) }}</td>
                                        <td>{{ $row->site }}</td>
                                        <td>{{ $row->notes }}</td>
                                    </tr>
                                @endforeach
                                <tr class="ccr-subtotal">
                                    <td colspan="8" class="label">รวม {{ $accountCount }} รายการ — {{ $accountLabel }}</td>
                                    <td class="num">{{ $fmt($accountTotal) }}</td>
                                    <td class="num">{{ $fmt($lastRunningBalance) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td colspan="12" class="ccr-empty">
                                @if (!empty($needClassPrompt))
                                    เลือก class ในตัวกรองด้านบนก่อน
                                @else
                                    ไม่มีข้อมูลตามเงื่อนไขที่เลือก
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="ccr-grand-total">
                                <td colspan="8" class="text-end">รวมทั้งหมด</td>
                                <td class="num">{{ $fmt($kpis['total_amount'] ?? 0) }}</td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
@endsection
