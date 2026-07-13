<?php

namespace App\Console\Commands;

use App\Services\FormWOS\DeadstockSnapshotImportService;
use Illuminate\Console\Command;

class ImportDeadstockSnapshot extends Command
{
    protected $signature = 'deadstock:import-snapshot
        {--file= : Full path to deadstock_items_YYYY-MM-DD.json}
        {--date= : Snapshot recv date in YYYY-MM-DD format}';

    protected $description = 'Import deadstock item snapshot JSON from mail-daily into ds_* review tables.';

    public function handle(DeadstockSnapshotImportService $importService): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $file = $this->resolveFileByDate(trim((string) $this->option('date')));
        }

        if ($file === '') {
            $this->error('No deadstock item snapshot file matched the request.');
            return self::FAILURE;
        }

        $result = $importService->importFile($file);

        if (!empty($result['skipped'])) {
            $this->warn('Snapshot file has no items - skipped import, no data changed. (' . $file . ')');
            return self::SUCCESS;
        }

        $this->info('Imported Deadstock snapshot successfully.');
        $this->line('Month: ' . $result['snapshot_month']);
        $this->line('Recv date: ' . $result['recv_date']);
        $this->line('Items: ' . $result['items']);
        $this->line('Created: ' . $result['created']);
        $this->line('Updated: ' . $result['updated']);
        $this->line('Deleted: ' . ($result['deleted'] ?? 0));

        return self::SUCCESS;
    }

    private function resolveFileByDate(string $date): string
    {
        $base = rtrim((string) env('DEADSTOCK_MAIL_DAILY_PATH', 'M:\\htdocs\\mail-daily'), '\\/');
        $dir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'snapshots';

        if ($date !== '') {
            $candidate = $dir . DIRECTORY_SEPARATOR . "deadstock_items_{$date}.json";
            return is_file($candidate) ? $candidate : '';
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'deadstock_items_????-??-??.json') ?: [];
        rsort($files);

        // ข้ามไฟล์ที่ลงวันที่อนาคต (เกิดจากการรันผิดวัน) ไม่ให้ scheduler import ซ้ำทุกเช้า
        $today = now('Asia/Bangkok')->toDateString();
        foreach ($files as $file) {
            if (preg_match('/deadstock_items_(\d{4}-\d{2}-\d{2})\.json$/', basename($file), $m) && $m[1] <= $today) {
                return $file;
            }
        }

        return '';
    }
}
