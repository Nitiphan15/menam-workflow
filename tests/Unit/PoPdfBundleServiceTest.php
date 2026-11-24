<?php

namespace Tests\Unit;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Services\Po\PdfMergeService;
use App\Services\Po\PoPdfBundleService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * ชุด PO (PO + PR) and PDF merge keeping text added in a PDF reader (annotations).
 * QPDF tests use the bundled tools/qpdf binary and are skipped when it is not there.
 */
class PoPdfBundleServiceTest extends TestCase
{
    private const QPDF = 'tools/qpdf/qpdf-12.4.0-msvc64/bin/qpdf.exe';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function file(int $id, string $name, ?string $type = null, ?int $version = null, string $ext = 'pdf'): PoAttached
    {
        $file = new PoAttached();
        $file->forceFill([
            'id' => $id,
            'file_name' => $name,
            'file_ext' => $ext,
            'mime_type' => $ext === 'pdf' ? 'application/pdf' : 'image/png',
            'file_path' => "po/test/{$name}",
            'attachment_type' => $type,
            'po_version' => $version,
        ]);

        return $file;
    }

    public function test_latest_po_submit_comes_first_then_other_pdfs_in_upload_order(): void
    {
        $selection = PoPdfBundleService::selectFiles(collect([
            $this->file(5, 'quotation.pdf', 'SUPPORTING'),
            $this->file(2, 'PO1_PO.pdf', 'PO_SUBMIT', 1),
            $this->file(3, 'PR.pdf'),
            $this->file(9, 'PO1_PO_v02.pdf', 'PO_SUBMIT', 2),
            $this->file(4, 'photo.png', 'SUPPORTING', null, 'png'),
        ]));

        $this->assertSame(['PO1_PO_v02.pdf', 'PR.pdf', 'quotation.pdf'], $selection['files']->pluck('file_name')->all());
        $this->assertSame(['photo.png'], $selection['skipped']->pluck('file_name')->all());
        $this->assertSame('PO1_PO_v02.pdf', $selection['po_file']->file_name);
    }

    public function test_old_po_without_po_submit_uses_all_pdfs_in_upload_order(): void
    {
        $selection = PoPdfBundleService::selectFiles(collect([
            $this->file(7, 'PR.pdf'),
            $this->file(6, 'PO-from-erp.pdf'),
        ]));

        $this->assertNull($selection['po_file']);
        $this->assertSame(['PO-from-erp.pdf', 'PR.pdf'], $selection['files']->pluck('file_name')->all());
    }

    public function test_only_approved_po_can_be_bundled(): void
    {
        $po = new PoHeader();
        $po->forceFill(['ordnumber' => 'PO2026110229', 'status_code' => 'DEPT_MANAGER_APPROVAL']);

        $this->assertFalse(PoPdfBundleService::isApproved($po));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('อนุมัติครบ');
        (new PoPdfBundleService(new PdfMergeService('', 10)))->build($po);
    }

    public function test_bundle_merges_po_and_pr_into_one_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('po/test/PO1_PO.pdf', $this->pdf('PO-PAGE'));
        Storage::disk('public')->put('po/test/PR.pdf', $this->pdf('PR-PAGE'));

        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('get')->once()->andReturn(collect([
            $this->file(3, 'PR.pdf'),
            $this->file(2, 'PO1_PO.pdf', 'PO_SUBMIT', 1),
        ]));
        $po = Mockery::mock(PoHeader::class)->makePartial();
        $po->forceFill(['ordnumber' => 'PO2026110229', 'status_code' => 'CLOSED']);
        $po->shouldReceive('attachments')->once()->andReturn($relation);

        $result = (new PoPdfBundleService(new PdfMergeService('', 10)))->build($po);

