@include('formdie.partials.styles')
<style>
    /* หน้า DIE เสียใช้โทนเดียวกับ de-* ของ Die Tracking */
    .dr-wrap { background:#f5f7fa; padding:16px; border-radius:8px; color:#1f2937; }
    .dr-wrap h2 { font-size:1.05rem; font-weight:800; color:#1f2937; margin:0 0 4px; }
    .dr-wrap h3, .dr-wrap h4 { font-size:.95rem; font-weight:700; color:#1f2937; margin:0 0 12px; }
    .dr-nav { display:flex; flex-wrap:wrap; gap:0; align-items:center; padding:0 8px; margin-bottom:12px; background:#fff; border:1px solid #dfe5ec; border-radius:8px; }
    .dr-nav a { padding:10px 16px; border-bottom:3px solid transparent; color:#667085; font-size:13px; font-weight:700; text-decoration:none; }
    .dr-nav a:hover { color:#1f2937; }
    .dr-nav a.active { color:#0d6efd; border-bottom-color:#0d6efd; }
    .dr-nav a.dr-nav-add { margin:6px 4px 6px auto; padding:5px 12px; border:1px solid #0d6efd; border-radius:6px; background:#0d6efd; color:#fff; }
    .dr-nav a.dr-nav-add:hover { background:#0b5ed7; color:#fff; }
    .dr-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .dr-button { display:inline-block; padding:5px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#334155; font-size:13px; font-weight:600; text-decoration:none; cursor:pointer; }
    .dr-button:hover { background:#f8fafc; color:#1f2937; }
    .dr-primary { background:#0d6efd; border-color:#0d6efd; color:#fff; }
    .dr-primary:hover { background:#0b5ed7; color:#fff; }
    .dr-export { background:#198754; border-color:#198754; color:#fff; }
    .dr-export:hover { background:#157347; border-color:#146c43; color:#fff; }
    .dr-card { background:#fff; border:1px solid #dfe5ec; border-radius:8px; padding:14px 16px; margin-bottom:12px; }
    .dr-card.dr-table { padding:0; }
    .dr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:10px; }
    .dr-grid label { display:flex; flex-direction:column; gap:3px; font-size:.72rem; font-weight:700; color:#475569; margin:0; }
    .dr-grid input, .dr-grid select, .dr-grid textarea { width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; font-size:13px; font-weight:400; }
    .dr-grid input[readonly] { background:#f1f5f9; }
    .dr-muted { font-size:12px; color:#667085; }
    .dr-table { overflow:auto; }
    .dr-table table { width:100%; border-collapse:collapse; white-space:nowrap; font-size:.82rem; }
    .dr-table th { padding:8px 10px; background:#eef3f6; border-bottom:2px solid #94a3b8; color:#1f2937; font-size:.72rem; font-weight:700; text-align:left; }
    .dr-table td { padding:6px 10px; border-bottom:1px solid #f0f3f7; vertical-align:middle; }
    .dr-table tbody tr:hover { background:#f8fafc; }
    .dr-table td form { margin:0; }
    .dr-notice { padding:10px 12px; margin-bottom:12px; border-radius:6px; background:#fffbeb; color:#92400e; font-size:13px; }
</style>
@include('formdie.partials.nav')
<nav class="dr-nav">
    <a href="{{ route('die-reports.index') }}" class="{{ request()->routeIs('die-reports.index') ? 'active' : '' }}">รายการ DIE เสีย</a>
    <a href="{{ route('die-reports.reports') }}" class="{{ request()->routeIs('die-reports.reports') ? 'active' : '' }}">รายงาน</a>
    <a href="{{ route('die-reports.create') }}" class="dr-nav-add"><i class="fa fa-plus me-1"></i>บันทึก DIE เสีย</a>
</nav>
@if(isset($ready) && !$ready)<div class="dr-notice">ยังไม่ได้ติดตั้งตาราง DIE Reports กรุณาให้ผู้ดูแลระบบติดตั้ง migration ของโมดูลนี้ก่อนเริ่มใช้งาน</div>@endif
