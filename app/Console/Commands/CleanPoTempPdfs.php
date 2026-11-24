<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ลบไฟล์ชั่วคราวของ PO Online (รวม PDF / ชุด PO / Download PDF ที่มีลายเซ็น) ที่ค้างอยู่
 * Normally each request deletes its own files; this catches leftovers (old Windows file locks,
 * aborted downloads). Only *.pdf / *.png directly inside the two PO temp folders are touched.
 */
class CleanPoTempPdfs extends Command
{
    protected $signature = 'po:clean-temp-pdfs
        {--hours=24 : ลบไฟล์ที่เก่ากว่ากี่ชั่วโมง (อย่างน้อย 1)}
        {--dry-run : แสดงรายการโดยไม่ลบ}';

    protected $description = 'ลบไฟล์ชั่วคราว PDF ของ PO Online ที่ค้างใน storage/app/tmp';

    /** @var array<int, string> relative to storage/app */
    public const DIRECTORIES = ['tmp/po-pdf-merge', 'tmp/po-pdf'];

    private const EXTENSIONS = ['pdf', 'png'];

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours)->getTimestamp();
        $deleted = 0;
        $bytes = 0;
        $failed = 0;

        foreach (self::DIRECTORIES as $relative) {
            $directory = storage_path('app/' . $relative);
            if (!File::isDirectory($directory)) {
                continue;
            }

            foreach (File::files($directory) as $file) {
                if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)
                    || $file->getMTime() >= $cutoff) {
                    continue;
                }

                $size = $file->getSize();
                if ($dryRun) {
                    $this->line("would delete {$relative}/{$file->getFilename()}");
                } elseif (!@unlink($file->getPathname())) {
                    $failed++;
                    continue;
                }

                $deleted++;
                $bytes += $size;
            }
        }

        $this->info(sprintf(
            '%s %d ไฟล์ (%.1f MB) ที่เก่ากว่า %d ชั่วโมง%s',
            $dryRun ? 'พบ' : 'ลบแล้ว',
            $deleted,
            $bytes / 1048576,
            $hours,
            $failed ? " · ลบไม่ได้ {$failed} ไฟล์ (อาจยังถูกใช้งานอยู่)" : '',
        ));

        return self::SUCCESS;
    }
}
