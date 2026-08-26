<?php

namespace Tests\Unit;

use App\Services\FormWOS\InvoicePackingDocumentService;
use PHPUnit\Framework\TestCase;

class InvoicePackingDocumentServiceTest extends TestCase
{
    public function test_invoice_numbers_accept_newlines_commas_and_semicolons_without_duplicates(): void
    {
        $numbers = InvoicePackingDocumentService::normalizeInvoiceNumbers(
            "d2026080106\nINVE2026080018, d2026080106;DP2025040211"
        );

        $this->assertSame([
            'D2026080106',
            'INVE2026080018',
            'DP2025040211',
        ], $numbers);
    }

    public function test_print_rows_are_split_into_five_rows_per_page(): void
    {
        $rows = range(1, 10);
        $pages = InvoicePackingDocumentService::pages($rows);

        $this->assertCount(2, $pages);
        $this->assertSame([1, 2, 3, 4, 5], $pages[0]);
        $this->assertSame([6, 7, 8, 9, 10], $pages[1]);
    }
}
