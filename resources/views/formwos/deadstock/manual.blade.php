@extends('layouts.layout')

@section('title', 'Deadstock Manual Mail')
@section('page-title', 'Deadstock Manual Mail')

@section('content')
    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1">Manual Send Deadstock Report</h3>
                <div class="text-muted small">Engine: {{ $mailDailyPath }}</div>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('deadstock.dashboard') }}">
                <i class="fa fa-chart-column me-1"></i> Dashboard
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <form method="post" action="{{ route('deadstock.send') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label">To</label>
                                <textarea class="form-control" name="to" rows="3" placeholder="เว้นว่างเพื่อใช้ DEADSTOCK_MAIL_TO ใน mail-daily">{{ old('to') }}</textarea>
                                <div class="form-text">ถ้าเว้นว่าง ระบบจะใช้ค่า default จาก mail-daily .env</div>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Report date</label>
                                    <input type="date" class="form-control" name="date" value="{{ old('date', now('Asia/Bangkok')->toDateString()) }}">
                                </div>
                                <div class="col-12 col-md-8 d-flex align-items-end gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="force_week" value="1" id="force_week" @checked(old('force_week'))>
                                        <label class="form-check-label" for="force_week">รวม weekly</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="force_month" value="1" id="force_month" @checked(old('force_month'))>
                                        <label class="form-check-label" for="force_month">รวม monthly</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa fa-paper-plane me-1"></i> Send Email
                                </button>
                                <a class="btn btn-outline-secondary" href="{{ route('deadstock.dashboard') }}">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="mb-3">Current mail-daily defaults</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-3">To</dt>
                            <dd class="col-sm-9 text-break">{{ $defaultTo ?: '-' }}</dd>
                            <dt class="col-sm-3">Cc</dt>
                            <dd class="col-sm-9 text-break">{{ $defaultCc ?: '-' }}</dd>
                            <dt class="col-sm-3">Bcc</dt>
                            <dd class="col-sm-9 text-break">{{ $defaultBcc ?: '-' }}</dd>
                        </dl>
                    </div>
                </div>

                @if (session('deadstock_output'))
                    <div class="card shadow-sm border-0 mt-3">
                        <div class="card-body">
                            <h5 class="mb-3">Command Output</h5>
                            <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height: 360px; overflow:auto;">{{ session('deadstock_output') }}</pre>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
