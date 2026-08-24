<?php

namespace Tests\Unit;

use App\Services\Po\PdfMergeService;
use FPDF;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Symfony\Component\Process\Process;

class PdfMergeServiceTest extends TestCase
{
    public function test_it_merges_all_pages_in_source_order(): void
    {
        $directory = $this->tempDirectory();

        try {
            $first = $directory . DIRECTORY_SEPARATOR . 'first.pdf';
            $second = $directory . DIRECTORY_SEPARATOR . 'second.pdf';
            $output = $directory . DIRECTORY_SEPARATOR . 'merged.pdf';
            $this->createPdf($first, ['first-1', 'first-2']);
            $this->createPdf($second, ['second-1']);

            $pageCount = (new PdfMergeService('qpdf'))->merge([$first, $second], $output);

            $this->assertSame(3, $pageCount);
            $this->assertSame(3, (new Fpdi())->setSourceFile($output));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_it_normalizes_compressed_xref_pdf_before_merging(): void
    {
        $qpdf = getenv('QPDF_TEST_BINARY') ?: '';
        if ($qpdf === '' || !is_file($qpdf)) {
            $this->markTestSkipped('Set QPDF_TEST_BINARY to run the compressed-XRef regression test.');
        }

        $directory = $this->tempDirectory();

        try {
            $plain = $directory . DIRECTORY_SEPARATOR . 'plain.pdf';
            $compressed = $directory . DIRECTORY_SEPARATOR . 'compressed.pdf';
            $second = $directory . DIRECTORY_SEPARATOR . 'second.pdf';
            $output = $directory . DIRECTORY_SEPARATOR . 'merged.pdf';
            $this->createPdf($plain, ['compressed-source']);
            $this->createPdf($second, ['second']);

            $process = new Process([$qpdf, '--object-streams=generate', $plain, $compressed]);
            $process->mustRun();

            $this->expectFpdiCrossReferenceFailure($compressed);

            $pageCount = (new PdfMergeService($qpdf, 30))->merge(
                [$compressed, $second],
                $output,
                ['compressed.pdf', 'second.pdf']
            );

            $this->assertSame(2, $pageCount);
            $this->assertSame(2, (new Fpdi())->setSourceFile($output));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function expectFpdiCrossReferenceFailure(string $path): void
    {
        try {
            (new Fpdi())->setSourceFile($path);
            $this->fail('The generated fixture should require XRef normalization.');
        } catch (CrossReferenceException) {
            $this->addToAssertionCount(1);
        }
    }

    private function createPdf(string $path, array $pages): void
    {
        $pdf = new FPDF();
        foreach ($pages as $text) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(40, 10, $text);
        }
        $pdf->Output('F', $path);
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'po-pdf-merge-' . bin2hex(random_bytes(6));
        mkdir($directory);

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}
