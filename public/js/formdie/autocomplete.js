/* DIE Tracking — reusable autocomplete widget
 *
 * Usage:
 *   DieAutocomplete.attach(input, {
 *       url: '/die-tracking/api/suggest/wo',
 *       getSiteFn: () => document.getElementById('filterConn').value,  // optional
 *       paramName: 'q',
 *       minChars: 1,
 *       debounceMs: 200,
 *       renderItem: (row) => `<div>${row.value}<small>${row.brand}</small></div>`,
 *       onSelect: (row) => { input.value = row.value; input.dispatchEvent(new Event('change')); },
 *   });
 */
window.DieAutocomplete = (function () {
    'use strict';

    // CSS injected once
    let cssInjected = false;
    function ensureCss() {
        if (cssInjected) return;
        cssInjected = true;
        const css = `
            .die-ac-wrap { position:relative; display:inline-block; }
            .die-ac-list {
                position:fixed; z-index:10050;
                background:#fff; border:1px solid #dfe5ec; border-radius:6px;
                margin-top:4px; max-height:300px; overflow-y:auto; overflow-x:hidden;
                box-shadow:0 4px 16px rgba(15,23,42,.08);
                font-size:13px; min-width:320px; max-width:460px;
            }
            .die-ac-item {
                padding:5px 10px; cursor:pointer; border-bottom:1px solid #f0f3f7;
                display:flex; flex-direction:column; align-items:flex-start; gap:1px;
                line-height:1.35;
            }
            .die-ac-item:last-child { border-bottom:0; }
            .die-ac-item:hover, .die-ac-item.active { background:#eff6ff; }
            .die-ac-item .main {
                color:#1f2937; font-weight:700;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;
            }
            .die-ac-item .sub  { color:#667085; font-size:11px; text-align:left; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
            .die-ac-item .sub small { display:inline-block; margin-right:6px; padding:1px 5px; background:#f1f5f9; border-radius:3px; color:#475569; font-weight:600; }
            .die-ac-empty { padding:10px; color:#94a3b8; text-align:center; font-size:12px; }
            .die-ac-loading { padding:10px; color:#0d6efd; text-align:center; font-size:12px; }
        `;
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
    }

    function debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function attach(input, options) {
        if (!input || input.dataset.acAttached === '1') return;
        input.dataset.acAttached = '1';
        ensureCss();

        const opts = Object.assign({
            url: '',
            paramName: 'q',
            minChars: 1,
            debounceMs: 200,
            getSiteFn: null,
            renderItem: (row) => `<div class="main">${row.value || ''}</div>`,
            onSelect: (row) => { input.value = row.value; },
        }, options || {});

        // Wrap input
        const parent = input.parentNode;
        const wrap = document.createElement('div');
        wrap.className = 'die-ac-wrap';
        wrap.style.width = '100%';
        parent.insertBefore(wrap, input);
        wrap.appendChild(input);

        const list = document.createElement('div');
        list.className = 'die-ac-list';
        list.style.display = 'none';
        document.body.appendChild(list);

        let rows = [];
        let activeIdx = -1;

        function positionList() {
            const rect = input.getBoundingClientRect();
            list.style.left = `${rect.left}px`;
            list.style.top = `${rect.bottom + 4}px`;
            list.style.width = `${Math.max(rect.width, 320)}px`;
            list.style.maxWidth = `${Math.max(320, Math.min(460, window.innerWidth - rect.left - 12))}px`;
        }

        function open() {
            positionList();
            list.style.display = '';
        }

        function close() {
            list.style.display = 'none';
            list.innerHTML = '';
            activeIdx = -1;
        }

        function render() {
            if (!rows.length) {
                list.innerHTML = '<div class="die-ac-empty">ไม่พบข้อมูล</div>';
                return;
            }
            list.innerHTML = rows.map((r, i) =>
                `<div class="die-ac-item ${i === activeIdx ? 'active' : ''}" data-idx="${i}">${opts.renderItem(r)}</div>`
            ).join('');
            list.querySelectorAll('.die-ac-item').forEach(el => {
                el.addEventListener('mousedown', (e) => {  // mousedown ก่อน blur
                    e.preventDefault();
                    const idx = +el.dataset.idx;
                    opts.onSelect(rows[idx]);
                    close();
                });
            });
        }

        const fetchSuggest = debounce(async () => {
            const q = input.value.trim();
            if (q.length < opts.minChars) { close(); return; }
            open();
            list.innerHTML = '<div class="die-ac-loading"><i class="fa fa-spinner fa-spin me-1"></i>กำลังค้นหา...</div>';
            try {
                const params = new URLSearchParams();
                params.append(opts.paramName, q);
                if (opts.getSiteFn) {
                    const conn = opts.getSiteFn();
                    if (conn === 'pgsqlpcmp' || conn === 'P') params.append('site', 'P');
                    else                                       params.append('site', 'W');
                }
                const res = await fetch(opts.url + '?' + params.toString());
                const data = await res.json();
                rows = data.rows || [];
                activeIdx = -1;
                render();
            } catch (e) {
                list.innerHTML = `<div class="die-ac-empty">Error: ${e.message}</div>`;
            }
        }, opts.debounceMs);

        input.addEventListener('input', fetchSuggest);
        input.addEventListener('focus', () => {
            if (input.value.trim().length >= opts.minChars) fetchSuggest();
        });
        input.addEventListener('blur', () => {
            setTimeout(close, 150);
        });
        window.addEventListener('resize', () => {
            if (list.style.display !== 'none') positionList();
        });
        window.addEventListener('scroll', () => {
            if (list.style.display !== 'none') positionList();
        }, true);
        input.addEventListener('keydown', (e) => {
            if (list.style.display === 'none') return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = Math.min(activeIdx + 1, rows.length - 1);
                render();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = Math.max(activeIdx - 1, 0);
                render();
            } else if (e.key === 'Enter') {
                if (activeIdx >= 0 && rows[activeIdx]) {
                    e.preventDefault();
                    opts.onSelect(rows[activeIdx]);
                    close();
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });
    }

    return { attach };
})();

/* DIE Tracking — multi-select dropdown (checkbox) ครอบ <select multiple> ที่มีอยู่
 *
 *   const ms = DieMultiSelect.enhance(document.getElementById('filterCategory'),
 *                  { allLabel: 'All categories', onChange: () => search() });
 *   // หลังโหลด options ใหม่ (select.innerHTML = ...) ให้เรียก ms.refresh()
 *   ms.getValue()  // -> "A,B"  (comma-separated ของ option ที่เลือก)
 */
window.DieMultiSelect = (function () {
    'use strict';

    let cssInjected = false;
    function ensureCss() {
        if (cssInjected) return;
        cssInjected = true;
        const css = `
            .die-ms-wrap { position:relative; display:inline-block; min-width:160px; }
            .die-ms-btn {
                width:100%; text-align:left; background:#fff; border:1px solid #cbd5e1;
                border-radius:6px; padding:7px 26px 7px 10px; font-size:13px; cursor:pointer;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; position:relative;
            }
            .die-ms-btn:after { content:'\\25BC'; position:absolute; right:9px; top:50%; transform:translateY(-50%); font-size:9px; color:#64748b; }
            .die-ms-list {
                position:absolute; top:100%; left:0; z-index:1100; margin-top:4px;
                background:#fff; border:1px solid #dfe5ec; border-radius:6px;
                max-height:280px; overflow-y:auto; min-width:100%; width:max-content; max-width:340px;
                box-shadow:0 4px 16px rgba(15,23,42,.1); font-size:13px; padding:4px 0;
            }
            .die-ms-item { display:flex; align-items:center; gap:8px; padding:5px 12px; cursor:pointer; }
            .die-ms-item:hover { background:#eff6ff; }
            .die-ms-item input { cursor:pointer; }
            .die-ms-empty { padding:8px 12px; color:#94a3b8; }
            .die-ms-tools { display:flex; justify-content:space-between; padding:4px 12px 6px; border-bottom:1px solid #f0f3f7; margin-bottom:2px; }
            .die-ms-tools a { font-size:11px; color:#2563eb; cursor:pointer; text-decoration:none; }
        `;
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
    }

    function enhance(select, options) {
        if (!select) return null;
        ensureCss();
        const opts = Object.assign({ allLabel: 'All', onChange: null }, options || {});
        select.multiple = true;
        select.style.display = 'none';

        const wrap = document.createElement('div');
        wrap.className = 'die-ms-wrap';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'die-ms-btn';
        wrap.appendChild(btn);

        const list = document.createElement('div');
        list.className = 'die-ms-list';
        list.style.display = 'none';
        wrap.appendChild(list);

        function realOptions() {
            return [...select.options].filter(o => o.value !== '');
        }
        function selectedValues() {
            return realOptions().filter(o => o.selected).map(o => o.value);
        }
        function updateBtn() {
            const sel = selectedValues();
            if (sel.length === 0) btn.textContent = opts.allLabel;
            else if (sel.length === 1) btn.textContent = realOptions().find(o => o.selected)?.textContent || sel[0];
            else btn.textContent = `เลือก ${sel.length} รายการ`;
        }
        function renderList() {
            const ro = realOptions();
            if (!ro.length) { list.innerHTML = '<div class="die-ms-empty">ไม่มีข้อมูล</div>'; return; }
            const tools = `<div class="die-ms-tools"><a data-act="all">เลือกทั้งหมด</a><a data-act="none">ล้าง</a></div>`;
            list.innerHTML = tools + ro.map((o, i) =>
                `<label class="die-ms-item"><input type="checkbox" data-i="${i}" ${o.selected ? 'checked' : ''}><span>${o.textContent}</span></label>`
            ).join('');
            list.querySelectorAll('input[type=checkbox]').forEach(cb => {
                cb.addEventListener('change', () => {
                    ro[+cb.dataset.i].selected = cb.checked;
                    updateBtn();
                    if (opts.onChange) opts.onChange();
                });
            });
            list.querySelectorAll('a[data-act]').forEach(a => {
                a.addEventListener('click', () => {
                    const on = a.dataset.act === 'all';
                    ro.forEach(o => o.selected = on);
                    renderList(); updateBtn();
                    if (opts.onChange) opts.onChange();
                });
            });
        }
        function open() { renderList(); list.style.display = ''; }
        function close() { list.style.display = 'none'; }

        btn.addEventListener('click', () => { list.style.display === 'none' ? open() : close(); });
        document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) close(); });

        updateBtn();

        return {
            refresh() { updateBtn(); if (list.style.display !== 'none') renderList(); },
            getValue() { return selectedValues().join(','); },
            clear() { realOptions().forEach(o => o.selected = false); updateBtn(); },
            setValue(arr) {
                const set = new Set((Array.isArray(arr) ? arr : String(arr || '').split(',')).map(s => s.trim()).filter(Boolean));
                realOptions().forEach(o => o.selected = set.has(o.value));
                updateBtn();
            },
        };
    }

    return { enhance };
})();
