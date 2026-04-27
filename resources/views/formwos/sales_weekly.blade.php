@extends('layouts.layout')

@section('title', 'Sales Weekly Summary')
@section('page-title', 'Sales Weekly Summary')

@section('content')
    @php
        // Controller ส่ง compact('from','to','partPrefix','metric')
        $from = $filters['from'] ?? '2026-01-01';
        $to = $filters['to'] ?? now()->toDateString();

        $fmtTon = fn($v) => number_format((float) $v, 2);
        $fmtBaht = fn($v) => number_format(((float) $v) * 1000, 2);
    @endphp

    <div class="container-fluid py-3">

        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div>
                <h4 class="mb-0">Filter Data</h4>

            </div>
        </div>

        {{-- Filter Bar --}}
        <form class="card card-body mb-3" method="GET" action="{{ route('wos.sales_weekly') }}">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1">From</label>
                    <input type="date" class="form-control" name="from" value="{{ $from }}">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1">To</label>
                    <input type="date" class="form-control" name="to" value="{{ $to }}">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">Search</button>
                </div>

                <div class="col-12 col-md-2 d-grid">
                    <a href="{{ route('wos.sales_weekly.export', [
                        'from' => $from,
                        'to' => $to,
                    ]) }}"
                        class="btn btn-success">
                        Export Excel
                    </a>
                </div>
            </div>
        </form>


        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div>
                <h4 class="mb-0">Inquiry Data</h4>

            </div>
        </div>
        <div id="reportContainer">
            @include('formwos._sales_weekly_table', [
                'monthBlocks' => $monthBlocks,
                'from' => $from,
                'to' => $to,
            ])

        </div>
    </div>

    <script>
        let lastHash = null;
        const hashUrl = @json(route('wos.sales_weekly.hash'));
        const dataUrl = @json(route('wos.sales_weekly.data'));

        async function tick() {
            const from = document.querySelector('[name="from"]').value;
            const to = document.querySelector('[name="to"]').value;

            const qs = new URLSearchParams({
                from,
                to
            }).toString();

            const st = await fetch(`${hashUrl}?${qs}`).then(r => r.json());
            if (lastHash && st.hash === lastHash) return;

            lastHash = st.hash;

            const data = await fetch(`${dataUrl}?${qs}`).then(r => r.json());
            document.querySelector('#reportContainer').innerHTML = data.html;
        }

        setInterval(tick, 15000);
        tick(); // โหลดครั้งแรก
    </script>
@endsection
