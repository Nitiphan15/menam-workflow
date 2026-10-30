@canany(['WR_DS_EXPORT','WR_DS_MANAGE_ALL','WR_DS_ADMIN'])
<form method="POST" action="{{ route('wr.deadstock.exports.store') }}">
    @csrf
    @foreach(request()->except('page') as $key => $value)
        @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach
    <button class="btn btn-outline-success" title="สร้าง Excel 2 Sheet ตาม Filter แบบ Background">
        <i class="fas fa-file-excel"></i> Export Excel
    </button>
</form>
<a class="btn btn-outline-secondary" href="{{ route('wr.deadstock.exports.index') }}">
    <i class="fas fa-clock-rotate-left"></i> ประวัติ Export <span id="wr-export-ready-badge" class="badge bg-success d-none">0</span>
</a>
@endcanany
