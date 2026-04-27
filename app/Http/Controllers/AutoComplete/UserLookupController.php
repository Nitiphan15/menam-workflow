<?php

namespace App\Http\Controllers\AutoComplete;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use Illuminate\Http\Request;


class UserLookupController extends Controller
{
    public function search(Request $r)
    {
        $q = trim((string)request('q', ''));
        $rows = User::query()
            ->when($q, fn($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('email', 'like', "%$q%")
                    ->orWhere('user_code', 'like', "%$q%");
            }))
            ->orderBy('name')
            ->limit(20)->get(['id', 'name', 'email', 'user_code']);

        return
            response()->json([
                'results' => $rows->map(fn($u) => ['id' => $u->id, 'text' => "$u->name ($u->email)"]),
            ]);
    }
}
