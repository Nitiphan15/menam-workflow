@php
    $fmtTon = $fmtTon ?? fn($v) => number_format((float) $v, 2);
    $fmtBaht = $fmtBaht ?? fn($v) => number_format(((float) $v) * 1000, 2);
@endphp

@forelse($monthBlocks as $blk)
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="fw-semibold">
                เดือน {{ $blk['month_name'] }}
            </div>
            <div class="text-muted small">
                สรุปรวม
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-center">
                        <th style="min-width:240px;">กลุ่มฝ่ายขาย</th>
                        <th style="min-width:95px;">สัปดาห์ 1</th>
                        <th style="min-width:95px;">สัปดาห์ 2</th>
                        <th style="min-width:95px;">สัปดาห์ 3</th>
                        <th style="min-width:95px;">สัปดาห์ 4</th>
                        <th style="min-width:95px;">สัปดาห์ 5</th>
                        <th style="min-width:110px;">รวมทั้งเดือน</th>
                        <th style="min-width:110px;">เป้าหมาย</th>
                        <th style="min-width:90px;">ทำได้</th>
                        <th style="min-width:70px;">หน่วย</th>
                        <th style="min-width:120px;">รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blk['rows'] as $r)
                        @php
                            $isTotal = $r->group_code === 'TOTAL';
                            $fmt = $r->is_baht ? $fmtBaht : $fmtTon;
                            $unit = $r->is_baht ? 'บาท' : 'ตัน';
                            $target = (float) ($r->target_month ?? 0);
                            $total = (float) ($r->total ?? 0);
                            $achText = '-';

                            if (!$isTotal && $target > 0) {
                                $achText = number_format(($total / $target) * 100, 1) . '%';
                            }
                        @endphp

                        <tr class="{{ $isTotal ? 'table-warning' : '' }}">
                            <td class="fw-semibold">{{ $r->group_name }}</td>
                            <td class="text-end">{{ $fmt($r->qtyw1) }}</td>
                            <td class="text-end">{{ $fmt($r->qtyw2) }}</td>
                            <td class="text-end">{{ $fmt($r->qtyw3) }}</td>
                            <td class="text-end">{{ $fmt($r->qtyw4) }}</td>
                            <td class="text-end">{{ $fmt($r->qtyw5) }}</td>
                            <td class="text-end fw-bold">{{ $fmt($r->total) }}</td>
                            <td class="text-end fw-bold">{{ $fmt($target) }}</td>
                            <td class="text-center">{{ $achText }}</td>
                            <td class="text-center">{{ $unit }}</td>

                            @php
                                $ids = is_array($r->requester_ids ?? null) ? $r->requester_ids : [];
                                $id1 = $ids[0] ?? null;

                                $qs = http_build_query([
                                    'from' => $from,
                                    'to' => $to,
                                    'month' => $blk['month_number'],
                                ]);

                                $href = $id1
                                    ? route('wos.sales_weekly.detail', ['salesId' => $id1]) . '?' . $qs
                                    : null;
                            @endphp

                            <td class="text-center">
                                @if (!$isTotal && $href)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $href }}">ดูรายการ</a>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="card card-body text-center text-muted py-5">
        No data
    </div>
@endforelse
