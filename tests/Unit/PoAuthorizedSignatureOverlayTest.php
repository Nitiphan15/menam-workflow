<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoExportController;
use App\Services\Po\PoErpService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PoAuthorizedSignatureOverlayTest extends TestCase
{
    public function test_authorized_signature_uses_the_configured_dimensions_and_stroke(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/app/Http/Controllers/Po/PoExportController.php',
        );

        $this->assertStringContainsString(
            '$authorizedBy, 0.39 * $pageWidth, 0.873 * $pageHeight, 0.22 * $pageWidth, 0.051 * $pageHeight',
            $controller,
        );
        $this->assertStringContainsString('strokeBoost: 0.02, trimTransparent: true', $controller);
        $this->assertStringContainsString('if ($strokeBoost > 0)', $controller);
        $this->assertStringContainsString('$diagonalBoost = $strokeBoost * 0.7', $controller);
        $this->assertStringContainsString('trimTransparentSignaturePng', $controller);
        $this->assertStringContainsString('$alpha >= 120', $controller);
        $this->assertStringContainsString('$imageX + $offsetX', $controller);
        $this->assertStringContainsString('$imageY + $offsetY', $controller);
    }

    public function test_transparent_margin_is_trimmed_before_authorized_signature_is_scaled(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for signature trimming.');
        }

        $root = dirname(__DIR__, 2);
        if (!class_exists(PoExportController::class, false)) {
            require_once $root . '/app/Http/Controllers/Po/PoExportController.php';
        }

        $image = imagecreatetruecolor(200, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
        imagefill($image, 0, 0, $transparent);
        $ink = imagecolorallocatealpha($image, 0, 0, 0, 0);
        imagefilledrectangle($image, 60, 40, 140, 60, $ink);
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        imagedestroy($image);

        $controller = new PoExportController(new PoErpService());
        $method = new ReflectionMethod($controller, 'trimTransparentSignaturePng');
        $method->setAccessible(true);
        $trimmed = $method->invoke($controller, $source);
        $size = getimagesizefromstring($trimmed);

        $this->assertSame(89, $size[0]);
        $this->assertSame(29, $size[1]);
    }
}
