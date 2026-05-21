{{-- resources/views/pp/index.blade.php --}}
@extends('layouts.layout')

@section('title', 'ฟอร์มวางแผนการผลิต')
@section('page-title', 'ฟอร์มวางแผนการผลิต')

@section('content')
    @php
        // กันพัง + ค่าเริ่มต้น
        $q = $q ?? request('q');
        $status = request('status');
        $site = request('site');

        // ตัวเลือกฟิลเตอร์
        $siteOptions = $siteOptions ?? collect($list)->pluck('data_site')->filter()->unique()->values();
        $statusOptions = $statusOptions ?? collect(['Planner Submit', 'Sale Submit', 'Closed', 'Voided']);
        //($status, $statusOptions);
        // ตัวนับ
        $counts = $counts ?? ['pending' => null, 'mine' => null, 'all' => null];

        // สร้างลิงก์คง query อื่น ๆ
        $tabUrl = fn($bx) => request()->fullUrlWithQuery(['box' => $bx, 'page' => 1]);
        $keepQuery = fn($arr = []) => request()->fullUrlWithQuery(array_merge($arr, ['page' => 1]));

        $approverIds = explode(',', $row->wf_approver_ids ?? '');
        $canApprove = in_array(auth()->id(), $approverIds);

    @endphp

    <div class="container-xxl py-4">

        {{-- หัวเรื่อง + แท็บ --}}
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3 mb-3">
            <h1 class="h5 mb-0">
                {{ $pageTitle }} —
                @if ($box === 'pending')
                    เอกสารที่ต้องดำเนินการ
                @elseif ($box === 'mine')
                    เอกสารของฉัน
                @else
                    เอกสารทั้งหมด
                @endif
            </h1>
        </div>

        {{-- ฟิลเตอร์ --}}
        <form method="get" class="row gy-2 gx-2 align-items-end mb-3">
            <input type="hidden" name="box" value="{{ $box }}">

            <div class="col-12 col-sm-5 col-md-4 col-lg-3">
                <label class="form-label small text-secondary">ค้นหา</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input name="q" value="{{ $q }}" class="form-control"
                        placeholder="Part No / ลูกค้า / Doc No">
                </div>
            </div>

            <div class="col-6 col-sm-3 col-md-2 col-lg-2">
                <label class="form-label small text-secondary">ไซต์</label>
                <select name="site" class="form-select">
                    <option value="">ทั้งหมด</option>
                    @foreach ($siteOptions as $s)
                        <option value="{{ $s }}" @selected($site === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2 col-lg-2">
                <label class="form-label small text-secondary">สถานะ</label>
                <select name="status" class="form-select">
                    <option value="">ทั้งหมด</option>
                    @foreach ($statusOptions as $st)
                        @php
                            //dump($st);
                        @endphp
                        <option value="{{ $st }}" @selected($status == $st)>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-auto">
                <button class="btn btn-dark px-4">ค้นหา</button>
                <button type="submit" formaction="{{ route('pp.export') }}" class="btn btn-success ms-1">
                    Export Excel
                </button>
                @if (request()->hasAny(['q', 'site', 'status']))
                    <a href="{{ request()->fullUrlWithQuery(['q' => null, 'site' => null, 'status' => null, 'page' => 1]) }}"
                        class="btn btn-outline-secondary ms-1">ล้างตัวกรอง</a>
                @endif
            </div>
        </form>

        {{-- ตาราง/กล่องแจ้งเตือน --}}
        @if (
            ($list instanceof \Illuminate\Contracts\Support\Htmlable && $list->isEmpty()) ||
                (is_countable($list) && count($list) === 0))
            <div class="alert alert-info d-flex align-items-center" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                ไม่มีรายการรอดำเนินการ
            </div>
        @else
            <div class="table-responsive bg-white border rounded-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-nowrap">#</th>
                            <th class="text-nowrap">Doc No</th>
                            <th class="text-nowrap">Req Date</th>
                            <th class="text-nowrap">Part No</th>
                            <th class="text-nowrap">Customer</th>
                            <th class="text-nowrap">สถานะ</th>
                            <th class="text-nowrap">ผู้ยื่น</th>
                            <th class="text-nowrap">อัปเดต</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($list as $i => $row)
                            @php
                                $st = (string) ($row->wf_status ?? ($row->wfForm?->status ?? 'pending'));

                                $rowNum = method_exists($list, 'firstItem') ? $list->firstItem() + $i : $i + 1;
                                //dump($list[$i]->wf_approver_ids);

                                $approverIds = explode(',', $row->wf_approver_ids ?? '');

                                $canApprove = in_array(auth()->id(), $approverIds);
                                //dump($row->wf_current_step, $canApprove);
                                if ($canApprove) {
                                    if ($row->wf_current_step == 1) {
                                        $openUrl = route('pp.sales', $row->form_id ?? ($row->wfForm?->id ?? $row->id));
                                    } elseif ($row->wf_current_step == 2) {
                                        $openUrl = route(
                                            'pp.planner',
                                            $row->form_id ?? ($row->wfForm?->id ?? $row->id),
                                        );
                                    }
                                } else {
                                    $openUrl = route('pp.view', $row->form_id ?? ($row->wfForm?->id ?? $row->id));
                                }
                            @endphp
                            <tr>
                                <td class="text-muted">{{ $rowNum }}</td>

                                <td class="fw-semibold">
                                    <a
                                        href="{{ $openUrl }}"class="link-dark link-underline-opacity-0 link-underline-opacity-75-hover">
                                        {{ $row->docu_no ?? '-' }}
                                    </a>

                                    @if (!empty($row->subject))
                                        <div class="small text-secondary">{{ $row->subject }}</div>
                                    @endif
                                </td>

                                <td>{{ optional($row->req_date)->format('d/m/Y') }}</td>
                                <td>{{ $row->part_no }}</td>
                                <td>{{ $row->customer }}</td>

                                <td>
                                    {{ $row->wf_current_step_name }}
                                </td>

                                <td>{{ $row->requester->name ?? '-' }}</td>
                                <td class="text-muted">{{ optional($row->updated_at)->format('d/m/Y H:i') }}</td>

                                <td class="text-end">
                                    <a href="{{ $openUrl }}" class="btn btn-secondary btn-sm">
                                        View
                                    </a>

                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- หน้าเพจ --}}
            <div class="mt-3">
                @if (method_exists($list, 'links'))
                    {{ $list->links() }}
                @endif
            </div>
        @endif
    </div>
@endsection
