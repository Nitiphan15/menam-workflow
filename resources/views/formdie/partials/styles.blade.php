<style>
    /* ===== DIE Tracking — shared styles (de-* prefix) — matches dp/vc/wos format ===== */
    .de-wrap { background:#f5f7fa; padding:16px; border-radius:8px; }
    .de-card { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:hidden; margin-bottom:12px; }
    .de-card.de-ac-open { overflow:visible; position:relative; z-index:1200; }
    .de-card-header {
        padding:12px 16px; border-bottom:1px solid #e8edf2; background:#fff;
        font-weight:700; color:#1f2937;
        display:flex; align-items:center; justify-content:space-between; gap:12px;
    }
    .de-card-body { padding:14px 16px; }
    .de-card-body.tight { padding:10px 12px; }

    /* KPI */
    .de-kpi {
        height:100%; padding:14px; border:1px solid #dfe5ec; border-radius:8px;
        background:#fff; position:relative;
    }
    .de-kpi::before {
        content:''; position:absolute; top:0; left:0; width:40px; height:3px; background:#0d6efd;
    }
    .de-kpi.kpi-green::before { background:#16a34a; }
    .de-kpi.kpi-red::before   { background:#dc2626; }
    .de-kpi.kpi-amber::before { background:#f59e0b; }
    .de-kpi.kpi-purple::before{ background:#7c3aed; }
    .de-kpi .label { color:#667085; font-size:.78rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
    .de-kpi .value { color:#1f2937; font-size:1.35rem; font-weight:800; line-height:1.2; }
    .de-kpi .sub   { color:#667085; font-size:.78rem; margin-top:6px; }
    .de-kpi.kpi-green .value { color:#16a34a; }
    .de-kpi.kpi-red   .value { color:#dc2626; }
    .de-kpi.kpi-amber .value { color:#b45309; }

    /* Filter bar */
    .de-filter { display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
    .de-filter label { display:flex; flex-direction:column; gap:3px; font-size:.72rem;
        color:#475569; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .de-filter input, .de-filter select {
        border:1px solid #cbd5e1; padding:6px 10px; border-radius:6px; min-width:140px; font-size:13px;
    }
    .de-filter .divider { width:1px; background:#e2e8f0; align-self:stretch; margin:0 4px; }
    .de-filter .actions { display:flex; gap:6px; margin-left:auto; flex-wrap:wrap; }

    /* Main page navigation */
    .de-main-nav {
        display:flex; flex-wrap:wrap; gap:6px; align-items:center;
        background:#fff; border:1px solid #dfe5ec; border-radius:8px;
        padding:8px; margin-bottom:12px;
    }
    .de-main-nav a {
        display:inline-flex; align-items:center; gap:7px;
        padding:7px 12px; border-radius:6px; color:#475569;
        text-decoration:none; font-size:13px; font-weight:800;
    }
    .de-main-nav a:hover { background:#eff6ff; color:#1d4ed8; }
    .de-main-nav a.active { background:#0d6efd; color:#fff; }
    .de-nav-back {
        position:fixed; right:20px; bottom:20px; z-index:1050;
        display:inline-flex; align-items:center; gap:7px;
        padding:10px 16px; border-radius:999px; font-size:13px; font-weight:800;
        color:#fff; background:#0d6efd; border:1px solid #0b5ed7; cursor:pointer;
        box-shadow:0 4px 14px rgba(13,110,253,.35);
    }
    .de-nav-back:hover { background:#0b5ed7; border-color:#0a58ca; box-shadow:0 6px 18px rgba(13,110,253,.45); }

    /* Table */
    .de-table-wrap {
        max-height:560px; overflow:auto; background:#fff;
    }
    .de-table { width:100%; border-collapse:collapse; font-size:.82rem; margin-bottom:0; }
    .de-table th {
        position:sticky; top:0; z-index:2; background:#eef3f6;
        color:#1f2937; border-bottom:2px solid #94a3b8;
        padding:8px 10px; text-align:left; white-space:nowrap;
        font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; font-weight:700;
    }
    .de-table td { padding:6px 10px; border-bottom:1px solid #f0f3f7; vertical-align:middle; }
    .de-table tbody tr:hover { background:#f8fafc; }
    .de-table tbody tr.clickable { cursor:pointer; }
    .de-table tbody tr.clickable:hover { background:#eff6ff; }
    .de-table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .de-table tfoot td {
        position:sticky; bottom:0; z-index:1; background:#d8eefb;
        border-top:2px solid #94a3b8; font-weight:800;
    }

    /* Tabs (sub-navigation inside a page) */
    .de-tabs {
        display:flex; flex-wrap:wrap; gap:0; margin:0; padding:0 16px;
        border-bottom:2px solid #1a1a1a; background:#fff;
    }
    .de-tabs button {
        background:transparent; border:0; border-bottom:3px solid transparent; margin-bottom:-2px;
        color:#667085; font-weight:700; padding:10px 18px; cursor:pointer;
    }
    .de-tabs button.active { color:#0d6efd; border-bottom-color:#0d6efd; }
    .de-tabs button:hover:not(.active) { color:#1f2937; }

    /* Status chips */
    .de-chip { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.7rem; font-weight:700; }
    .de-chip.ok      { background:#dcfce7; color:#166534; }
    .de-chip.warn    { background:#fef3c7; color:#92400e; }
    .de-chip.danger  { background:#fee2e2; color:#991b1b; }
    .de-chip.info    { background:#dbeafe; color:#1e40af; }
    .de-chip.muted   { background:#f1f5f9; color:#475569; }

    /* Empty/loading states */
    .de-empty {
        text-align:center; padding:40px; color:#94a3b8; background:#fff;
    }
    .de-empty .icon { font-size:32px; color:#cbd5e1; margin-bottom:10px; }
    .de-empty .text { font-size:14px; }
    .de-loading {
        text-align:center; padding:40px; color:#0d6efd; font-weight:600; background:#fff;
    }
    .de-skel { background:#f1f5f9; border-radius:6px; height:14px; margin:6px 0; animation:deSkel 1.2s infinite; }
    .de-skel.lg { height:24px; }
    @keyframes deSkel { 0%{opacity:.6;} 50%{opacity:1;} 100%{opacity:.6;} }

    /* Sub-card (inside a panel) */
    .de-sub-card { background:#fff; border:1px solid #e8edf2; border-radius:6px; padding:10px 12px; }
    .de-sub-card .title { font-size:.72rem; color:#667085; text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-bottom:4px; }

    /* Progress bar */
    .de-progress { height:6px; background:#edf2f7; border-radius:999px; overflow:hidden; }
    .de-progress > span { display:block; height:100%; background:#2563eb; }
    .de-progress.warn > span { background:#f59e0b; }
    .de-progress.danger > span { background:#dc2626; }
    .de-progress.ok > span { background:#16a34a; }

    /* Chart frame */
    .de-chart-box { padding:12px; height:280px; }
    .de-chart-box.tall { height:380px; }

    @media (max-width: 767.98px) {
        .de-wrap { padding:10px; }
        .de-chart-box, .de-chart-box.tall { height:240px; }
        .de-kpi .value { font-size:1.1rem; }
    }
</style>
