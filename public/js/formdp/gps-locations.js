(function () {
    'use strict';

    // GPS Phase 1 — customer / ship-to locations. Real data only.
    const root = document.getElementById('gps-locations');
    if (!root) return;

    const COMPANY = [13.547532, 100.787167];
    const customers = JSON.parse(root.dataset.customers || '[]');
    const canEdit = root.dataset.canEdit === '1';
    const defaultRadius = Number(root.dataset.defaultRadius || 150);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const el = (id) => document.getElementById(id);

    const listEl = el('gloc-list');
    const searchEl = el('gloc-search');
    const flashEl = el('gloc-flash');
    const emptyEl = el('gloc-empty');
    const detailEl = el('gloc-detail');
    const form = el('gloc-form');
    const latEl = el('gloc-lat');
    const lonEl = el('gloc-lon');
    const radiusEl = el('gloc-radius');
    const labelEl = el('gloc-label');
    const addressEl = el('gloc-address');
    const sourceEl = el('gloc-source');

    let statusFilter = 'all';
    let current = null; // customer detail from server
    let source = { type: 'manual', poiId: null };
    let map = null;
    let marker = null;
    let circle = null;
    let savedLayer = null;
    let suggestLayer = null;

    const STATUS_LABEL = { pinned: 'ปักแล้ว', suggested: 'มีคำแนะนำ', missing: 'ยังไม่มี' };

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = value == null ? '' : String(value);
        return node.innerHTML;
    }

    function flash(message, ok = true) {
        flashEl.hidden = false;
        flashEl.className = `gloc-flash ${ok ? 'is-ok' : 'is-error'}`;
        flashEl.textContent = message;
        window.clearTimeout(flash.timer);
        flash.timer = window.setTimeout(() => { flashEl.hidden = true; }, 6000);
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body || {}),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) {
            const firstError = payload.errors ? Object.values(payload.errors)[0]?.[0] : null;
            throw new Error(firstError || payload.message || `HTTP ${response.status}`);
        }
        return payload;
    }

    // ---------- list ----------
    function renderList() {
        const keyword = String(searchEl?.value || '').trim().toLowerCase();
        const rows = customers.filter((customer) => (statusFilter === 'all' || customer.status === statusFilter)
            && (!keyword || `${customer.name} ${customer.customer_id} ${customer.company}`.toLowerCase().includes(keyword)));
        listEl.innerHTML = rows.length ? rows.slice(0, 400).map((customer) => {
            const active = current && current.company === customer.company && current.customer_id === customer.customer_id;
            return `<button type="button" class="gloc-row ${active ? 'is-active' : ''}" data-company="${escapeHtml(customer.company)}" data-customer="${customer.customer_id}">
                <span class="gloc-row-top"><strong>${escapeHtml(customer.name || `ลูกค้า #${customer.customer_id}`)}</strong><span class="gloc-status is-${customer.status}">${STATUS_LABEL[customer.status] || '-'}</span></span>
                <small>${escapeHtml(customer.company)} · #${customer.customer_id} · ${customer.line_count} รายการ · ${customer.address_count} ที่อยู่${customer.location_count ? ` · ${customer.location_count} จุด` : ''}</small>
            </button>`;
        }).join('') : '<div class="gloc-list-empty">ไม่พบลูกค้าตามเงื่อนไข</div>';
    }

    listEl?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-customer]');
        if (row) openCustomer(row.dataset.company, Number(row.dataset.customer));
    });
    searchEl?.addEventListener('input', renderList);
    root.querySelectorAll('[data-status]').forEach((chip) => chip.addEventListener('click', () => {
        statusFilter = chip.dataset.status;
        root.querySelectorAll('[data-status]').forEach((other) => other.classList.toggle('is-active', other === chip));
        renderList();
    }));

    // ---------- map ----------
    function ensureMap() {
        if (map || !window.L) return;
        map = L.map('gloc-map', { zoomControl: true }).setView(COMPANY, 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
        L.marker(COMPANY, { icon: L.divIcon({ className: '', html: '<div class="gloc-company"><i class="fas fa-industry"></i></div>', iconSize: [28, 28], iconAnchor: [14, 14] }) })
            .addTo(map).bindTooltip('บริษัท แม่น้ำ สเตนเลสไวร์');
        savedLayer = L.layerGroup().addTo(map);
        suggestLayer = L.layerGroup().addTo(map);
        if (canEdit) map.on('click', (event) => setPoint(event.latlng.lat, event.latlng.lng, { type: 'manual', poiId: null }));
        window.setTimeout(() => map.invalidateSize(), 150);
    }

    function setPoint(lat, lon, nextSource, radius) {
        if (!canEdit || !map) return;
        lat = Number(lat);
        lon = Number(lon);
        if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180 || (lat === 0 && lon === 0)) {
            flash('พิกัดไม่ถูกต้อง', false);
            return;
        }
        latEl.value = lat.toFixed(6);
        lonEl.value = lon.toFixed(6);
        if (radius) radiusEl.value = Math.min(2000, Math.max(20, Math.round(radius)));
        source = nextSource || source;
        sourceEl.textContent = source.type === 'dtc_poi' ? `ที่มา: จุด DTC ${source.poiId}` : 'ที่มา: ปักเอง';
        drawEditMarker();
        map.setView([lat, lon], Math.max(map.getZoom(), 15));
    }

    function drawEditMarker() {
        const lat = Number(latEl.value);
        const lon = Number(lonEl.value);
        if (!Number.isFinite(lat) || !Number.isFinite(lon) || !latEl.value || !lonEl.value) return;
        const radius = Number(radiusEl.value) || defaultRadius;
        if (!marker) {
            // divIcon: Leaflet's default PNG markers are not shipped in public/vendor/leaflet.
            marker = L.marker([lat, lon], {
                draggable: true,
                zIndexOffset: 1000,
                icon: L.divIcon({ className: '', html: '<div class="gloc-pin"><i class="fas fa-location-dot"></i></div>', iconSize: [30, 36], iconAnchor: [15, 34] }),
            }).addTo(map);
            marker.on('dragend', () => {
                const point = marker.getLatLng();
                setPoint(point.lat, point.lng, { type: 'manual', poiId: null });
            });
            circle = L.circle([lat, lon], { radius, color: '#126a83', weight: 2, fillOpacity: .12 }).addTo(map);
        } else {
            marker.setLatLng([lat, lon]);
            circle.setLatLng([lat, lon]).setRadius(radius);
        }
    }

    function clearEditMarker() {
        marker?.remove();
        circle?.remove();
        marker = null;
        circle = null;
    }

    [latEl, lonEl].forEach((input) => input?.addEventListener('change', () => setPoint(latEl.value, lonEl.value, { type: 'manual', poiId: null })));
    radiusEl?.addEventListener('input', drawEditMarker);

    // Google Maps links: ...@lat,lon,... / ?q=lat,lon / !3dlat!4dlon / plain "lat, lon"
    function parseCoordinates(text) {
        const value = String(text || '').trim();
        const patterns = [/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/, /@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/, /[?&](?:q|query|ll|destination)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/, /^\s*(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)\s*$/];
        for (const pattern of patterns) {
            const match = value.match(pattern);
            if (match) return [Number(match[1]), Number(match[2])];
        }
        return null;
    }

    el('gloc-paste-apply')?.addEventListener('click', () => {
        const point = parseCoordinates(el('gloc-paste').value);
        if (!point) {
            flash('อ่านพิกัดจากข้อความไม่ได้ · ลิงก์แบบย่อ (maps.app.goo.gl) ให้เปิดก่อนแล้วคัดลอกลิงก์เต็ม', false);
            return;
        }
        setPoint(point[0], point[1], { type: 'manual', poiId: null });
    });

    // ---------- detail ----------
    async function openCustomer(company, customerId) {
        try {
            const url = root.dataset.showUrl.replace('__COMPANY__', encodeURIComponent(company)).replace('__CUSTOMER__', String(customerId));
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || `HTTP ${response.status}`);
            current = payload.customer;
            renderDetail();
            renderList();
        } catch (error) {
            flash(`โหลดข้อมูลลูกค้าไม่สำเร็จ: ${error.message}`, false);
        }
    }

    function renderDetail() {
        emptyEl.hidden = true;
        detailEl.hidden = false;
        ensureMap();
        el('gloc-name').textContent = current.name || `ลูกค้า #${current.customer_id}`;
        el('gloc-meta').textContent = `${current.company} · #${current.customer_id} · ${current.addresses.length} ที่อยู่ใน 90 วัน`;

        if (addressEl) {
            addressEl.innerHTML = '<option value="">ค่าเริ่มต้นของลูกค้า (ใช้กับทุกที่อยู่)</option>'
                + current.addresses.map((address) => `<option value="${escapeHtml(address.address)}">${escapeHtml(address.address.slice(0, 120))} (${address.line_count})</option>`).join('');
        }
        if (form) {
            form.reset();
            radiusEl.value = defaultRadius;
            source = { type: 'manual', poiId: null };
            sourceEl.textContent = 'ที่มา: ปักเอง';
        }
        clearEditMarker();

        // Saved locations
        savedLayer.clearLayers();
        const saved = current.locations || [];
        el('gloc-saved').innerHTML = saved.length ? saved.map((location) => `<div class="gloc-saved-row">
            <span><b>${escapeHtml(location.label || (location.address_key ? 'ที่อยู่เฉพาะ' : 'ค่าเริ่มต้นของลูกค้า'))}</b>
            <small>${escapeHtml(location.address_text || 'ใช้กับทุกที่อยู่')} · ${location.lat.toFixed(5)}, ${location.lon.toFixed(5)} · รัศมี ${location.radius_m} ม. · ${location.source === 'dtc_poi' ? `DTC ${escapeHtml(location.dtc_poi_id || '')}` : 'ปักเอง'}</small></span>
            ${canEdit ? `<span class="gloc-saved-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-edit="${location.id}">แก้ไข</button><button type="button" class="btn btn-outline-danger btn-sm" data-deactivate="${location.id}">ยกเลิก</button></span>` : ''}
        </div>`).join('') : '<div class="gloc-muted">ยังไม่มีพิกัด</div>';
        saved.forEach((location) => {
            L.circle([location.lat, location.lon], { radius: location.radius_m, color: '#16856f', weight: 2, fillOpacity: .15 }).addTo(savedLayer)
                .bindTooltip(location.label || current.name || 'ลูกค้า');
            L.circleMarker([location.lat, location.lon], { radius: 5, color: '#fff', weight: 2, fillColor: '#16856f', fillOpacity: 1 }).addTo(savedLayer);
        });

        // Suggestions from DTC POIs
        suggestLayer.clearLayers();
        const suggestions = current.suggestions || [];
        el('gloc-suggestions').innerHTML = suggestions.length ? suggestions.map((poi, index) => `<div class="gloc-suggestion">
            <span><b>${escapeHtml(poi.poi_name)}</b><small>${escapeHtml(poi.location_info || '')} · รัศมี ${poi.area_m ?? '-'} ม. · ความคล้าย ${poi.score}%</small></span>
            ${canEdit ? `<button type="button" class="btn btn-outline-success btn-sm" data-suggest="${index}">ใช้จุดนี้</button>` : ''}
        </div>`).join('') : '<div class="gloc-muted">ไม่พบจุดใน DTC ที่ชื่อคล้ายกัน · ปักเองบนแผนที่ หรือวางลิงก์ Google Maps</div>';
        suggestions.forEach((poi) => {
            L.circle([poi.lat, poi.lon], { radius: poi.area_m || 100, color: '#b45309', weight: 1, dashArray: '4 4', fillOpacity: .06 }).addTo(suggestLayer).bindTooltip(`DTC: ${poi.poi_name}`);
        });

        const points = [...saved.map((l) => [l.lat, l.lon]), ...suggestions.map((s) => [s.lat, s.lon])];
        if (points.length) map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 16 });
        else map.setView(COMPANY, 11);
        window.setTimeout(() => map.invalidateSize(), 50);
    }

    el('gloc-suggestions')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-suggest]');
        if (!button) return;
        const poi = current.suggestions[Number(button.dataset.suggest)];
        if (!poi) return;
        if (labelEl && !labelEl.value) labelEl.value = poi.poi_name.slice(0, 255);
        // DTC areas are often tiny (30 m): never go below the standard arrival radius.
        setPoint(poi.lat, poi.lon, { type: 'dtc_poi', poiId: poi.poi_id }, Math.min(2000, Math.max(defaultRadius, Number(poi.area_m) || 0)));
    });

    el('gloc-saved')?.addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-edit]');
        if (edit) {
            const location = current.locations.find((item) => item.id === Number(edit.dataset.edit));
            if (!location) return;
            if (addressEl) addressEl.value = location.address_text || '';
            if (labelEl) labelEl.value = location.label || '';
            setPoint(location.lat, location.lon, location.source === 'dtc_poi' ? { type: 'dtc_poi', poiId: location.dtc_poi_id } : { type: 'manual', poiId: null }, location.radius_m);
            return;
        }
        const remove = event.target.closest('[data-deactivate]');
        if (!remove || !window.confirm('ยกเลิกพิกัดนี้? (ข้อมูลเดิมยังเก็บไว้ในประวัติ)')) return;
        try {
            await postJson(root.dataset.deactivateUrl.replace('__ID__', remove.dataset.deactivate));
            flash('ยกเลิกพิกัดแล้ว');
            await refreshCurrent();
        } catch (error) {
            flash(`ยกเลิกไม่สำเร็จ: ${error.message}`, false);
        }
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!current) return;
        if (!latEl.value || !lonEl.value) {
            flash('ยังไม่ได้กำหนดตำแหน่ง · คลิกบนแผนที่ เลือกจุด DTC หรือวางพิกัด', false);
            return;
        }
        const button = el('gloc-save');
        button.disabled = true;
        try {
            await postJson(root.dataset.storeUrl, {
                company: current.company,
                customer_id: current.customer_id,
                address: addressEl?.value || null,
                label: labelEl?.value || null,
                lat: Number(latEl.value),
                lon: Number(lonEl.value),
                radius_m: Number(radiusEl.value),
                source: source.type,
                dtc_poi_id: source.poiId,
            });
            flash('บันทึกพิกัดแล้ว');
            await refreshCurrent();
        } catch (error) {
            flash(`บันทึกไม่สำเร็จ: ${error.message}`, false);
        } finally {
            button.disabled = false;
        }
    });

    async function refreshCurrent() {
        const company = current.company;
        const customerId = current.customer_id;
        await openCustomer(company, customerId);
        const row = customers.find((customer) => customer.company === company && customer.customer_id === customerId);
        if (row) {
            row.location_count = (current.locations || []).length;
            row.status = row.location_count ? 'pinned' : (current.suggestions?.length ? 'suggested' : 'missing');
        }
        renderList();
    }

    // ---------- admin actions ----------
    el('gloc-import')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        if (!window.confirm('ดึงจุด (POI) จากระบบ DTC? ใช้ 1 request')) return;
        button.disabled = true;
        try {
            const payload = await postJson(root.dataset.importUrl);
            flash(`${payload.message} · กำลังโหลดหน้าใหม่`);
            window.setTimeout(() => window.location.reload(), 1200);
        } catch (error) {
            flash(`นำเข้าไม่สำเร็จ: ${error.message}`, false);
            button.disabled = false;
        }
    });

    el('gloc-schema-check')?.addEventListener('click', async () => {
        try {
            const payload = await postJson(root.dataset.schemaUrl);
            if (payload.ready) window.location.reload();
            else flash('ยังไม่พบตาราง · ตรวจว่ารัน SQL บนฐานที่ระบบนี้ใช้อยู่', false);
        } catch (error) {
            flash(`ตรวจไม่สำเร็จ: ${error.message}`, false);
        }
    });

    renderList();
}());
