<?php

namespace App\Http\Controllers;

use App\Support\MenuCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function index(Request $request): View
    {
        return view('menus.index', [
            'menuGroups' => MenuCatalog::forUser($request->user()),
        ]);
    }
}
