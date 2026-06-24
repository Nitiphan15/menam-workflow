(function () {
    'use strict';

    let mode = 'workorder';
    const charts = { c1: null, c2: null, c3: null };
    let categoryMs = null;        // instance ของ multi-select (ตั้งค่าใน init)
    let pendingCategory = null;   // ค่า category จาก URL ที่รอ apply หลังโหลด options

    function buildQuery() {
        const conn = document.getElementById('filterConn').value;
        const category = categoryMs ? categoryMs.getValue() : (document.getElementById('filterCategory')?.value || '');
        const description = document.getElementById('filterDescription')?.value.trim() || '';
        const woInput = document.getElementById('filterWo');
        const wo   = woInput.value.trim().toUpperCase();
        if (wo) woInput.value = wo;
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo   = document.getElementById('filterDateTo').value;
        const year = document.getElementById('filterYear').value;
        const week = document.getElementById('filterWeek').value;

        // Priority: WO > Date range > Week
        if (wo) {
            mode = 'workorder';
            return { url: window.DIE_ROUTES.byWorkorder, params: { wo, category, description } };
        }
        if (dateFrom || dateTo) {
            mode = 'date';
            const from = dateFrom || dateTo;
            const to   = dateTo   || dateFrom;
            return { url: window.DIE_ROUTES.byDate, params: { date_from: from, date_to: to, connection: conn, category, description } };
        }
        if (year && week) {
            mode = 'week';
            return { url: window.DIE_ROUTES.byWeek, params: { year, week, connection: conn, category, description } };
        }
        return null;
    }

    function updateUrl(params) {
        const next = new URLSearchParams();
        next.set('mode', mode);
        const clean = { ...(params || {}) };
        // URL ใช้ site=W/P/ALL ไม่โชว์ชื่อ connection ภายใน (เช่น pgsqlpcmw)
        if (clean.connection !== undefined) {
            clean.site = clean.connection === 'pgsqlpcmp' ? 'P' : (clean.connection === 'ALL' ? 'ALL' : 'W');
            delete clean.connection;
        }
        Object.entries(clean).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') next.set(key, value);
        });
        window.history.replaceState(null, '', window.location.pathname + '?' + next.toString());
    }

    function applyUrlParams() {
        const params = new URLSearchParams(window.location.search);
        const wo = params.get('wo');
        const from = params.get('date_from') || params.get('from');
        const to = params.get('date_to') || params.get('to');
        const year = params.get('year');
        const week = params.get('week');
        const conn = params.get('connection');
        const site = params.get('site');
        const category = params.get('category');
        const description = params.get('description');

        const connMap = { P: 'pgsqlpcmp', W: 'pgsqlpcmw', ALL: 'ALL', pgsqlpcmp: 'pgsqlpcmp', pgsqlpcmw: 'pgsqlpcmw' };
        const resolvedConn = connMap[site] || connMap[conn];
        if (resolvedConn) document.getElementById('filterConn').value = resolvedConn;
        if (category) pendingCategory = category;  // apply หลัง loadCategories
        if (description && document.getElementById('filterDescription')) document.getElementById('filterDescription').value = description;
        if (wo) document.getElementById('filterWo').value = wo.toUpperCase();
        if (from) document.getElementById('filterDateFrom').value = from;
        if (to) document.getElementById('filterDateTo').value = to;
        if (year) document.getElementById('filterYear').value = year;
        if (week) document.getElementById('filterWeek').value = week;
    }

    function setLoading(msg) {
        document.getElementById('dieTableContainer').innerHTML = `<div class="de-loading"><i class="fa fa-spinner fa-spin me-2"></i>${msg}</div>`;
    }

    function setEmpty(msg) {
        document.getElementById('dieTableContainer').innerHTML = `<div class="de-empty"><div class="icon"><i class="fa fa-inbox"></i></div><div class="text">${msg}</div></div>`;
    }

    function renderSummary(s) {
        const sum = document.getElementById('dieSummary');
        if (!s || s.total_trans == null) { sum.innerHTML = ''; return; }
        sum.innerHTML = `
            <div class="col-md col-6"><div class="de-kpi"><div class="label">Total Transfer</div><div class="value">${s.total_trans ?? 0}</div></div></div>
            <div class="col-md col-6"><div class="de-kpi kpi-green"><div class="label">Unique Die</div><div class="value">${s.unique_die ?? 0}</div></div></div>
            <div class="col-md col-6"><div class="de-kpi kpi-amber"><div class="label">FG Output (kg)</div><div class="value">${Number(s.total_kg ?? 0).toLocaleString(undefined, {maximumFractionDigits: 0})}</div></div></div>
            <div class="col-md col-6"><div class="de-kpi kpi-purple"><div class="label">Active WO</div><div class="value">${s.unique_wo ?? 0}</div></div></div>
            <div class="col-md col-6"><div class="de-kpi"><div class="label">Departments</div><div class="value">${s.unique_dept ?? 0}</div></div></div>
        `;
    }

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[m]));
    }

    function renderExceptions(exceptions) {
        const card = document.getElementById('dieExceptionCard');
        const body = document.getElementById('dieExceptions');
        const countEl = document.getElementById('dieExceptionCounts');
        const items = exceptions?.items || [];
        const counts = exceptions?.counts || {};

        card.style.display = '';
        countEl.textContent = `Danger ${counts.danger || 0} / Warning ${counts.warning || 0} / Info ${counts.info || 0}`;
        if (!items.length) {
            body.innerHTML = '<div class="text-muted small">No open exception in this result.</div>';
            return;
        }

        body.innerHTML = `<div class="die-risk-grid">${items.slice(0, 12).map(item => `
            <div class="die-risk-item ${esc(item.severity || 'info')}" data-wo="${esc(item.workordernumber || '')}" data-die="${esc(item.equipnumber || '')}">
                <div class="title">${esc(item.title)}</div>
                <div class="detail">${esc(item.detail)}</div>
                <div class="detail">${item.workordernumber ? `WO ${esc(item.workordernumber)}` : ''}${item.block_no ? ` / B${esc(item.block_no)}` : ''}</div>
            </div>
        `).join('')}</div>`;

        body.querySelectorAll('.die-risk-item').forEach(el => {
            el.addEventListener('click', () => {
                const wo = el.dataset.wo;
                const die = el.dataset.die;
                if (wo) window.location = window.DIE_ROUTES.woDetail + '?wo=' + encodeURIComponent(wo);
                else if (die) window.location = window.DIE_ROUTES.master + '?tab=master&q=' + encodeURIComponent(die);
            });
        });
    }

    function renderReadiness(readiness) {
        const card = document.getElementById('dieReadinessCard');
        const compareCard = document.getElementById('dieCompareCard');
        const body = document.getElementById('dieReadiness');
        const summary = document.getElementById('dieReadinessSummary');
        if (!readiness || mode !== 'workorder') {
            card.style.display = 'none';
            return;
        }
        compareCard.style.display = 'none';
        card.style.display = '';
        summary.textContent = `Ready ${readiness.ready || 0} / Check ${readiness.check || 0} / Missing ${readiness.missing || 0}`;
        body.innerHTML = `<div class="die-readiness">${(readiness.blocks || []).map(b => `
            <div class="die-ready-block ${esc(b.status)}">
                <div class="block">B${esc(b.block_no)} ${b.block_name ? esc(b.block_name) : ''}</div>
                <div class="meta">${b.workcenter ? esc(b.workcenter) : ''}${b.block_size ? ` / size ${esc(b.block_size)}` : ''}</div>
                <div class="meta">${(b.dies || []).length ? esc((b.dies || []).join(', ')) : 'No die'}</div>
                <div class="meta">${(b.notes || []).map(esc).join(', ')}</div>
            </div>
        `).join('')}</div>`;
    }

    function renderCompare(data) {
        const card = document.getElementById('dieCompareCard');
        const readiness = document.getElementById('dieReadinessCard');
        const body = document.getElementById('dieCompare');
        const range = document.getElementById('dieCompareRange');
        if (!data || mode === 'workorder') {
            card.style.display = 'none';
            return;
        }
        readiness.style.display = 'none';
        card.style.display = '';
        range.textContent = `${data.current?.start || ''} to ${data.current?.end || ''} vs ${data.previous?.start || ''} to ${data.previous?.end || ''}`;

        const label = {
            total_trans: 'Transfer',
            unique_die: 'Unique Die',
            total_kg: 'FG kg',
            unique_wo: 'Active WO',
            unique_dept: 'Departments',
        };
        const cells = Object.entries(data.delta || {}).map(([key, row]) => {
            const up = Number(row.change || 0) >= 0;
            const pct = row.percent == null ? '-' : `${up ? '+' : ''}${row.percent}%`;
            return `<div class="de-sub-card">
                <div class="title">${label[key] || key}</div>
                <div style="font-size:18px; font-weight:800;">${Number(row.current || 0).toLocaleString()}</div>
                <div class="${up ? 'text-success' : 'text-danger'} small">${up ? '+' : ''}${Number(row.change || 0).toLocaleString()} (${pct})</div>
            </div>`;
        }).join('');
        body.innerHTML = `<div class="die-compare-grid">${cells}</div>`;
    }

    async function loadCompare(params, data) {
        if (mode === 'workorder' || !window.DIE_ROUTES.compare) {
            renderCompare(null);
            return;
        }
        try {
            const compareParams = { connection: params.connection, category: params.category || '' };
            if (mode === 'week') {
                compareParams.date_from = data.start;
                compareParams.date_to = data.end;
            } else {
                compareParams.date_from = data.date_from || params.date_from || params.date;
                compareParams.date_to = data.date_to || params.date_to || compareParams.date_from;
            }
            const qs = new URLSearchParams(compareParams).toString();
            const res = await fetch(window.DIE_ROUTES.compare + '?' + qs, { headers: { 'Accept': 'application/json' }});
            if (!res.ok) return;
            renderCompare(await res.json());
        } catch (e) {
            document.getElementById('dieCompare').innerHTML = '<div class="text-danger small">Compare error</div>';
        }
    }

    function renderTable(rows) {
        const meta = document.getElementById('dieResultMeta');
        if (meta) meta.textContent = rows?.length ? `${rows.length.toLocaleString()} rows` : '-';
        if (!rows || rows.length === 0) { setEmpty('ไม่พบข้อมูล'); return; }
        const headers = [
            'Site','Trans#','Date','WO#','Block (WO Detail)','Workseq','Workcenter','Die#','Description','Category',
            'Machine','Issued By','Requested By','Ordered Size','Present Dia.','Reduction / Angle','Bearing','FG kg','Qty','แผนกที่ใช้','สถานะตอนเบิก'
        ];
        const head = `<thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>`;
        const body = rows.map(r => `
            <tr ${r.workordernumber ? `data-wo="${r.workordernumber}" style="cursor:pointer;" title="คลิกเพื่อดู WO Detail Sheet"` : ''}>
                <td>${r.site ?? ''}</td>
                <td>${r.transnumber ?? ''}</td>
                <td>${r.transdate ?? ''}</td>
                <td><b>${r.workordernumber ?? ''}</b></td>
                <td>${r.block_no != null
                    ? `<span class="de-chip info">B${r.block_no}</span>${r.block_name ? ' ('+r.block_name+')' : ''}`
                    : (String(r.to_class || '').toUpperCase().includes('DRAWING')
                        ? '<span class="de-chip muted" title="มีรายการเบิก DIE แต่จับคู่กับ Block ใน WO Detail ไม่ได้">ไม่จับคู่</span>'
                        : '-')}</td>
                <td>${r.workseq ?? ''}</td>
                <td>${r.workcenter ?? ''}${r.workcenter_desc ? ' - '+r.workcenter_desc : ''}</td>
                <td>${r.equipnumber ? `<a href="${window.DIE_ROUTES.master}?tab=master&q=${encodeURIComponent(r.equipnumber)}" class="fw-bold text-primary text-decoration-none" title="ดู Die Profile" onclick="event.stopPropagation();">${r.equipnumber}</a>` : ''}</td>
                <td>${r.die_description ?? ''}</td>
                <td>${r.equipcategory ?? ''}</td>
                <td>${r.used_machine ?? r.machine_number ?? ''}</td>
                <td>${r.issued_by_name ?? r.updated_by_name ?? ''}</td>
                <td>${r.requester_name ?? ''}</td>
                <td>${r.ordered_size ?? r.size_in ?? ''}</td>
                <td>${r.present_diameter ?? r.size_out ?? ''}</td>
                <td>${r.reduction_area_angle ?? r.extra_f3 ?? ''}</td>
                <td>${r.bearing_length ?? r.extra_f4 ?? ''}</td>
                <td>${r.wo_fg_kg != null ? Number(r.wo_fg_kg).toLocaleString(undefined, {maximumFractionDigits: 0}) : ''}</td>
                <td>${r.qty ?? ''}</td>
                <td>${r.to_class ?? ''}</td>
                <td><span class="badge-status ${(r.txn_status || r.status) === 'USABLE' ? '' : 'bad'}">${r.txn_status ?? r.status ?? ''}</span></td>
            </tr>
        `).join('');
        document.getElementById('dieTableContainer').innerHTML =
            `<table class="de-table">${head}<tbody>${body}</tbody></table>`;

        document.querySelectorAll('#dieTableContainer tr[data-wo]').forEach(tr => {
            tr.addEventListener('click', () => {
                const wo = (tr.dataset.wo || '').toUpperCase();
                window.location = window.DIE_ROUTES.woDetail + '?wo=' + encodeURIComponent(wo);
            });
        });
    }

    function destroyChart(key) { if (charts[key]) { charts[key].destroy(); charts[key] = null; } }

    function renderCharts(chartsData, currentMode, heatmapData = null) {
        const labels = (obj) => Object.keys(obj || {});
        const values = (obj) => Object.values(obj || {});
        const colors = ['#2563eb','#16a34a','#ea580c','#7c3aed','#0ea5e9','#dc2626','#ca8a04','#0d9488','#db2777','#475569'];

        const c1 = document.getElementById('chart1').getContext('2d');
        const c2 = document.getElementById('chart2').getContext('2d');
        const c3 = document.getElementById('chart3').getContext('2d');
        destroyChart('c1'); destroyChart('c2'); destroyChart('c3');

        if (currentMode === 'workorder') {
            document.getElementById('chart1Title').textContent = 'จำนวนเบิกต่อ Block';
            document.getElementById('chart2Title').textContent = 'สัดส่วนประเภทไดร์';
            document.getElementById('chart3Title').textContent = 'Top ไดร์ที่ใช้';

            charts.c1 = new Chart(c1, {
                type: 'bar',
                data: { labels: labels(chartsData.by_block).map(k => 'B' + k),
                        datasets: [{ label: 'Count', data: values(chartsData.by_block), backgroundColor: '#2563eb' }] },
                options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} } }
            });
            charts.c2 = new Chart(c2, {
                type: 'doughnut',
                data: { labels: labels(chartsData.by_category),
                        datasets: [{ data: values(chartsData.by_category), backgroundColor: colors }] },
                options: { responsive:true, maintainAspectRatio:false }
            });
            charts.c3 = new Chart(c3, {
                type: 'bar',
                data: { labels: labels(chartsData.top_die),
                        datasets: [{ label: 'Count', data: values(chartsData.top_die), backgroundColor: '#16a34a' }] },
                options: { indexAxis: 'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} } }
            });
        } else if (currentMode === 'date') {
            const drawingBlocks = heatmapData?.matrix?.DRAWING || {};
            document.getElementById('chart1Title').textContent = 'DIE ใช้งานตาม Block (DRAWING)';
            document.getElementById('chart2Title').textContent = 'สัดส่วนประเภทไดร์';
            document.getElementById('chart3Title').textContent = 'Top ไดร์ของวันนี้';

            charts.c1 = new Chart(c1, {
                type: 'bar',
                data: { labels: labels(drawingBlocks).map(k => 'B' + k),
                        datasets: [{ label:'DIE', data: values(drawingBlocks), backgroundColor: '#ea580c' }] },
                options: {
                    responsive:true,
                    maintainAspectRatio:false,
                    plugins:{ legend:{display:false} },
                    onClick: (_, elements) => {
                        if (!elements.length) return;
                        const block = labels(drawingBlocks)[elements[0].index];
                        showHeatmapDetails('DRAWING', block, heatmapData?.details?.DRAWING?.[block] || []);
                    },
                }
            });
            charts.c2 = new Chart(c2, {
                type: 'doughnut',
                data: { labels: labels(chartsData.by_category),
                        datasets: [{ data: values(chartsData.by_category), backgroundColor: colors }] },
                options: { responsive:true, maintainAspectRatio:false }
            });
            charts.c3 = new Chart(c3, {
                type: 'bar',
                data: { labels: labels(chartsData.top_die),
                        datasets: [{ label:'Count', data: values(chartsData.top_die), backgroundColor: '#7c3aed' }] },
                options: { indexAxis: 'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} } }
            });
        } else {
            document.getElementById('chart1Title').textContent = 'แนวโน้ม FG kg รายวัน';
            document.getElementById('chart2Title').textContent = 'FG kg ตามแผนก';
            document.getElementById('chart3Title').textContent = 'Top ไดร์ของสัปดาห์';

            charts.c1 = new Chart(c1, {
                type: 'line',
                data: { labels: labels(chartsData.by_date),
                        datasets: [{ label:'kg', data: values(chartsData.by_date), borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.15)', fill:true, tension:.3 }] },
                options: { responsive:true, maintainAspectRatio:false }
            });
            charts.c2 = new Chart(c2, {
                type: 'bar',
                data: { labels: labels(chartsData.by_dept),
                        datasets: [{ label:'kg', data: values(chartsData.by_dept), backgroundColor:'#16a34a' }] },
                options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} } }
            });
            charts.c3 = new Chart(c3, {
                type: 'bar',
                data: { labels: labels(chartsData.top_die),
                        datasets: [{ label:'Count', data: values(chartsData.top_die), backgroundColor:'#ea580c' }] },
                options: { indexAxis: 'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} } }
            });
        }
    }

    function renderHeatmap(h) {
        const wrap = document.getElementById('heatmapWrap');
        if (!h || !h.workcenters?.length || !h.blocks?.length || mode === 'workorder') {
            wrap.style.display = 'none';
            return;
        }
        wrap.style.display = '';

        // หา max value เพื่อ scale color
        let max = 0;
        Object.values(h.matrix || {}).forEach(row => {
            Object.values(row).forEach(v => { if (v > max) max = v; });
        });

        const colorFor = (v) => {
            if (!v) return '#fff';
            const pct = max > 0 ? v / max : 0;
            // gradient ขาว → ส้ม → แดง
            const r = 255, g = Math.round(255 - 145 * pct), b = Math.round(255 - 230 * pct);
            return `rgb(${r},${g},${b})`;
        };

        let head = '<tr><th>Workcenter \\ Block</th>';
        h.blocks.forEach(b => head += `<th>B${b}</th>`);
        head += '<th>รวม</th></tr>';

        let body = '';
        h.workcenters.forEach(wc => {
            const row = h.matrix[wc] || {};
            let rowSum = 0;
            let cells = '';
            h.blocks.forEach(b => {
                const v = row[b] || 0;
                rowSum += v;
                const detail = h.details?.[wc]?.[b] || [];
                const usable = detail.filter(item => (item.status || 'USABLE') === 'USABLE').length;
                cells += `<td class="cell ${v === 0 ? 'zero' : ''} ${v ? 'clickable' : ''}"
                    data-wc="${esc(wc)}" data-block="${esc(b)}"
                    title="${v ? `คลิกดู DIE ที่ใช้ได้ (${usable}/${v})` : ''}"
                    style="background:${colorFor(v)};">${v || ''}</td>`;
            });
            body += `<tr><td class="row-label">${wc}</td>${cells}<td class="cell" style="background:#eef2f7;">${rowSum}</td></tr>`;
        });

        document.getElementById('heatmapBody').innerHTML =
            `<table class="heatmap-table"><thead>${head}</thead><tbody>${body}</tbody></table>`;
        document.querySelectorAll('#heatmapBody td.cell.clickable').forEach(cell => {
            cell.addEventListener('click', () => {
                const wc = cell.dataset.wc;
                const block = cell.dataset.block;
                showHeatmapDetails(wc, block, h.details?.[wc]?.[block] || []);
            });
        });
    }

    function showHeatmapDetails(workcenter, block, items) {
        const modal = document.getElementById('dieHeatmapModal');
        const title = document.getElementById('dieHeatmapModalTitle');
        const body = document.getElementById('dieHeatmapModalBody');
        if (!modal || !body) return;
        title.textContent = `${workcenter} / Block ${block}`;
        body.innerHTML = items.length ? `
            <table class="de-table">
                <thead><tr><th>DIE#</th><th>Trans เบิก</th><th>Description</th><th>WO</th><th>Size</th><th>สถานะตอนเบิก</th></tr></thead>
                <tbody>${items.map(item => {
                    const usable = (item.status || 'USABLE') === 'USABLE';
                    const dieNo = item.equipnumber || '';
                    const woNo = item.workordernumber || '';
                    const dieCell = dieNo
                        ? `<a class="die-claim-wo-link" href="${window.DIE_ROUTES.master}?tab=master&q=${encodeURIComponent(dieNo)}" title="เปิดหน้า Master ของไดร์"><b>${esc(dieNo)}</b></a>`
                        : '<b>-</b>';
                    const woCell = woNo
                        ? `<a class="die-claim-wo-link" href="${window.DIE_ROUTES.woDetail}?wo=${encodeURIComponent(woNo)}" title="เปิดหน้า WO Detail">${esc(woNo)}</a>`
                        : '-';
                    return `<tr>
                        <td>${dieCell}</td>
                        <td>${esc(item.transnumber || '-')}</td>
                        <td>${esc(item.die_description || '-')}</td>
                        <td>${woCell}</td>
                        <td>${esc(item.ordered_size ?? '-')} → ${esc(item.present_diameter ?? '-')}</td>
                        <td><span class="de-chip ${usable ? 'ok' : 'danger'}">${esc(item.status || 'USABLE')}</span></td>
                    </tr>`;
                }).join('')}</tbody>
            </table>` : '<div class="text-muted">ไม่พบ DIE ใน block นี้</div>';
        modal.style.display = 'flex';
    }

    function renderCompleteness(c) {
        const el = document.getElementById('dieCompleteness');
        if (!c || mode !== 'workorder') { el.style.display = 'none'; return; }
        const ok = (c.missing || []).length === 0;
        const color = ok ? '#16a34a' : '#f59e0b';
        const bg    = ok ? '#dcfce7' : '#fef3c7';
        const icon  = ok ? 'check-circle' : 'exclamation-triangle';
        el.style.cssText = `display:block; background:${bg}; border:1px solid ${color}; border-left:4px solid ${color}; border-radius:8px; padding:12px 16px; margin-bottom:12px;`;
        let html = `<div style="font-weight:700; color:#1f2937;"><i class="fa fa-${icon} me-2" style="color:${color};"></i>Block ที่เบิกไดร์แล้ว ${c.covered_block_count}/${c.expected_block_count}</div>`;
        if (!ok) {
            html += '<div style="margin-top:6px;">Block ที่ยังไม่เบิก: ' +
                c.missing.map(m => `<span class="de-chip danger me-1">${m.workseq ? 'WS'+m.workseq+' / ' : ''}B${m.block_no}${m.block_name ? ' ('+m.block_name+')' : ''}</span>`).join('') +
                '</div>';
        }
        el.innerHTML = html;
    }

    async function loadSideWidgets() {
        const grid = document.getElementById('dieSideGrid');
        if (mode === 'workorder') { grid.style.display = 'none'; return; }
        grid.style.display = '';
        const conn = document.getElementById('filterConn').value;
        const category = categoryMs ? categoryMs.getValue() : (document.getElementById('filterCategory')?.value || '');

        try {
            const [topRes, outRes] = await Promise.all([
                fetch(window.DIE_ROUTES.topConsumers + '?' + new URLSearchParams({ connection: conn, category }).toString()).then(r => r.json()),
                fetch(window.DIE_ROUTES.topOutput    + '?' + new URLSearchParams({ connection: conn, category }).toString()).then(r => r.json()),
            ]);
            const masterUrl = window.DIE_ROUTES.master;
            const top = (topRes.rows || []).map(r => `
                <tr class="clickable" data-dept="${(r.dept || '').replace(/"/g, '&quot;')}" title="คลิกเพื่อดู Current Location">
                    <td>${r.dept ?? ''}</td>
                    <td class="num">${Number(r.total_kg ?? 0).toLocaleString(undefined, {maximumFractionDigits: 0})}</td>
                    <td class="num">${r.unique_die ?? 0}</td>
                </tr>`).join('');
            document.getElementById('topConsumers').innerHTML = top
                ? `<table class="de-table"><thead><tr><th>Dept</th><th class="num">FG kg</th><th class="num">Die</th></tr></thead><tbody>${top}</tbody></table>`
                : '<div class="text-muted small">ไม่พบข้อมูล</div>';
            document.querySelectorAll('#topConsumers tr[data-dept]').forEach(tr => {
                tr.addEventListener('click', () => {
                    window.location = masterUrl + '?tab=location&q=' + encodeURIComponent(tr.dataset.dept);
                });
            });

            const out = (outRes.rows || []).map((r, i) => `
                <tr class="clickable" data-die="${(r.equipnumber || '').replace(/"/g, '&quot;')}" title="คลิกเพื่อดู Die Profile">
                    <td>${i + 1}</td>
                    <td><b>${r.equipnumber ?? ''}</b></td>
                    <td>${r.die_description ?? ''}</td>
                    <td class="num">${Number(r.total_kg ?? 0).toLocaleString(undefined, {maximumFractionDigits: 0})}</td>
                </tr>`).join('');
            document.getElementById('topOutput').innerHTML = out
                ? `<table class="de-table"><thead><tr><th>#</th><th>Die#</th><th>Description</th><th class="num">FG kg</th></tr></thead><tbody>${out}</tbody></table>`
                : '<div class="text-muted small">ไม่พบข้อมูล</div>';
            document.querySelectorAll('#topOutput tr[data-die]').forEach(tr => {
                tr.addEventListener('click', () => {
                    window.location = masterUrl + '?tab=master&q=' + encodeURIComponent(tr.dataset.die);
                });
            });
        } catch (e) {
            document.getElementById('topConsumers').innerHTML = '<div class="text-danger small">Error</div>';
            document.getElementById('topOutput').innerHTML    = '<div class="text-danger small">Error</div>';
        }
    }

    // ---------- Customer Claim (รอบ 30 วัน) ----------
    let claimState = { rows: [], from: '', to: '', error: false };

    function claimSiteParam() {
        const conn = document.getElementById('filterConn').value;
        return conn === 'pgsqlpcmp' ? 'P' : (conn === 'ALL' ? 'ALL' : 'W');
    }

    function last30Range() {
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - 29);
        const pad = n => String(n).padStart(2, '0');
        const fmt = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        return { from: fmt(from), to: fmt(to) };
    }

    function claimNum(v, d = 2) {
        return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
    }

    async function loadRecentClaims() {
        const banner = document.getElementById('dieClaimBanner');
        const text = document.getElementById('dieClaimBannerText');
        if (!banner || !text || !window.DIE_ROUTES.recentClaims) return;

        const range = last30Range();
        banner.className = 'die-claim-banner loading';
        text.innerHTML = 'กำลังตรวจสอบ Customer Claim ในรอบ 30 วัน…';
        try {
            const params = new URLSearchParams({ date_from: range.from, date_to: range.to, site: claimSiteParam() });
            const res = await fetch(window.DIE_ROUTES.recentClaims + '?' + params.toString(), { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('request failed');
            const data = await res.json();
            claimState = { rows: data.rows || [], from: data.from || range.from, to: data.to || range.to, error: false };
            const count = data.count || 0;
            if (count > 0) {
                const woCount = claimState.rows.reduce((s, r) => s + ((r.workorders && r.workorders.length) || 0), 0);
                banner.className = 'die-claim-banner has-claim';
                text.innerHTML = `🔴 พบ Customer Claim ${count.toLocaleString()} รายการ ในรอบ 30 วัน`
                    + `<small>${esc(data.from)} ถึง ${esc(data.to)}${woCount ? ` · เชื่อมกับ ${woCount.toLocaleString()} WO` : ''} — คลิกเพื่อดูรายละเอียด</small>`;
            } else {
                banner.className = 'die-claim-banner no-claim';
                text.innerHTML = `ไม่พบ Customer Claim ในรอบ 30 วัน<small>${esc(data.from)} ถึง ${esc(data.to)}</small>`;
            }
        } catch (e) {
            claimState = { rows: [], from: range.from, to: range.to, error: true };
            banner.className = 'die-claim-banner';
            text.innerHTML = 'ตรวจสอบ Customer Claim ไม่สำเร็จ<small>คลิกเพื่อลองใหม่</small>';
        }
    }

    function openClaimModal() {
        const modal = document.getElementById('dieClaimModal');
        const body = document.getElementById('dieClaimModalBody');
        const title = document.getElementById('dieClaimModalTitle');
        if (!modal || !body) return;

        const rows = claimState.rows || [];
        title.innerHTML = `<i class="fa fa-box-open me-2 text-danger"></i>Customer Claim · ${esc(claimState.from)} ถึง ${esc(claimState.to)}`;

        if (!rows.length) {
            body.innerHTML = '<div class="de-empty"><div class="icon"><i class="fa fa-inbox"></i></div><div class="text">ไม่พบ Customer Claim ในรอบ 30 วัน</div></div>';
        } else {
            const tableRows = rows.map(r => {
                const wos = r.workorders || [];
                const woHtml = wos.length
                    ? wos.map(wo => `<a class="die-claim-wo-link" href="${window.DIE_ROUTES.woDetail}?wo=${encodeURIComponent(wo)}" title="เปิด WO Detail">${esc(wo)}</a>`).join('')
                    : '<span class="text-muted small">— ไม่พบ WO —</span>';
                return `<tr>
                    <td>${esc(r.transdate || '-')}</td>
                    <td><b>${esc(r.returnnumber || '-')}</b></td>
                    <td><span class="de-chip muted">${esc(r.site || '')}</span></td>
                    <td>${esc(r.sales_order || '-')}</td>
                    <td>${esc(r.customer || '-')}</td>
                    <td class="num">${claimNum(r.qty)}</td>
                    <td class="num">${claimNum(r.amount)}</td>
                    <td>${woHtml}</td>
                </tr>`;
            }).join('');
            body.innerHTML = `
                <div class="small text-muted mb-2">${rows.length.toLocaleString()} รายการ · คลิกหมายเลข WO เพื่อเปิดหน้า Detail</div>
                <div style="overflow:auto;">
                    <table class="de-table" style="min-width:900px;">
                        <thead><tr><th>วันที่</th><th>Claim No.</th><th>Site</th><th>Sales Order</th><th>Customer</th><th class="num">Qty</th><th class="num">Amount</th><th>Work Order</th></tr></thead>
                        <tbody>${tableRows}</tbody>
                    </table>
                </div>`;
        }
        modal.style.display = 'flex';
    }

    (() => {
        const banner = document.getElementById('dieClaimBanner');
        const modal = document.getElementById('dieClaimModal');
        const closeModal = () => { if (modal) modal.style.display = 'none'; };
        const onBanner = () => {
            if (banner.classList.contains('loading')) return;
            if (claimState.error) { loadRecentClaims(); return; }
            openClaimModal();
        };
        banner?.addEventListener('click', onBanner);
        banner?.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onBanner(); } });
        document.getElementById('dieClaimModalClose')?.addEventListener('click', closeModal);
        modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
        document.getElementById('filterConn')?.addEventListener('change', loadRecentClaims);
    })();

    // ---------- ไดร์ที่อยู่บนเครื่อง ณ ปัจจุบัน ----------
    async function loadCurrentMachineDies() {
        const body = document.getElementById('dieMachineBody');
        const meta = document.getElementById('dieMachineMeta');
        if (!body || !window.DIE_ROUTES.currentMachines) return;

        const category = categoryMs ? categoryMs.getValue() : (document.getElementById('filterCategory')?.value || '');
        const dFrom = document.getElementById('dieMachineFrom')?.value || '';
        const dTo   = document.getElementById('dieMachineTo')?.value || '';
        body.innerHTML = '<div class="de-loading"><i class="fa fa-spinner fa-spin me-2"></i>กำลังโหลด…</div>';
        if (meta) meta.textContent = '-';

        try {
            const params = new URLSearchParams({ site: claimSiteParam() });
            if (category) params.set('category', category);
            if (dFrom) params.set('date_from', dFrom);
            if (dTo) params.set('date_to', dTo);
            const res = await fetch(window.DIE_ROUTES.currentMachines + '?' + params.toString(), { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('request failed');
            const data = await res.json();
            const groups = data.groups || [];

            if (!groups.length) {
                body.innerHTML = '<div class="de-empty"><div class="icon"><i class="fa fa-inbox"></i></div><div class="text">ยังไม่มีไดร์ที่ถูกเบิกอยู่บนเครื่อง</div></div>';
                if (meta) meta.textContent = '0 ไดร์';
                return;
            }

            if (meta) {
                const rangeTxt = (data.date_from || data.date_to)
                    ? ` · ขึ้นเครื่อง ${data.date_from || '…'}${data.date_to && data.date_to !== data.date_from ? ' ถึง ' + data.date_to : ''}`
                    : ' · ทั้งหมด';
                meta.textContent = `${Number(data.total_dies || 0).toLocaleString()} ไดร์ · ${Number(data.machine_count || 0).toLocaleString()} เครื่อง${rangeTxt}`;
            }

            // ในแต่ละ card จัดกลุ่มไดร์ตาม WO; แต่ละ WO โชว์ 3 ตัว ที่เหลือกดปุ่มขยายราย WO
            const PER_WO_LIMIT = 3;

            body.innerHTML = '<div class="die-machine-grid">' + groups.map((g, gi) => {
                const unknown = !g.machine_number;
                const dies = g.dies || [];

                // จัดกลุ่มตาม WO (รักษาลำดับที่เจอ)
                const woOrder = [];
                const byWo = {};
                dies.forEach((d) => {
                    const wo = (d.workordernumber || '').trim() || '__nowo__';
                    if (!byWo[wo]) { byWo[wo] = []; woOrder.push(wo); }
                    byWo[wo].push(d);
                });

                const woGroups = woOrder.map((wo, wi) => {
                    const list = byWo[wo];
                    const collapsible = list.length > PER_WO_LIMIT;
                    const woGid = `mb${gi}-wo${wi}`;
                    const chips = list.map((d, di) => {
                        const href = window.DIE_ROUTES.master + '?tab=master&q=' + encodeURIComponent(d.equipnumber || '');
                        const extraCls = collapsible && di >= PER_WO_LIMIT ? ' extra' : '';
                        return `<a class="die-machine-chip${extraCls}" href="${href}" title="${esc(d.die_description || '')}${d.equipcategory ? ' · ' + esc(d.equipcategory) : ''}${d.current_dept ? ' · ' + esc(d.current_dept) : ''}">
                            <span class="dno">${esc(d.equipnumber || '')}</span>
                            ${d.die_description ? `<span class="dmeta">${esc(d.die_description)}</span>` : ''}
                        </a>`;
                    }).join('');
                    const woLabel = wo === '__nowo__' ? 'ไม่มี WO' : ('WO ' + esc(wo));
                    const expandBtn = collapsible
                        ? `<button type="button" class="die-machine-expand wo-expand" data-wogroup="${woGid}">
                               <i class="fa fa-chevron-down me-1"></i><span>ขยาย (+${list.length - PER_WO_LIMIT})</span>
                           </button>`
                        : '';
                    return `
                        <div class="wo-group${collapsible ? ' collapsed' : ''}" id="${woGid}">
                            <div class="wo-subhead"><span>${woLabel}</span><span class="wo-count">${list.length} ไดร์</span></div>
                            <div class="wo-chips">${chips}</div>
                            ${expandBtn}
                        </div>`;
                }).join('');

                const name = unknown
                    ? 'ไม่ทราบเครื่อง <small>WO ไม่พบใน MFG / ไม่มี WO</small>'
                    : `${esc(g.machine_number)}${g.machine_desc ? `<small>${esc(g.machine_desc)}</small>` : ''}`;
                return `
                    <div class="die-machine-box${unknown ? ' unknown' : ''}" id="mb${gi}">
                        <div class="mhead die-machine-card-toggle" data-mbox="mb${gi}" title="คลิกเพื่อย่อ/ขยาย card">
                            <span class="mname">${name}</span>
                            <span class="mhead-right">
                                <span class="mcount">${Number(g.count || 0).toLocaleString()} ไดร์</span>
                                <i class="fa fa-chevron-down mtoggle-icon"></i>
                            </span>
                        </div>
                        <div class="mbody">${woGroups}</div>
                    </div>`;
            }).join('') + '</div>';
        } catch (e) {
            body.innerHTML = '<div class="text-danger small">โหลดข้อมูลเครื่องไม่สำเร็จ</div>';
            if (meta) meta.textContent = '-';
        }
    }

    // ย่อ/ขยายทั้ง card (คลิกที่หัว card)
    document.getElementById('dieMachineBody')?.addEventListener('click', (e) => {
        const head = e.target.closest('.die-machine-card-toggle');
        if (!head) return;
        const box = document.getElementById(head.dataset.mbox);
        if (!box) return;
        box.classList.toggle('card-collapsed'); // CSS หมุน chevron + ซ่อน body เอง
    });

    // ขยาย/ย่อ ราย WO group (delegated เพราะ #dieMachineBody ถูก re-render ทุกครั้ง)
    document.getElementById('dieMachineBody')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.die-machine-expand');
        if (!btn) return;
        const group = document.getElementById(btn.dataset.wogroup);
        if (!group) return;
        const collapsed = group.classList.toggle('collapsed');
        const icon = btn.querySelector('i');
        const label = btn.querySelector('span');
        const hidden = group.querySelectorAll('.die-machine-chip.extra').length;
        if (collapsed) {
            icon.className = 'fa fa-chevron-down me-1';
            label.textContent = `ขยาย (+${hidden})`;
        } else {
            icon.className = 'fa fa-chevron-up me-1';
            label.textContent = 'ย่อ';
        }
    });

    document.getElementById('filterConn')?.addEventListener('change', loadCurrentMachineDies);
    document.getElementById('dieMachineFrom')?.addEventListener('change', loadCurrentMachineDies);
    document.getElementById('dieMachineTo')?.addEventListener('change', loadCurrentMachineDies);
    document.getElementById('dieMachineClear')?.addEventListener('click', () => {
        const f = document.getElementById('dieMachineFrom');
        const t = document.getElementById('dieMachineTo');
        if (f) f.value = '';
        if (t) t.value = '';
        loadCurrentMachineDies();
    });

    async function search() {
        const q = buildQuery();
        if (!q) { setEmpty('กรุณากรอกอย่างน้อย 1 อย่าง: WO# / Date / Year+Week'); return; }
        const { url, params } = q;
        const qs = new URLSearchParams(params).toString();
        updateUrl(params);
        setLoading('กำลังโหลดข้อมูล...');
        try {
            const res = await fetch(url + '?' + qs, { headers: { 'Accept': 'application/json' }});
            if (!res.ok) {
                const err = await res.json().catch(()=>({error:'request failed'}));
                setEmpty('Error: ' + (err.error || res.status));
                return;
            }
            const data = await res.json();

            renderCompleteness(data.completeness);
            renderExceptions(data.exceptions);
            renderReadiness(data.readiness);
            renderSummary(data.summary || {});
            renderTable(data.rows || []);
            const hasRows = (data.rows || []).length > 0;
            document.getElementById('dieChartsGrid').style.display = hasRows ? '' : 'none';
            if (hasRows) renderCharts(data.charts || {}, mode, data.heatmap || null);
            renderHeatmap(data.heatmap);
            loadSideWidgets();
            loadCurrentMachineDies();
            loadCompare(params, data);
        } catch (e) {
            setEmpty('Error: ' + e.message);
        }
    }

    function doExport() {
        const q = buildQuery();
        if (!q) return;
        const qs = new URLSearchParams({ ...q.params, mode }).toString();
        window.location = window.DIE_ROUTES.export + '?' + qs;
    }

    document.getElementById('btnSearch').addEventListener('click', search);
    document.getElementById('btnExport').addEventListener('click', doExport);
    document.getElementById('btnReset').addEventListener('click', () => {
        document.getElementById('filterWo').value = '';
        document.getElementById('filterWeek').value = '';
        if (categoryMs) categoryMs.clear();
        else if (document.getElementById('filterCategory')) document.getElementById('filterCategory').value = '';
        if (document.getElementById('filterDescription')) document.getElementById('filterDescription').value = '';
        document.getElementById('filterDateFrom').value = new Date().toISOString().slice(0, 10);
        document.getElementById('filterDateTo').value = new Date().toISOString().slice(0, 10);
        document.getElementById('filterYear').value = new Date().getFullYear();
        window.history.replaceState(null, '', window.location.pathname);
        search();
    });

    document.getElementById('filterWo').addEventListener('keydown', e => {
        if (e.key === 'Enter') search();
    });
    document.getElementById('filterDescription')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') search();
    });

    async function loadCategories() {
        const select = document.getElementById('filterCategory');
        if (!select || !window.DIE_ROUTES.categories) return;
        const keep = categoryMs ? categoryMs.getValue() : select.value;
        const conn = document.getElementById('filterConn').value;
        try {
            const res = await fetch(window.DIE_ROUTES.categories + '?' + new URLSearchParams({ connection: conn }).toString());
            const data = await res.json();
            select.innerHTML = '<option value="">All categories</option>' + (data.rows || []).map(r => {
                const value = esc(r.category || '');
                const count = r.die_count == null ? '' : ` (${Number(r.die_count).toLocaleString()})`;
                return `<option value="${value}">${esc(r.category || '')}${count}</option>`;
            }).join('');
            const restore = pendingCategory != null ? pendingCategory : keep;
            pendingCategory = null;
            if (categoryMs) { categoryMs.setValue(restore); categoryMs.refresh(); }
            else if ([...select.options].some(o => o.value === restore)) select.value = restore;
        } catch (e) {
            select.innerHTML = '<option value="">All categories</option>';
            if (categoryMs) categoryMs.refresh();
        }
    }

    document.getElementById('filterConn').addEventListener('change', loadCategories);

    const detailBtn = document.getElementById('btnWoDetail');
    if (detailBtn) {
        const updateDetailHref = () => {
            const wo = document.getElementById('filterWo').value.trim().toUpperCase();
            if (wo) {
                detailBtn.href = window.DIE_ROUTES.woDetail + '?wo=' + encodeURIComponent(wo);
                detailBtn.style.opacity = '1';
                detailBtn.style.pointerEvents = '';
            } else {
                detailBtn.href = '#';
                detailBtn.style.opacity = '0.5';
                detailBtn.style.pointerEvents = 'none';
            }
        };
        document.getElementById('filterWo').addEventListener('input', updateDetailHref);
        updateDetailHref();
    }

    // Autocomplete สำหรับช่อง WO#
    if (window.DieAutocomplete && window.DIE_ROUTES.suggestWo) {
        DieAutocomplete.attach(document.getElementById('filterWo'), {
            url: window.DIE_ROUTES.suggestWo,
            getSiteFn: () => document.getElementById('filterConn').value,
            renderItem: (row) => {
                const tags = `${row.brand ? `<small>${row.brand}</small>` : ''}${row.fcat ? `<small>${row.fcat}</small>` : ''}${row.fsize ? `<small>${row.fsize}mm</small>` : ''}`;
                const status = row.open ? '<span class="de-chip info">open</span>' : '<span class="de-chip muted">closed</span>';
                return `<div class="main">${row.value}</div><div class="sub">${status}${tags}</div>`;
            },
            onSelect: (row) => {
                document.getElementById('filterWo').value = row.value;
                search();
            },
        });
    }

    // Autocomplete สำหรับช่อง Description
    if (window.DieAutocomplete && window.DIE_ROUTES.suggestDesc) {
        DieAutocomplete.attach(document.getElementById('filterDescription'), {
            url: window.DIE_ROUTES.suggestDesc,
            getSiteFn: () => document.getElementById('filterConn').value,
            renderItem: (row) => {
                const c = row.die_count == null ? '' : `<small>${Number(row.die_count).toLocaleString()} ไดร์</small>`;
                return `<div class="main">${row.value || ''}</div><div class="sub">${c}</div>`;
            },
            onSelect: (row) => {
                document.getElementById('filterDescription').value = row.value;
                search();
            },
        });
    }

    // Multi-select สำหรับ Die Category (เลือกได้หลายตัว)
    if (window.DieMultiSelect && document.getElementById('filterCategory')) {
        categoryMs = DieMultiSelect.enhance(document.getElementById('filterCategory'), { allLabel: 'All categories' });
    }

    applyUrlParams();
    loadCategories();
    loadRecentClaims();
    loadCurrentMachineDies();
    document.getElementById('filterWo').dispatchEvent(new Event('input'));
    // Auto-search ตอนเปิดหน้า: ถ้ามี WO# default -> ใช้ WO, ไม่งั้นใช้ Date range วันนี้
    setTimeout(() => search(), 100);
})();
