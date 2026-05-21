<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$connection = 'sqlsrv_menam';
$root = dirname(__DIR__);
$sqlFiles = [
    'database/sql/create_fc_division_forecast_workflow.sql',
    'database/sql/create_fc_division_forecast_approval.sql',
];

foreach ($sqlFiles as $relativeFile) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeFile);
    if (!is_file($path)) {
        fwrite(STDERR, "Cannot find SQL file: {$relativeFile}" . PHP_EOL);
        exit(1);
    }

    $sql = trim((string) file_get_contents($path));
    if ($sql === '') {
        echo "Skipping empty file: {$relativeFile}" . PHP_EOL;
        continue;
    }

    echo "Running {$relativeFile} on {$connection} ..." . PHP_EOL;
    DB::connection($connection)->unprepared($sql);
}

echo "Done. FormFC Division workflow tables are ready." . PHP_EOL;
