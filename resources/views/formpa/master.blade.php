{{-- resources/views/pa/master.blade.php --}}
@extends('layouts.layout')

@section('title', 'PA Master')
@section('page-title', 'PA Master')

@section('content')
    <div class="container-fluid px-3 px-lg-4 pa-master">
        <h4 class="mb-3">แก้ไขหัวข้อประเมิน</h4>

        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif
        @if (session('err'))
            <div class="alert alert-danger">{{ session('err') }}</div>
        @endif

        <div class="row g-3">
            {{-- ================= SECTIONS (ไม่มีน้ำหนัก) ================= --}}
            <div class="col-12 col-xl-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <strong>หัวข้อใหญ่ (Sections)</strong>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#secCreate">+
                            เพิ่ม</button>
                    </div>

                    {{-- เพิ่มหัวข้อใหญ่: ไม่มีช่องน้ำหนักแล้ว --}}
                    <div id="secCreate" class="collapse p-3 border-bottom">
                        <form method="post" action="{{ route('paadmin.sections.store') }}" class="row g-2">
                            @csrf
                            <div class="col-4"><input name="code" class="form-control" placeholder="รหัส (เช่น SEC1)"
                                    required></div>
                            <div class="col-5"><input name="name" class="form-control" placeholder="ชื่อหัวข้อใหญ่"
                                    required></div>
                            <div class="col-3"><input type="number" name="order_no" class="form-control"
                                    placeholder="ลำดับ"></div>
                            <div class="col-12 form-check">
                                <input class="form-check-input" type="checkbox" id="sec_active" value="1"
                                    name="is_active" checked>
                                <label class="form-check-label" for="sec_active">ใช้งาน</label>
                            </div>
                            <div class="col-12"><button class="btn btn-success">บันทึก</button></div>
                        </form>
                    </div>

                    {{-- ตารางหัวข้อใหญ่: ตัดคอลัมน์น้ำหนักออก --}}
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <colgroup>
                                <col style="width:64px"> {{-- ลำดับ --}}
                                <col style="width:88px"> {{-- รหัส --}}
                                <col> {{-- ชื่อ --}}
                                <col style="width:88px"> {{-- ใช้งาน --}}
                                <col style="width:160px"> {{-- จัดการ --}}
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>รหัส</th>
                                    <th>ชื่อ</th>
                                    <th>ใช้งาน</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sections as $sec)
                                    @php $fid = "sec-{$sec->id}"; @endphp
                                    <tr @class(['table-secondary' => $sectionId == $sec->id])>
                                        <td>
                                            <input type="number" class="form-control form-control-sm ord text-end"
                                                name="orders[{{ $sec->id }}]" value="{{ $sec->order_no }}"
                                                form="sec-reorder">
                                        </td>
                                        <td class="text-muted">{{ $sec->code }}</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm w-100 sec-name"
                                                name="name" value="{{ $sec->name }}" form="{{ $fid }}">
                                        </td>
                                        <td class="text-center">
                                            <input type="hidden" name="is_active" value="0"
                                                form="{{ $fid }}">
                                            <div class="form-check form-switch d-inline-block">
                                                <input class="form-check-input" type="checkbox" value="1"
                                                    name="is_active" {{ $sec->is_active ? 'checked' : '' }}
                                                    form="{{ $fid }}"
                                                    onchange="document.getElementById('{{ $fid }}').submit()">
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary"
                                                form="{{ $fid }}">บันทึก</button>
                                            <a class="btn btn-sm btn-outline-secondary"
                                                href="{{ route('paadmin.index', ['section_id' => $sec->id]) }}">ดูย่อย</a>
                                            <form method="post" action="{{ route('paadmin.sections.destroy', $sec->id) }}"
                                                class="d-inline" onsubmit="return confirm('ลบหัวข้อใหญ่นี้ใช่ไหม?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- ฟอร์มอัปเดตแต่ละแถว (อยู่นอกตาราง) --}}
                    @foreach ($sections as $sec)
                        <form id="sec-{{ $sec->id }}" method="post"
                            action="{{ route('paadmin.sections.update', $sec->id) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="code" value="{{ $sec->code }}">
                            <input type="hidden" name="order_no" value="{{ $sec->order_no }}">
                        </form>
                    @endforeach

                    {{-- ฟอร์มบันทึกลำดับ --}}
                    <form id="sec-reorder" method="post" action="{{ route('paadmin.sections.reorder') }}">@csrf</form>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary btn-sm" form="sec-reorder">บันทึกลำดับ</button>
                    </div>
                </div>
            </div>

            {{-- ================= QUESTIONS (ยังมีน้ำหนัก 1 ตำแหน่ง) ================= --}}
            <div class="col-12 col-xl-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <strong>หัวข้อย่อย (Questions)</strong>
                            <span class="text-muted ms-2">
                                หมวดที่เลือก:
                                @php $cur = $sections->firstWhere('id',$sectionId); @endphp
                                {{ $cur?->name ?? '-' }}
                            </span>
                        </div>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#qCreate"
                            @disabled(!$sectionId)>+ เพิ่ม</button>
                    </div>

                    <div id="qCreate" class="collapse p-3 border-bottom">
                        <form method="post" action="{{ route('paadmin.questions.store') }}" class="row g-2">
                            @csrf
                            <input type="hidden" name="section_id" value="{{ $sectionId }}">
                            <div class="col-12"><input name="text" class="form-control"
                                    placeholder="ข้อความหัวข้อย่อย" required></div>
                            <div class="col-4"><input type="number" name="order_no" class="form-control"
                                    placeholder="ลำดับ"></div>
                            <div class="col-4">
                                <input type="number" step="0.1" min="0" inputmode="decimal" name="weight"
                                    class="form-control text-end" placeholder="1.0">
                            </div>
                            <div class="col-4 form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="q_active" value="1"
                                    name="is_active" checked>
                                <label class="form-check-label" for="q_active">ใช้งาน</label>
                            </div>
                            <div class="col-12"><button class="btn btn-success">บันทึก</button></div>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <colgroup>
                                <col style="width:64px"> {{-- ลำดับ --}}
                                <col> {{-- ข้อความ --}}
                                <col style="width:110px"> {{-- น้ำหนัก (ขยายเล็กน้อย) --}}
                                <col style="width:88px"> {{-- ใช้งาน --}}
                                <col style="width:140px"> {{-- จัดการ --}}
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>ข้อความ</th>
                                    <th>น้ำหนัก</th>
                                    <th>ใช้งาน</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($questions as $q)
                                    @php $fid = "q-{$q->id}"; @endphp
                                    <tr>
                                        <td>
                                            <input type="number" class="form-control form-control-sm ord text-end"
                                                name="orders[{{ $q->id }}]" value="{{ $q->order_no }}"
                                                form="q-reorder">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm w-100 q-text"
                                                name="text" value="{{ $q->text }}" form="{{ $fid }}">
                                        </td>
                                        <td>
                                            <input type="number" step="0.1" min="0" inputmode="decimal"
                                                class="form-control form-control-sm num-1 text-end" name="weight"
                                                value="{{ number_format((float) $q->weight, 1, '.', '') }}"
                                                form="{{ $fid }}">
                                        </td>
                                        <td class="text-center">
                                            <input type="hidden" name="is_active" value="0"
                                                form="{{ $fid }}">
                                            <div class="form-check form-switch d-inline-block">
                                                <input class="form-check-input" type="checkbox" value="1"
                                                    name="is_active" {{ $q->is_active ? 'checked' : '' }}
                                                    form="{{ $fid }}"
                                                    onchange="document.getElementById('{{ $fid }}').submit()">
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary"
                                                form="{{ $fid }}">บันทึก</button>
                                            <form method="post"
                                                action="{{ route('paadmin.questions.destroy', $q->id) }}"
                                                class="d-inline" onsubmit="return confirm('ลบหัวข้อย่อยนี้ใช่ไหม?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">ยังไม่มีหัวข้อย่อยในหมวดนี้
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @foreach ($questions as $q)
                        <form id="q-{{ $q->id }}" method="post"
                            action="{{ route('paadmin.questions.update', $q->id) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="section_id" value="{{ $q->section_id }}">
                            <input type="hidden" name="order_no" value="{{ $q->order_no }}">
                        </form>
                    @endforeach

                    <form id="q-reorder" method="post" action="{{ route('paadmin.questions.reorder') }}">
                        @csrf
                        <input type="hidden" name="section_id" value="{{ $sectionId }}">
                    </form>

                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div>
                            <form method="get" action="{{ route('paadmin.index') }}" class="d-inline">
                                <select class="form-select form-select-sm" name="section_id"
                                    onchange="this.form.submit()">
                                    @foreach ($sections as $s)
                                        <option value="{{ $s->id }}" @selected($sectionId == $s->id)>
                                            {{ $s->order_no }}. {{ $s->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </div>
                        <button class="btn btn-primary btn-sm" form="q-reorder">บันทึกลำดับ</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== add/replace styles at the bottom of the view ===== --}}
    <style>
        /* คอลัมน์ลำดับ/น้ำหนักให้กว้างพอดี และเลขชิดขวา */
        .pa-master .ord {
            width: 56px;
            text-align: end;
        }

        .pa-master .num-1 {
            width: 72px;
            text-align: end;
        }

        /* กันตารางดันจนเกิดสโครล; ให้วัดความกว้างจาก colgroup */
        .pa-master .table {
            table-layout: fixed;
        }

        /* ให้ความกว้างอิง colgroup จริง */

        /* เลขลำดับ/น้ำหนัก พอดีเซลล์และชิดขวา */
        .pa-master .ord {
            width: 56px;
            text-align: end;
        }

        .pa-master .num-1 {
            width: 86px;
            text-align: end;
        }

        /* ช่องข้อความต้องไม่ล้นเซลล์ */
        .pa-master .q-text {
            width: 100% !important;
            max-width: 100%;
            min-width: 0 !important;
            /* ตัดข้อบังคับเดิมที่ทำให้ล้น */
            box-sizing: border-box;
        }

        /* กันเนื้อหาใน td ล้น */
        .pa-master td {
            overflow: hidden;
        }
    </style>

    {{-- ===== keep this script (or add if missing) to format 1 decimal ===== --}}
    <script>
        // ช่องน้ำหนัก (Questions) ปัดเป็นทศนิยม 1 ตำแหน่งอัตโนมัติเมื่อพิมพ์เสร็จ
        document.querySelectorAll('input.num-1').forEach(el => {
            el.addEventListener('blur', () => {
                if (el.value !== '') {
                    const n = Number(el.value);
                    if (!Number.isNaN(n)) el.value = n.toFixed(1);
                }
            });
        });
    </script>

@endsection
