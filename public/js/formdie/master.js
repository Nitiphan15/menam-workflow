(function () {
    'use strict';

    let mode = 'master';
    let suppressTabSearch = true; // กันไม่ให้ tab.click() ตอน init ยิง search ก่อนค่าจาก URL ถูกเซ็ต (กัน race ทับผล filter)
    let allRows = [];
    let currentPage = 1;
    let pageSize = 50;
    let categoryMs = null;        // multi-select instance ของ Category
    let pendingCategory = null;   // ค่า category จาก URL ที่รอ apply หลังโหลด options

    const tabs = document.querySelectorAll('.de-tabs button');
    tabs.forEach(b => b.addEventListener('click', () => {
        mode = b.dataset.mode;
        tabs.forEach(t => t.classList.toggle('active', t === b));
        document.querySelectorAll('[data-show]').forEach(el => {
            el.style.display = el.dataset.show.split(',').includes(mode) ? '' : 'none';
        });
        if (mode === 'material') {
            setEmpty('กรอก Heat No หรือ Coil No แล้วกดค้นหา');
        } else if (!suppressTabSearch) {
            search();
        }
    }));

    const result = document.getElementById('dmResult');

    function transferClass(label, classId) {
        if (label) return label;
        if (Number(classId) === 0) return 'Repair';
        if (Number(classId) === -1) return 'Store';
        return '-';
    }

    function setLoading(msg) {
        document.getElementById('dmPager').style.display = 'none';
        result.innerHTML = `<div class="de-loading"><i class="fa fa-spinner fa-spin me-2"></i>${msg}</div>`;
    }
    function setEmpty(msg) {
        document.getElementById('dmPager').style.display = 'none';
        const meta = document.getElementById('dmResultMeta');
        if (meta) meta.textContent = '-';
        result.innerHTML = `<div class="de-empty"><div class="icon"><i class="fa fa-inbox"></i></div><div class="text">${msg}</div></div>`;
    }

    function updateCardMeta(total) {
        const label = mode === 'master' ? 'Master List' : (mode === 'location' ? 'Current Location' : 'Material Trace');
        const title = document.querySelector('#dmCardTitle span:first-child');
        const meta = document.getElementById('dmResultMeta');
        if (title) title.innerHTML = `<i class="fa fa-database me-2 text-primary"></i>${label}`;
        if (meta) meta.textContent = total ? `${Number(total).toLocaleString()} rows` : '-';
    }

    function updateUrl(params) {
        const next = new URLSearchParams();
        next.set('tab', mode);
        Object.entries(params || {}).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') next.set(key, value);
        });
        window.history.replaceState(null, '', window.location.pathname + '?' + next.toString());
    }

    function paginate() {
        const total = allRows.length;
        if (total === 0) { setEmpty('ไม่พบข้อมูล'); return []; }
        updateCardMeta(total);
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        const start = (currentPage - 1) * pageSize;
        const end   = Math.min(start + pageSize, total);

        document.getElementById('dmPager').style.display = '';
        document.getElementById('dmPagerInfo').textContent = `แสดง ${start + 1}-${end} จาก ${total} รายการ`;
        document.getElementById('dmPageInfo').textContent  = `หน้า ${currentPage} / ${totalPages}`;
        document.getElementById('dmFirst').disabled = currentPage === 1;
        document.getElementById('dmPrev').disabled  = currentPage === 1;
        document.getElementById('dmNext').disabled  = currentPage === totalPages;
        document.getElementById('dmLast').disabled  = currentPage === totalPages;
        return allRows.slice(start, end);
    }

    // เรียง allRows ตามตัวเลือก dmSort (ทำฝั่ง client เพราะ kg ผลิตคำนวณหลัง query)
    function applySort() {
        const sort = document.getElementById('dmSort')?.value || 'last';
        const num = (v) => Number(v ?? 0);
        if (sort === 'kg_desc')        allRows.sort((a, b) => num(b.total_kg) - num(a.total_kg));
        else if (sort === 'meter_desc') allRows.sort((a, b) => num(b.total_meter) - num(a.total_meter));
        else if (sort === 'used_desc')  allRows.sort((a, b) => num(b.usage_count) - num(a.usage_count));
        // 'last' = คงลำดับจาก server (last_used DESC)
    }

    function rerender() {
        const slice = paginate();
        if (mode === 'master')        renderMasterTable(slice);
        else if (mode === 'material') renderMaterialTable(slice);
        else                          renderLocationTable(slice);
    }

    function statusBadge(s) {
        if (!s) return '';
        const cls = s === 'USABLE' ? 'ok' : (s === 'SCRAP' ? 'danger' : 'warn');
        return `<span class="de-chip ${cls}">${s}</span>`;
    }

    function renderMasterTable(rows) {
        const head = `<thead><tr>
            <th>Site</th><th>Die#</th><th>Description</th><th>Category</th><th>Type</th><th>Supplier</th>
            <th>Used</th><th>Total Meter</th><th>kg ผลิต</th><th>Last Used</th>
            <th title="ตำแหน่ง/แผนกปัจจุบันของไดร์ (จาก transfer ล่าสุด)">ตำแหน่งปัจจุบัน</th>
            <th title="เครื่องผลิตจริงล่าสุดของไดร์ (จาก WO ผลิตล่าสุด)">เครื่องปัจจุบัน</th>
            <th>Ordered Size</th><th>Present Diameter</th><th>Reduction / Angle</th><th>Bearing Length</th><th>Status</th>
        </tr></thead>`;
        const body = rows.map(r => `
            <tr class="clickable" data-die="${r.equipnumber}" data-site="${r.site ?? ''}" title="คลิกเพื่อดู profile">
                <td>${r.site ?? ''}</td>
                <td><b>${r.equipnumber ?? ''}</b></td>
                <td>${r.die_description ?? ''}</td>
                <td>${r.equipcategory ?? ''}</td>
                <td>${r.equiptype ?? ''}</td>
                <td>${r.supplier ?? ''}</td>
                <td class="num">${Number(r.usage_count ?? 0).toLocaleString()}</td>
                <td class="num">${Number(r.total_meter ?? 0).toLocaleString()}</td>
                <td class="num">${Number(r.total_kg ?? 0).toLocaleString()}</td>
                <td>${r.last_used_at ?? ''}</td>
                <td>${r.current_class
                    ? `${r.current_class}${r.last_move_date ? ` <span class="text-muted small">(${String(r.last_move_date).substring(0, 10)})</span>` : ''}`
                    : '<span class="text-muted">—</span>'}</td>
                <td>${r.current_machine
                    ? `<b>${r.current_machine}</b>${r.current_machine_desc ? ` <span class="text-muted small">${r.current_machine_desc}</span>` : ''}${r.last_prod_wo ? `<div class="text-muted small">WO ${r.last_prod_wo}</div>` : ''}`
                    : '<span class="text-muted">—</span>'}</td>
                <td>${r.ordered_size ?? r.size_in ?? ''}</td>
                <td>${r.present_diameter ?? r.size_out ?? ''}</td>
                <td>${r.reduction_area_angle ?? r.f3 ?? ''}</td>
                <td>${r.bearing_length ?? r.f4 ?? ''}</td>
                <td>${statusBadge(r.status)}</td>
            </tr>
        `).join('');
        result.innerHTML = `<table class="de-table">${head}<tbody>${body}</tbody></table>`;
        bindRowClick();
    }

    function renderMaterialTable(rows) {
        const head = `<thead><tr>
            <th>Heat No</th><th>Coil No</th><th>WO#</th><th>MFG</th>
            <th>Brand</th><th>Size</th><th>Workseq</th><th>Workcenter</th><th>Machine</th>
            <th style="text-align:right;">Receive Qty</th><th>Receive Date</th><th>Receive By</th>
            <th>Dies Used (Block → Die#)</th>
        </tr></thead>`;
        const body = rows.map(r => {
            const dies = (r.dies_used || []).map(d => {
                const block = d.block_no ? `B${d.block_no}` : (d.block_desc || '-');
                return `<span style="display:inline-block; padding:2px 6px; margin:1px;
                        background:#dbeafe; border-radius:4px; font-size:11px; cursor:pointer;"
                        data-die="${d.equipnumber}" title="${d.die_description || ''} (${d.ordered_size ?? d.size_in ?? ''}→${d.present_diameter ?? d.size_out ?? ''})">
                        ${block}: ${d.equipnumber || ''}</span>`;
            }).join(' ');
            const woLink = r.workordernumber
                ? `<a href="${window.DM_ROUTES.woDetail}?wo=${encodeURIComponent(r.workordernumber)}" title="เปิด WO Detail Sheet"><b>${r.workordernumber}</b></a>`
                : '';
            return `
                <tr>
                    <td><b>${r.heatno ?? ''}</b></td>
                    <td>${r.coilno ?? ''}</td>
                    <td>${woLink}</td>
                    <td>${r.ordnumber ?? ''}</td>
                    <td>${r.brand ?? ''}</td>
                    <td>${r.fsize ?? ''}</td>
                    <td>${r.workseq ?? ''}</td>
                    <td>${r.workcenter ?? ''}${r.workcenter_desc ? ' - ' + r.workcenter_desc : ''}</td>
                    <td>${r.used_machine ?? r.machine_number ?? ''}</td>
                    <td class="num">${r.receive_qty != null ? Number(r.receive_qty).toLocaleString() : ''}</td>
                    <td>${r.receivestamp ?? ''}</td>
                    <td>${r.receiveby ?? ''}</td>
                    <td>${dies || '<span class="text-muted">ไม่มีไดร์</span>'}</td>
                </tr>
            `;
        }).join('');
        result.innerHTML = `<table class="de-table">${head}<tbody>${body}</tbody></table>`;

        document.querySelectorAll('#dmResult span[data-die]').forEach(s => {
            s.addEventListener('click', () => openProfile(s.dataset.die));
        });
    }

    function renderLocationTable(rows) {
        const head = `<thead><tr>
            <th>Site</th><th>Die#</th><th>Description</th><th>Category</th><th>Status</th>
            <th>Current Dept</th><th>Last Move</th><th>Last WO</th><th>Last Trans#</th>
        </tr></thead>`;
        const body = rows.map(r => {
            const woLink = r.last_workorder
                ? `<a href="${window.DM_ROUTES.woDetail}?wo=${encodeURIComponent(r.last_workorder)}" onclick="event.stopPropagation()">${r.last_workorder}</a>`
                : '';
            return `
            <tr class="clickable" data-die="${r.equipnumber}" data-site="${r.site ?? ''}" title="คลิกเพื่อดู profile">
                <td>${r.site ?? ''}</td>
                <td><b>${r.equipnumber ?? ''}</b></td>
                <td>${r.die_description ?? ''}</td>
                <td>${r.equipcategory ?? ''}</td>
                <td>${statusBadge(r.status)}</td>
                <td>${r.current_class ?? '<span class="text-muted">ยังไม่มีการเบิก</span>'}</td>
                <td>${r.last_move_date ?? ''}</td>
                <td>${woLink}</td>
                <td>${r.last_trans ?? ''}</td>
            </tr>
        `;
        }).join('');
        result.innerHTML = `<table class="de-table">${head}<tbody>${body}</tbody></table>`;
        bindRowClick();
    }

    function bindRowClick() {
        document.querySelectorAll('#dmResult tr.clickable').forEach(tr => {
            tr.addEventListener('click', () => openProfile(tr.dataset.die, tr.dataset.site));
        });
    }

    async function search() {
        const conn = document.getElementById('dmSite').value;
        const category = categoryMs ? categoryMs.getValue() : (document.getElementById('dmCategory')?.value || '');
        setLoading('กำลังโหลด...');

        let url, params;
        if (mode === 'material') {
            const heatno = document.getElementById('dmHeatno').value.trim();
            const coilno = document.getElementById('dmCoilno').value.trim();
            if (!heatno && !coilno) { setEmpty('กรุณากรอก Heat No หรือ Coil No'); return; }
            url    = window.DM_ROUTES.material;
            params = { site: conn === 'pgsqlpcmp' ? 'P' : 'W', heatno, coilno, category };
        } else {
            const q  = document.getElementById('dmQuery').value.trim();
            const st = document.getElementById('dmStatus').value;
            const etype = document.getElementById('dmType')?.value || '';
            url    = mode === 'master' ? window.DM_ROUTES.master : window.DM_ROUTES.location;
            params = { connection: conn, q, category };
            if (etype) params.equiptype = etype;
            if (mode === 'master' && st) params.status = st;
            if (mode === 'master') {
                const vendor = document.getElementById('dmSupplier')?.value || '';
                const desc   = document.getElementById('dmDesc')?.value.trim() || '';
                if (vendor) params.vendor = vendor;
                if (desc)   params.description = desc;
            }
            if (mode === 'location') {
                const days = document.getElementById('dmDays').value.trim();
                if (days) params.days = days;
            }
        }
        const qs = new URLSearchParams(params).toString();
        // URL ใช้ site=W/P/ALL (ไม่โชว์ชื่อ connection ภายใน เช่น pgsqlpcmw)
        const urlParams = { ...params, site: conn === 'pgsqlpcmp' ? 'P' : (conn === 'ALL' ? 'ALL' : 'W') };
        delete urlParams.connection;
        updateUrl(urlParams);

        try {
            const res  = await fetch(url + '?' + qs);
            const data = await res.json();
            if (data.error) { setEmpty('Error: ' + data.error); return; }
            allRows = data.rows || [];
            if (mode === 'master') applySort();
            updateCardMeta(allRows.length);
            currentPage = 1;
            rerender();
        } catch (e) {
            setEmpty('Error: ' + e.message);
        }
    }

    async function openProfile(equipnumber, site) {
        // เมื่อ Site = ALL ต้องใช้ site ของแถวที่คลิก เพื่อชี้ฐานให้ถูก
        const dmSiteVal = document.getElementById('dmSite').value;
        const conn = dmSiteVal === 'ALL' ? (site === 'P' ? 'pgsqlpcmp' : 'pgsqlpcmw') : dmSiteVal;
        document.getElementById('dmModalBg').style.display = 'flex';
        document.getElementById('dmModalTitle').textContent = 'Die Profile — ' + equipnumber;
        document.getElementById('dmModalBody').innerHTML = '<div class="dm-loading">กำลังโหลด...</div>';

        try {
            const res  = await fetch(window.DM_ROUTES.profile + '/' + encodeURIComponent(equipnumber) + '?connection=' + conn, { cache: 'no-store' });
            const data = await res.json();
            renderProfile(data);
        } catch (e) {
            document.getElementById('dmModalBody').innerHTML = '<div class="dm-empty">Error: ' + e.message + '</div>';
        }
    }

    let lifecycleChart = null;

    function renderLifecycleChart(series, unit) {
        if (lifecycleChart) { lifecycleChart.destroy(); lifecycleChart = null; }
        const canvas = document.getElementById('dmLifecycleChart');
        if (!canvas || !series || series.length === 0) return;

        const isKg = unit === 'kg';
        const perLabel = isKg ? 'kg ต่อ WO' : 'meter ต่อครั้ง';
        const cumLabel = isKg ? 'kg สะสม' : 'meter สะสม';

        const sorted = [...series].sort((a, b) => (a.date || '').localeCompare(b.date || ''));
        let cum = 0;
        const labels = [];
        const cumData = [];
        const perData = [];
        sorted.forEach(it => {
            const v = Number(it.value ?? 0);
            cum += v;
            labels.push((it.date || '') + (it.wo ? ' · ' + it.wo : ''));
            cumData.push(cum);
            perData.push(v);
        });

        lifecycleChart = new Chart(canvas.getContext('2d'), {
            data: {
                labels,
                datasets: [
                    { type: 'bar',  label: perLabel, data: perData, backgroundColor: 'rgba(37,99,235,.4)', yAxisID: 'y1' },
                    { type: 'line', label: cumLabel, data: cumData, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.15)', fill: true, tension: .3, yAxisID: 'y' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y:  { type: 'linear', position: 'left',  beginAtZero: true, title: { display: true, text: 'สะสม (' + (isKg ? 'kg' : 'meter') + ')' } },
                    y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: (isKg ? 'ต่อ WO (kg)' : 'ต่อครั้ง (meter)') } },
                }
            }
        });
    }

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[m]));
    }

    function healthClass(level) {
        return level === 'good' ? 'ok' : (level === 'watch' ? 'warn' : 'danger');
    }

    function daysSince(dateStr) {
        if (!dateStr) return null;
        const d = new Date(String(dateStr).slice(0, 10));
        if (isNaN(d.getTime())) return null;
        return Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000));
    }

    function renderHealthAndMaintenance(summary) {
        const health = summary.health || {};
        const maintenance = summary.maintenance || {};
        const totalKg = Number(health.total_kg ?? maintenance.total_kg ?? summary.total_kg ?? 0);
        const totalMeter = Number(summary.total_meter || 0);
        const woCount = Number(health.wo_count || summary.unique_wo || 0);
        const transCount = Number(summary.total_trans || 0);
        const statusLabel = health.label || maintenance.label || 'USABLE';
        const statusClass = healthClass(health.level);
        const currentClass = summary.current_class || 'ยังไม่มีการเบิก';
        const lastMove = summary.last_move || null;
        const idle = daysSince(lastMove);
        const note = maintenance.note || 'ไม่มี limit — เปลี่ยนสถานะ die เมื่อชำรุด';

        return `
            <div class="dm-profile-summary">
                <div class="de-sub-card">
                    <div class="title">การใช้งานสะสม (kg ที่ผลิต)</div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <div style="font-size:28px; font-weight:900;">${totalKg.toLocaleString()}</div>
                        <span style="font-size:13px; color:#667085; font-weight:600;">kg</span>
                    </div>
                    <div class="small text-muted mt-1">
                        ผ่าน ${woCount.toLocaleString()} WO · meter สะสม ${totalMeter.toLocaleString()} · เบิก ${transCount.toLocaleString()} ครั้ง
                    </div>
                </div>
                <div class="de-sub-card">
                    <div class="title">สถานะ &amp; ตำแหน่ง Die</div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span class="de-chip ${statusClass}" style="font-size:.78rem;">${esc(statusLabel)}</span>
                        <span class="small text-muted">ตำแหน่งปัจจุบัน: <b>${esc(currentClass)}</b></span>
                    </div>
                    <div class="small text-muted mt-1">
                        เคลื่อนไหวล่าสุด: ${esc(lastMove || '-')}${idle !== null ? ` (ไม่เคลื่อนไหว ${idle.toLocaleString()} วัน)` : ''}
                    </div>
                    <div class="small text-muted mt-1"><i class="fa fa-circle-info me-1"></i>${esc(note)}</div>
                </div>
            </div>
        `;
    }

    // ข้อ 10: รายการ MFG (เลข WO ผลิตจริงไม่ซ้ำ) ที่ไดร์ตัวนี้เคยใช้ผลิต
    // ใช้ prod_wo (WO ผลิตจริงจากแถวเบิก) ไม่ใช่ f3 ดิบบนแถวคืน (รหัสสเปค) — เรียงลำดับ + มีช่องค้นหา
    function renderMfgList(hist) {
        const seen = new Set();
        const wos = [];
        (hist || []).forEach(h => {
            // ใช้เฉพาะ prod_wo (WO ผลิตจริงจากการเบิก) — แถวซ่อม/รหัสสเปคบนแถวคืนจะไม่มี prod_wo จึงไม่ถูกนับ
            const wo = (h.prod_wo || '').trim();
            if (wo && wo !== '-' && !seen.has(wo)) { seen.add(wo); wos.push(wo); }
        });
        if (!wos.length) return '';
        wos.sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()));
        const chips = wos.map(wo =>
            `<a href="${window.DM_ROUTES.woDetail}?wo=${encodeURIComponent(wo)}" class="dm-mfg-chip"
                data-wo="${esc(wo.toLowerCase())}"
                style="display:inline-block; padding:3px 8px; margin:2px; background:#eef2ff;
                       border:1px solid #c7d2fe; border-radius:6px; font-size:12px; font-weight:700;
                       color:#3730a3; text-decoration:none;" title="เปิด WO Detail Sheet">${esc(wo)}</a>`
        ).join(' ');
        return `
            <div style="display:flex; align-items:center; gap:10px; margin:14px 0 8px; flex-wrap:wrap;">
                <h6 style="font-weight:700; margin:0;">MFG ที่เคยผลิต</h6>
                <span class="small text-muted" id="dmMfgCount">(${wos.length} รายการ)</span>
                <input type="text" id="dmMfgSearch" placeholder="ค้นหา MFG…" autocomplete="off"
                       style="margin-left:auto; max-width:180px; font-size:12px; padding:3px 8px;
                              border:1px solid #cbd5e1; border-radius:6px;">
            </div>
            <div id="dmMfgChips" style="max-height:160px; overflow-y:auto; margin-bottom:14px;">${chips}</div>
        `;
    }

    function renderTimeline(summary) {
        const items = summary.timeline || [];
        if (!items.length) return '';
        return `
            <h6 style="font-weight:700; margin:14px 0 8px;">Usage Timeline <span class="small text-muted" style="font-weight:500;">(${items.length} รายการ)</span></h6>
            <div style="border-left:3px solid #dbeafe; padding-left:12px; margin:0 0 14px 6px; max-height:240px; overflow-y:auto;">
                ${items.map(item => `
                    <div style="position:relative; padding:0 0 10px 10px;">
                        <div style="position:absolute; left:-18px; top:4px; width:9px; height:9px; background:#2563eb; border-radius:50%;"></div>
                        <div style="font-weight:800; color:#1f2937;">${esc(item.date || '')} ${(item.prod_wo || item.workordernumber) ? `- ${esc(item.prod_wo || item.workordernumber)}` : ''}</div>
                        <div class="small text-muted">${esc(transferClass(item.from_class, item.from_class_id))} → ${esc(transferClass(item.to_class, item.to_class_id))} / ${Number(item.meter || 0).toLocaleString()} m / ${esc(item.block_desc || '')}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    function renderProfile(data) {
        const info = data.info || {};
        const s    = data.summary || {};
        const hist = data.history || [];

        const summary = `
            <div class="dm-profile-summary">
                <div class="de-sub-card"><div class="title">Description</div><div>${info.die_description ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Category</div><div>${info.equipcategory ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Status</div><div>${statusBadge(info.status)}</div></div>
                <div class="de-sub-card"><div class="title">Ordered Size</div><div>${info.ordered_size ?? info.size_in ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Present Diameter</div><div>${info.present_diameter ?? info.size_out ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Reduction / Entry Angle</div><div>${info.reduction_area_angle ?? info.f3 ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Bearing Length</div><div>${info.bearing_length ?? info.f4 ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Current Dept</div><div>${s.current_class ?? 'ยังไม่มีการเบิก'}</div></div>
                <div class="de-sub-card"><div class="title">Last Move</div><div>${s.last_move ?? '-'}</div></div>
                <div class="de-sub-card"><div class="title">Total Trans</div><div style="font-size:18px; font-weight:800;">${s.total_trans ?? 0}</div></div>
                <div class="de-sub-card"><div class="title">Total Meter</div><div style="font-size:18px; font-weight:800;">${Number(s.total_meter ?? 0).toLocaleString()}</div></div>
                <div class="de-sub-card"><div class="title">Unique WO</div><div style="font-size:18px; font-weight:800;">${s.unique_wo ?? 0}</div></div>
                <div class="de-sub-card"><div class="title">Unique Dept</div><div style="font-size:18px; font-weight:800;">${s.unique_dept ?? 0}</div></div>
            </div>
        `;

        const hHead = `<thead><tr>
            <th>Trans#</th><th>Date</th><th>WO#</th><th>Block</th>
            <th>From → To</th><th>Qty</th><th>Meter</th>
        </tr></thead>`;
        const hBody = hist.map(h => {
            const wo = h.prod_wo || h.workordernumber;   // โชว์ WO ผลิตจริง (แถวคืนใช้ WO ของแถวเบิก)
            return `
            <tr>
                <td>${h.transnumber ?? ''}</td>
                <td>${h.transdate ?? ''}</td>
                <td>${wo ? `<a href="${window.DM_ROUTES.woDetail}?wo=${encodeURIComponent(wo)}">${wo}</a>` : ''}</td>
                <td>${h.block_desc ?? ''}</td>
                <td>${esc(transferClass(h.from_class, h.from_class_id))} → ${esc(transferClass(h.to_class, h.to_class_id))}</td>
                <td style="text-align:right;">${h.qty ?? ''}</td>
                <td style="text-align:right;">${h.meter != null ? Number(h.meter).toLocaleString() : ''}</td>
            </tr>
        `;
        }).join('');
        const histTable = hist.length
            ? `<div class="de-table-wrap" style="max-height:400px;"><table class="de-table">${hHead}<tbody>${hBody}</tbody></table></div>`
            : '<div class="de-empty"><div class="text">ไม่มีประวัติการเคลื่อนไหว</div></div>';

        // ใช้ kg ต่อ WO ถ้ามี; ถ้าไม่มี (die ไม่มีเลข WO) ย้อนไปโชว์ meter ต่อครั้งจากประวัติ
        const lifecycleKg = s.lifecycle_kg || [];
        const useKg = lifecycleKg.length > 0;
        const series = useKg
            ? lifecycleKg.map(it => ({ date: it.date, wo: it.wo, value: it.kg }))
            : hist.map(h => ({ date: String(h.transdate || '').slice(0, 10), wo: h.workordernumber, value: Number(h.meter || 0) }));
        const chartTitle = useKg ? 'Lifecycle Curve (kg ที่ผลิต ต่อ WO)' : 'Lifecycle Curve (meter ต่อครั้ง — ไม่มีข้อมูล WO/kg)';
        const chartSection = series.length
            ? `<h6 style="font-weight:700; margin:14px 0 8px;">📈 ${chartTitle}</h6>
               <div style="background:#fff; border:1px solid #eef2f7; border-radius:10px; padding:10px; margin-bottom:14px; height:260px;">
                   <canvas id="dmLifecycleChart"></canvas>
               </div>`
            : '';

        document.getElementById('dmModalBody').innerHTML = renderHealthAndMaintenance(s) + summary + renderMfgList(hist) + renderTimeline(s) + chartSection +
            '<h6 style="font-weight:700; margin:14px 0 8px;">ประวัติการเคลื่อนไหว</h6>' + histTable;

        renderLifecycleChart(series, useKg ? 'kg' : 'meter');
        wireMfgSearch();
    }

    // กรองรายการ MFG ตามคำค้น (chip ที่ไม่ตรงจะถูกซ่อน) + อัปเดตตัวนับ
    function wireMfgSearch() {
        const input = document.getElementById('dmMfgSearch');
        const wrap  = document.getElementById('dmMfgChips');
        const count = document.getElementById('dmMfgCount');
        if (!input || !wrap) return;
        const chips = Array.from(wrap.querySelectorAll('.dm-mfg-chip'));
        const total = chips.length;
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            let shown = 0;
            chips.forEach(c => {
                const hit = !q || (c.dataset.wo || '').includes(q);
                c.style.display = hit ? 'inline-block' : 'none';
                if (hit) shown++;
            });
            count.textContent = q ? `(${shown}/${total} รายการ)` : `(${total} รายการ)`;
        });
    }

    document.getElementById('dmFirst').addEventListener('click', () => { currentPage = 1; rerender(); });
    document.getElementById('dmPrev').addEventListener('click',  () => { currentPage--; rerender(); });
    document.getElementById('dmNext').addEventListener('click',  () => { currentPage++; rerender(); });
    document.getElementById('dmLast').addEventListener('click',  () => { currentPage = 99999; rerender(); });
    document.getElementById('dmPageSize').addEventListener('change', (e) => {
        pageSize = parseInt(e.target.value, 10) || 50;
        currentPage = 1;
        rerender();
    });
    document.getElementById('dmSearch').addEventListener('click', search);
    document.getElementById('dmReset').addEventListener('click', () => {
        document.getElementById('dmQuery').value = '';
        document.getElementById('dmStatus').value = '';
        document.getElementById('dmDays').value = 30;
        document.getElementById('dmHeatno').value = '';
        document.getElementById('dmCoilno').value = '';
        if (categoryMs) categoryMs.clear();
        else if (document.getElementById('dmCategory')) document.getElementById('dmCategory').value = '';
        if (document.getElementById('dmType')) document.getElementById('dmType').value = 'ไดร์';
        if (document.getElementById('dmSupplier')) document.getElementById('dmSupplier').value = '';
        if (document.getElementById('dmDesc')) document.getElementById('dmDesc').value = '';
        if (document.getElementById('dmSort')) document.getElementById('dmSort').value = 'last';
        window.history.replaceState(null, '', window.location.pathname);
        Promise.all([loadCategories(), loadStatuses(), loadSuppliers()]).then(search);
    });
    document.getElementById('dmClose').addEventListener('click', () => {
        document.getElementById('dmModalBg').style.display = 'none';
    });
    document.getElementById('dmModalBg').addEventListener('click', (e) => {
        if (e.target.id === 'dmModalBg') document.getElementById('dmModalBg').style.display = 'none';
    });
    document.getElementById('dmQuery').addEventListener('keydown', e => {
        if (e.key === 'Enter') search();
    });

    async function loadEquipTypes() {
        const select = document.getElementById('dmType');
        if (!select || !window.DM_ROUTES.equiptypes) return;
        const current = select.value || 'ไดร์';
        const conn = document.getElementById('dmSite').value;
        try {
            const res = await fetch(window.DM_ROUTES.equiptypes + '?' + new URLSearchParams({ connection: conn }).toString());
            const data = await res.json();
            const opts = (data.rows || []).map(r => {
                const v = esc(r.equiptype || '');
                const c = r.die_count == null ? '' : ` (${Number(r.die_count).toLocaleString()})`;
                return `<option value="${v}">${esc(r.equiptype || '')}${c}</option>`;
            }).join('');
            select.innerHTML = opts + '<option value="ALL">ทั้งหมด</option>';
            // คงค่าที่เลือกไว้ ถ้าไม่มีให้ default ไดร์
            select.value = [...select.options].some(o => o.value === current) ? current : 'ไดร์';
            if (!select.value) select.value = 'ไดร์';
        } catch (e) {
            select.innerHTML = '<option value="ไดร์">ไดร์</option><option value="ALL">ทั้งหมด</option>';
        }
    }

    async function loadStatuses() {
        const select = document.getElementById('dmStatus');
        if (!select || !window.DM_ROUTES.statuses) return;
        const current = select.value;
        const conn = document.getElementById('dmSite').value;
        const etype = document.getElementById('dmType')?.value || 'ไดร์';
        try {
            const res = await fetch(window.DM_ROUTES.statuses + '?' + new URLSearchParams({ connection: conn, equiptype: etype }).toString());
            const data = await res.json();
            select.innerHTML = '<option value="">ทั้งหมด</option>' + (data.rows || []).map(r => {
                const v = esc(r.status || '');
                const c = r.n == null ? '' : ` (${Number(r.n).toLocaleString()})`;
                return `<option value="${v}">${esc(r.status || '')}${c}</option>`;
            }).join('');
            if ([...select.options].some(o => o.value === current)) select.value = current;
        } catch (e) {
            select.innerHTML = '<option value="">ทั้งหมด</option>';
        }
    }

    async function loadCategories() {
        const select = document.getElementById('dmCategory');
        if (!select || !window.DM_ROUTES.categories) return;
        const keep = categoryMs ? categoryMs.getValue() : select.value;
        const conn = document.getElementById('dmSite').value;
        const etype = document.getElementById('dmType')?.value || 'ไดร์';
        try {
            const res = await fetch(window.DM_ROUTES.categories + '?' + new URLSearchParams({ connection: conn, equiptype: etype }).toString());
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

    async function loadSuppliers() {
        const select = document.getElementById('dmSupplier');
        if (!select || !window.DM_ROUTES.suppliers) return;
        const current = select.value;
        const conn = document.getElementById('dmSite').value;
        const etype = document.getElementById('dmType')?.value || 'ไดร์';
        try {
            const res = await fetch(window.DM_ROUTES.suppliers + '?' + new URLSearchParams({ connection: conn, equiptype: etype }).toString());
            const data = await res.json();
            select.innerHTML = '<option value="">All suppliers</option>' + (data.rows || []).map(r => {
                const v = esc(r.supplier || '');
                const c = r.die_count == null ? '' : ` (${Number(r.die_count).toLocaleString()})`;
                return `<option value="${v}">${esc(r.supplier || '')}${c}</option>`;
            }).join('');
            if ([...select.options].some(o => o.value === current)) select.value = current;
        } catch (e) {
            select.innerHTML = '<option value="">All suppliers</option>';
        }
    }

    document.getElementById('dmSite').addEventListener('change', async () => {
        await loadEquipTypes();
        await Promise.all([loadCategories(), loadStatuses(), loadSuppliers()]);
    });
    document.getElementById('dmType')?.addEventListener('change', async () => {
        await Promise.all([loadCategories(), loadStatuses(), loadSuppliers()]);
        search();
    });
    document.getElementById('dmSort')?.addEventListener('change', () => { applySort(); currentPage = 1; rerender(); });
    document.getElementById('dmSupplier')?.addEventListener('change', search);
    document.getElementById('dmDesc')?.addEventListener('keydown', e => { if (e.key === 'Enter') search(); });

    // Autocomplete: Die / Heat / Coil
    if (window.DieAutocomplete) {
        const getSite = () => document.getElementById('dmSite').value;

        if (window.DM_ROUTES.suggestDie) {
            DieAutocomplete.attach(document.getElementById('dmQuery'), {
                url: window.DM_ROUTES.suggestDie,
                getSiteFn: getSite,
                renderItem: (row) => {
                    const status = row.status ? `<span class="de-chip muted">${row.status}</span>` : '';
                    const size = (row.size_in || row.size_out) ? `<small>ordered ${row.size_in || '-'} / present ${row.size_out || '-'}</small>` : '';
                    const cat = row.category ? `<small>${row.category}</small>` : '';
                    return `<div class="main">${row.value}<span style="color:#475569; font-weight:500; margin-left:6px;">${row.description || ''}</span></div><div class="sub">${status}${cat}${size}</div>`;
                },
                onSelect: (row) => { document.getElementById('dmQuery').value = row.value; search(); },
            });
        }
        if (window.DM_ROUTES.suggestDesc) {
            DieAutocomplete.attach(document.getElementById('dmDesc'), {
                url: window.DM_ROUTES.suggestDesc,
                getSiteFn: getSite,
                renderItem: (row) => {
                    const c = row.die_count == null ? '' : `<small>${Number(row.die_count).toLocaleString()} ไดร์</small>`;
                    return `<div class="main">${esc(row.value || '')}</div><div class="sub">${c}</div>`;
                },
                onSelect: (row) => { document.getElementById('dmDesc').value = row.value; search(); },
            });
        }
        if (window.DM_ROUTES.suggestHeat) {
            DieAutocomplete.attach(document.getElementById('dmHeatno'), {
                url: window.DM_ROUTES.suggestHeat,
                getSiteFn: getSite,
                renderItem: (row) => `<div class="main">${row.value}</div>`,
                onSelect: (row) => { document.getElementById('dmHeatno').value = row.value; search(); },
            });
        }
        if (window.DM_ROUTES.suggestCoil) {
            DieAutocomplete.attach(document.getElementById('dmCoilno'), {
                url: window.DM_ROUTES.suggestCoil,
                getSiteFn: getSite,
                renderItem: (row) => `<div class="main">${row.value}</div>`,
                onSelect: (row) => { document.getElementById('dmCoilno').value = row.value; search(); },
            });
        }
    }

    // อ่าน URL params ?tab=...&q=... สำหรับ deep-link จากหน้าอื่น
    const params = new URLSearchParams(window.location.search);
    const urlTab = params.get('tab');
    const urlQ   = params.get('q');
    const urlSite = params.get('site') || params.get('connection');
    const urlStatus = params.get('status');
    const urlDays = params.get('days');
    const urlHeat = params.get('heatno');
    const urlCoil = params.get('coilno');
    const urlCategory = params.get('category');
    const urlType = params.get('equiptype');
    if (urlSite) {
        const connMap = { P: 'pgsqlpcmp', W: 'pgsqlpcmw', ALL: 'ALL', pgsqlpcmp: 'pgsqlpcmp', pgsqlpcmw: 'pgsqlpcmw' };
        document.getElementById('dmSite').value = connMap[urlSite] || 'pgsqlpcmw';
    }
    if (urlCategory) pendingCategory = urlCategory;  // apply หลัง loadCategories
    if (urlTab && ['master', 'location'].includes(urlTab)) {
        const tabBtn = document.querySelector(`.de-tabs button[data-mode="${urlTab}"]`);
        if (tabBtn) tabBtn.click(); // จะตั้ง mode + filter labels
    }
    if (urlStatus) document.getElementById('dmStatus').value = urlStatus;
    if (urlDays) document.getElementById('dmDays').value = urlDays;
    if (urlHeat) document.getElementById('dmHeatno').value = urlHeat;
    if (urlCoil) document.getElementById('dmCoilno').value = urlCoil;
    if (urlQ) {
        if (mode === 'material') {
            // เดาว่าเป็น heat (ตัวเลข+ตัวอักษร) — ใส่ใน heatno
            document.getElementById('dmHeatno').value = urlQ;
        } else {
            document.getElementById('dmQuery').value = urlQ;
        }
    }

    // Multi-select สำหรับ Category (เลือกได้หลายตัว)
    if (window.DieMultiSelect && document.getElementById('dmCategory')) {
        categoryMs = DieMultiSelect.enhance(document.getElementById('dmCategory'), { allLabel: 'All categories' });
    }

    // Auto-search ตอนเปิดหน้า → โหลดประเภท + category แล้วค้นหา
    (async () => {
        await loadEquipTypes();
        if (urlType && document.getElementById('dmType')) {
            const t = document.getElementById('dmType');
            if ([...t.options].some(o => o.value === urlType)) t.value = urlType;
        }
        await Promise.all([loadCategories(), loadStatuses(), loadSuppliers()]);
        if (urlStatus && document.getElementById('dmStatus')) {
            const s = document.getElementById('dmStatus');
            if ([...s.options].some(o => o.value === urlStatus)) s.value = urlStatus;
        }
        suppressTabSearch = false; // เปิดให้ tab ที่ผู้ใช้คลิกหลังจากนี้ทำงานตามปกติ
        search();
    })();
})();
