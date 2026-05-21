@php
    $tabLabels = [
        'summary' => 'สรุปแผนก',
        'monthly' => 'รายเดือน',
        'matrix' => 'แผนก × ค่าใช้จ่าย',
        'accounts' => 'รายงานตามบัญชี',
        'yearly' => 'รายปีตาม Class',
        'details' => 'รายละเอียด',
    ];
@endphp

<div id="vcLoadingOverlay" class="vc-loading-overlay" aria-hidden="true">
    <div class="vc-loading-box">
        <div class="vc-loading-spinner"></div>
        <div class="vc-loading-text">กำลังโหลดข้อมูล<span id="vcLoadingTarget"></span>…</div>
        <div class="vc-loading-hint">ครั้งแรกของช่วงนี้อาจใช้เวลา 10-40 วินาที</div>
    </div>
</div>

@push('styles')
    <style>
        .vc-loading-overlay {
            position: fixed; inset: 0; z-index: 2000;
            background: rgba(255,255,255,.88);
            display: none;
            align-items: center; justify-content: center;
            backdrop-filter: blur(2px);
        }
        .vc-loading-overlay.show { display: flex; }
        .vc-loading-box {
            background: #fff;
            border: 1px solid #cfd6cf;
            border-radius: 12px;
            padding: 28px 36px;
            box-shadow: 0 12px 40px rgba(0,0,0,.12);
            text-align: center;
            min-width: 280px;
        }
        .vc-loading-spinner {
            width: 48px; height: 48px;
            margin: 0 auto 14px;
            border: 4px solid #e3ebe3;
            border-top-color: #2d6a4f;
            border-radius: 50%;
            animation: vcLoadingSpin 0.9s linear infinite;
        }
        .vc-loading-text { font-size: 1.05rem; font-weight: 600; color: #1f2d22; }
        .vc-loading-hint { font-size: .82rem; color: #7a8579; margin-top: 6px; }
        @keyframes vcLoadingSpin { to { transform: rotate(360deg); } }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            const overlay = document.getElementById('vcLoadingOverlay');
            const targetSpan = document.getElementById('vcLoadingTarget');
            if (!overlay) return;

            const labelMap = @json($tabLabels);
            const routeMap = {
                'variable-cost.summary': 'summary',
                'variable-cost.monthly': 'monthly',
                'variable-cost.matrix': 'matrix',
                'variable-cost.accounts': 'accounts',
                'variable-cost.yearly': 'yearly',
                'variable-cost.details': 'details',
            };
            let showTimer = null;
            const DELAY_MS = 250;

            function pageLabelFromHref(href) {
                if (!href) return '';
                try {
                    const url = new URL(href, window.location.origin);
                    const path = url.pathname.replace(/\/+$/, '');
                    const last = path.split('/').filter(Boolean).pop() || '';
                    return labelMap[last] || '';
                } catch (e) { return ''; }
            }

            function showOverlay(label) {
                if (showTimer) clearTimeout(showTimer);
                showTimer = setTimeout(function () {
                    targetSpan.textContent = label ? ' หน้า' + label : '';
                    overlay.classList.add('show');
                }, DELAY_MS);
            }

            function cancelOverlay() {
                if (showTimer) { clearTimeout(showTimer); showTimer = null; }
                overlay.classList.remove('show');
            }

            function cookieValue(name) {
                const prefix = name + '=';
                const match = document.cookie
                    .split(';')
                    .map(function (part) { return part.trim(); })
                    .find(function (part) { return part.indexOf(prefix) === 0; });

                return match ? match.substring(prefix.length) : '';
            }

            function clearCookie(name) {
                document.cookie = name + '=; Max-Age=0; path=/; SameSite=Lax';
                document.cookie = name + '=; Max-Age=0; path={{ request()->getBaseUrl() ?: '/' }}; SameSite=Lax';
            }

            function isExportHref(href) {
                if (!href) return false;
                try {
                    const url = new URL(href, window.location.origin);
                    return /\/variable-cost\/export\/?$/.test(url.pathname);
                } catch (e) {
                    return /\/variable-cost\/export(\?|$)/.test(href);
                }
            }

            function beginExportDownload(href) {
                const token = String(Date.now()) + String(Math.random()).slice(2);
                const url = new URL(href, window.location.origin);
                url.searchParams.set('vc_download_token', token);
                clearCookie('vc_download_token');
                showOverlay(' Excel');

                let tries = 0;
                const maxTries = 240; // 2 minutes
                const poll = setInterval(function () {
                    tries++;
                    if (cookieValue('vc_download_token') === token || tries >= maxTries) {
                        clearInterval(poll);
                        clearCookie('vc_download_token');
                        cancelOverlay();
                    }
                }, 500);

                window.location.href = url.toString();
            }

            // Cancel on bfcache restore
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) cancelOverlay();
            });

            // Hook into all link clicks within the VC wrap
            document.addEventListener('click', function (e) {
                const a = e.target.closest('a');
                if (!a) return;
                const href = a.getAttribute('href');
                if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
                if (a.target === '_blank' || a.hasAttribute('download')) return;
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;

                if (isExportHref(href)) {
                    e.preventDefault();
                    beginExportDownload(href);
                    return;
                }

                // Only intercept VC routes
                if (!/\/variable-cost(\/|\?|$)/.test(href) && !a.closest('.vc-wrap')) return;
                showOverlay(pageLabelFromHref(href));
            });

            // Hook into form submit within filters
            document.addEventListener('submit', function (e) {
                const form = e.target;
                if (!(form instanceof HTMLFormElement)) return;
                const action = form.getAttribute('action') || window.location.href;
                if (!/\/variable-cost/.test(action)) return;
                showOverlay(pageLabelFromHref(action) || '');
            });

            // Safety: hide overlay if user uses browser back/forward
            window.addEventListener('beforeunload', function () {
                // keep showing — page is leaving
            });
        })();
    </script>
@endpush
