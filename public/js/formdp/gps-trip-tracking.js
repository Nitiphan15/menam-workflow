(function () {
    'use strict';

    const root = document.getElementById('gps-trip-tracking');
    if (!root) return;

    const vehicles = JSON.parse(root.dataset.vehicles || '[]').map((vehicle, index) => ({
        ...vehicle,
        progress: index === 0 ? 1.4 : index === 1 ? .9 : index === 2 ? .55 : index === 3 ? 1.7 : .2,
        direction: index === 0 || index === 3 ? -1 : 1,
    }));
    const mockSnapshot = JSON.parse(root.dataset.dtcMock || '{"data":[]}');
    let matchReport = JSON.parse(root.dataset.dtcMatches || '{"matched_count":0,"dtc_count":0,"rows":[]}');
    const company = [13.547532, 100.787167];
    let running = false;
    let timer = null;
    let selectedId = null;
    let expandedLoadId = null;
    let map = null;
    let liveMode = false;
    let dtcOnlyRows = [];
    const markers = new Map();
    const paths = new Map();
    const dtcOnlyMarkers = new Map();
    const COMPANY_GEOFENCE_METERS = 300;
    const STALE_MINUTES = 10;

    const list = document.getElementById('gps-vehicle-list');
    const toggleButton = document.getElementById('gps-simulation-toggle');
    const resetButton = document.getElementById('gps-simulation-reset');
    const viewAllButton = document.getElementById('gps-view-all');
    const dtcTestButton = document.getElementById('gps-dtc-test');
    const dtcRefreshButton = document.getElementById('gps-dtc-refresh-master');
    const dtcUsage = document.getElementById('gps-dtc-usage');
    const dtcTestResult = document.getElementById('gps-dtc-test-result');
    const sourceDot = document.getElementById('gps-source-dot');
    const sourceBadge = document.getElementById('gps-source-badge');
    const sourceTitle = document.getElementById('gps-source-title');
    const sourceNote = document.getElementById('gps-source-note');
    const sourceFreshness = document.getElementById('gps-source-freshness');
    const lastSuccess = document.getElementById('gps-last-success');
    const matchSummary = document.getElementById('gps-match-summary');
    const mappingToggle = document.getElementById('gps-mapping-toggle');
    const mappingPanel = document.getElementById('gps-mapping-panel');
    const mappingRows = document.getElementById('gps-mapping-rows');
    const mappingCount = document.getElementById('gps-mapping-count');
    const updatedAt = document.getElementById('gps-updated-at');
    const movingCount = document.getElementById('gps-moving-count');
    const companyCount = document.getElementById('gps-company-count');
    const delayedCount = document.getElementById('gps-delayed-count');
    const destinationCount = document.getElementById('gps-destination-count');
    const viewTabs = root.querySelectorAll('[data-gps-view]');
    const viewPanels = root.querySelectorAll('[data-gps-panel]');
    const historyVehicle = document.getElementById('gps-history-vehicle');
    const historyDate = document.getElementById('gps-history-date');
    const historyList = document.getElementById('gps-history-list');
    const historyDetail = document.getElementById('gps-history-detail');
    const historyResultCount = document.getElementById('gps-history-result-count');
    const reportRows = document.getElementById('gps-report-rows');
    const dataBadge = document.getElementById('gps-data-badge');
    const dataBadgeText = document.getElementById('gps-data-badge-text');
    const toolbarTitle = document.getElementById('gps-toolbar-title');
    const toolbarNote = document.getElementById('gps-toolbar-note');
    const mapTarget = document.getElementById('gps-demo-map');
    let selectedHistoryId = vehicles[0]?.gps_id || null;

    function positionAt(vehicle) {
        if (Array.isArray(vehicle.live_position)) return vehicle.live_position;
        const maxIndex = vehicle.route.length - 1;
        const raw = Math.max(0, Math.min(maxIndex, vehicle.progress));
        const lower = Math.floor(raw);
        const upper = Math.min(maxIndex, lower + 1);
        const ratio = raw - lower;
        return [
            vehicle.route[lower][0] + (vehicle.route[upper][0] - vehicle.route[lower][0]) * ratio,
            vehicle.route[lower][1] + (vehicle.route[upper][1] - vehicle.route[lower][1]) * ratio,
        ];
    }

    function distanceMeters(a, b) {
        const rad = (deg) => deg * Math.PI / 180;
        const dLat = rad(b[0] - a[0]);
        const dLon = rad(b[1] - a[1]);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLon / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    // Live status comes only from DTC fields. Geofence is a position fact ("ในพื้นที่บริษัท"),
    // never an inferred intent such as "returning".
    function liveStateOf(vehicle) {
        if (!Array.isArray(vehicle.live_position)) {
            return { label: vehicle.live_found ? 'DTC ไม่มีพิกัด' : 'ไม่พบใน DTC', className: 'is-nodata', phase: 'nodata' };
        }
        const status = vehicle.live_status || 'ไม่ทราบสถานะ';
        const className = vehicle.live_stale ? 'is-stale' : '';
        if (distanceMeters(vehicle.live_position, company) <= COMPANY_GEOFENCE_METERS) {
            return { label: `ในพื้นที่บริษัท · ${status}`, className, phase: 'company' };
        }
        if (Number(vehicle.live_speed) > 0) return { label: status, className, phase: 'moving' };
        return { label: status, className, phase: 'stopped' };
    }

    function stateOf(vehicle) {
        if (liveMode) return liveStateOf(vehicle);
        const max = vehicle.route.length - 1;
        if (vehicle.progress <= .08) return { label: 'อยู่บริษัท', className: '', phase: 'company' };
        if (vehicle.progress >= max - .08) return { label: 'อยู่จุดส่ง', className: '', phase: 'destination' };
        return { label: 'กำลังเดินทาง', className: vehicle.gps_id === 'DEMO-GPS-002' ? 'is-delayed' : '', phase: 'moving' };
    }

    function initMap() {
        const target = document.getElementById('gps-demo-map');
        if (!window.L) {
            target.innerHTML = '<div class="gps-map-fallback"><div><i class="fas fa-map-marked-alt fa-2x mb-3"></i><br>โหลดแผนที่ไม่สำเร็จ<br><small>ตรวจสอบการเชื่อมต่อไปยัง OpenStreetMap แล้วลองใหม่</small></div></div>';
            return;
        }

        map = L.map(target, { zoomControl: true }).setView(company, 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        L.circle(company, { radius: 300, color: '#172033', weight: 2, fillColor: '#172033', fillOpacity: .08 }).addTo(map);
        const companyMarker = L.marker(company, {
            title: 'บริษัท แม่น้ำ สเตนเลสไวร์',
            alt: 'หมุดบริษัท แม่น้ำ สเตนเลสไวร์',
            keyboard: true,
            riseOnHover: true,
            zIndexOffset: 1000,
            icon: L.divIcon({ className: '', html: '<div class="gps-company-marker" aria-label="บริษัท แม่น้ำ สเตนเลสไวร์"><i class="fas fa-industry"></i></div>', iconSize: [36, 36], iconAnchor: [18, 18] }),
        }).addTo(map)
            .bindTooltip('บริษัท แม่น้ำ สเตนเลสไวร์', {
                permanent: true,
                direction: 'top',
                className: 'gps-marker-label gps-company-label',
                offset: [0, -17],
            })
            .bindPopup('<strong>บริษัท แม่น้ำ สเตนเลสไวร์</strong><br><small>จุดเริ่มต้น/สิ้นสุดเที่ยว · Geofence 300 เมตร</small>', {
                closeButton: true,
                autoPan: true,
            });
        companyMarker.on('click keypress', () => companyMarker.openPopup());

        vehicles.forEach((vehicle) => {
            const status = stateOf(vehicle);
            const path = L.polyline(vehicle.route, { color: '#7796a1', weight: 3, opacity: .45, dashArray: '7 8' }).addTo(map);
            const marker = L.marker(positionAt(vehicle), {
                icon: L.divIcon({ className: '', html: markerHtml(vehicle, status), iconSize: [32, 32], iconAnchor: [16, 16] }),
            }).addTo(map).bindTooltip(vehicle.plate_no, { permanent: true, direction: 'top', className: 'gps-marker-label', offset: [0, -13] });
            marker.on('click', () => selectVehicle(vehicle.gps_id));
            paths.set(vehicle.gps_id, path);
            markers.set(vehicle.gps_id, marker);
        });

        map.setView(company, 13);

        const resizeMap = () => map?.invalidateSize({ pan: false });
        window.requestAnimationFrame(resizeMap);
        window.setTimeout(resizeMap, 250);
        if (window.ResizeObserver) {
            const observer = new ResizeObserver(resizeMap);
            observer.observe(target);
        }
    }

    function markerHtml(vehicle, status) {
        const liveClass = liveMode && Array.isArray(vehicle.live_position) && !vehicle.live_stale ? 'is-live' : '';
        return `<div class="gps-truck-marker ${status.className} ${liveClass}"><i class="fas fa-truck"></i></div>`;
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = value == null ? '' : String(value);
        return node.innerHTML;
    }

    function formatQuantity(value) {
        return new Intl.NumberFormat('th-TH', { maximumFractionDigits: 3 }).format(Number(value || 0));
    }

    function normalizePlate(value) {
        return String(value || '').toLocaleUpperCase('th-TH').replace(/[^0-9A-Zก-๙]/gu, '');
    }

    // DTC "time" is "Y-m-d H:i:s" in GMT+7 without an offset; pin it to +07:00
    // so freshness does not depend on the browser's timezone.
    function ageMinutes(value) {
        let text = String(value || '').trim();
        if (!text) return null;
        text = text.replace(' ', 'T');
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(text)) text += '+07:00';
        const timestamp = Date.parse(text);
        return Number.isFinite(timestamp) ? Math.max(0, Math.round((Date.now() - timestamp) / 60000)) : null;
    }

    function isStale(value) {
        const age = ageMinutes(value);
        return age === null || age > STALE_MINUTES;
    }

    function freshnessOf(rows) {
        const ages = (rows || []).map((row) => ageMinutes(row.time)).filter(Number.isFinite);
        if (!ages.length) return { label: 'ยังไม่มีเวลาจากอุปกรณ์', stale: true, staleCount: 0 };
        const newest = Math.min(...ages);
        const staleCount = ages.filter((minutes) => minutes > STALE_MINUTES).length;
        return {
            label: `ล่าสุด ${newest} นาที${staleCount ? ` · ข้อมูลเก่า ${staleCount} คัน` : ''}`,
            stale: staleCount > 0,
            staleCount,
        };
    }

    function setLastSuccess(value) {
        if (!lastSuccess) return;
        const timestamp = Date.parse(String(value || ''));
        if (!Number.isFinite(timestamp)) {
            lastSuccess.innerHTML = '<i class="fas fa-cloud-arrow-down"></i> ยังไม่เคยเชื่อมต่อสำเร็จ';
            return;
        }
        const formatted = new Intl.DateTimeFormat('th-TH', {
            dateStyle: 'short', timeStyle: 'medium', timeZone: 'Asia/Bangkok',
        }).format(new Date(timestamp));
        lastSuccess.innerHTML = `<i class="fas fa-cloud-arrow-down"></i> Sync สำเร็จล่าสุด ${escapeHtml(formatted)}`;
    }

    function setSourceState(mode, rows = []) {
        const states = {
            demo: { badge: 'DEMO', title: 'กำลังใช้ตำแหน่งจำลอง', note: 'คิวและรายการส่งมาจาก FormDP จริง · ตำแหน่งรถจำลองในรูปแบบเดียวกับ DTC' },
            checking: { badge: 'CHECKING', title: 'กำลังตรวจการเชื่อมต่อ DTC', note: 'แผนที่ยังใช้ข้อมูลจำลองระหว่างรอผลจาก API' },
            live: { badge: 'LIVE DTC', title: 'เชื่อมต่อข้อมูล DTC แล้ว', note: 'ตำแหน่งและสถานะรถมาจาก DTC · คิวและรายการส่งมาจาก FormDP' },
            offline: { badge: 'DTC OFFLINE', title: 'DTC ยังเชื่อมต่อไม่ได้', note: 'ตำแหน่งรถบนแผนที่ยังเป็นข้อมูลจำลอง (DEMO DATA) ไม่ใช่ตำแหน่งจริง' },
        };
        const state = states[mode] || states.demo;
        sourceBadge.textContent = state.badge;
        sourceTitle.textContent = state.title;
        sourceNote.textContent = state.note;
        sourceDot.className = `gps-source-dot is-${mode}`;
        const freshness = freshnessOf(rows);
        sourceFreshness.classList.toggle('is-stale', freshness.stale);
        sourceFreshness.innerHTML = `<i class="fas fa-clock"></i> ${escapeHtml(mode === 'offline' ? 'ยังไม่มีข้อมูล Live' : freshness.label)}`;
    }

    function renderMapping(report) {
        matchReport = report || matchReport;
        const rows = Array.isArray(matchReport.rows) ? matchReport.rows : [];
        const issueCount = Number(matchReport.missing_in_formdp_count || 0) + Number(matchReport.missing_in_dtc_count || 0);
        matchSummary.innerHTML = `<i class="fas fa-link"></i> จับคู่ ${matchReport.matched_count || 0}/${matchReport.dtc_count || 0} คัน${issueCount ? ` · ต้องตรวจ ${issueCount}` : ''}`;
        mappingCount.textContent = `${matchReport.matched_count || 0} จับคู่ · ${issueCount} ต้องตรวจ`;
        mappingCount.classList.toggle('is-alert', issueCount > 0);
        mappingRows.innerHTML = rows.map((row) => {
            const stale = row.issue !== 'missing_dtc' && isStale(row.last_time);
            const result = row.issue === 'missing_dtc'
                ? 'มีใน FormDP · ไม่พบ DTC'
                : row.issue === 'missing_formdp' ? 'มีใน DTC · ไม่พบ FormDP' : 'จับคู่แล้ว';
            return `<tr><td><code>${escapeHtml(row.gps_id || '-')}</code></td><td class="fw-semibold">${escapeHtml(row.dtc_name || '-')}</td><td>${escapeHtml(row.plate_no || '-')}</td><td><span>${escapeHtml(row.trip_ref || '-')}</span><small>${escapeHtml(row.driver_name || '')}</small></td><td><span>${escapeHtml(row.last_time || '-')} ${stale ? '<b class="gps-stale-tag">ข้อมูลเก่า</b>' : ''}</span><small>${escapeHtml(row.status_name || '')}</small></td><td><span class="gps-match-status ${row.matched ? 'is-ok' : 'is-missing'}"><i class="fas fa-${row.matched ? 'check' : 'triangle-exclamation'}"></i> ${escapeHtml(result)}</span></td></tr>`;
        }).join('');
    }

    function livePositionOf(row) {
        const lat = Number(row?.lat);
        const lon = Number(row?.lon);
        const valid = row?.lat !== null && row?.lat !== '' && row?.lon !== null && row?.lon !== ''
            && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 && !(lat === 0 && lon === 0);
        return valid ? [lat, lon] : null;
    }

    function setDataBadge(mode) {
        const live = mode === 'live';
        dataBadge?.classList.toggle('is-live', live);
        if (dataBadgeText) dataBadgeText.textContent = live ? 'LIVE DTC' : 'DEMO DATA';
        if (toolbarTitle) toolbarTitle.textContent = live ? 'ตำแหน่งรถจาก DTC (ทดสอบ 1 ครั้ง)' : 'Simulation สำหรับทดสอบหน้าจอ';
        if (toolbarNote) {
            toolbarNote.textContent = live
                ? 'ตำแหน่ง/สถานะจาก DTC ณ เวลาที่ทดสอบ ไม่อัปเดตอัตโนมัติ · รถที่ไม่พบใน DTC จะไม่แสดงตำแหน่ง · เวลาออก/ETA ใน Timeline ยังเป็นข้อมูลจำลอง'
                : 'ตำแหน่งรถบนแผนที่เป็นข้อมูลจำลอง · หยุดหรือเริ่มการเคลื่อนที่ได้ โดยไม่เรียก DTC API';
        }
        mapTarget?.setAttribute('aria-label', live ? 'แผนที่ตำแหน่งรถจาก DTC' : 'แผนที่ตำแหน่งรถจำลอง');
        if (toggleButton) toggleButton.disabled = live;
        if (resetButton) resetButton.disabled = live;
    }

    function clearDtcOnlyMarkers() {
        dtcOnlyMarkers.forEach((marker) => marker.remove());
        dtcOnlyMarkers.clear();
    }

    function drawDtcOnlyMarkers() {
        clearDtcOnlyMarkers();
        if (!map) return;
        dtcOnlyRows.forEach((row) => {
            const position = livePositionOf(row);
            if (!position) return;
            const name = row.license_plate || row.truck_name || row.vehicle_name || row.gps_id || '-';
            const stale = isStale(row.time);
            const marker = L.marker(position, {
                icon: L.divIcon({ className: '', html: `<div class="gps-truck-marker is-unmatched ${stale ? 'is-stale' : ''}"><i class="fas fa-question"></i></div>`, iconSize: [32, 32], iconAnchor: [16, 16] }),
            }).addTo(map)
                .bindTooltip(String(name), { permanent: true, direction: 'top', className: 'gps-marker-label', offset: [0, -13] })
                .bindPopup(`<strong>${escapeHtml(name)}</strong><br><small>มีใน DTC · ไม่พบในคิว FormDP</small><br><small>${escapeHtml(row.status_name_th || row.status_name_en || '-')} · ${escapeHtml(row.time || '-')}${stale ? ' · <b>ข้อมูลเก่า</b>' : ''}</small>`);
            dtcOnlyMarkers.set(row.gps_id, marker);
        });
    }

    function applyLiveSnapshot(rows, report) {
        const mappingRowsByGps = new Map((report?.rows || []).filter((row) => row.gps_id).map((row) => [String(row.gps_id), row]));
        const liveByGps = new Map(rows.map((row) => [String(row.gps_id || ''), row]));
        const usedGpsIds = new Set();

        setRunning(false);
        liveMode = true;
        vehicles.forEach((vehicle) => {
            // Match by the plate resolved server-side (Vehicle Master license_plate first).
            const mapping = (report?.rows || []).find((row) => row.matched && normalizePlate(row.plate_no) === normalizePlate(vehicle.plate_no));
            const live = mapping ? liveByGps.get(String(mapping.gps_id)) : null;
            vehicle.live_found = Boolean(live);
            vehicle.live_position = live ? livePositionOf(live) : null;
            vehicle.live_time = live?.time || '';
            vehicle.live_status = live ? (live.status_name_th || live.status_name_en || '') : '';
            vehicle.live_speed = live ? Number(live.gps_speed || 0) : 0;
            vehicle.live_name = live ? (live.license_plate || live.truck_name || '') : '';
            vehicle.live_stale = live ? isStale(live.time) : false;
            if (live) usedGpsIds.add(String(live.gps_id));
        });
        dtcOnlyRows = rows.filter((row) => !usedGpsIds.has(String(row.gps_id || ''))
            && (mappingRowsByGps.get(String(row.gps_id || ''))?.issue || 'missing_formdp') !== 'matched');

        // Demo routes are not real tracks: hide them, and hide markers for vehicles without a DTC position.
        paths.forEach((path) => path.remove());
        vehicles.forEach((vehicle) => {
            const marker = markers.get(vehicle.gps_id);
            if (!marker || !map) return;
            if (Array.isArray(vehicle.live_position)) marker.addTo(map);
            else marker.remove();
        });
        drawDtcOnlyMarkers();

        setDataBadge('live');
        setSourceState('live', rows);
        renderMapping(report);
        render();
        fitAllVehicles();
    }

    function resetToDemo() {
        liveMode = false;
        dtcOnlyRows = [];
        clearDtcOnlyMarkers();
        vehicles.forEach((vehicle) => {
            ['live_found', 'live_position', 'live_time', 'live_status', 'live_speed', 'live_name', 'live_stale'].forEach((key) => delete vehicle[key]);
            if (map) {
                paths.get(vehicle.gps_id)?.addTo(map);
                markers.get(vehicle.gps_id)?.addTo(map);
            }
        });
        setDataBadge('demo');
        render();
    }

    function fitAllVehicles() {
        if (!map) return;
        const points = [company];
        vehicles.forEach((vehicle) => {
            if (liveMode && !Array.isArray(vehicle.live_position)) return;
            points.push(positionAt(vehicle));
        });
        dtcOnlyMarkers.forEach((marker) => {
            const latLng = marker.getLatLng();
            points.push([latLng.lat, latLng.lng]);
        });
        if (points.length === 1) {
            map.setView(company, 13);
            return;
        }
        map.fitBounds(L.latLngBounds(points), { padding: [44, 44], maxZoom: 15 });
    }

    function deliveryContents(vehicle) {
        const deliveries = Array.isArray(vehicle.deliveries) ? vehicle.deliveries : [];
        if (!deliveries.length) return '<span class="gps-load-empty">ยังไม่พบรายละเอียดสินค้าในคิวที่จับคู่ทะเบียน</span>';

        return deliveries.map((delivery, stopIndex) => `<span class="gps-load-stop">
            <span class="gps-load-stop-head"><span class="gps-load-stop-no">${stopIndex + 1}</span><strong>${escapeHtml(delivery.customer || '-')}</strong></span>
            ${(delivery.items || []).map((item) => `<span class="gps-load-line"><span><b>${escapeHtml(item.product || item.part_number || '-')}</b><small>${escapeHtml(item.so_number || '-')} · ${escapeHtml(item.dp_ref || '-')} ${item.part_number ? `· ${escapeHtml(item.part_number)}` : ''}</small></span><strong>${formatQuantity(item.quantity)} ${escapeHtml(item.unit || '')}</strong></span>`).join('')}
        </span>`).join('');
    }

    function routeSummary(vehicle) {
        const stops = Array.isArray(vehicle.stops) ? vehicle.stops : [];
        if (!stops.length) return '';

        const rows = [
            {
                name: 'บริษัท',
                time: `ออก ${vehicle.actual_departure || vehicle.planned_departure || '-'}`,
                metric: 'เริ่มเที่ยวจาก Geofence บริษัท',
            },
            ...stops.map((stop, index) => ({
                name: `${index + 1}. ${stop.name}`,
                time: `ถึง ${stop.actual_arrival || '-'}`,
                metric: `${stop.distance_km} กม. · ใช้จริง ${stop.actual_minutes} นาที · ETA จำลอง ${stop.eta_minutes} นาที`,
            })),
            {
                name: 'กลับบริษัท',
                time: vehicle.actual_return ? `ถึง ${vehicle.actual_return}` : `คาด ${vehicle.estimated_return || '-'}`,
                metric: `${vehicle.return_distance_km || '-'} กม. จากจุดล่าสุด · ETA จำลอง ${vehicle.return_eta_minutes || '-'} นาที · ${vehicle.estimate_basis || ''}`,
            },
        ];

        return `<span class="gps-route-summary">${rows.map((row) => `<span class="gps-route-leg">
            <span class="gps-route-dot"></span>
            <span class="gps-route-place" title="${escapeHtml(row.name)}">${escapeHtml(row.name)}</span>
            <span class="gps-route-time">${escapeHtml(row.time)}</span>
            <span class="gps-route-metric">${escapeHtml(row.metric)}</span>
        </span>`).join('')}</span>`;
    }

    function render() {
        if (!list) return;
        const vehicleItems = vehicles.map((vehicle) => {
            const status = stateOf(vehicle);
            const max = vehicle.route.length - 1;
            const percent = Math.round((vehicle.progress / max) * 100);
            const selected = selectedId === vehicle.gps_id;
            const loadExpanded = expandedLoadId === vehicle.gps_id;
            const source = liveMode
                ? (vehicle.live_time ? `DTC ${escapeHtml(vehicle.live_time)} · ${formatQuantity(vehicle.live_speed)} กม./ชม.` : 'ไม่มีตำแหน่งจาก DTC')
                : 'ตำแหน่งจำลอง';
            return `<article class="gps-vehicle-item ${selected ? 'is-active' : ''}">
                <button type="button" class="gps-vehicle-select" data-gps-id="${escapeHtml(vehicle.gps_id)}" aria-expanded="${selected ? 'true' : 'false'}">
                    <span class="gps-vehicle-top"><span class="gps-plate">${escapeHtml(vehicle.plate_no)}</span><span class="gps-status ${status.className}">${escapeHtml(status.label)}</span></span>
                    <span class="gps-vehicle-meta ${vehicle.live_stale ? 'is-stale' : ''}">${vehicle.live_stale ? '<b>ข้อมูลเก่า</b> · ' : ''}${escapeHtml(vehicle.driver_name)} · ${vehicle.stops?.length || 0} จุดส่ง · ${source}</span>
                    ${liveMode ? '' : `<span class="gps-progress"><span style="width:${percent}%"></span></span>`}
                </button>
                <button type="button" class="gps-load-toggle" data-load-toggle="${escapeHtml(vehicle.gps_id)}" aria-expanded="${loadExpanded ? 'true' : 'false'}"><span>ส่งอะไรบ้าง</span><span>${(vehicle.deliveries || []).reduce((total, delivery) => total + (delivery.items?.length || 0), 0)} รายการ <i class="fas fa-chevron-${loadExpanded ? 'up' : 'down'}"></i></span></button>
                ${loadExpanded ? `<span class="gps-load-detail">${deliveryContents(vehicle)}</span>` : ''}
                ${selected ? `${liveMode ? '<span class="gps-live-note"><i class="fas fa-circle-info"></i> เวลาออก/ถึง/ETA ด้านล่างยังเป็นข้อมูลจำลอง</span>' : ''}${routeSummary(vehicle)}` : ''}
            </article>`;
        });
        const dtcOnlyItems = liveMode ? dtcOnlyRows.map((row) => {
            const stale = isStale(row.time);
            const hasPosition = Boolean(livePositionOf(row));
            const name = row.license_plate || row.truck_name || row.vehicle_name || row.gps_id || '-';
            return `<article class="gps-vehicle-item is-unmatched">
                <button type="button" class="gps-vehicle-select" data-dtc-only="${escapeHtml(row.gps_id)}" ${hasPosition ? '' : 'disabled'}>
                    <span class="gps-vehicle-top"><span class="gps-plate">${escapeHtml(name)}</span><span class="gps-status is-unmatched">ไม่พบในคิว FormDP</span></span>
                    <span class="gps-vehicle-meta ${stale ? 'is-stale' : ''}">${stale ? '<b>ข้อมูลเก่า</b> · ' : ''}${escapeHtml(row.status_name_th || row.status_name_en || 'ไม่ทราบสถานะ')} · DTC ${escapeHtml(row.time || '-')}${hasPosition ? '' : ' · ไม่มีพิกัด'}</span>
                </button>
            </article>`;
        }) : [];
        list.innerHTML = vehicleItems.concat(dtcOnlyItems).join('');

        list.querySelectorAll('[data-gps-id]').forEach((button) => button.addEventListener('click', () => selectVehicle(button.dataset.gpsId)));
        list.querySelectorAll('[data-dtc-only]').forEach((button) => button.addEventListener('click', () => {
            const marker = dtcOnlyMarkers.get(button.dataset.dtcOnly);
            if (!marker || !map) return;
            map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 14), { duration: .45 });
            marker.openPopup();
        }));
        list.querySelectorAll('[data-load-toggle]').forEach((button) => button.addEventListener('click', () => {
            expandedLoadId = expandedLoadId === button.dataset.loadToggle ? null : button.dataset.loadToggle;
            render();
        }));

        vehicles.forEach((vehicle) => {
            const marker = markers.get(vehicle.gps_id);
            if (!marker) return;
            if (liveMode && !Array.isArray(vehicle.live_position)) return;
            const status = stateOf(vehicle);
            marker.setLatLng(positionAt(vehicle));
            marker.setIcon(L.divIcon({ className: '', html: markerHtml(vehicle, status), iconSize: [32, 32], iconAnchor: [16, 16] }));
        });

        const statuses = vehicles.map(stateOf);
        if (liveMode) {
            // Delay and "at destination" need plan/geofence data DTC does not give us yet.
            const dtcOnlyMoving = dtcOnlyRows.filter((row) => livePositionOf(row) && Number(row.gps_speed) > 0).length;
            movingCount.textContent = statuses.filter((s) => s.phase === 'moving').length + dtcOnlyMoving;
            companyCount.textContent = statuses.filter((s) => s.phase === 'company').length;
            delayedCount.textContent = '-';
            destinationCount.textContent = '-';
        } else {
            movingCount.textContent = statuses.filter((s) => s.phase === 'moving').length;
            companyCount.textContent = statuses.filter((s) => s.phase === 'company').length;
            delayedCount.textContent = statuses.filter((s) => s.className === 'is-delayed').length;
            destinationCount.textContent = statuses.filter((s) => s.phase === 'destination').length;
        }
        updatedAt.textContent = new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date());
    }

    function selectVehicle(gpsId) {
        if (selectedId === gpsId) {
            selectedId = null;
            render();
            return;
        }
        selectedId = gpsId;
        const vehicle = vehicles.find((item) => item.gps_id === gpsId);
        const hasPosition = vehicle && (!liveMode || Array.isArray(vehicle.live_position));
        if (hasPosition && map) map.flyTo(positionAt(vehicle), Math.max(map.getZoom(), 13), { duration: .45 });
        render();
    }

    function tick() {
        vehicles.forEach((vehicle) => {
            const max = vehicle.route.length - 1;
            vehicle.progress += .12 * vehicle.direction;
            if (vehicle.progress >= max) { vehicle.progress = max; vehicle.direction = -1; }
            if (vehicle.progress <= 0) { vehicle.progress = 0; vehicle.direction = 1; }
        });
        render();
    }

    function setRunning(next) {
        running = next;
        window.clearInterval(timer);
        timer = running ? window.setInterval(tick, 1800) : null;
        toggleButton.innerHTML = running ? '<i class="fas fa-pause me-1"></i> หยุด Simulation' : '<i class="fas fa-play me-1"></i> เริ่ม Simulation';
        toggleButton.classList.toggle('btn-primary', !running);
        toggleButton.classList.toggle('btn-outline-primary', running);
        toggleButton.setAttribute('aria-pressed', running ? 'true' : 'false');
    }

    function minuteValue(time) {
        const [hour, minute] = String(time || '00:00').split(':').map(Number);
        return (hour * 60) + minute;
    }

    function departureVariance(vehicle) {
        return minuteValue(vehicle.actual_departure) - minuteValue(vehicle.planned_departure);
    }

    function totalTripMinutes(vehicle) {
        return (vehicle.stops || []).reduce((total, stop) => total + Number(stop.actual_minutes || 0) + 20, 0) + Number(vehicle.return_eta_minutes || 0);
    }

    function historyLegs(vehicle) {
        const legs = [
            { name: 'ออกจากบริษัท', time: vehicle.actual_departure, detail: `ตามแผน ${vehicle.planned_departure} · ${departureVariance(vehicle) > 10 ? `ช้า ${departureVariance(vehicle)} นาที` : 'ตรงเวลา'}` },
            ...(vehicle.stops || []).map((stop, index) => ({ name: `${index + 1}. ${stop.name}`, time: stop.actual_arrival, detail: `${stop.distance_km} กม. · ใช้จริง ${stop.actual_minutes} นาที · ETA ${stop.eta_minutes} นาที` })),
            { name: 'กลับถึงบริษัท', time: vehicle.estimated_return, detail: `${vehicle.return_distance_km} กม. จากจุดล่าสุด · ETA ${vehicle.return_eta_minutes} นาที` },
        ];
        return legs.map((leg) => `<div class="gps-history-leg"><strong>${escapeHtml(leg.name)}</strong><time>${escapeHtml(leg.time || '-')}</time><small>${escapeHtml(leg.detail)}</small></div>`).join('');
    }

    function renderHistory() {
        if (!historyList || !historyDetail) return;
        const vehicleFilter = historyVehicle?.value || 'all';
        const matchesDate = historyDate?.value === root.dataset.queueDate || !root.dataset.queueDate;
        const filtered = matchesDate ? vehicles.filter((vehicle) => vehicleFilter === 'all' || vehicle.gps_id === vehicleFilter) : [];
        historyResultCount.textContent = `${filtered.length} เที่ยว`;

        if (!filtered.length) {
            historyList.innerHTML = '<div class="gps-history-empty"><div><i class="fas fa-route fa-2x mb-2"></i><br>ไม่พบเที่ยวในวันที่เลือก</div></div>';
            historyDetail.innerHTML = '<div class="gps-history-empty">เลือกวันที่หรือทะเบียนรถใหม่</div>';
            return;
        }

        if (!filtered.some((vehicle) => vehicle.gps_id === selectedHistoryId)) selectedHistoryId = filtered[0].gps_id;
        historyList.innerHTML = filtered.map((vehicle) => {
            const late = departureVariance(vehicle) > 10;
            return `<button type="button" class="gps-history-trip ${vehicle.gps_id === selectedHistoryId ? 'is-active' : ''}" data-history-id="${escapeHtml(vehicle.gps_id)}"><span class="gps-history-trip-top"><strong>${escapeHtml(vehicle.plate_no)}</strong><span class="gps-history-chip ${late ? 'is-alert' : ''}">${late ? 'ออกช้า' : 'ตรงเวลา'}</span></span><small>${escapeHtml(vehicle.driver_name)}<br>${vehicle.stops?.length || 0} จุดส่ง · ออก ${escapeHtml(vehicle.actual_departure)} · คาดกลับ ${escapeHtml(vehicle.estimated_return)}</small></button>`;
        }).join('');
        historyList.querySelectorAll('[data-history-id]').forEach((button) => button.addEventListener('click', () => { selectedHistoryId = button.dataset.historyId; renderHistory(); }));

        const vehicle = filtered.find((item) => item.gps_id === selectedHistoryId) || filtered[0];
        historyDetail.innerHTML = `<div class="gps-history-detail-head"><div><h2>${escapeHtml(vehicle.plate_no)} · ${escapeHtml(vehicle.trip_ref)}</h2><p>${escapeHtml(vehicle.driver_name)} · ${vehicle.stops?.length || 0} จุดส่ง</p></div><div class="gps-history-total"><span>เวลารวมโดยประมาณ</span><strong>${totalTripMinutes(vehicle)} นาที</strong></div></div><div class="gps-history-route">${historyLegs(vehicle)}</div>`;
    }

    function renderReports() {
        if (!reportRows) return;
        reportRows.innerHTML = vehicles.map((vehicle) => {
            const variance = departureVariance(vehicle);
            const late = variance > 10;
            return `<tr><td class="fw-bold">${escapeHtml(vehicle.plate_no)}</td><td>${escapeHtml(vehicle.driver_name)}</td><td>${escapeHtml(vehicle.planned_departure)}</td><td>${escapeHtml(vehicle.actual_departure)}</td><td><span class="gps-report-result ${late ? 'is-alert' : ''}">${late ? `ช้า ${variance} นาที` : 'ตรงเวลา'}</span></td><td>${escapeHtml(vehicle.estimated_return)}</td></tr>`;
        }).join('');
    }

    function showView(view) {
        viewTabs.forEach((tab) => {
            const active = tab.dataset.gpsView === view;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        viewPanels.forEach((panel) => {
            const active = panel.dataset.gpsPanel === view;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
        if (view === 'live') window.setTimeout(() => map?.invalidateSize({ pan: false }), 30);
        if (view === 'history') renderHistory();
        if (view === 'reports') renderReports();
    }

    function renderDtcResult(payload, ok) {
        if (!dtcTestResult) return;
        const rows = Array.isArray(payload?.snapshot?.data) ? payload.snapshot.data : [];
        const rawError = payload?.raw_error;
        const errorInfo = payload?.error_info || {};
        setLastSuccess(payload?.last_success_at || root.dataset.lastSuccess);
        dtcTestResult.hidden = false;
        dtcTestResult.classList.toggle('is-error', !ok);
        dtcTestResult.innerHTML = `<div class="gps-dtc-result-head"><strong><i class="fas fa-${ok ? 'circle-check' : 'triangle-exclamation'}"></i> ${escapeHtml(ok ? payload?.message : (errorInfo.title || payload?.message) || 'ไม่ทราบผลการทดสอบ')}</strong>${ok ? `<span>${rows.length} คัน · ${escapeHtml(payload.snapshot.duration_ms)} ms · ใช้ ${escapeHtml(payload.snapshot.requests_used ?? 0)} requests · รายชื่อรถ${payload.snapshot.vehicle_master_from_cache ? 'จาก cache' : 'ดึงใหม่'}</span>` : `<span>${errorInfo.http_status ? `HTTP ${escapeHtml(errorInfo.http_status)} · ` : ''}ใช้ ${escapeHtml(payload?.requests_attempted ?? 0)} requests</span>`}</div>${!ok ? `<div class="gps-error-guide"><strong>${escapeHtml(errorInfo.advice || 'ตรวจเครือข่ายและลองใหม่')}</strong><span>${errorInfo.retryable ? 'ลองใหม่ได้เมื่อแก้สาเหตุแล้ว' : 'ต้องแก้การตั้งค่าหรือสิทธิ์ก่อนลองใหม่'}</span></div>` : ''}${ok ? `<div class="gps-dtc-cars">${rows.map((row) => `<div><strong>${escapeHtml(row.license_plate || row.vehicle_name || row.truck_name || row.gps_id || '-')}</strong><span>${escapeHtml(row.status_name_th || row.status_name_en || '-')} · ${formatQuantity(row.gps_speed)} กม./ชม.</span><small>${escapeHtml(row.time || '-')}${isStale(row.time) ? ' <b class="gps-stale-tag">ข้อมูลเก่า</b>' : ''} · ${escapeHtml(row.lat ?? '-')} / ${escapeHtml(row.lon ?? '-')}</small></div>`).join('')}</div>` : ''}${!ok && rawError ? `<details class="gps-dtc-raw"><summary>Raw error สำหรับตรวจสอบ</summary><div><b>${escapeHtml(rawError.type || 'Error')}</b><pre>${escapeHtml(rawError.detail || '-')}</pre></div></details>` : ''}`;
        if (ok) {
            applyLiveSnapshot(rows, payload.match_report || matchReport);
        } else {
            resetToDemo();
            setSourceState('offline');
        }
    }

    function renderUsage(usage) {
        if (!dtcUsage || !usage) return;
        const number = (value) => new Intl.NumberFormat('th-TH').format(Number(value || 0));
        dtcUsage.classList.toggle('is-stale', Number(usage.percent) >= 80 || Boolean(usage.locked_until));
        dtcUsage.title = `งบใช้ได้ ${number(usage.budget)} · เหลือ ${number(usage.remaining)}`;
        dtcUsage.innerHTML = `<i class="fas fa-gauge-high"></i> DTC เดือนนี้ ${number(usage.month_total)} / ${number(usage.quota)} · วันนี้ ${number(usage.today)}${usage.locked_until ? ' · หยุดเรียกชั่วคราว' : ''}`;
    }

    async function runDtcTest(button) {
        if (!button?.dataset.url || button.disabled) return;
        const buttons = [dtcTestButton, dtcRefreshButton].filter(Boolean);
        const original = button.innerHTML;
        buttons.forEach((item) => { item.disabled = true; });
        setSourceState('checking', mockSnapshot.data || []);
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> กำลังเรียก DTC...';
        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const payload = await response.json();
            if (response.status === 429 && !payload.error_info) {
                payload.error_info = { title: 'กดถี่เกินไป', advice: 'ปุ่มนี้กดได้ 1 ครั้งต่อนาที เพื่อประหยัดโควตา DTC', http_status: 429, retryable: true };
            }
            renderUsage(payload.usage);
            renderDtcResult(payload, response.ok && payload.ok);
        } catch (error) {
            renderDtcResult({ message: 'เรียก DTC ไม่สำเร็จ', error_info: { title: 'เบราว์เซอร์เชื่อมต่อระบบไม่ได้', advice: 'ตรวจเครือข่ายภายในหรือโหลดหน้าใหม่', retryable: true } }, false);
        } finally {
            buttons.forEach((item) => { item.disabled = false; });
            button.innerHTML = original;
        }
    }

    dtcTestButton?.addEventListener('click', () => runDtcTest(dtcTestButton));
    dtcRefreshButton?.addEventListener('click', () => runDtcTest(dtcRefreshButton));

    mappingToggle?.addEventListener('click', () => {
        const expanded = mappingPanel.hidden;
        mappingPanel.hidden = !expanded;
        mappingToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        mappingToggle.innerHTML = `<i class="fas fa-${expanded ? 'chevron-up' : 'list-check'} me-1"></i> ${expanded ? 'ซ่อนการจับคู่' : 'ดูการจับคู่รถ'}`;
    });

    toggleButton?.addEventListener('click', () => setRunning(!running));
    resetButton?.addEventListener('click', () => {
        setRunning(false);
        vehicles.forEach((vehicle, index) => { vehicle.progress = index * .35; vehicle.direction = 1; });
        selectedId = null;
        if (map) map.setView(company, 13);
        render();
    });
    viewAllButton?.addEventListener('click', fitAllVehicles);
    viewTabs.forEach((tab) => tab.addEventListener('click', () => showView(tab.dataset.gpsView)));
    historyVehicle?.addEventListener('change', renderHistory);
    historyDate?.addEventListener('change', renderHistory);

    initMap();
    setLastSuccess(root.dataset.lastSuccess);
    setSourceState('demo', mockSnapshot.data || []);
    renderMapping(matchReport);
    render();
    renderHistory();
    renderReports();
}());
