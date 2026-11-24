<?php

namespace App\Services\Po;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfMergeService
{
    private string $qpdfBinary;

    private int $qpdfTimeout;

    public function __construct(?string $qpdfBinary = null, ?int $qpdfTimeout = null)
    {
        $this->qpdfBinary = $qpdfBinary ?? $this->resolveQpdfBinary((string) $this->configValue('services.pdf.qpdf_binary', 'qpdf'));
        $this->qpdfTimeout = $qpdfTimeout ?? (int) $this->configValue('services.pdf.qpdf_timeout', 60);
    }

    /**
     * @param  array<int, string>  $sourcePaths
     * @param  array<int, string>  $sourceLabels
     */
    public function merge(array $sourcePaths, string $targetPath, array $sourceLabels = []): int
    {
        if (count($sourcePaths) < 2) {
            throw new RuntimeException('กรุณาเลือกไฟล์ PDF อย่างน้อย 2 ไฟล์');
        }

        $normalizedPaths = [];
        $pdf = null;

        try {
            $preparedPaths = $this->prepareSources($sourcePaths, $sourceLabels, $targetPath, $normalizedPaths);
            $pdf = new Fpdi();
            $totalPages = 0;

            foreach ($preparedPaths as $index => $sourcePath) {
                $label = $this->sourceLabel($sourceLabels, $sourcePaths, $index);

                try {
                    $pageCount = $pdf->setSourceFile($sourcePath);
                    for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                        $templateId = $pdf->importPage($pageNumber);
                        $size = $pdf->getTemplateSize($templateId);
                        $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                        $pdf->useTemplate($templateId);
                        $totalPages++;
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException("ไม่สามารถอ่านไฟล์ {$label} ได้", 0, $exception);
                }
            }

            try {
                $pdf->Output('F', $targetPath);
            } catch (Throwable $exception) {
                throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ที่รวมแล้วได้', 0, $exception);
            }

            return $totalPages;
        } finally {
            // FPDI keeps the source files open; on Windows they cannot be deleted until it is released.
            $pdf = null;
            gc_collect_cycles();
            foreach ($normalizedPaths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<int, string>  $sourcePaths
     * @param  array<int, string>  $sourceLabels
     * @param  array<int, string>  $normalizedPaths
     * @return array<int, string>
     */
    private function prepareSources(array $sourcePaths, array $sourceLabels, string $targetPath, array &$normalizedPaths): array
    {
        $preparedPaths = [];

        foreach ($sourcePaths as $index => $sourcePath) {
            $label = $this->sourceLabel($sourceLabels, $sourcePaths, $index);

            // FPDI copies only the page content stream, so text/notes/form values a user
            // adds in a PDF reader (annotations) would be dropped. Bake them into the page first.
            $flattenedPath = dirname($targetPath) . DIRECTORY_SEPARATOR
                . pathinfo($targetPath, PATHINFO_FILENAME) . '-flat-' . $index . '.pdf';
            if ($this->flattenAnnotations($sourcePath, $flattenedPath)) {
                $normalizedPaths[] = $flattenedPath;
                $sourcePath = $flattenedPath;
            } elseif ($this->hasAnnotations($sourcePath)) {
                throw new RuntimeException(
                    "ไฟล์ {$label} มีข้อความ/หมายเหตุที่เพิ่มจากโปรแกรมอ่าน PDF แต่ระบบรวมข้อความนั้นไม่สำเร็จ (ตรวจสอบ QPDF_BINARY) "
                    . 'เพื่อไม่ให้ข้อความหาย กรุณา Print เป็น PDF ใหม่ (Microsoft Print to PDF) แล้วรวมอีกครั้ง'
                );
            }

            try {
                $this->assertReadableByFpdi($sourcePath);
                $preparedPaths[] = $sourcePath;
            } catch (CrossReferenceException $exception) {
                $normalizedPath = dirname($targetPath) . DIRECTORY_SEPARATOR
                    . pathinfo($targetPath, PATHINFO_FILENAME) . '-normalized-' . $index . '.pdf';

                $this->normalizeWithQpdf($sourcePath, $normalizedPath, $label, $exception);
                $normalizedPaths[] = $normalizedPath;

                try {
                    $this->assertReadableByFpdi($normalizedPath);
                } catch (Throwable $normalizedException) {
                    throw new RuntimeException("ไม่สามารถอ่านไฟล์ {$label} หลังแปลงด้วย QPDF ได้", 0, $normalizedException);
                }

                $preparedPaths[] = $normalizedPath;
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "ไม่สามารถอ่านไฟล์ {$label} ได้ กรุณาตรวจสอบว่าไฟล์ไม่เสียหายและไม่ได้ตั้งรหัสผ่าน",
                    0,
                    $exception
                );
            }
        }

        return $preparedPaths;
    }

    /**
     * Flatten annotations (typed text, comments, stamps, form values) into the page content with QPDF.
     * Returns false when QPDF is not available or cannot process the file; the caller decides what to do.
     */
    private function flattenAnnotations(string $sourcePath, string $targetPath): bool
    {
        if (trim($this->qpdfBinary) === '') {
            return false;
        }

        $process = new Process([
            $this->qpdfBinary,
            '--flatten-annotations=all',
            '--generate-appearances',
            '--object-streams=disable',
            $sourcePath,
            $targetPath,
        ]);
        $process->setTimeout(max(10, $this->qpdfTimeout));

        try {
            $process->run();
        } catch (Throwable) {
            @unlink($targetPath);

            return false;
        }

        // QPDF exit code 3 = succeeded with warnings.
        if (!in_array($process->getExitCode(), [0, 3], true) || !is_file($targetPath) || filesize($targetPath) === 0) {
            @unlink($targetPath);

            return false;
        }

        return true;
    }

    /** Cheap check on the raw bytes; compressed object streams can hide it, so this is a safety net only. */
    private function hasAnnotations(string $sourcePath): bool
    {
        $handle = @fopen($sourcePath, 'rb');
        if (!$handle) {
            return false;
        }

        try {
            $tail = '';
            while (!feof($handle)) {
                $chunk = $tail . (string) fread($handle, 1048576);
                if (str_contains($chunk, '/Annots') || str_contains($chunk, '/AcroForm')) {
                    return true;
                }
                $tail = substr($chunk, -16);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private function assertReadableByFpdi(string $sourcePath): void
    {
        $probe = new Fpdi();
        $pageCount = $probe->setSourceFile($sourcePath);

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $probe->importPage($pageNumber);
        }
    }

    private function normalizeWithQpdf(
        string $sourcePath,
        string $targetPath,
        string $label,
        Throwable $originalException
    ): void {
        if (trim($this->qpdfBinary) === '') {
            throw new RuntimeException("ไฟล์ {$label} ต้องแปลงด้วย QPDF แต่ยังไม่ได้ตั้งค่า QPDF_BINARY", 0, $originalException);
        }

        $process = new Process([
            $this->qpdfBinary,
            '--object-streams=disable',
            $sourcePath,
            $targetPath,
        ]);
        $process->setTimeout(max(10, $this->qpdfTimeout));

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "ไม่สามารถเรียก QPDF สำหรับไฟล์ {$label} ได้ กรุณาตรวจสอบ QPDF_BINARY",
                0,
                $exception
            );
        }

        if (!in_array($process->getExitCode(), [0, 3], true) || !is_file($targetPath)) {
            throw new RuntimeException(
                "QPDF ไม่สามารถแปลงไฟล์ {$label} ได้: " . trim($process->getErrorOutput() ?: $process->getOutput()),
                0,
                $originalException
            );
        }
    }

    /** @param array<int, string> $sourceLabels @param array<int, string> $sourcePaths */
    private function sourceLabel(array $sourceLabels, array $sourcePaths, int $index): string
    {
        $label = trim((string) ($sourceLabels[$index] ?? ''));

        return $label !== '' ? $label : basename((string) ($sourcePaths[$index] ?? 'PDF'));
    }

    /** Use the QPDF copy shipped in tools/qpdf when QPDF_BINARY is not set (bare "qpdf" may not be on PATH). */
    private function resolveQpdfBinary(string $configured): string
    {
        if ($configured !== '' && $configured !== 'qpdf') {
            return $configured;
        }

        try {
            $bundled = function_exists('base_path')
                ? base_path('tools/qpdf/qpdf-12.4.0-msvc64/bin/qpdf.exe')
                : '';
        } catch (Throwable) {
            $bundled = '';
        }

        return $bundled !== '' && is_file($bundled) ? $bundled : $configured;
    }

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
