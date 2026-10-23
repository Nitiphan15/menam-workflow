@extends('layouts.layout')

@section('title', 'กู้ PO กลับ Step 3 และส่งเมล')
@section('page-title', 'กู้ PO กลับ Step 3 และส่งเมล')

@section('content')
<div class="container py-4" style="max-width: 900px">
    <div class="card shadow-sm">
        <div class="card-header fw-semibold">Preview ก่อนย้อน Workflow และส่งเมล</div>
        <div class="card-body">
            @if (session('ok'))
                <div class="alert alert-success">{{ session('ok') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif
            @if ($error)
                <div class="alert alert-danger">{{ $error }}</div>
            @endif

            <form method="GET" action="{{ route('adminweb.po-step3-recovery.index') }}" class="row g-2">
                <div class="col-sm-9">
                    <label class="form-label" for="ordnumber">เลขที่ PO</label>
                    <input id="ordnumber" name="ordnumber" class="form-control" value="{{ $ordnumber }}"
                           placeholder="เช่น PO2026100071" required>
                </div>
                <div class="col-sm-3 d-grid align-items-end">
                    <button class="btn btn-outline-primary mt-sm-4">ตรวจสอบ</button>
                </div>
            </form>

            @if ($result)
                @php($po = $result['po'])
                <div class="border rounded p-3 mt-4 bg-light">
                    <div><strong>PO:</strong> {{ $po->ordnumber }}</div>
                    <div><strong>Department จาก ERP:</strong> {{ $po->f1 }}</div>
                    <div><strong>สถานะ:</strong> {{ $po->status_code }} / Step {{ $result['workflow']->current_step_no }}</div>
                    <div><strong>Skip:</strong> {{ $result['skip']->created_at }} — {{ $result['skip']->comment }}</div>
                    <div><strong>Recovery state:</strong> {{ $result['state'] }}</div>
                </div>

                <div class="mt-3">
                    <strong>ผู้อนุมัติที่ resolver หาได้:</strong>
                    @forelse ($result['resolver_users'] as $resolverUser)
                        <div>{{ $resolverUser->name }} ({{ $resolverUser->username }}) — {{ $resolverUser->email ?: 'ไม่มีอีเมล' }}</div>
                    @empty
                        <div class="text-danger">ไม่พบผู้อนุมัติจาก resolver</div>
                    @endforelse
                </div>

                @if (!$result['selected_user'])
                    <form method="GET" action="{{ route('adminweb.po-step3-recovery.index') }}" class="row g-2 mt-2">
                        <input type="hidden" name="ordnumber" value="{{ $po->ordnumber }}">
                        <div class="col-sm-9">
                            <label class="form-label" for="user_search">ค้นหา Active User</label>
                            <input id="user_search" name="user_search" class="form-control" value="{{ $userSearch }}"
                                   placeholder="ชื่อ, username หรือ email" minlength="2" required>
                        </div>
                        <div class="col-sm-3 d-grid align-items-end">
                            <button class="btn btn-outline-secondary mt-sm-4">ค้นหา</button>
                        </div>
                    </form>

                    @if ($userSearch !== '')
                        <div class="list-group mt-3">
                            @forelse ($users as $user)
                                <a class="list-group-item list-group-item-action"
                                   href="{{ route('adminweb.po-step3-recovery.index', ['ordnumber' => $po->ordnumber, 'approver_user_id' => $user->id, 'user_search' => $userSearch]) }}">
                                    {{ $user->name }} ({{ $user->username }}) — {{ $user->email }}
                                    <span class="text-muted">{{ $user->department_code }} {{ $user->department_name }}</span>
                                </a>
                            @empty
                                <div class="alert alert-warning mb-0">ไม่พบ Active User ที่มีอีเมล</div>
                            @endforelse
                        </div>
                    @endif
                @else
                    @php($selected = $result['selected_user'])
                    <div class="border rounded p-3 mt-3">
                        <div><strong>ผู้รับที่เลือก:</strong> {{ $selected->name }} ({{ $selected->username }})</div>
                        <div><strong>Email:</strong> {{ $selected->email }}</div>
                        <div><strong>Department:</strong> {{ $selected->department_code }} {{ $selected->department_name }}</div>
                    </div>

                    @if (!$result['is_resolver_match'])
                        <div class="alert alert-warning mt-3">
                            ผู้รับที่เลือกไม่ตรงกับผล resolver การยืนยันจะถูกบันทึกเป็น ADMINWEB override พร้อมเหตุผล
                        </div>
                    @endif

                    @if ($result['already_dispatched'])
                        <div class="alert alert-info mt-3 mb-0">เคยส่งเมลสำหรับผู้รับนี้แล้ว ระบบไม่ส่งซ้ำ</div>
                    @else
                        <form method="POST" action="{{ route('adminweb.po-step3-recovery.send') }}" class="mt-3"
                              onsubmit="return confirm('ยืนยันย้อน PO {{ $po->ordnumber }} ไป Step 3 และส่งเมลให้ {{ $selected->name }}?')">
                            @csrf
                            <input type="hidden" name="ordnumber" value="{{ $po->ordnumber }}">
                            <input type="hidden" name="approver_user_id" value="{{ $selected->id }}">
                            <label class="form-label" for="reason">เหตุผลในการย้อน/Override</label>
                            <textarea id="reason" name="reason" class="form-control" rows="3" maxlength="500" required>{{ old('reason') }}</textarea>
                            <button class="btn btn-danger mt-3">ยืนยันย้อนและส่งเมล</button>
                        </form>
                    @endif
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
