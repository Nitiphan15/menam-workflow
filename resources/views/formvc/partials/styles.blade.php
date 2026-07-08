<style>
    .vc-wrap { background:#fafaf7; padding:16px; border-radius:8px; }
    .vc-card { background:#fff; border:1px solid #e8e4dd; border-radius:8px; overflow:hidden; }
    .vc-summary-card { width:min(100%, 1360px); margin-inline:auto; }
    .vc-card-header { padding:12px 16px; border-bottom:1px solid #e8e4dd; background:#fff; font-weight:700; }
    .vc-kpi { height:100%; padding:14px; border:1px solid #e8e4dd; border-radius:8px; background:#fff; position:relative; }
    .vc-kpi::before { content:''; position:absolute; top:0; left:0; width:40px; height:3px; background:#b8421f; }
    .vc-kpi .label { color:#667085; font-size:.78rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; }
    .vc-kpi .value { color:#1f2937; font-size:1.35rem; font-weight:800; line-height:1.2; }
    .vc-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }
    .vc-table-wrap { max-height:560px; overflow:auto; border:1px solid #e8e4dd; }
    .vc-table th { position:sticky; top:0; z-index:2; background:#eef3f6; color:#1f2937; border-bottom:2px solid #94a3b8; white-space:nowrap; font-size:.76rem; text-transform:uppercase; letter-spacing:.03em; }
    .vc-table th .text-white-50 { color:#52677a !important; }
    .vc-table td { vertical-align:middle; }
    .vc-table tbody tr:hover { background:#f4e4dd; }
    .vc-table tfoot td { position:sticky; bottom:0; z-index:1; background:#d8eefb; border-top:2px solid #94a3b8; font-weight:800; }
    .vc-excel-wrap { max-height:none; overflow-x:auto; overflow-y:visible; border:0; }
    .vc-excel-summary { min-width:900px; table-layout:fixed; border-color:#d6dde5; }
    .vc-excel-summary .vc-code-col { width:10%; }
    .vc-excel-summary .vc-dept-col { width:28%; }
    .vc-excel-summary .vc-money-col { width:22%; }
    .vc-excel-summary .vc-rate-col { width:20%; }
    .vc-excel-summary th {
        position:static;
        padding:10px 12px;
        text-transform:none;
        letter-spacing:0;
        text-align:center;
        vertical-align:middle;
        font-size:.8rem;
        color:#263547;
        background:#eaf1f6;
        border-color:#c7d2dc;
    }
    .vc-excel-summary thead tr:first-child th { background:#dce9f2; font-weight:800; }
    .vc-excel-summary thead .vc-rate-group { background:#d7eaf7; color:#174b6b; }
    .vc-excel-summary td { padding:8px 12px; border-color:#d6dde5; font-size:.86rem; }
    .vc-excel-summary tbody tr { background:#fff; }
    .vc-excel-summary tbody tr:nth-child(even):not(.vc-group-total):not(.vc-grand-total):not(.vc-welding-row) { background:#fbfcfd; }
    .vc-excel-summary tbody tr:hover:not(.vc-group-total):not(.vc-grand-total):not(.vc-welding-row) { background:#f2f7fa; }
    .vc-excel-summary .vc-section-row td {
        padding:9px 14px;
        border-top:2px solid #a9b9c7;
        border-bottom:1px solid #ccd7e0;
        background:linear-gradient(90deg, #eef4f8 0%, #f9fbfc 75%);
        color:#334155;
    }
    .vc-excel-summary .vc-section-row:first-child td { border-top:0; }
    .vc-section-icon {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:28px;
        height:28px;
        margin-right:8px;
        border-radius:8px;
        background:#d7e5ef;
        color:#315b77;
    }
    .vc-section-title { font-size:.78rem; font-weight:900; letter-spacing:.07em; }
    .vc-section-description { margin-left:10px; color:#718096; font-size:.76rem; }
    .vc-section-production td { background:linear-gradient(90deg, #eef6f1 0%, #fbfcfb 75%) !important; }
    .vc-section-production .vc-section-icon { background:#d9ecdf; color:#326345; }
    .vc-section-logistic td { background:linear-gradient(90deg, #fff3e8 0%, #fffaf6 75%) !important; }
    .vc-section-logistic .vc-section-icon { background:#f6ddc6; color:#8a4a17; }
    .vc-section-grating td { background:linear-gradient(90deg, #f8ebf7 0%, #fdf8fc 75%) !important; }
    .vc-section-grating .vc-section-icon { background:#efd7ec; color:#84477e; }
    .vc-excel-summary .vc-group-total td {
        background:#fffbd2;
        color:#373300;
        font-weight:800;
        border-top:1px solid #d2c85a;
        border-bottom:1px solid #d2c85a;
    }
    .vc-excel-summary .vc-grand-total td {
        background:#bcefeb;
        color:#073f3b;
        font-weight:900;
        font-size:.92rem;
        border-top:2px solid #328f89;
        border-bottom:2px solid #328f89;
    }
    .vc-excel-summary .vc-welding-row td {
        background:#fff;
        color:#1f2937;
        border-top:1px solid #d6dde5;
        border-bottom:1px solid #d6dde5;
    }
    .vc-excel-summary .vc-welding-row td { padding-top:8px; padding-bottom:8px; }
    .vc-basis-tag {
        display:inline-block;
        margin-left:5px;
        padding:1px 6px;
        border-radius:999px;
        background:rgba(111, 35, 101, .12);
        color:#6f2365;
        font-size:.62rem;
        font-weight:700;
        line-height:1.45;
        vertical-align:middle;
    }
    .vc-excel-summary tfoot td {
        position:static;
        padding:9px 12px;
        background:#f8fafc;
        color:#334155;
        border-top:0;
        border-bottom:1px solid #d6dde5;
        font-weight:500;
    }
    .vc-excel-summary tfoot tr:first-child td { border-top:3px solid #64748b; }
    .vc-excel-summary tfoot td:first-child { color:#64748b; font-weight:700; text-align:center; }
    .vc-excel-summary tfoot td:last-child { background:#eef5fa; font-weight:800; color:#173f59; }
    .vc-basis-panel { padding:18px; border-top:1px solid #dce4ea; background:linear-gradient(180deg, #fbfcfd 0%, #f4f7f9 100%); }
    .vc-basis-heading { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
    .vc-basis-unit { padding:4px 10px; border-radius:999px; background:#e5edf3; color:#52677a; font-size:.72rem; font-weight:700; }
    .vc-basis-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .vc-basis-card {
        display:flex;
        align-items:center;
        gap:12px;
        min-height:88px;
        padding:14px;
        border:1px solid #dce4ea;
        border-radius:12px;
        background:#fff;
        box-shadow:0 3px 10px rgba(31, 41, 55, .045);
    }
    .vc-basis-icon {
        display:flex;
        align-items:center;
        justify-content:center;
        flex:0 0 42px;
        height:42px;
        border-radius:11px;
        font-size:1rem;
    }
    .vc-basis-label { color:#64748b; font-size:.76rem; font-weight:700; }
    .vc-basis-value { margin-top:3px; color:#1f2937; font-size:1.08rem; font-weight:900; font-variant-numeric:tabular-nums; }
    .vc-basis-meta { margin-top:3px; color:#64748b; font-size:.68rem; font-weight:600; }
    .vc-basis-action {
        width:100%;
        color:inherit;
        text-align:left;
        cursor:pointer;
        transition:border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .vc-basis-action:hover {
        border-color:#e2a064;
        box-shadow:0 8px 18px rgba(150, 80, 29, .14);
        transform:translateY(-1px);
    }
    .vc-truck-period {
        display:flex;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
        padding:10px 12px;
        margin-bottom:14px;
        border:1px solid #e7edf3;
        border-radius:8px;
        background:#f8fafc;
        color:#475569;
        font-size:.84rem;
        font-weight:700;
    }
    .vc-truck-last { margin-top:12px; color:#64748b; font-size:.82rem; }
    .vc-basis-fg .vc-basis-icon { background:#dcecf8; color:#24658e; }
    .vc-basis-grating .vc-basis-icon { background:#f1dff1; color:#83477d; }
    .vc-basis-sales .vc-basis-icon { background:#dcefe5; color:#34704e; }
    .vc-basis-truck .vc-basis-icon { background:#f9e3d2; color:#96501d; }
    @media (max-width: 991.98px) {
        .vc-basis-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 575.98px) {
        .vc-section-description { display:none; }
        .vc-basis-grid { grid-template-columns:1fr; }
    }
    .vc-matrix-table { min-width:1400px; }
    .vc-matrix-table th.account-col { min-width:130px; max-width:160px; white-space:normal; line-height:1.25; vertical-align:middle; }
    .vc-matrix-table th.dept-col, .vc-matrix-table td.dept-col { position:sticky; left:0; z-index:3; }
    .vc-matrix-table td.dept-col { background:#fff; box-shadow:1px 0 0 #e3e7ec; }
    .vc-matrix-table tbody tr:hover td.dept-col { background:#f4e4dd; }
    .vc-matrix-table tfoot td.dept-col { background:#d8eefb; }
    .vc-yoy-cell { min-width:96px; }
    .vc-yoy-sub { margin-top:2px; color:#667085; font-size:.7rem; line-height:1.15; white-space:nowrap; }
    .vc-yoy-sub.up { color:#146c43; }
    .vc-yoy-sub.down { color:#b42318; }
    .vc-site-split { color:#667085; font-size:.68rem; line-height:1.15; white-space:nowrap; }
    .vc-tabs { display:flex; flex-wrap:wrap; gap:0; margin:0 0 16px; border-bottom:2px solid #1a1a1a; }
    .vc-tabs .btn { border-radius:0; border:0; border-bottom:3px solid transparent; margin-bottom:-2px; color:#667085; font-weight:700; }
    .vc-tabs .btn.active { color:#b8421f; border-bottom-color:#b8421f; background:transparent; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    .text-tight { max-width:320px; white-space:normal; word-break:break-word; line-height:1.35; }
    .chart-box { height:340px; padding:12px; }
    .chart-box.tall { height:480px; }
    .quick-actions .btn { white-space:nowrap; }
    @media (max-width: 767.98px) {
        .vc-wrap { padding:10px; }
        .chart-box, .chart-box.tall { height:280px; }
        .vc-kpi .value { font-size:1.1rem; }
    }
</style>
