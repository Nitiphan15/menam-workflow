<?php

namespace App\Console\Commands;

use App\Services\OpsMonitoring\ErpPostgresChecker;
use Illuminate\Console\Command;

class CheckErpPostgres extends Command
{
    protected $signature = 'ops:check-erp-postgres {--json : Emit a machine-readable result}';

    protected $description = 'Check every configured ERP PostgreSQL connection';

    public function handle(ErpPostgresChecker $checker): int
    {
        $results = $checker->check((array) config('ops_monitor.erp_connections', []));

        $healthy = $results !== [] && collect($results)->every(
            fn (array $result): bool => $result['ok']
        );

        $payload = [
            'ok' => $healthy,
            'checked_at' => now()->toIso8601String(),
            'connections' => $results,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $connection => $result) {
                $result['ok']
                    ? $this->info("{$connection}: OK ({$result['latency_ms']} ms)")
                    : $this->error("{$connection}: FAILED - {$result['error']}");
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
