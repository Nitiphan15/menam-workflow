@extends('layouts.layout')

@section('content')
<div class="container">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Delivery Plan - {{ $month->format('F Y') }}</h4>

    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary"
         href="{{ route('dp.calendar', ['ym' => $month->copy()->subMonth()->format('Y-m')]) }}">
        ‹ Prev
      </a>
      <a class="btn btn-outline-secondary"
         href="{{ route('dp.calendar', ['ym' => $month->copy()->addMonth()->format('Y-m')]) }}">
        Next ›
      </a>
    </div>
  </div>

  @php
    // เติมช่องว่างก่อนวันแรกให้ตรง dow
    $firstDow = \Carbon\Carbon::createFromFormat('Y-m', $month->format('Y-m'))->startOfMonth()->dayOfWeekIso; // 1..7
    $pad = $firstDow - 1;
  @endphp

  <div class="row g-2">
    @for($i=0;$i<$pad;$i++)
      <div class="col-12 col-md-1-7"></div>
    @endfor

    @foreach($days as $d)
      <div class="col-6 col-md-1-7">
        <a class="card text-decoration-none" href="{{ route('dp.day', ['date' => $d['date']]) }}">
          <div class="card-body p-2">
            <div class="d-flex justify-content-between align-items-center">
              <div class="fw-semibold">{{ $d['day'] }}</div>
              @if($d['count'] > 0)
                <span class="badge text-bg-primary">{{ $d['count'] }}</span>
              @endif
            </div>
            <div class="text-muted small">{{ $d['date'] }}</div>
          </div>
        </a>
      </div>
    @endforeach
  </div>
</div>

<style>
/* trick ให้ 7 columns ใน md ขึ้นไป (ถ้าไม่ใช้ css grid) */
@media (min-width: 768px) {
  .col-md-1-7 { width: 14.285714%; }
}
</style>
@endsection
