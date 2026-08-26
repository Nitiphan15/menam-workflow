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

    public function test_amount_is_converted_to_thai_baht_text(): void
    {
        $this->assertSame(
            'สองแสนสองหมื่นแปดพันสองร้อยเก้าสิบห้าบาทสี่สิบสตางค์',
            InvoicePackingDocumentService::amountInThaiText(228295.40)
        );
    }

    public function test_material_type_is_derived_from_erp_description(): void
    {
        $this->assertSame('เพลาเหล็ก', InvoicePackingDocumentService::materialType('Carbon Steel Bar S50C'));
        $this->assertSame('เพลาสแตนเลส', InvoicePackingDocumentService::materialType('Bar 430F dia.6.00'));
        $this->assertSame('', InvoicePackingDocumentService::materialType('Special product'));
    }

    public function test_print_template_uses_the_legacy_customs_a4_sections(): void
    {
        $template = file_get_contents(__DIR__ . '/../../resources/views/formwos/invoice_packing_document/print.blade.php');

        $this->assertStringContainsString('@page { size: A4 portrait;', $template);
        $this->assertStringContainsString('font-family: "Cordia New"', $template);
        $this->assertStringContainsString('font-size: 12pt;', $template);
        $this->assertStringNotContainsString('{{ $row->package_numbers }}', $template);
        $this->assertStringNotContainsString('{{ $row->partnumber }}', $template);
        $this->assertStringContainsString('{{ number_format((int) $row->package_qty) }} ลัง', $template);
        $this->assertStringContainsString('font-size: 10pt; line-height: 1; white-space: nowrap;', $template);
        $this->assertStringContainsString('{{ number_format($pagePackageQty) }} ลัง', $template);
        $this->assertStringContainsString('คำร้องขอส่งของในราชอาณาจักร', $template);
        $this->assertStringContainsString('จำนวนหีบห่อ', $template);
        $this->assertStringContainsString('น้ำหนักสุทธิ', $template);
        $this->assertStringContainsString('น้ำหนักรวม', $template);
        $this->assertStringContainsString("pluck('customer_name')", $template);
        $this->assertStringContainsString("pluck('customer_province')", $template);
        $this->assertStringContainsString('ซึ่งจำหน่ายให้แก่', $template);
        $this->assertStringContainsString('border-top: 0; border-bottom: 0;', $template);
        $this->assertStringContainsString('ใบกำกับภาษีเลขที่', $template);
        $this->assertStringContainsString('จำนวนเงิน (ตัวอักษร)', $template);
        $this->assertStringContainsString('class="stamp-space"', $template);
        $this->assertStringContainsString('text-align: right;', $template);
        $this->assertStringContainsString('bottom: -8mm;', $template);
        $this->assertStringContainsString('จึงเรียนมาเพื่อโปรดทราบ', $template);
        $this->assertStringContainsString('$bodyRowHeight = 80 / max($pageRows->count(), 1);', $template);
        $this->assertStringNotContainsString('@for ($emptyRow = count($rows);', $template);

        $service = file_get_contents(__DIR__ . '/../../app/Services/FormWOS/InvoicePackingDocumentService.php');
        $this->assertStringContainsString('customer.f5 AS customer_province', $service);
        $this->assertStringContainsString('บันทึกการอนุญาตของพนักงานศุลกากร', $template);
        $this->assertStringContainsString('{{ $signerName }}', $template);
    }
}
