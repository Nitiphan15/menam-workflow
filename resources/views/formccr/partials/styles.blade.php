<style>
    .ccr-wrap { background:#f7f8fa; padding:18px; border-radius:10px; }
    .ccr-card { background:#fff; border:1px solid #e3e6eb; border-radius:10px; box-shadow:0 1px 2px rgba(15,23,42,.04); overflow:hidden; }
    .ccr-card + .ccr-card { margin-top:16px; }
    .ccr-card-header { padding:14px 18px; border-bottom:1px solid #eef0f4; background:#fff; font-weight:700; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .ccr-card-header .ccr-meta { color:#667085; font-weight:500; font-size:.82rem; }
    .ccr-card-body { padding:16px 18px; }

    .ccr-kpi { height:100%; padding:16px 18px; border:1px solid #e3e6eb; border-radius:10px; background:#fff; position:relative; overflow:hidden; }
    .ccr-kpi::before { content:''; position:absolute; top:0; left:0; width:100%; height:3px; background:linear-gradient(90deg,#2563eb,#0ea5e9); }
    .ccr-kpi.kpi-amber::before { background:linear-gradient(90deg,#d97706,#f59e0b); }
    .ccr-kpi.kpi-green::before { background:linear-gradient(90deg,#059669,#10b981); }
    .ccr-kpi.kpi-rose::before { background:linear-gradient(90deg,#be123c,#f43f5e); }
    .ccr-kpi .label { color:#667085; font-size:.74rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .ccr-kpi .value { color:#0f172a; font-size:1.6rem; font-weight:800; line-height:1.15; font-variant-numeric:tabular-nums; }
    .ccr-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }

    .ccr-tabs { display:flex; flex-wrap:wrap; gap:0; margin:0 0 16px; border-bottom:2px solid #0f172a; }
    .ccr-tabs .btn { border-radius:0; border:0; border-bottom:3px solid transparent; margin-bottom:-2px; color:#667085; font-weight:700; padding:10px 18px; }
    .ccr-tabs .btn:hover { color:#0f172a; background:transparent; }
    .ccr-tabs .btn.active { color:#2563eb; border-bottom-color:#2563eb; background:transparent; }

    .ccr-table-wrap { max-height:640px; overflow:auto; }
    .ccr-table { margin-bottom:0; }
    .ccr-table th { position:sticky; top:0; z-index:2; background:#0f172a; color:#fff; white-space:nowrap; font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; font-weight:600; padding:10px 12px; }
    .ccr-table td { vertical-align:middle; padding:8px 12px; border-color:#eef0f4; color:#0f172a; }
    .ccr-table tbody tr:hover { background:#f1f5ff; }
    .ccr-table tfoot td { position:sticky; bottom:0; z-index:1; background:#e0ecff; border-top:2px solid #0f172a; font-weight:700; }
    .ccr-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }

    .ccr-class-row td { background:#f8fafc; font-weight:700; cursor:pointer; }
    .ccr-class-row:hover td { background:#eef2ff; }
    .ccr-class-row .toggle-ic::before { content:'▸'; display:inline-block; margin-right:6px; transition:transform .15s; color:#2563eb; }
    .ccr-class-row.is-open .toggle-ic::before { transform:rotate(90deg); }
    .ccr-account-row td { background:#fff; }
    .ccr-account-row.d-none { display:none; }

    .ccr-detail-scroll { max-height:720px; overflow:auto; border:1px solid #e3e6eb; background:#fff; }
    .ccr-detail-table { min-width:1480px; font-size:.86rem; border-collapse:collapse; }
    .ccr-detail-table th { position:sticky; top:0; z-index:2; padding:8px 10px; vertical-align:middle; background:#0f172a; color:#fff; border:1px solid #1e293b; text-align:center; font-weight:600; font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; }
    .ccr-detail-table td { padding:6px 10px; vertical-align:middle; background:#fff; border:1px solid #eef0f4; color:#0f172a; }
    .ccr-detail-table .vc-num, .ccr-detail-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .ccr-subtotal td { background:#f1f5f9 !important; font-weight:700; border-top:1px solid #cbd5e1; }
    .ccr-subtotal td.label { text-align:right; }
    .ccr-grand-total td { position:sticky; bottom:0; z-index:1; background:#dbeafe !important; border-top:2px solid #1d4ed8; font-weight:700; }

    .ccr-chart-box { padding:14px 16px; height:340px; }
    .ccr-chart-box.tall { height:400px; }

    .ccr-empty { padding:40px 16px; text-align:center; color:#94a3b8; }

    .ccr-drill-link { color:#2563eb; font-weight:700; text-decoration:none; }
    .ccr-drill-link:hover { color:#1d4ed8; text-decoration:underline; }

    @media (max-width:767.98px) {
        .ccr-wrap { padding:10px; }
        .ccr-kpi .value { font-size:1.2rem; }
        .ccr-chart-box, .ccr-chart-box.tall { height:280px; }
    }
</style>
