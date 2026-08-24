<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoPdfMergeController;
use App\Services\Po\PdfMergeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PoPdfMergeControllerTest extends TestCase
{
    public function test_it_sanitizes_invalid_windows_filename_characters(): void
    {
        $controller = new PoPdfMergeController(new PdfMergeService('qpdf'));
        $method = new ReflectionMethod($controller, 'downloadFileName');
        $method->setAccessible(true);

        $this->assertSame('PO_42_43_.pdf', $method->invoke($controller, 'PO:42/43?.PDF'));
        $this->assertSame('merged-purchase-orders.pdf', $method->invoke($controller, '  .pdf  '));
    }
}
