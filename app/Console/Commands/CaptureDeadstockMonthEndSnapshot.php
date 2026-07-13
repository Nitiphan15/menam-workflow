<?php

namespace App\Console\Commands;

use App\Models\FormWOS\DeadstockSnapshotMonth;
use App\Services\FormWOS\DeadstockReviewService;
use App\Services\FormWOS\DeadstockSnapshotImportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class CaptureDeadstockMonthEndSnapshot extends Command
{
    protected $signature = 'deadstock:capture-month-end
        {--date= : Snapshot date in YYYY-MM-DD format}
        {--skip-compare : Do not compare the imported snapshot with current ERP data}';

    protected $description = 'Create and import a month-end Deadstock snapshot for monthly reduction comparison.';

    public function handle(
        DeadstockSnapshotImportService $importService,
        DeadstockReviewService $reviewService
    ): int {
        $date = $this->snapshotDate();
        $mailDailyPath = $this->mailDailyPath();

        $process = new Process([
            $this->phpBinary(),
            'artisan',
            'report:deadstock',
            '--snapshot-only',
            '--date=' . $date->toDateString(),
        ], $mailDailyPath);
        $process->setTimeout(1200);
        $process->run();

        $output = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());

        if (!$process->isSuccessful()) {
            $this->error('Failed to create Deadstock month-end snapshot.');
            $this->line($output);
            return self::FAILURE;
        }

        $file = $this->snapshotFile($date);
        if ($file === '') {
            $this->error('Snapshot was generated, but deadstock_items_' . $date->toDateString() . '.json was not found.');
            $this->line($output);
            return self::FAILURE;
        }

        $result = $importService->importFile($file);

        if (!empty($result['skipped'])) {
            $this->warn('Month-end snapshot file has no items - skipped import, no data changed.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Imported month-end Deadstock snapshot %s: month_id=%d items=%d created=%d updated=%d deleted=%d',
            $result['recv_date'],
            $result['month_id'],
            $result['items'],
            $result['created'],
            $result['updated'],
            $result['deleted'] ?? 0
        ));

        if (!$this->option('skip-compare')) {
            $month = DeadstockSnapshotMonth::query()->find($result['month_id']);
            if ($month) {
                $counts = $reviewService->compareMonth($month, false);
                $this->info(sprintf(
                    'Compared imported snapshot: active=%d changed=%d cleared=%d',
                    $counts['active'] ?? 0,
                    $counts['changed'] ?? 0,
                    $counts['cleared'] ?? 0
                ));
            }
        }

        return self::SUCCESS;
    }

    private function snapshotDate(): Carbon
    {
        $date = trim((string) $this->option('date'));

        return $date !== ''
            ? Carbon::parse($date, 'Asia/Bangkok')
            : now('Asia/Bangkok')->endOfMonth();
    }

    private function snapshotFile(Carbon $date): string
    {
        $path = $this->snapshotPath() . DIRECTORY_SEPARATOR . 'deadstock_items_' . $date->toDateString() . '.json';

        return is_file($path) ? $path : '';
    }

    private function snapshotPath(): string
    {
        return $this->mailDailyPath()
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'reports'
            . DIRECTORY_SEPARATOR . 'snapshots';
    }

    private function mailDailyPath(): string
    {
        return rtrim((string) env('DEADSTOCK_MAIL_DAILY_PATH', 'M:\\htdocs\\mail-daily'), '\\/');
    }

    private function phpBinary(): string
    {
        $configured = trim((string) env('DEADSTOCK_PHP_BINARY', ''));
        if ($configured !== '') {
            return $configured;
        }

        $binary = PHP_BINDIR . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');

        return is_file($binary) ? $binary : 'php';
    }
}
