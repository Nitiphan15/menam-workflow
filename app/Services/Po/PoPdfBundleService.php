<?php

namespace App\Services\Po;

use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ชุด PO (PO + PR/เอกสารประกอบ) as one PDF, built from files already stored on the PO.
 *
 * Order: latest "PO Submit" file first (highest po_version), then every other PDF attachment
 * in upload order. Older PO Submit versions and non-PDF files are left out.
 * POs from before auto-attach (no PO Submit file) simply get all their PDFs in upload order.
 */
class PoPdfBundleService
{
    public const MAX_FILES = 20;

    public function __construct(private readonly PdfMergeService $pdfMergeService) {}

    public static function isApproved(PoHeader $po): bool
    {
        return strtoupper((string) $po->status_code) === 'CLOSED';
    }

    /**
     * @param  Collection<int, PoAttached>  $attachments
     * @return array{files: Collection<int, PoAttached>, skipped: Collection<int, PoAttached>, po_file: ?PoAttached}
     */
    public static function selectFiles(Collection $attachments): array
    {
        $attachments = $attachments->sortBy(fn (PoAttached $file) => (int) $file->id)->values();
        [$pdfs, $skipped] = $attachments->partition(fn (PoAttached $file) => self::isPdf($file));

        $poFile = $pdfs
            ->filter(fn (PoAttached $file) => strtoupper((string) ($file->attachment_type ?? '')) === 'PO_SUBMIT')
            ->sortBy(fn (PoAttached $file) => sprintf('%010d-%020d', (int) ($file->po_version ?? 0), (int) $file->id))
            ->last();

        $others = $pdfs->reject(
            fn (PoAttached $file) => strtoupper((string) ($file->attachment_type ?? '')) === 'PO_SUBMIT'
        );

        $files = $poFile ? collect([$poFile])->concat($others) : $others;

        return ['files' => $files->values(), 'skipped' => $skipped->values(), 'po_file' => $poFile];
    }

    /** @return array{path: string, file_name: string, pages: ?int, skipped: array<int, string>} */
    public function build(PoHeader $po): array
    {
        if (!self::isApproved($po)) {
            throw new RuntimeException('ดาวน์โหลดชุด PO ได้หลัง PO อนุมัติครบแล้วเท่านั้น');
        }

        $selection = self::selectFiles($po->attachments()->get());
        $files = $selection['files'];

        if ($files->isEmpty()) {
            throw new RuntimeException("PO {$po->ordnumber} ยังไม่มีไฟล์ PDF แนบ");
        }
        if ($files->count() > self::MAX_FILES) {
            throw new RuntimeException('ไฟล์ PDF ใน PO นี้เกิน ' . self::MAX_FILES . ' ไฟล์ กรุณาใช้แบบเลือกไฟล์เอง');
        }

        $paths = $files->map(function (PoAttached $file) {
            $path = (string) $file->file_path;
            if ($path === '' || !Storage::disk('public')->exists($path)) {
                throw new RuntimeException("ไม่พบไฟล์ {$file->file_name} ในระบบ");
            }

            return Storage::disk('public')->path($path);
        })->all();

        $tempDirectory = storage_path('app/tmp/po-pdf-merge');
        File::ensureDirectoryExists($tempDirectory);
        $targetPath = $tempDirectory . DIRECTORY_SEPARATOR . Str::uuid() . '.pdf';

        $pages = null;
        if (count($paths) === 1) {
            File::copy($paths[0], $targetPath);
        } else {
            try {
                $pages = $this->pdfMergeService->merge($paths, $targetPath, $files->pluck('file_name')->all());
            } catch (RuntimeException $exception) {
                File::delete($targetPath);
                throw $exception;
            }
        }

        return [
            'path' => $targetPath,
            'file_name' => self::fileName((string) $po->ordnumber),
            'pages' => $pages,
            'skipped' => $selection['skipped']->pluck('file_name')->all(),
        ];
    }

    public static function fileName(string $ordnumber): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ordnumber) ?: 'PO';

        return "{$safe}_PO_Set.pdf";
    }

    private static function isPdf(PoAttached $file): bool
    {
        return strtolower((string) $file->file_ext) === 'pdf'
            || strtolower((string) $file->mime_type) === 'application/pdf'
            || str_ends_with(strtolower((string) $file->file_name), '.pdf');
    }
}
