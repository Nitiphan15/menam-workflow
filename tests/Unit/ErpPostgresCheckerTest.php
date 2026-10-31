<?php

namespace Tests\Unit;

use App\Services\OpsMonitoring\ErpPostgresChecker;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../app/Services/OpsMonitoring/ErpPostgresChecker.php';

class ErpPostgresCheckerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_reports_each_connection_and_purges_it_after_checking(): void
    {
        $healthy = Mockery::mock(Connection::class);
        $healthy->shouldReceive('selectOne')->once()->andReturn((object) ['health_check' => 1]);
        $broken = Mockery::mock(Connection::class);
        $broken->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('connection refused'));

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->with('pgsqlw')->once()->andReturn($healthy);
        $database->shouldReceive('connection')->with('pgsqlp')->once()->andReturn($broken);
        $database->shouldReceive('purge')->with('pgsqlw')->once();
        $database->shouldReceive('purge')->with('pgsqlp')->once();

        $results = (new ErpPostgresChecker($database))->check(['pgsqlw', 'pgsqlp']);

        $this->assertTrue($results['pgsqlw']['ok']);
        $this->assertFalse($results['pgsqlp']['ok']);
        $this->assertSame('connection refused', $results['pgsqlp']['error']);
    }
}
