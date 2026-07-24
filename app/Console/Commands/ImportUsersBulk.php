<?php

namespace App\Console\Commands;

use App\Services\Admin\BulkUserImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportUsersBulk extends Command
{
    protected $signature = 'users:bulk-import
        {file : CSV, XLSX or XLS file path}
        {--default-password= : Password used when the file has no password column}
        {--email-domain=menam.local : Domain used to generate email from employee code}
        {--delimiter= : CSV delimiter; auto-detected when omitted}
        {--dry-run : Validate and preview without creating users}';

    protected $description = 'Create multiple users and assign their department positions from a spreadsheet.';

    public function handle(BulkUserImportService $importer): int
    {
        $file = trim((string) $this->argument('file'));
        $defaultPassword = (string) $this->option('default-password');
        $emailDomain = trim((string) $this->option('email-domain'));
        $delimiter = $this->option('delimiter');
        $delimiter = is_string($delimiter) && $delimiter !== '' ? $delimiter : null;
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $importer->import(
                $file,
                $defaultPassword,
                $emailDomain,
                $dryRun,
                $delimiter
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['Row', 'Employee code', 'Name', 'Email', 'Department', 'Position'],
            collect($result['rows'])->map(fn ($row) => [
                $row['row'],
                $row['user_code'],
                $row['name'],
                $row['email'],
                $row['department'],
                $row['position'],
            ])->all()
        );

        if ($result['dry_run']) {
            $this->info("Validation passed for {$result['total']} user(s). No data was created.");
            return self::SUCCESS;
        }

        $this->info("Imported {$result['imported']} user(s) successfully.");
        $this->warn('The generated accounts do not receive web permissions automatically.');

        return self::SUCCESS;
    }
}
