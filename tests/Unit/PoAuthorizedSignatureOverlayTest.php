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
            '$authorizedBy, 0.385 * $pageWidth, 0.864 * $pageHeight, 0.23 * $pageWidth, 0.056 * $pageHeight',
            $controller,
        );
        $this->assertStringContainsString('strokeBoost: 0.18', $controller);
        $this->assertStringContainsString('if ($strokeBoost > 0)', $controller);
        $this->assertStringContainsString('$diagonalBoost = $strokeBoost * 0.7', $controller);
        $this->assertStringContainsString('$imageX + $offsetX', $controller);
        $this->assertStringContainsString('$imageY + $offsetY', $controller);
    }
}