        try {
            $this->assertSame('PO2026110229_PO_Set.pdf', $result['file_name']);
            $this->assertSame(2, $result['pages']);
            $this->assertStringStartsWith('%PDF-', (string) file_get_contents($result['path']));
        } finally {
            File::delete($result['path']);
        }
    }

    public function test_merge_keeps_text_added_in_pdf_reader(): void
    {
        $qpdf = base_path(self::QPDF);
        if (!is_file($qpdf)) {
            $this->markTestSkipped('bundled qpdf not available');
        }

        $dir = storage_path('framework/testing/pdf-merge-' . uniqid());
        File::ensureDirectoryExists($dir);
        try {
            file_put_contents("{$dir}/annotated.pdf", $this->annotatedPdf('TYPED-IN-READER'));
            file_put_contents("{$dir}/plain.pdf", $this->pdf('PLAIN-PAGE'));

            $pages = (new PdfMergeService($qpdf, 30))->merge(["{$dir}/annotated.pdf", "{$dir}/plain.pdf"], "{$dir}/out.pdf");
            $this->assertSame(2, $pages);

            // Decompress the result and look for the annotation text inside the page content.
            exec('"' . $qpdf . '" --qdf --object-streams=disable "' . $dir . '/out.pdf" "' . $dir . '/out-qdf.pdf"');
            $this->assertStringContainsString('TYPED-IN-READER', (string) file_get_contents("{$dir}/out-qdf.pdf"));
            $this->assertSame(['out-qdf.pdf', 'out.pdf'], collect(File::files($dir))->map->getFilename()
                ->reject(fn ($n) => in_array($n, ['annotated.pdf', 'plain.pdf'], true))->sort()->values()->all(),
                'temporary flattened files are removed');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_annotated_pdf_without_qpdf_fails_loudly_instead_of_dropping_text(): void
    {
        $dir = storage_path('framework/testing/pdf-merge-' . uniqid());
        File::ensureDirectoryExists($dir);
        try {
            file_put_contents("{$dir}/annotated.pdf", $this->annotatedPdf('TYPED-IN-READER'));
            file_put_contents("{$dir}/plain.pdf", $this->pdf('PLAIN-PAGE'));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('annotated.pdf');
            (new PdfMergeService($dir . '/no-qpdf.exe', 10))->merge(["{$dir}/annotated.pdf", "{$dir}/plain.pdf"], "{$dir}/out.pdf");
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** One A4 page with $text in the page content. */
    private function pdf(string $text): string
    {
        return $this->buildPdf("BT /F1 18 Tf 72 760 Td ({$text}) Tj ET", null);
    }

    /** One A4 page plus a FreeText annotation (what a PDF reader's "add text" tool creates). */
    private function annotatedPdf(string $annotationText): string
    {
        return $this->buildPdf('BT /F1 18 Tf 72 760 Td (PAGE-BODY) Tj ET', $annotationText);
    }

    private function buildPdf(string $content, ?string $annotationText): string
    {
        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R'
                . ($annotationText !== null ? ' /Annots [5 0 R]' : '') . ' /Resources << /Font << /F1 7 0 R >> >> >>',
            4 => '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
            7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        if ($annotationText !== null) {
            $ap = "BT /F1 14 Tf 2 12 Td ({$annotationText}) Tj ET";
            $objs[5] = "<< /Type /Annot /Subtype /FreeText /Rect [100 600 300 640] /Contents ({$annotationText}) /DA (/F1 14 Tf 0 g) /F 4 /AP << /N 6 0 R >> >>";
            $objs[6] = '<< /Type /XObject /Subtype /Form /BBox [0 0 200 40] /Resources << /Font << /F1 7 0 R >> >> /Length ' . strlen($ap) . " >>\nstream\n{$ap}\nendstream";
        } else {
            $objs[5] = '<< >>';
            $objs[6] = '<< >>';
        }
        ksort($objs);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $obj) {
            $offsets[$n] = strlen($out);
            $out .= "{$n} 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 8\n0000000000 65535 f \n";
        for ($i = 1; $i <= 7; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $out . "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
