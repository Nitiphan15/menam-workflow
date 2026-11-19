/**
 * ส่ง JavaScript error ที่เกิดในหน้าเว็บไปเก็บที่ server (POST /client-errors)
 * ดูได้ที่ adminweb > Error Log > ไฟล์ [JS] client-errors-*.log
 *
 * - ส่งได้ไม่เกิน 10 ครั้งต่อการโหลดหน้า และไม่ส่ง error เดิมซ้ำ
 * - ข้าม error จาก browser extension และ "Script error." (ไม่มีข้อมูลให้ debug)
 * - ห้าม throw ออกไปเด็ดขาด
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var endpoint = script && script.getAttribute('data-endpoint');
    if (!endpoint || !window.fetch) {
        return;
    }

    var MAX_REPORTS = 10;
    var sent = 0;
    var seen = {};

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function isIgnored(message, source) {
        if (!message) return true;
        if (/^Script error\.?$/i.test(message)) return true;
        if (/ResizeObserver loop/i.test(message)) return true; // เตือนของ browser ไม่ใช่ bug
        if (source && /^(chrome|moz|safari|edge)-extension:/i.test(source)) return true;
        return false;
    }

    function report(payload) {
        try {
            if (sent >= MAX_REPORTS || isIgnored(payload.message, payload.source)) return;

            var key = [payload.message, payload.source, payload.line].join('|');
            if (seen[key]) return;
            seen[key] = true;
            sent++;

            payload.page = location.origin + location.pathname; // ไม่ส่ง query string

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            }).catch(function () { /* เงียบ */ });
        } catch (e) {
            /* เงียบ */
        }
    }

    function text(value, max) {
        var s = value == null ? '' : String(value);
        return s.length > max ? s.slice(0, max) : s;
    }

    window.addEventListener('error', function (event) {
        // error ของการโหลด resource (img/script 404) ไม่มี message — ข้าม
        if (!event || !event.message) return;

        report({
            type: 'error',
            message: text(event.message, 2000),
            source: text(event.filename, 1000),
            line: event.lineno || null,
            column: event.colno || null,
            stack: event.error && event.error.stack ? text(event.error.stack, 6000) : null
        });
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event && event.reason;
        var message = reason && reason.message ? reason.message : text(reason, 2000);

        report({
            type: 'unhandledrejection',
            message: text('Unhandled promise rejection: ' + message, 2000),
            source: null,
            line: null,
            column: null,
            stack: reason && reason.stack ? text(reason.stack, 6000) : null
        });
    });
})();
