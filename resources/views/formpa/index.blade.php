@extends('layouts.layout')

@section('title', 'Evaluate Employees')
@section('page-title', 'PA Online (Accounting)')

@section('content')
    <div class="container-fluid px-3">
        <h4 class="mb-3">ประเมินพนักงาน</h4>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong>รายชื่อพนักงานในแผนก</strong>
                    <span class="text-muted ms-2">แผนก: {{ $department->name ?? '-' }}</span>
                </div>
                <form class="d-flex gap-2" method="get">
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm"
                        placeholder="ค้นหา ชื่อ/อีเมล">
                    <button class="btn btn-sm btn-outline-secondary">ค้นหา</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px">#</th>
                            <th>พนักงาน</th>
                            <th style="width:220px">อีเมล</th>
                            <th style="width:160px" class="text-center">สถานะ</th>
                            <th style="width:120px" class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $i => $u)
                            @php
                                // $status[$u->id] = ['label'=>'Draft/Complete/...','class'=>'secondary/success/...','updated_at'=>...]
                                $st = $status[$u->id] ?? null;
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration + ($users->currentPage() - 1) * $users->perPage() }}</td>
                                <td>{{ $u->name }}</td>
                                <td class="text-muted">{{ $u->email }}</td>
                                <td class="text-center">
                                    @if ($st)
                                        <span class="badge bg-{{ $st['class'] ?? 'secondary' }}">{{ $st['label'] }}</span>
                                        <div class="small text-muted">
                                            {{ \Carbon\Carbon::parse($st['updated_at'] ?? null)->diffForHumans() }}</div>
                                    @else
                                        <span class="badge bg-secondary">ยังไม่เริ่ม</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-primary" href="{{ route('pa.form', $u->id) }}">ประเมิน</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">- ไม่มีพนักงานในแผนก -</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                {{ $users->withQueryString()->links() }}
            </div>
        </div>
    </div>
@endsection
