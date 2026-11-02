<?php

namespace App\Repositories\FormDIE;

use Illuminate\Support\Facades\DB;

class DieDamageSourceRepository
{
    public function master(string $die, string $connection): ?object
    {
        return DB::connection($connection)->selectOne(
            'SELECT e.equipnumber, e.description AS die_description, e.brand, e.vendor,
                    e.f1 AS ordered_size, e.f2 AS present_diameter
             FROM equipment e WHERE e.equipnumber = :die LIMIT 1', ['die' => $die]
        );
    }
}
