<div class="de-main-nav">
    <a href="{{ route('die.index') }}?mode=workorder" class="{{ request()->routeIs('die.index') ? 'active' : '' }}">
        <i class="fa fa-chart-line"></i> Dashboard
    </a>
    <a href="{{ route('die.master') }}" class="{{ request()->routeIs('die.master') ? 'active' : '' }}">
        <i class="fa fa-database"></i> Die Master
    </a>
    <a href="{{ request('wo') ? route('die.wo-detail', ['wo' => request('wo')]) : route('die.wo-detail') }}" class="{{ request()->routeIs('die.wo-detail') ? 'active' : '' }}">
        <i class="fa fa-file-lines"></i> WO Detail
    </a>
    <button type="button" id="deBackBtn" class="de-nav-back" style="display:none;" onclick="history.back()"
            title="ย้อนกลับไปหน้าก่อนหน้า">
        <i class="fa fa-arrow-left"></i> ย้อนกลับ
    </button>
</div>
<script>
    // โชว์ปุ่มย้อนกลับเฉพาะเมื่อมาจากลิงก์ภายในเว็บเดียวกัน (กันกรณีเปิดหน้านี้ตรงๆ)
    (function () {
        try {
            var ref = document.referrer;
            if (!ref) return;
            var refUrl = new URL(ref);
            if (refUrl.origin === window.location.origin && refUrl.href !== window.location.href) {
                var btn = document.getElementById('deBackBtn');
                if (btn) btn.style.display = 'inline-flex';
            }
        } catch (e) { /* referrer ใช้ไม่ได้ → ไม่ต้องโชว์ปุ่ม */ }
    })();
</script>
