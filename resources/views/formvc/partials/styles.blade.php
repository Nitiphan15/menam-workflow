<style>
    .vc-wrap { background:#fafaf7; padding:16px; border-radius:8px; }
    .vc-card { background:#fff; border:1px solid #e8e4dd; border-radius:8px; overflow:hidden; }
    .vc-card-header { padding:12px 16px; border-bottom:1px solid #e8e4dd; background:#fff; font-weight:700; }
    .vc-kpi { height:100%; padding:14px; border:1px solid #e8e4dd; border-radius:8px; background:#fff; position:relative; }
    .vc-kpi::before { content:''; position:absolute; top:0; left:0; width:40px; height:3px; background:#b8421f; }
    .vc-kpi .label { color:#667085; font-size:.78rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; }
    .vc-kpi .value { color:#1f2937; font-size:1.35rem; font-weight:800; line-height:1.2; }
    .vc-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }
    .vc-table-wrap { max-height:560px; overflow:auto; border:1px solid #e8e4dd; }
    .vc-table th { position:sticky; top:0; z-index:2; background:#1a1a1a; color:#fff; white-space:nowrap; font-size:.76rem; text-transform:uppercase; letter-spacing:.03em; }
    .vc-table td { vertical-align:middle; }
    .vc-table tbody tr:hover { background:#f4e4dd; }
    .vc-table tfoot td { position:sticky; bottom:0; z-index:1; background:#d8eefb; border-top:2px solid #1a1a1a; font-weight:800; }
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
