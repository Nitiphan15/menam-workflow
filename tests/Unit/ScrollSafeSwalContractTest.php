<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ScrollSafeSwalContractTest extends TestCase
{
    public function test_layout_loads_helper_after_sweetalert_with_safe_asset_version(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root.'/resources/views/layouts/layout.blade.php');

        $sweetAlertPosition = strpos($layout, "vendor/sweetalert2/sweetalert2.all.min.js");
        $scrollSafePosition = strpos($layout, "js/scroll-safe-swal.js");

        $this->assertNotFalse($sweetAlertPosition);
        $this->assertNotFalse($scrollSafePosition);
        $this->assertGreaterThan($sweetAlertPosition, $scrollSafePosition);
        $this->assertStringContainsString("file_exists(public_path('js/scroll-safe-swal.js'))", $layout);
        $this->assertStringContainsString("config('app.version')", $layout);
    }

    public function test_helper_restores_window_and_container_scroll_and_callers_have_fallback(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = file_get_contents($root.'/public/js/scroll-safe-swal.js');
        $layout = file_get_contents($root.'/resources/views/layouts/layout.blade.php');
        $inquiryView = file_get_contents($root.'/resources/views/formdp/inquiry.blade.php');
        $inquiryJs = file_get_contents($root.'/public/js/formdp/inquiry.js');

        $this->assertStringContainsString('window.scrollTo(snapshot.windowX, snapshot.windowY)', $helper);
        $this->assertStringContainsString('position.element.scrollTop = position.top', $helper);
        $this->assertStringContainsString('scrollableParents(active)', $helper);
        $this->assertStringContainsString('config.returnFocus = false', $helper);
        $this->assertStringContainsString('window.ScrollSafeSwal || window.Swal', $layout);
        $this->assertStringContainsString('window.ScrollSafeSwal || window.Swal', $inquiryView);
        $this->assertStringContainsString('window.ScrollSafeSwal || window.Swal', $inquiryJs);
    }
}
