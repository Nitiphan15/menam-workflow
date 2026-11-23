<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseSafetyGuardTest extends TestCase
{
    public function test_destructive_traits_are_allowed_on_sqlite_memory_only(): void
    {
        config()->set('database.default', 'safety_probe');
        config()->set('database.connections.safety_probe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->invokeSafetyGuard(RefreshDatabase::class);

        $this->addToAssertionCount(1);
    }

    public function test_destructive_traits_abort_before_using_a_real_database_connection(): void
    {
        config()->set('database.default', 'safety_probe');
        config()->set('database.connections.safety_probe', [
            'driver' => 'sqlsrv',
            'database' => 'menam_workflow_dev',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive DB test traits are only allowed on sqlite :memory:.');
        $this->expectExceptionMessage('driver=sqlsrv');
        $this->expectExceptionMessage('database=menam_workflow_dev');

        $this->invokeSafetyGuard(RefreshDatabase::class);
    }

    private function invokeSafetyGuard(string $trait): void
    {
        $method = new \ReflectionMethod(TestCase::class, 'assertSafeTestDatabase');
        $method->setAccessible(true);
        $method->invoke($this, $trait);
    }
}
