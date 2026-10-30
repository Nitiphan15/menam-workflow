<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeadstockBackgroundExportConfigTest extends TestCase
{
    public function test_queue_connection_matches_job_and_sql_table(): void
    {
        $config = require dirname(__DIR__, 2).'/config/queue.php';
        $connection = $config['connections']['wr-deadstock-export'] ?? null;

        $this->assertSame('database', $connection['driver'] ?? null);
        $this->assertSame('sqlsrv_menam', $connection['connection'] ?? null);
        $this->assertSame('wr_ds_export_jobs', $connection['table'] ?? null);
        $this->assertSame('wr-deadstock-export', $connection['queue'] ?? null);
        $this->assertGreaterThan(900, $connection['retry_after'] ?? 0);
        $this->assertTrue($connection['after_commit'] ?? false);

        $job = file_get_contents(dirname(__DIR__, 2).'/app/Jobs/GenerateWireRodDeadstockExport.php');
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sql/wirerod-deadstock/09_add_wr_ds_background_exports.sql');

        $this->assertStringContainsString("onConnection('wr-deadstock-export')", $job);
        $this->assertStringContainsString('CREATE TABLE dbo.wr_ds_export_jobs', $sql);
    }
}
