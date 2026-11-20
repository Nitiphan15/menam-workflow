(function () {
    'use strict';

    // GPS Trip Tracking — real DTC data only.
    // Positions come from the shared server snapshot (lazy sync); routes from DTC getHistory on click.
    const root = document.getElementById('gps-trip-tracking');
    if (!root) return;

    const COMPANY = [13.547532, 100.787167];
    const COMPANY_NAME = 'บริษัท แม่น้ำ สเตนเลสไวร์';
    const GEOFENCE_METERS = 300;
    const STALE_MINUTES = 10;
    const POLL_MS = 60000;

    const fleet = JSON.parse(root.dataset.vehicles || '[]');
    let snapshot = JSON.parse(root.dataset.snapshot || '{"rows":[]}');
    let matchReport = JSON.parse(root.dataset.matches || '{"rows":[]}');
    const queueDate = root.dataset.queueDate || '';
    const isToday = root.dataset.isToday === '1';
    const syncMinutes = Number(root.dataset.syncMinutes || 3);
    const liveUrl = root.dataset.liveUrl;
    const routeUrlTemplate = root.dataset.routeUrl;

    const el = (id) => document.getElementById(id);
    const list = el('gps-vehicle-list');
    const sourceDot = el('gps-source-dot');
    const sourceBadge = el('gps-source-badge');
    const sourceTitle = el('gps-source-title');
    const sourceFreshness = el('gps-source-freshness');
    const lastSuccess = el('gps-last-success');
    const matchSummary = el('gps-match-summary');
    const usageEl = el('gps-dtc-usage');
    const resultBox = el('gps-dtc-test-result');
    const mappingToggle = el('gps-mapping-toggle');
    const mappingPanel = el('gps-mapping-panel');
    const mappingRows = el('gps-mapping-rows');
    const mappingCount = el('gps-mapping-count');
    const syncButton = el('gps-dtc-test');
    const refreshMasterButton = el('gps-dtc-refresh-master');
    const viewAllButton = el('gps-view-all');

    let map = null;
    let companyMarker = null;
    let routeLayer = null;
    let stopsLayer = null;
    const markers = new Map();
    const routes = new Map(); // key -> { status: 'loading'|'ok'|'error', data?, error? }
    let items = [];
    let selectedKey = null;
    let expandedLoadKey = null;
    let filter = 'all';
    let showNoGps = false;
    let syncing = false;
    let lastError = null;
    let pollTimer = null;

    // ---------- helpers ----------
    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = value == null ? '' : String(value);
        return node.innerHTML;
    }

    const numberFormat = new Intl.NumberFormat('th-TH', { maximumFractionDigits: 1 });
    const qtyFormat = new Intl.NumberFormat('th-TH', { maximumFractionDigits: 3 });

    function normalizePlate(value) {
        return String(value || '').toLocaleUpperCase('th-TH').replace(/[^0-9A-Zก-๙]/gu, '');
    }

    // DTC time is "Y-m-d H:i:s" in GMT+7 without an offset.
    function parseDtcTime(value) {
        let text = String(value || '').trim();
        if (!text) return NaN;
        text = text.replace(' ', 'T');
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(text)) text += '+07:00';
        return Date.parse(text);
    }

    function ageMinutes(value) {
        const timestamp = parseDtcTime(value);
        return Number.isFinite(timestamp) ? Math.max(0, Math.round((Date.now() - timestamp) / 60000)) : null;
    }

    function isStale(value) {
        const age = ageMinutes(value);
        return age === null || age > STALE_MINUTES;
    }

    function hhmm(value) {
        const match = String(value || '').match(/(\d{2}:\d{2})/);
        return match ? match[1] : '-';
    }

    function distanceMeters(a, b) {
        const rad = (deg) => deg * Math.PI / 180;
        const dLat = rad(b[0] - a[0]);
        const dLon = rad(b[1] - a[1]);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLon / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    function positionOf(row) {
        if (!row) return null;
        const lat = Number(row.lat);
        const lon = Number(row.lon);
        if (row.lat === null || row.lon === null || !Number.isFinite(lat) || !Number.isFinite(lon)) return null;
        if (Math.abs(lat) > 90 || Math.abs(lon) > 180 || (lat === 0 && lon === 0)) return null;
        return [lat, lon];
    }

    function inCompany(position) {
        return Array.isArray(position) && distanceMeters(position, COMPANY) <= GEOFENCE_METERS;
    }

    // ---------- model ----------
    function buildItems() {
        const rowsByGps = new Map((snapshot.rows || []).map((row) => [String(row.gps_id || ''), row]));
        const gpsByPlate = new Map();
        (matchReport.rows || []).forEach((row) => {
            if (row.matched && row.gps_id) gpsByPlate.set(normalizePlate(row.plate_no), String(row.gps_id));
        });

        const fleetItems = fleet.map((vehicle) => {
            const gpsId = gpsByPlate.get(normalizePlate(vehicle.plate_no)) || '';
            const live = gpsId ? rowsByGps.get(gpsId) || null : null;
            return { ...vehicle, kind: 'fleet', gps_id: gpsId, live, position: positionOf(live) };
        });

        const dtcOnly = (matchReport.rows || [])
            .filter((row) => row.issue === 'missing_formdp' && row.gps_id)
            .map((row) => {
                const live = rowsByGps.get(String(row.gps_id)) || null;
                return {
                    key: `DTC-${row.gps_id}`,
                    kind: 'dtc_only',
                    plate_no: live?.license_plate || row.dtc_name || row.gps_id,
                    driver_name: '-',
                    trip_ref: 'มีใน DTC แต่ไม่อยู่ใน Truck Master',
                    has_queue: false,
                    stops: [],
                    deliveries: [],
                    gps_id: String(row.gps_id),
                    live,
                    position: positionOf(live),
                };
            });

        items = fleetItems.concat(dtcOnly).map((item) => ({ ...item, state: stateOf(item) }));
    }

    function stateOf(item) {
        if (!item.gps_id) return { label: 'ไม่มี GPS DTC', className: 'is-nodata', phase: 'nogps', stale: false };
        if (!item.position) return { label: 'DTC ไม่มีพิกัด', className: 'is-nodata', phase: 'nofix', stale: true };
        const stale = isStale(item.live?.time);
        const status = item.live?.status_name_th || item.live?.status_name_en || 'ไม่ทราบสถานะ';
        const atCompany = inCompany(item.position);
        const phase = atCompany ? 'company' : (Number(item.live?.gps_speed) > 0 ? 'moving' : 'stopped');
        return {
            label: atCompany ? `ในบริษัท · ${status}` : status,
            className: stale ? 'is-stale' : (phase === 'moving' ? 'is-moving' : ''),
            phase,
            stale,
        };
    }

    function needsReview(item) {
        return item.kind === 'dtc_only'
            || (item.has_queue && !item.gps_id)
            || (item.gps_id && (!item.position || item.state.stale));
    }

    function matchesFilter(item) {
        if (filter === 'gps') return Boolean(item.gps_id);
        if (filter === 'queue') return item.has_queue;
        if (filter === 'issue') return needsReview(item);
        return true;
    }

    function sortWeight(item) {
        if (item.kind === 'dtc_only') return 2;
        if (item.gps_id && item.has_queue) return 0;
        if (item.gps_id) return 1;
        if (item.has_queue) return 3;
        return 4;
    }

    // ---------- map ----------
    function initMap() {
        const target = el('gps-map');
        if (!window.L) {
            target.innerHTML = '<div class="gps-map-fallback"><div><i class="fas fa-map-marked-alt fa-2x mb-3"></i><br>โหลดแผนที่ไม่สำเร็จ<br><small>ตรวจสอบการเชื่อมต่อไปยัง OpenStreetMap แล้วลองใหม่</small></div></div>';
            return;
        }
        map = L.map(target, { zoomControl: true }).setView(COMPANY, 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
        L.circle(COMPANY, { radius: GEOFENCE_METERS, color: '#172033', weight: 2, fillColor: '#172033', fillOpacity: .08 }).addTo(map);
        companyMarker = L.marker(COMPANY, {
            title: COMPANY_NAME,
            alt: `หมุด${COMPANY_NAME}`,
            keyboard: true,
            riseOnHover: true,
            zIndexOffset: 1000,
            icon: L.divIcon({ className: '', html: `<div class="gps-company-marker" aria-label="${COMPANY_NAME}"><i class="fas fa-industry"></i></div>`, iconSize: [36, 36], iconAnchor: [18, 18] }),
        }).addTo(map)
            .bindTooltip(COMPANY_NAME, { permanent: true, direction: 'top', className: 'gps-marker-label gps-company-label', offset: [0, -17] })
            .bindPopup(companyPopupHtml(), { closeButton: true, autoPan: true });
        companyMarker.on('click keypress', () => companyMarker.setPopupContent(companyPopupHtml()).openPopup());

        const resize = () => map?.invalidateSize({ pan: false });
        window.requestAnimationFrame(resize);
        window.setTimeout(resize, 250);
        if (window.ResizeObserver) new ResizeObserver(resize).observe(target);
    }

    function companyPopupHtml() {
        const inside = items.filter((item) => item.state?.phase === 'company');
        const lines = inside.length
            ? inside.map((item) => `<li>${escapeHtml(item.plate_no)} · ${escapeHtml(item.live?.status_name_th || '-')}</li>`).join('')
            : '<li>ไม่มีรถในพื้นที่บริษัทตอนนี้</li>';
        return `<strong>${COMPANY_NAME}</strong><br><small>Geofence ${GEOFENCE_METERS} เมตร · รถในพื้นที่ ${inside.length} คัน</small><ul class="gps-popup-list">${lines}</ul>`;
    }

    function markerHtml(item) {
        const classes = ['gps-truck-marker'];
        if (item.kind === 'dtc_only') classes.push('is-unmatched');
        else if (item.state.stale) classes.push('is-stale');
        else classes.push('is-live');
        if (selectedKey === item.key) classes.push('is-selected');
        const icon = item.kind === 'dtc_only' ? 'fa-question' : 'fa-truck';
        return `<div class="${classes.join(' ')}"><i class="fas ${icon}"></i></div>`;
    }

    function syncMarkers() {
        if (!map) return;
        const keep = new Set();
        items.forEach((item) => {
            if (!item.position) return;
            keep.add(item.key);
            const icon = L.divIcon({ className: '', html: markerHtml(item), iconSize: [32, 32], iconAnchor: [16, 16] });
            // Trucks inside the company geofence keep a hover label only, so labels do not pile up there.
            const permanent = item.state.phase !== 'company' || selectedKey === item.key;
            let marker = markers.get(item.key);
            if (!marker) {
                marker = L.marker(item.position, { icon, title: item.plate_no }).addTo(map);
                marker.on('click', () => selectItem(item.key));
                markers.set(item.key, marker);
            } else {
                marker.setLatLng(item.position).setIcon(icon);
            }
            if (!marker.getTooltip() || marker.getTooltip().options.permanent !== permanent) {
                marker.unbindTooltip();
                marker.bindTooltip(item.plate_no, { permanent, direction: 'top', className: 'gps-marker-label', offset: [0, -13] });
            }
        });
        markers.forEach((marker, key) => {
            if (!keep.has(key)) {
                marker.remove();
                markers.delete(key);
            }
        });
        if (companyMarker) {
            const count = items.filter((item) => item.state.phase === 'company').length;
            companyMarker.setTooltipContent(count ? `${COMPANY_NAME} · รถ ${count} คัน` : COMPANY_NAME);
        }
    }

    function fitAll() {
        if (!map) return;
        const points = [COMPANY, ...items.filter((item) => item.position).map((item) => item.position)];
        if (points.length === 1) {
            map.setView(COMPANY, 13);
            return;
        }
        map.fitBounds(L.latLngBounds(points), { padding: [44, 44], maxZoom: 15 });
    }

    // ---------- route (DTC getHistory) ----------
    function routeSummary(route) {
        const points = route.points || [];
        if (!points.length) return null;
        let meters = 0;
        let maxSpeed = 0;
        let leftCompany = null;
        let backAtCompany = null;
        let wasInside = inCompany([points[0].lat, points[0].lon]);
        points.forEach((point, index) => {
            const position = [point.lat, point.lon];
            if (index > 0) meters += distanceMeters([points[index - 1].lat, points[index - 1].lon], position);
            maxSpeed = Math.max(maxSpeed, Number(point.speed) || 0);
            const inside = inCompany(position);
            if (wasInside && !inside && !leftCompany) leftCompany = point.time;
            if (!wasInside && inside) backAtCompany = point.time;
            wasInside = inside;
        });
        const first = points[0];
        const last = points[points.length - 1];
        const odometer = Number.isFinite(first.distance) && Number.isFinite(last.distance) && last.distance >= first.distance
            ? last.distance - first.distance
            : null;
        return {
            first: first.time,
            last: last.time,
            km: odometer ?? meters / 1000,
            kmSource: odometer !== null ? 'ระยะสะสมจาก DTC' : 'คำนวณจากพิกัด',
            maxSpeed,
            leftCompany,
            backAtCompany,
        };
    }

    function drawRoute(item) {
        if (routeLayer) {
            routeLayer.remove();
            routeLayer = null;
        }
        const state = routes.get(item.key);
        if (!map || state?.status !== 'ok') return;
        const points = (state.data.points || []).map((point) => [point.lat, point.lon]);
        if (points.length < 2) return;
        routeLayer = L.layerGroup([
            L.polyline(points, { color: '#126a83', weight: 4, opacity: .8 }),
            L.circleMarker(points[0], { radius: 6, color: '#fff', weight: 2, fillColor: '#126a83', fillOpacity: 1 }).bindTooltip(`เริ่ม ${hhmm(state.data.points[0].time)}`),
        ]).addTo(map);
        const bounds = L.latLngBounds([...points, ...(item.position ? [item.position] : []), ...stopPoints(item)]);
        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
    }

    async function loadRoute(item) {
        if (!item.gps_id || routes.get(item.key)?.status === 'loading') return;
        const cached = routes.get(item.key);
        if (cached?.status === 'ok' && Date.now() - cached.loadedAt < 10 * 60000) {
            drawRoute(item);
            return;
        }
        routes.set(item.key, { status: 'loading' });
        renderList();
        try {
            const url = new URL(routeUrlTemplate.replace('__GPS__', encodeURIComponent(item.gps_id)), window.location.href);
            url.searchParams.set('date', queueDate);
            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (payload.usage) renderUsage(payload.usage);
            if (response.ok && payload.ok) {
                routes.set(item.key, { status: 'ok', data: payload.route, visits: Array.isArray(payload.stop_visits) ? payload.stop_visits : [], loadedAt: Date.now() });
            } else {
                routes.set(item.key, { status: 'error', error: payload.error_info || { title: response.status === 429 ? 'เรียกถี่เกินไป' : 'โหลดเส้นทางไม่สำเร็จ', advice: 'ลองใหม่อีกครั้งภายหลัง' } });
            }
        } catch (error) {
            routes.set(item.key, { status: 'error', error: { title: 'เชื่อมต่อระบบไม่ได้', advice: 'ตรวจเครือข่ายแล้วลองใหม่' } });
        }
        if (selectedKey === item.key) { drawRoute(item); drawStops(item); }
        renderList();
    }

    // Arrival / leave per stop (server-side from the real route; see GpsStopVisitService).
    const VISIT_TEXT = {
        visited: { label: 'เข้าจุดแล้ว', className: 'is-ok' },
        at_stop: { label: 'อยู่ที่จุดนี้', className: 'is-ok' },
        passed: { label: 'ผ่านแต่ไม่จอด', className: 'is-warn' },
        not_visited: { label: 'ไม่พบว่าเข้าจุด', className: 'is-bad' },
        no_location: { label: 'ไม่มีพิกัดลูกค้า', className: '' },
        no_route: { label: 'ไม่มีเส้นทาง', className: '' },
    };

    function visitDetail(visit) {
        if (!visit) return '';
        if (visit.status === 'visited') return `ถึง ${hhmm(visit.arrived_at)} · ออก ${hhmm(visit.left_at)} · จอด ${visit.dwell_minutes} นาที${visit.visits > 1 ? ` · เข้า ${visit.visits} ครั้ง` : ''}`;
        if (visit.status === 'at_stop') return `ถึง ${hhmm(visit.arrived_at)} · ยังอยู่ (${visit.dwell_minutes} นาที)`;
        if (visit.status === 'passed') return `ผ่านช่วง ${hhmm(visit.arrived_at)} จอดไม่ถึง 3 นาที`;
        if (visit.status === 'not_visited' && visit.nearest_m !== null) return `ใกล้สุด ${numberFormat.format(visit.nearest_m / 1000)} กม.`;
        return '';
    }

    function stopVisitsHtml(item, state) {
        const deliveries = item.deliveries || [];
        if (!deliveries.length) return '';
        const visits = state.visits || [];
        const rows = deliveries.map((delivery, index) => {
            const visit = visits[index];
            const text = VISIT_TEXT[visit?.status] || VISIT_TEXT.no_location;
            return `<li><span class="gps-visit-no">${index + 1}</span><span class="gps-visit-body"><b>${escapeHtml(delivery.customer || '-')}</b><small>${escapeHtml(visitDetail(visit))}</small></span><span class="gps-visit-tag ${text.className}">${escapeHtml(text.label)}</span></li>`;
        }).join('');
        return `<span class="gps-route-title mt-2">จุดส่งจากเส้นทางจริง</span><ol class="gps-visit-list">${rows}</ol>`;
    }

    function routeHtml(item) {
        if (!item.gps_id) return '<span class="gps-route-state">รถคันนี้ไม่มี GPS ของ DTC จึงไม่มีเส้นทาง</span>';
        const state = routes.get(item.key);
        if (!state || state.status === 'loading') return '<span class="gps-route-state"><i class="fas fa-spinner fa-spin"></i> กำลังโหลดเส้นทางจาก DTC...</span>';
        if (state.status === 'error') {
            return `<span class="gps-route-state is-error"><b>${escapeHtml(state.error.title || 'โหลดเส้นทางไม่สำเร็จ')}</b>${escapeHtml(state.error.advice || '')}<button type="button" class="btn btn-link btn-sm p-0" data-route-retry="${escapeHtml(item.key)}">ลองใหม่</button></span>`;
        }
        const summary = routeSummary(state.data);
        if (!summary) return `<span class="gps-route-state">ไม่มีพิกัดจาก DTC ในวันที่ ${escapeHtml(queueDate)}</span>`;
        const rows = [
            ['ข้อมูลแรก/ล่าสุด', `${hhmm(summary.first)} – ${hhmm(summary.last)}`],
            ['ออกจากพื้นที่บริษัท', summary.leftCompany ? hhmm(summary.leftCompany) : '-'],
            ['กลับเข้าพื้นที่บริษัท', summary.backAtCompany ? hhmm(summary.backAtCompany) : '-'],
            ['ระยะทาง', `${numberFormat.format(summary.km)} กม. (${summary.kmSource})`],
            ['ความเร็วสูงสุด', `${numberFormat.format(summary.maxSpeed)} กม./ชม.`],
        ];
        return `<span class="gps-route-facts">${rows.map(([label, value]) => `<span><small>${escapeHtml(label)}</small><b>${escapeHtml(value)}</b></span>`).join('')}</span>
            ${stopVisitsHtml(item, state)}
            <small class="gps-route-foot">จาก DTC getHistory · ${escapeHtml(state.data.point_count)} จุด · ${state.data.from_cache ? 'cache' : 'ดึงใหม่'}</small>`;
    }

    // ---------- list ----------
    function deliveryHtml(item) {
        const deliveries = Array.isArray(item.deliveries) ? item.deliveries : [];
        if (!deliveries.length) return '<span class="gps-load-empty">ไม่มีรายการส่งในคิววันที่เลือก</span>';
        return deliveries.map((delivery, index) => `<span class="gps-load-stop">
            <span class="gps-load-stop-head"><span class="gps-load-stop-no">${index + 1}</span><strong>${escapeHtml(delivery.customer || '-')}</strong>${delivery.location ? '<span class="gps-pin-tag is-ok" title="มีพิกัดลูกค้า"><i class="fas fa-location-dot"></i> พิกัด</span>' : '<span class="gps-pin-tag" title="ยังไม่ได้ปักพิกัดลูกค้า (เมนู พิกัดลูกค้า GPS)">ไม่มีพิกัด</span>'}</span>
            ${(delivery.items || []).map((line) => `<span class="gps-load-line"><span><b>${escapeHtml(line.product || line.part_number || '-')}</b><small>${escapeHtml(line.so_number || '-')} · ${escapeHtml(line.dp_ref || '-')}${line.part_number ? ` · ${escapeHtml(line.part_number)}` : ''}</small></span><strong>${qtyFormat.format(Number(line.quantity || 0))} ${escapeHtml(line.unit || '')}</strong></span>`).join('')}
        </span>`).join('');
    }

    function itemHtml(item) {
        const selected = selectedKey === item.key;
        const loadOpen = expandedLoadKey === item.key;
        const live = item.live;
        const lineCount = (item.deliveries || []).reduce((sum, delivery) => sum + (delivery.items?.length || 0), 0);
        const gpsText = item.gps_id
            ? (live ? `DTC ${hhmm(live.time)} · ${numberFormat.format(Number(live.gps_speed || 0))} กม./ชม.` : 'ยังไม่มีข้อมูลรอบนี้')
            : 'ไม่มี GPS DTC';
        return `<article class="gps-vehicle-item ${selected ? 'is-active' : ''} ${item.kind === 'dtc_only' ? 'is-unmatched' : ''}">
            <button type="button" class="gps-vehicle-select" data-item="${escapeHtml(item.key)}" aria-expanded="${selected}">
                <span class="gps-vehicle-top"><span class="gps-plate">${escapeHtml(item.plate_no)}</span><span class="gps-status ${item.state.className}">${escapeHtml(item.state.label)}</span></span>
                <span class="gps-vehicle-meta ${item.state.stale ? 'is-stale' : ''}">${item.state.stale && item.gps_id ? '<b>ข้อมูลเก่า</b> · ' : ''}${escapeHtml(item.driver_name)} · ${escapeHtml(item.has_queue ? `${item.stops?.length || 0} จุดส่ง` : item.trip_ref)} · ${escapeHtml(gpsText)}</span>
            </button>
            ${item.kind === 'fleet' ? `<button type="button" class="gps-load-toggle" data-load="${escapeHtml(item.key)}" aria-expanded="${loadOpen}"><span>ส่งอะไรบ้าง</span><span>${lineCount} รายการ <i class="fas fa-chevron-${loadOpen ? 'up' : 'down'}"></i></span></button>` : ''}
            ${loadOpen ? `<span class="gps-load-detail">${deliveryHtml(item)}</span>` : ''}
            ${selected ? `<span class="gps-route-panel"><span class="gps-route-title">เส้นทางจริง ${escapeHtml(queueDate)}</span>${routeHtml(item)}</span>` : ''}
        </article>`;
    }

    function renderList() {
        if (!list) return;
        const visible = items.filter(matchesFilter).sort((a, b) => sortWeight(a) - sortWeight(b) || String(a.plate_no).localeCompare(String(b.plate_no), 'th'));
        // In "all", trucks with neither GPS nor a queue are folded into one group to keep the list short.
        const folded = filter === 'all' ? visible.filter((item) => !item.gps_id && !item.has_queue && item.kind === 'fleet') : [];
        const main = visible.filter((item) => !folded.includes(item));
        let html = main.map(itemHtml).join('');
        if (folded.length) {
            html += `<button type="button" class="gps-group-toggle" data-group-toggle aria-expanded="${showNoGps}">
                <span>รถไม่มี GPS DTC และไม่มีคิว (${folded.length})</span><i class="fas fa-chevron-${showNoGps ? 'up' : 'down'}"></i></button>`;
            if (showNoGps) html += folded.map(itemHtml).join('');
        }
        list.innerHTML = html || '<div class="gps-list-empty">ไม่มีรถตามตัวกรองนี้</div>';
    }

    function renderKpis() {
        const withGps = items.filter((item) => item.position);
        const set = (id, value) => { const node = el(id); if (node) node.textContent = value; };
        const hasData = (snapshot.rows || []).length > 0;
        set('gps-moving-count', hasData ? withGps.filter((item) => item.state.phase === 'moving').length : '-');
        set('gps-stopped-count', hasData ? withGps.filter((item) => item.state.phase === 'stopped').length : '-');
        set('gps-company-count', hasData ? withGps.filter((item) => item.state.phase === 'company').length : '-');
        set('gps-stale-count', hasData ? items.filter((item) => item.gps_id && item.state.stale).length : '-');
    }

    function renderMapping() {
        const rows = Array.isArray(matchReport.rows) ? matchReport.rows : [];
        const issues = Number(matchReport.missing_in_formdp_count || 0);
        const hasData = (snapshot.rows || []).length > 0;
        matchSummary.innerHTML = hasData
            ? `<i class="fas fa-link"></i> จับคู่ ${matchReport.matched_count || 0}/${matchReport.dtc_count || 0} คัน${issues ? ` · ไม่อยู่ใน Truck Master ${issues}` : ''}`
            : '<i class="fas fa-link"></i> จับคู่รถ: รอข้อมูล DTC';
        mappingCount.textContent = hasData ? `${matchReport.matched_count || 0} จับคู่ · ${issues} ไม่พบใน FormDP` : 'ยังไม่มีข้อมูล';
        mappingCount.classList.toggle('is-alert', issues > 0);
        // Show DTC vehicles first (matched / missing), then FormDP trucks without GPS.
        mappingRows.innerHTML = rows.map((row) => {
            const stale = row.issue !== 'missing_dtc' && isStale(row.last_time);
            const result = row.issue === 'missing_dtc' ? 'มีใน FormDP · ไม่มี GPS DTC' : row.issue === 'missing_formdp' ? 'มีใน DTC · ไม่อยู่ใน Truck Master' : 'จับคู่แล้ว';
            return `<tr><td><code>${escapeHtml(row.gps_id || '-')}</code></td><td class="fw-semibold">${escapeHtml(row.dtc_name || '-')}</td><td>${escapeHtml(row.plate_no || '-')}</td><td><span>${escapeHtml(row.trip_ref || '-')}</span><small>${escapeHtml(row.driver_name || '')}</small></td><td><span>${escapeHtml(row.last_time || '-')} ${stale ? '<b class="gps-stale-tag">ข้อมูลเก่า</b>' : ''}</span><small>${escapeHtml(row.status_name || '')}</small></td><td><span class="gps-match-status ${row.matched ? 'is-ok' : 'is-missing'}"><i class="fas fa-${row.matched ? 'check' : 'triangle-exclamation'}"></i> ${escapeHtml(result)}</span></td></tr>`;
        }).join('');
    }

    function renderUsage(usage) {
        if (!usageEl || !usage) return;
        const n = (value) => new Intl.NumberFormat('th-TH').format(Number(value || 0));
        usageEl.classList.toggle('is-stale', Number(usage.percent) >= 80 || Boolean(usage.locked_until));
        usageEl.title = `งบใช้ได้ ${n(usage.budget)} · เหลือ ${n(usage.remaining)}`;
        usageEl.innerHTML = `<i class="fas fa-gauge-high"></i> DTC เดือนนี้ ${n(usage.month_total)} / ${n(usage.quota)} · วันนี้ ${n(usage.today)}${usage.locked_until ? ' · หยุดเรียกชั่วคราว' : ''}`;
    }

    function formatClock(iso) {
        const timestamp = Date.parse(String(iso || ''));
        if (!Number.isFinite(timestamp)) return null;
        return new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Bangkok' }).format(new Date(timestamp));
    }

    function renderStatus() {
        const hasData = (snapshot.rows || []).length > 0;
        const fetchedAge = snapshot.fetched_at ? Math.max(0, Math.round((Date.now() - Date.parse(snapshot.fetched_at)) / 60000)) : null;
        let mode = 'live';
        let badge = 'LIVE DTC';
        let title = 'ตำแหน่งรถจาก DTC';
        if (syncing) { mode = 'checking'; badge = 'กำลังซิงก์'; title = 'กำลังดึงตำแหน่งจาก DTC'; }
        else if (!hasData && lastError) { mode = 'offline'; badge = 'DTC OFFLINE'; title = `ยังไม่มีข้อมูล: ${lastError.title || 'เชื่อมต่อ DTC ไม่ได้'}`; }
        else if (!hasData) { mode = 'checking'; badge = 'ยังไม่มีข้อมูล'; title = 'รอการดึงตำแหน่งครั้งแรก'; }
        else if (lastError) { mode = 'offline'; badge = 'DTC OFFLINE'; title = `แสดงข้อมูลล่าสุดที่มี · ${lastError.title || 'ซิงก์ไม่สำเร็จ'}`; }
        sourceDot.className = `gps-source-dot is-${mode}`;
        sourceBadge.textContent = badge;
        sourceTitle.textContent = title;

        const newest = (snapshot.rows || []).map((row) => ageMinutes(row.time)).filter(Number.isFinite);
        const staleCount = items.filter((item) => item.gps_id && item.state.stale).length;
        sourceFreshness.classList.toggle('is-stale', staleCount > 0 || (fetchedAge ?? 0) > syncMinutes * 3);
        sourceFreshness.innerHTML = `<i class="fas fa-clock"></i> ${hasData ? `GPS ล่าสุด ${newest.length ? Math.min(...newest) : '-'} นาทีที่แล้ว${staleCount ? ` · ข้อมูลเก่า ${staleCount} คัน` : ''}` : 'ยังไม่มีข้อมูล GPS'}`;
        const clock = formatClock(snapshot.fetched_at);
        lastSuccess.innerHTML = `<i class="fas fa-cloud-arrow-down"></i> ${clock ? `ซิงก์ล่าสุด ${escapeHtml(clock)}${fetchedAge ? ` (${fetchedAge} นาที)` : ''}` : 'ยังไม่เคยซิงก์สำเร็จ'}`;
    }

    function renderResult(payload) {
        if (!resultBox) return;
        const error = payload?.error_info;
        if (!error) {
            resultBox.hidden = true;
            return;
        }
        const raw = payload.raw_error;
        resultBox.hidden = false;
        resultBox.classList.add('is-error');
        resultBox.innerHTML = `<div class="gps-dtc-result-head"><strong><i class="fas fa-triangle-exclamation"></i> ${escapeHtml(error.title || 'ซิงก์ไม่สำเร็จ')}</strong><span>${error.http_status ? `HTTP ${escapeHtml(error.http_status)} · ` : ''}ใช้ ${escapeHtml(payload.requests_attempted ?? 0)} requests</span></div>
            <div class="gps-error-guide"><strong>${escapeHtml(error.advice || '')}</strong><span>${(snapshot.rows || []).length ? 'ตอนนี้แสดงข้อมูลล่าสุดที่ซิงก์ไว้' : 'ยังไม่มีตำแหน่งให้แสดง'}</span></div>
            ${raw ? `<details class="gps-dtc-raw"><summary>Raw error สำหรับตรวจสอบ</summary><div><b>${escapeHtml(raw.type || 'Error')}</b><pre>${escapeHtml(raw.detail || '-')}</pre></div></details>` : ''}`;
    }

    function renderAll() {
        buildItems();
        renderStatus();
        renderKpis();
        renderMapping();
        renderList();
        syncMarkers();
        if (companyMarker?.isPopupOpen()) companyMarker.setPopupContent(companyPopupHtml());
    }

    // ---------- sync ----------
    async function refresh(params = {}) {
        if (syncing) return;
        syncing = true;
        renderStatus();
        [syncButton, refreshMasterButton].forEach((button) => { if (button) button.disabled = true; });
        try {
            const url = new URL(params.url || liveUrl, window.location.href);
            url.searchParams.set('date', queueDate);
            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (response.status === 429 && !payload.error_info) {
                payload.error_info = { title: 'เรียกถี่เกินไป', advice: 'รอสักครู่แล้วระบบจะลองใหม่เอง' };
            }
            if (payload.snapshot) snapshot = payload.snapshot;
            if (payload.match_report) matchReport = payload.match_report;
            if (payload.usage) renderUsage(payload.usage);
            lastError = payload.error_info || (response.ok ? null : { title: `HTTP ${response.status}` });
            renderResult(payload);
        } catch (error) {
            lastError = { title: 'เชื่อมต่อระบบไม่ได้', advice: 'ตรวจเครือข่ายภายใน' };
            renderResult({ error_info: lastError });
        } finally {
            syncing = false;
            [syncButton, refreshMasterButton].forEach((button) => { if (button) button.disabled = false; });
            renderAll();
            schedulePoll();
        }
    }

    function schedulePoll() {
        window.clearTimeout(pollTimer);
        if (document.visibilityState !== 'visible') return;
        pollTimer = window.setTimeout(() => refresh(), POLL_MS);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') {
            window.clearTimeout(pollTimer);
            return;
        }
        const age = snapshot.fetched_at ? (Date.now() - Date.parse(snapshot.fetched_at)) / 1000 : Infinity;
        if (age > syncMinutes * 60) refresh();
        else schedulePoll();
    });

    // ---------- interactions ----------
    // Customer pins of the selected truck's deliveries (from "พิกัดลูกค้า (GPS)").
    function stopPoints(item) {
        return (item?.deliveries || []).filter((delivery) => delivery.location).map((delivery) => [delivery.location.lat, delivery.location.lon]);
    }

    function drawStops(item) {
        if (stopsLayer) { stopsLayer.remove(); stopsLayer = null; }
        if (!map || !item) return;
        const layers = [];
        const routeState = routes.get(item.key);
        const visits = routeState?.status === 'ok' ? (routeState.visits || []) : [];
        (item.deliveries || []).forEach((delivery, index) => {
            if (!delivery.location) return;
            const point = [delivery.location.lat, delivery.location.lon];
            const visit = visits[index];
            const visitClass = visit ? (VISIT_TEXT[visit.status]?.className || '') : '';
            const detail = visitDetail(visit);
            layers.push(L.circle(point, { radius: delivery.location.radius_m || 150, color: '#16856f', weight: 2, fillOpacity: .12 }));
            layers.push(L.marker(point, {
                icon: L.divIcon({ className: '', html: `<div class="gps-stop-pin ${visitClass}">${index + 1}</div>`, iconSize: [24, 24], iconAnchor: [12, 12] }),
            }).bindTooltip(`${index + 1}. ${escapeHtml(delivery.customer || '')}${detail ? `<br>${escapeHtml(detail)}` : ''}`));
        });
        if (layers.length) stopsLayer = L.layerGroup(layers).addTo(map);
    }

    function selectItem(key) {
        if (selectedKey === key) {
            selectedKey = null;
            if (routeLayer) { routeLayer.remove(); routeLayer = null; }
            drawStops(null);
            renderAll();
            return;
        }
        selectedKey = key;
        const item = items.find((candidate) => candidate.key === key);
        if (item && !item.gps_id && !item.has_queue && item.kind === 'fleet') showNoGps = true;
        renderAll();
        if (!item) return;
        if (routeLayer) { routeLayer.remove(); routeLayer = null; }
        drawStops(item);
        const focus = [...(item.position ? [item.position] : []), ...stopPoints(item)];
        if (map && focus.length > 1) map.fitBounds(L.latLngBounds(focus), { padding: [40, 40], maxZoom: 15 });
        else if (map && focus.length === 1) map.flyTo(focus[0], Math.max(map.getZoom(), 14), { duration: .45 });
        if (item.gps_id) loadRoute(item);
    }

    list?.addEventListener('click', (event) => {
        const select = event.target.closest('[data-item]');
        if (select) { selectItem(select.dataset.item); return; }
        const load = event.target.closest('[data-load]');
        if (load) { expandedLoadKey = expandedLoadKey === load.dataset.load ? null : load.dataset.load; renderList(); return; }
        const retry = event.target.closest('[data-route-retry]');
        if (retry) {
            routes.delete(retry.dataset.routeRetry);
            const item = items.find((candidate) => candidate.key === retry.dataset.routeRetry);
            if (item) loadRoute(item);
            return;
        }
        if (event.target.closest('[data-group-toggle]')) { showNoGps = !showNoGps; renderList(); }
    });

    root.querySelectorAll('[data-filter]').forEach((chip) => chip.addEventListener('click', () => {
        filter = chip.dataset.filter;
        root.querySelectorAll('[data-filter]').forEach((other) => other.classList.toggle('is-active', other === chip));
        renderList();
    }));

    mappingToggle?.addEventListener('click', () => {
        const open = mappingPanel.hidden;
        mappingPanel.hidden = !open;
        mappingToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        mappingToggle.innerHTML = `<i class="fas fa-${open ? 'chevron-up' : 'list-check'} me-1"></i> ${open ? 'ซ่อนการจับคู่' : 'ดูการจับคู่รถ'}`;
    });
    syncButton?.addEventListener('click', () => refresh({ url: syncButton.dataset.url }));
    refreshMasterButton?.addEventListener('click', () => refresh({ url: refreshMasterButton.dataset.url }));
    viewAllButton?.addEventListener('click', () => {
        if (routeLayer) { routeLayer.remove(); routeLayer = null; }
        fitAll();
    });

    // ---------- start ----------
    initMap(); // starts at the company, zoom 13
    renderAll();
    // Positions are always "now"; for a past date only the clicked route uses that date.
    const initialAge = snapshot.age_seconds ?? Infinity;
    if (initialAge > syncMinutes * 60) refresh();
    else schedulePoll();
    void isToday;
}());
