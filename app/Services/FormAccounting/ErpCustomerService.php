<?php

namespace App\Services\FormAccounting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ErpCustomerService
{
    private const CONNECTIONS = [
        'pgsqlw' => 'WIRE',
        'pgsqlp' => 'PLUS',
    ];

    public function lookup(?string $keyword, int $limit = 20): Collection
    {
        $keyword = trim((string) $keyword);

        if (mb_strlen($keyword) < 2) {
            return collect();
        }

        return collect(array_keys(self::CONNECTIONS))
            ->flatMap(fn($connection) => $this->fetchCustomers($connection, $keyword, $limit))
            ->sortBy(['customer_name', 'erp_source'])
            ->take($limit)
            ->values();
    }

    public function fetchCustomers(string $connection, ?string $keyword = null, int $limit = 20): Collection
    {
        if (!array_key_exists($connection, self::CONNECTIONS)) {
            return collect();
        }

        try {
            $query = DB::connection($connection)
                ->table('customer')
                ->select('customernumber', 'name', 'terms')
                ->whereNotNull('name');

            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $query->where(function ($sub) use ($keyword) {
                    $sub->where('name', 'ilike', '%' . $keyword . '%')
                        ->orWhere('customernumber', 'ilike', '%' . $keyword . '%');
                });
            }

            return $query
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(function ($row) use ($connection) {
                    $code = trim((string) ($row->customernumber ?: $row->name));
                    $name = trim((string) $row->name);
                    $terms = trim((string) ($row->terms ?? ''));

                    return [
                        'id' => $connection . '|' . $code,
                        'erp_source' => $connection,
                        'source_label' => self::CONNECTIONS[$connection],
                        'customer_code' => $code,
                        'customer_name' => $name,
                        'terms' => $terms,
                        'credit_days' => $this->extractCreditDays($terms),
                    ];
                });
        } catch (Throwable $e) {
            report($e);
            return collect();
        }
    }

    private function extractCreditDays(?string $terms): int
    {
        if (!preg_match('/\d+/', (string) $terms, $matches)) {
            return 0;
        }

        return (int) $matches[0];
    }
}
