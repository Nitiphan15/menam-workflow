<?php

namespace App\Services\OpsMonitoring;

use Illuminate\Database\DatabaseManager;
use Throwable;

class ErpPostgresChecker
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function check(array $connections): array
    {
        $results = [];

        foreach ($connections as $connection) {
            $startedAt = microtime(true);

            try {
                $this->database->connection($connection)->selectOne('select 1 as health_check');
                $results[$connection] = [
                    'ok' => true,
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            } catch (Throwable $error) {
                $results[$connection] = ['ok' => false, 'error' => $error->getMessage()];
            } finally {
                $this->database->purge($connection);
            }
        }

        return $results;
    }
}
