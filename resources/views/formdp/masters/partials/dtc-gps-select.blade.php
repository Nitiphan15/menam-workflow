{{-- DTC GPS device for a truck. Options come from the cached DTC Vehicle Master (no DTC call). --}}
@php
    $current = trim((string) ($current ?? ''));
    $options = $dtcVehicles ?? [];
@endphp
@if (count($options) > 0)
    <select class="form-select form-select-sm" name="dtc_gps_id" @if ($formId) form="{{ $formId }}" @endif>
        <option value="">- ไม่ผูก GPS -</option>
        @foreach ($options as $gpsId => $label)
            <option value="{{ $gpsId }}" {{ $current === (string) $gpsId ? 'selected' : '' }}>{{ $label }} ({{ $gpsId }})</option>
        @endforeach
        @if ($current !== '' && !array_key_exists($current, $options))
            <option value="{{ $current }}" selected>{{ $current }} (ไม่พบในรายชื่อ DTC ล่าสุด)</option>
        @endif
    </select>
@else
    <input class="form-control form-control-sm" name="dtc_gps_id" value="{{ $current }}" maxlength="30"
        pattern="[A-Za-z0-9]*" placeholder="GPS ID" title="ยังไม่มีรายชื่อรถจาก DTC ใน cache — เปิดหน้า GPS Trip Tracking ก่อน หรือกรอกรหัส GPS เอง"
        @if ($formId) form="{{ $formId }}" @endif>
@endif
