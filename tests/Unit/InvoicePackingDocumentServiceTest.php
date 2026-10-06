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

    public function test_material_type_uses_part_mapping_before_description_fallback(): void
    {
        $this->assertSame(
            'เพลาสแตนเลส',
            InvoicePackingDocumentService::materialType('0600101034', 'Carbon Steel Bar S50C')
        );
        $this->assertSame(
            'เพลาสแตนเลส',
            InvoicePackingDocumentService::materialType(
                'FB303XXX100003000C',
                'Carbon Steel Bar (0600101.0.34) conflicting fallback'
            )
        );
        $this->assertSame(
            'เพลาเหล็ก',
            InvoicePackingDocumentService::materialType('0600101025', 'Bar 303 stainless')
        );
        $this->assertSame(
            'เพลาสแตนเลส',
            InvoicePackingDocumentService::materialType('UNKNOWN', 'Bar 430F dia.6.00')
        );

        $mapPath = __DIR__ . '/../../resources/data/formwos/invoice_packing_material_types.json';
        $map = json_decode((string) file_get_contents($mapPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(62, $map);
        $this->assertSame('เพลาสแตนเลส', $map['0600101106']);
        $this->assertSame('เพลาเหล็ก', $map['0600101114']);
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
        $this->assertStringContainsString('{{ number_format($documentPackageQty) }} ลัง', $template);
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
        $this->assertStringContainsString('.amount-text-line { display: inline-block; min-width: 95mm; border-bottom: 1px dotted #777; }', $template);
        $this->assertStringContainsString('<span class="amount-text-line">{{ $documentAmountText }}</span>', $template);
        $this->assertStringContainsString('class="stamp-space"', $template);
        $this->assertStringContainsString('text-align: right;', $template);
        $this->assertStringContainsString('bottom: -8mm;', $template);
        $this->assertStringContainsString('.w-package { width: 11%; }', $template);
        $this->assertStringContainsString('.w-qty { width: 19%; }', $template);
        $this->assertStringContainsString('.w-price { width: 11%; }', $template);
        $this->assertStringContainsString('.w-desc { width: 39%; }', $template);
        $this->assertStringContainsString('.items-table tbody tr:last-child td { border-bottom: 0; }', $template);
        $this->assertStringContainsString('.items-table.no-total tbody tr:last-child td { border-bottom: 1px solid #000; }', $template);
        $this->assertStringContainsString('.items-table tbody tr.single-row-continuation td { vertical-align: top; padding-top: 2mm; }', $template);
        $this->assertStringContainsString('.items-table tfoot td { height: 8mm; border-top: 0; background: #fff; vertical-align: middle; font-weight: 400; }', $template);
        $this->assertStringContainsString('.letter-body .subject-line { margin-bottom: 2mm; }', $template);
        $this->assertStringContainsString('.letter-body .contact-line { margin-bottom: 2mm; }', $template);
        $this->assertStringContainsString('.customer-line { min-width: 68mm; color: #f00000; font-weight: 400; }', $template);
        $this->assertStringContainsString('.letter-body .customer-location-line { margin-bottom: .2mm; white-space: nowrap; }', $template);
        $this->assertStringContainsString('.po-line { min-height: 6mm; margin: .2mm 0 1.5mm;', $template);
        $this->assertStringContainsString('.approval-section { margin-top: 6mm; }', $template);
        $this->assertStringContainsString('<p class="subject-line">เรื่อง', $template);
        $this->assertStringContainsString('<p class="contact-line">รหัสไปรษณีย์', $template);
        $this->assertStringContainsString('<p class="customer-location-line">บริษัท', $template);
        $this->assertStringContainsString('.closing { width: 72mm; padding-top: 5mm;', $template);
        $this->assertStringContainsString('.signature-name { margin-top: 1mm; color: #f00000; font-weight: 400; }', $template);
        $this->assertStringContainsString('จึงเรียนมาเพื่อโปรดทราบ', $template);
        $this->assertStringContainsString('$bodyRowHeight = 80 / max($pageRows->count(), 1);', $template);
        $this->assertStringContainsString('$documentRows = collect($pages)->flatten(1);', $template);
        $this->assertStringContainsString('$isLastPage = $pageIndex === count($pages) - 1;', $template);
        $this->assertStringContainsString('$isSingleRowContinuation = $pageIndex > 0 && $pageRows->count() === 1;', $template);
        $this->assertStringContainsString('<table class="items-table {{ $isLastPage ? \'has-total\' : \'no-total\' }}">', $template);
        $this->assertStringContainsString('@if ($isLastPage)', $template);
        $this->assertStringNotContainsString('@for ($emptyRow = count($rows);', $template);
        $this->assertStringContainsString('$poNumbers', $template);
        $this->assertStringContainsString('$poDueDates', $template);

        $service = file_get_contents(__DIR__ . '/../../app/Services/FormWOS/InvoicePackingDocumentService.php');
        $this->assertStringContainsString('customer.f5 AS customer_province', $service);
        $this->assertStringContainsString('ar.duedate AS due_date', $service);
        $this->assertStringContainsString('บันทึกการอนุญาตของพนักงานศุลกากร', $template);
        $this->assertStringContainsString('{{ $signerName }}', $template);
        $this->assertStringContainsString('materialType($row->partnumber, $row->description)', $template);
    }

    public function test_invoice_entry_page_supports_erp_autocomplete(): void
    {
        $template = file_get_contents(__DIR__ . '/../../resources/views/formwos/invoice_packing_document/index.blade.php');
        $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/FormWOS/InvoicePackingDocumentController.php');
        $service = file_get_contents(__DIR__ . '/../../app/Services/FormWOS/InvoicePackingDocumentService.php');
        $routes = file_get_contents(__DIR__ . '/../../routes/web.php');

        $this->assertStringContainsString("new TomSelect('#invoice_numbers_picker'", $template);
        $this->assertStringContainsString('maxItems: 10', $template);
        $this->assertStringContainsString('name="invoice_numbers[]" multiple required', $template);
        $this->assertStringNotContainsString('id="invoice_numbers" name="invoice_numbers" type="hidden"', $template);
        $this->assertStringContainsString("route('wos.invoice_packing_document.invoices')", $template);
        $this->assertStringContainsString('public function invoices(', $controller);
        $this->assertStringContainsString("is_array(\$request->input('invoice_numbers'))", $controller);
        $this->assertStringContainsString('suggestInvoiceNumbers($query)', $controller);
        $this->assertStringContainsString('public function suggestInvoiceNumbers(', $service);
        $this->assertStringContainsString('UPPER(TRIM(ar.invnumber)) LIKE ?', $service);
        $this->assertStringContainsString("name('invoice_packing_document.invoices')", $routes);
        $this->assertStringContainsString("middleware('throttle:60,1')", $routes);
    }
}
