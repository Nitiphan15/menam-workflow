(function () {
    'use strict';

    // GPS planner dashboard: charts from the summary tables + "สรุปวันที่นี้ใหม่" (DPA).
    const root = document.getElementById('gps-dashboard');
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const weeks = JSON.parse(root.dataset.weeks || '[]');
    const byTruck = JSON.parse(root.dataset.byTruck || '[]');
    const flashEl = document.getElementById('gdash-flash');
    const COLORS = { ok: '#16856f', late: '#d97706', miss: '#b42318', grid: 'rgba(100,116,139,.18)' };

    function flash(message, ok = true) {
        if (!flashEl) return;
        flashEl.hidden = false;
        flashEl.className = `gdash-flash ${ok ? 'is-ok' : 'is-error'}`;
        flashEl.textContent = message;
    }

    function weekLabel(iso) {
        const [y, m, d] = iso.split('-');
        return `${d}/${m}`;
    }

    const filterButtons = [...document.querySelectorAll('[data-gdash-filter]')];
    const truckRows = [...document.querySelectorAll('.gdash-board tbody tr[data-issue]')];
    const hideNormal = document.getElementById('gdash-hide-normal');
    const filterCount = document.getElementById('gdash-filter-count');
    const filterEmpty = document.getElementById('gdash-filter-empty');
    let activeFilter = 'all';

    function applyTruckFilter() {
        let visible = 0;
        truckRows.forEach((row) => {
            const matchesFilter = activeFilter === 'all'
                || (activeFilter === 'late' && row.dataset.late === '1')
                || (activeFilter === 'no-pin' && row.dataset.noPin === '1')
                || (activeFilter === 'no-return' && row.dataset.noReturn === '1')
                || (activeFilter === 'unplanned' && row.dataset.unplanned === '1');
            const matchesNormal = !hideNormal?.checked || row.dataset.issue === '1';
            row.hidden = !(matchesFilter && matchesNormal);
            if (!row.hidden) visible += 1;
        });
        if (filterCount) filterCount.textContent = `แสดง ${visible} จาก ${truckRows.length} คัน`;
        if (filterEmpty) filterEmpty.hidden = visible !== 0;
    }

    filterButtons.forEach((button) => button.addEventListener('click', () => {
        activeFilter = button.dataset.gdashFilter;
        filterButtons.forEach((item) => {
            const active = item === button;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-pressed', String(active));
        });
        applyTruckFilter();
    }));
    hideNormal?.addEventListener('change', applyTruckFilter);

    if (window.Chart && weeks.length) {
        const weekCanvas = document.getElementById('gdash-week-chart');
        if (weekCanvas) {
            new Chart(weekCanvas, {
                data: {
                    labels: weeks.map((week) => `สัปดาห์ ${weekLabel(week.week)}`),
                    datasets: [
                        { type: 'line', label: '% ถึงตรงเวลา', data: weeks.map((week) => week.on_time_percent), borderColor: COLORS.ok, backgroundColor: COLORS.ok, yAxisID: 'percent', tension: .25, spanGaps: true },
                        { type: 'bar', label: 'ถึงช้า (จุด)', data: weeks.map((week) => week.late), backgroundColor: COLORS.late, yAxisID: 'count' },
                        { type: 'bar', label: 'กลับช้า (คัน)', data: weeks.map((week) => week.late_returns), backgroundColor: COLORS.miss, yAxisID: 'count' },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom' }, datalabels: { display: false } },
                    scales: {
                        percent: { position: 'left', min: 0, max: 100, ticks: { callback: (value) => `${value}%` }, grid: { color: COLORS.grid } },
                        count: { position: 'right', beginAtZero: true, ticks: { precision: 0 }, grid: { display: false } },
                        x: { grid: { display: false } },
                    },
                },
            });
        }

        const truckCanvas = document.getElementById('gdash-truck-chart');
        if (truckCanvas && byTruck.length) {
            new Chart(truckCanvas, {
                type: 'bar',
                data: {
                    labels: byTruck.map((row) => row.plate_no),
                    datasets: [
                        { label: 'ถึงช้า (จุด)', data: byTruck.map((row) => row.late_arrivals), backgroundColor: COLORS.late },
                        { label: 'กลับช้า (วัน)', data: byTruck.map((row) => row.late_returns), backgroundColor: COLORS.miss },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' }, datalabels: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: COLORS.grid } }, y: { grid: { display: false } } },
                },
            });
        }
    }

    document.getElementById('gdash-refresh')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        flash('กำลังดึงเส้นทางจาก DTC และสรุปข้อมูล...');
        try {
            const response = await fetch(root.dataset.refreshUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ date: root.dataset.date }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || (response.status === 429 ? 'กดถี่เกินไป ลองใหม่ในอีก 1 นาที' : `HTTP ${response.status}`));
            }
            flash(`${payload.message} · กำลังโหลดหน้าใหม่`);
            window.setTimeout(() => window.location.reload(), 1500);
        } catch (error) {
            flash(`สรุปไม่สำเร็จ: ${error.message}`, false);
            button.disabled = false;
        }
    });
}());
