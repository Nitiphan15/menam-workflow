<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SqlServerDb
{
    public static function connectionName(): string
    {
        return (string) config('database.workflow_connection', 'sqlsrv_menam');
    }

    public static function connection(): ConnectionInterface
    {
        return DB::connection(self::connectionName());
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }

    public static function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return self::connection()->transaction($callback, $attempts);
    }

    public static function qualifyTable(string $table): string
    {
        return self::connectionName() . '.' . $table;
    }
}
