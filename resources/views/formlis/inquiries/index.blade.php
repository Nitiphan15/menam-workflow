@extends('layouts.layout')

@section('content')
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="mb-0">Inquiry List</h4>
            <a href="{{ route('lis.create') }}" class="btn btn-primary">+ New Inquiry</a>
        </div>

        <form method="GET" class="card mb-3">
            <div class="card-body row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Filter: Partnumber</label>
                    <input type="text" name="partnumber" value="{{ $partnumber }}" class="form-control"
                        placeholder="เช่น ABC-123">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Delivery date from</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Delivery date to</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-outline-primary w-100">Search</button>
                    <a href="{{ route('lis.index') }}" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Partnumber</th>
                            <th class="text-end">Qty</th>
                            <th>Delivery</th>
                            <th>Priority</th>
                            <th>Attachments</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr>
                                <td>#{{ $r->id }}</td>
                                <td>{{ $r->customer }}</td>
                                <td class="fw-semibold">{{ $r->partnumber }}</td>
                                <td class="text-end">{{ number_format($r->qty) }}</td>
                                <td>{{ optional($r->delivery_date)->format('Y-m-d') }}</td>
                                <td>
                                    <span
                                        class="badge
                                    {{ $r->priority == 1 ? 'text-bg-danger' : ($r->priority == 2 ? 'text-bg-warning' : 'text-bg-secondary') }}">
                                        {{ $r->priority_text }}
                                    </span>
                                </td>
                                <td>
                                    @if ($r->files->count())
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach ($r->files as $f)
                                                <a class="btn btn-sm btn-outline-primary"
                                                    href="{{ asset('storage/' . $f->path) }}" target="_blank">
                                                    {{ $loop->iteration }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $r->created_at->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                {{ $rows->links() }}
            </div>
        </div>
    </div>
@endsection
