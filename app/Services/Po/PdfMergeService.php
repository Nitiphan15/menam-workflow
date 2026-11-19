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
        $this->qpdfBinary = $qpdfBinary ?? $this->configValue('services.pdf.qpdf_binary', 'qpdf');
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

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
