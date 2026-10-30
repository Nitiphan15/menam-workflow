@once
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pageKey = 'wr-ds:'+location.pathname;
    const scrollKey = pageKey+location.search+':scroll';
    const form = document.getElementById('wr-filter-form');
    const filterKey = pageKey+':filters';
    if (form && !location.search) {
        const savedFilters = localStorage.getItem(filterKey);
        if (savedFilters) { location.replace(location.pathname+'?'+savedFilters); return; }
    }
    if (form) form.addEventListener('submit', function () {
        const params = new URLSearchParams(new FormData(form));
        Array.from(params.keys()).forEach(function (key) { if (!params.get(key)) params.delete(key); });
        params.delete('page');
        localStorage.setItem(filterKey, params.toString());
    });
    document.querySelectorAll('.js-wr-clear-filters').forEach(function (link) {
        link.addEventListener('click', function () { localStorage.removeItem(filterKey); sessionStorage.removeItem(scrollKey); });
    });
    document.querySelectorAll('.js-wr-remove-filter').forEach(function (link) {
        link.addEventListener('click', function () {
            const params = new URLSearchParams(localStorage.getItem(filterKey) || location.search);
            params.delete(link.dataset.filterKey || ''); params.delete('page');
            localStorage.setItem(filterKey, params.toString());
        });
    });
    const savedScroll = sessionStorage.getItem(scrollKey);
    if (savedScroll !== null) requestAnimationFrame(function () { window.scrollTo(0, Number(savedScroll) || 0); });
    const remember = function () { sessionStorage.setItem(scrollKey, String(window.scrollY)); };
    window.addEventListener('pagehide', remember);
    document.querySelectorAll('a[href]').forEach(function (link) { link.addEventListener('click', remember); });

    document.querySelectorAll('.js-wr-page-size').forEach(function (select) {
        select.addEventListener('change', function () {
            const url = new URL(location.href);
            url.searchParams.set('per_page', select.value);
            url.searchParams.delete('page');
            localStorage.setItem(pageKey+':per_page', select.value);
            location.assign(url.toString());
        });
    });
    if (!new URL(location.href).searchParams.has('per_page')) {
        const rememberedSize = localStorage.getItem(pageKey+':per_page');
        if (rememberedSize && ['25','50','100'].includes(rememberedSize) && form) {
            const url = new URL(location.href); url.searchParams.set('per_page', rememberedSize); location.replace(url.toString()); return;
        }
    }

    const hiddenKey = pageKey+':hidden-columns';
    let hidden = JSON.parse(localStorage.getItem(hiddenKey) || '[]');
    const applyColumns = function () {
        document.querySelectorAll('[data-wr-col]').forEach(function (cell) {
            cell.classList.toggle('wr-col-hidden', hidden.includes(cell.dataset.wrCol));
        });
        document.querySelectorAll('.js-wr-column').forEach(function (check) { check.checked = !hidden.includes(check.value); });
    };
    document.querySelectorAll('.js-wr-column').forEach(function (check) {
        check.addEventListener('change', function () {
            hidden = check.checked ? hidden.filter(function (key) { return key !== check.value; }) : Array.from(new Set(hidden.concat(check.value)));
            localStorage.setItem(hiddenKey, JSON.stringify(hidden));
            applyColumns();
        });
    });
    applyColumns();

    document.querySelectorAll('[data-wr-copy]').forEach(function (button) {
        button.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(button.dataset.wrCopy || '');
                const icon = button.querySelector('i');
                if (icon) { icon.className = 'fas fa-check'; setTimeout(function () { icon.className = 'far fa-copy'; }, 1200); }
            } catch (_) { /* Browser blocks clipboard outside secure context. */ }
        });
    });

    @canany(['WR_DS_EXPORT','WR_DS_MANAGE_ALL','WR_DS_ADMIN'])
    const statusUrl = @json(route('wr.deadstock.exports.status'));
    const notifiedKey = 'wr-ds:export-notified';
    const pollExports = function () {
        fetch(statusUrl, {headers:{'Accept':'application/json'}}).then(function (response) { return response.ok ? response.json() : null; }).then(function (data) {
            if (!data) return;
            const notified = JSON.parse(localStorage.getItem(notifiedKey) || '[]');
            const completed = (data.items || []).filter(function (item) { return item.status === 'COMPLETED' && !notified.includes(item.token); });
            const badge = document.getElementById('wr-export-ready-badge');
            if (badge) { badge.textContent = completed.length; badge.classList.toggle('d-none', completed.length === 0); }
            if (!completed.length) return;
            const item = completed[0];
            notified.push.apply(notified, completed.map(function (row) { return row.token; }));
            localStorage.setItem(notifiedKey, JSON.stringify(notified.slice(-50)));
            if (window.Swal) Swal.fire({toast:true,position:'top-end',icon:'success',title:'ไฟล์ Excel พร้อมดาวน์โหลด',html:'<a class="btn btn-sm btn-success mt-2" href="'+item.download_url+'"><i class="fas fa-download me-1"></i>'+item.filename+'</a>',showConfirmButton:false,timer:12000,timerProgressBar:true});
        }).catch(function () {});
    };
    pollExports();
    window.setInterval(pollExports, 15000);
    @endcanany
});
</script>
@endpush
@endonce
