<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Stop destructive Laravel database-test traits before they can touch a
     * configured SQL Server or PostgreSQL connection.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));

        $destructiveTraits = array_filter([
            RefreshDatabase::class,
            DatabaseMigrations::class,
            class_exists(DatabaseTruncation::class) ? DatabaseTruncation::class : null,
        ]);

        foreach ($destructiveTraits as $trait) {
            if (isset($uses[$trait])) {
                $this->assertSafeTestDatabase($trait);
                break;
            }
        }

        return parent::setUpTraits();
    }

    protected function assertSafeTestDatabase(string $trait): void
    {
        $default = (string) config('database.default');
        $driver = (string) config("database.connections.{$default}.driver");
        $database = (string) config("database.connections.{$default}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'ABORTED: %s uses %s on connection "%s" (driver=%s, database=%s). '
                .'Destructive DB test traits are only allowed on sqlite :memory:.',
                static::class,
                class_basename($trait),
                $default,
                $driver,
                $database
            ));
        }
    }
}
