<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PoAuthorizedSignatureOverlayTest extends TestCase
{
    public function test_authorized_signature_is_larger_and_has_a_stroke_boost(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/app/Http/Controllers/Po/PoExportController.php',
        );

        $this->assertStringContainsString(
            '$authorizedBy, 0.39 * $pageWidth, 0.868 * $pageHeight, 0.22 * $pageWidth, 0.052 * $pageHeight',
            $controller,
        );
        $this->assertStringContainsString('strokeBoost: 0.12', $controller);
        $this->assertStringContainsString('if ($strokeBoost > 0)', $controller);
        $this->assertStringContainsString('$imageX + $offsetX', $controller);
        $this->assertStringContainsString('$imageY + $offsetY', $controller);
    }
}
