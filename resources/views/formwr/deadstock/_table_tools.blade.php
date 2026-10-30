<div class="wr-table-tools">
    <label class="d-flex align-items-center gap-2 mb-0 small text-muted">
        แสดง
        <select class="form-select form-select-sm js-wr-page-size" aria-label="จำนวนรายการต่อหน้า">
            @foreach([25,50,100] as $size)<option value="{{ $size }}" @selected((int) request('per_page',50)===$size)>{{ $size }}</option>@endforeach
        </select>
        รายการ
    </label>
    @if(!empty($columns ?? []))
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="fas fa-table-columns me-1"></i>เลือกคอลัมน์</button>
        <div class="dropdown-menu dropdown-menu-end wr-column-menu p-2">
            @foreach($columns as $key => $label)
                <label class="dropdown-item d-flex align-items-center gap-2"><input class="form-check-input js-wr-column" type="checkbox" value="{{ $key }}" checked> {{ $label }}</label>
            @endforeach
        </div>
    </div>
    @endif
</div>
