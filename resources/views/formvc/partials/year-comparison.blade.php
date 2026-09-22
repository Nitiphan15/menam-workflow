@php
    $comparisonYears = collect((array) request()->input('years', []))
        ->map(fn($year) => (int) $year)->filter(fn($year) => $year >= 2000 && $year <= 2100)->unique()->sortDesc()->values();
@endphp

@if ($comparisonYears->isNotEmpty())
    <div class="vc-card mb-3" id="vcYearComparison" data-url="{{ route('variable-cost.year-comparison') }}"
        data-page="{{ $activePage }}" data-years='@json($comparisonYears)'>
        <div class="vc-card-header">เปรียบเทียบปี {{ $comparisonYears->implode(', ') }}</div>
        <div class="p-3" data-vc-loading>
            <div class="small text-muted mb-2">กำลังโหลดทีละปี...</div>
            <div class="progress" style="height:8px"><div class="progress-bar" data-vc-progress style="width:0%"></div></div>
        </div>
        <div class="vc-table-wrap d-none" data-vc-result>
            <table class="table table-bordered table-sm vc-table mb-0">
                <thead><tr><th>รายการ</th>@foreach ($comparisonYears as $year)<th class="num">{{ $year }}</th>@endforeach</tr></thead>
                <tbody></tbody>
                <tfoot><tr><td>Total</td>@foreach ($comparisonYears as $year)<td class="num" data-vc-total="{{ $year }}">-</td>@endforeach</tr></tfoot>
            </table>
        </div>
    </div>
@endif

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', async function () {
    const root = document.getElementById('vcYearComparison');
    if (!root) return;
    const years = JSON.parse(root.dataset.years || '[]');
    const params = new URLSearchParams(window.location.search);
    params.delete('years[]'); params.delete('years');
    params.set('comparison_page', root.dataset.page);
    const loaded = {}, failed = {};
    const money = value => Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const escapeHtml = text => { const div = document.createElement('div'); div.textContent = text || ''; return div.innerHTML; };
    const requestJson = url => new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = () => xhr.status >= 200 && xhr.status < 300 ? resolve(JSON.parse(xhr.responseText)) : reject(new Error(`HTTP ${xhr.status}`));
        xhr.onerror = () => reject(new Error('Network error'));
        xhr.send();
    });
    const render = () => {
        const all = new Map();
        Object.values(loaded).flat().forEach(row => { if (!all.has(row.key)) all.set(row.key, {label: row.label, amounts: {}}); });
        Object.entries(loaded).forEach(([year, rows]) => rows.forEach(row => all.get(row.key).amounts[year] = Number(row.amount || 0)));
        const rows = Array.from(all.values()).sort((a, b) => Object.values(b.amounts).reduce((s,v)=>s+v,0) - Object.values(a.amounts).reduce((s,v)=>s+v,0));
        root.querySelector('tbody').innerHTML = rows.map(row => `<tr><td>${escapeHtml(row.label)}</td>${years.map(year => `<td class="num">${row.amounts[year] ? money(row.amounts[year]) : '-'}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="${years.length + 1}" class="text-center text-muted py-4">No data</td></tr>`;
        years.forEach(year => {
            const cell = root.querySelector(`[data-vc-total="${year}"]`);
            cell.textContent = failed[year] ? 'โหลดไม่สำเร็จ' : money((loaded[year] || []).reduce((sum, row) => sum + Number(row.amount || 0), 0));
        });
        root.querySelector('[data-vc-result]').classList.remove('d-none');

    };
    for (let i = 0; i < years.length; i++) {
        const year = years[i]; params.set('comparison_year', year);
        try {
            loaded[year] = (await requestJson(`${root.dataset.url}?${params.toString()}`)).rows || [];
        } catch (error) { failed[year] = true; }
        root.querySelector('[data-vc-progress]').style.width = `${((i + 1) / years.length) * 100}%`;
        render();
    }
    root.querySelector('[data-vc-loading]').classList.add('d-none');
});
</script>
@endpush
