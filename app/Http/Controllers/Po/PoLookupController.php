<?php

namespace App\Http\Controllers\Po;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PoLookupController extends Controller
{
    public function suppliers(Request $request)
    {
        $q = trim((string) $request->query('q'));

        $rows = DB::connection('pgsqlw')
            ->table('vendor')
            ->when($q !== '', fn ($builder) => $builder->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        return response()->json($rows);
    }

    public function items(Request $request)
    {
        $q = trim((string) $request->query('q'));

        $rows = DB::connection('pgsqlw')
            ->table('orderitems')
            ->when($q !== '', fn ($builder) => $builder->where('description', 'like', "%{$q}%"))
            ->orderBy('description')
            ->limit(20)
            ->get(['description']);

        return response()->json($rows);
    }

    public function units()
    {
        return response()->json([
            ['code' => 'UNIT'],
            ['code' => 'PCS'],
            ['code' => 'SET'],
        ]);
    }
}
